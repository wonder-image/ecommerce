<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Cart;

use Throwable;
use Wonder\Frontend\Support\FlashMessage;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Auth\Frontend\AuthSession;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\OnlinePayment;
use Wonder\Http\SafeRedirect;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\View\View;

final class CartController
{
    public static function handle(string $action, array $parameters = []): void
    {
        match ($action) {
            'index' => self::index(),
            'preview' => self::preview(),
            'add' => self::add(),
            'quantity' => self::quantity((int) ($parameters['id'] ?? 0)),
            'remove' => self::remove((int) ($parameters['id'] ?? 0)),
            default => self::notFound(),
        };
    }

    private static function index(): void
    {
        self::resume(false);
        $cart = CartSession::current(false);

        self::seo();
        View::make(Ecommerce::viewPath('pages/cart/index.php'), [
            'cart' => $cart,
            'csrf_token' => AuthSession::csrfToken(),
            'coupons' => Gestionale::feature('coupons'),
            'step' => 'cart',
        ])->render();
    }

    private static function add(): void
    {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity = self::number($_POST['quantity'] ?? 1);
        $choices = self::choices($_POST);

        self::mutate(static function (int $cartId) use ($productId, $quantity, $choices): array {
            return Cart::add($cartId, [
                'product_id' => $productId,
                'quantity' => $quantity,
                'choices' => $choices,
            ]);
        }, (string) __t('ecommerce.cart.added'), true, static fn (array $cart): ?array => CartPresenter::addEvent(
            $cart,
            $productId,
            $choices,
            $quantity,
            (string) ($cart['order']['currency'] ?? '') ?: (string) Ecommerce::config('catalog.currency', 'EUR')
        ));
    }

    /**
     * Le opzioni scelte di un multiprodotto, piatte: la scheda le manda gruppo
     * per gruppo in `choices_{id}`, un client le può mandare tutte in `choices`.
     * Le valida {@see \Wonder\Plugin\Gestionale\Support\Catalog\Bundles::resolve()}.
     *
     * @return list<int>
     */
    public static function choices(array $post): array
    {
        $ids = [];

        foreach ($post as $key => $value) {
            if ($key === 'choices' || preg_match('/^choices_\d+$/', (string) $key) === 1) {
                foreach ((array) $value as $id) {
                    if (is_scalar($id) && (int) $id > 0) {
                        $ids[(int) $id] = (int) $id;
                    }
                }
            }
        }

        return array_values($ids);
    }

    private static function preview(): never
    {
        self::json(self::payload(CartSession::current(false)));
    }

    private static function quantity(int $itemId): void
    {
        self::mutate(
            static fn (int $cartId): array => Cart::setQuantity(
                $cartId,
                $itemId,
                self::number($_POST['quantity'] ?? 0)
            ),
            (string) __t('ecommerce.cart.updated')
        );
    }

    private static function remove(int $itemId): void
    {
        self::mutate(
            static fn (int $cartId): array => Cart::remove($cartId, $itemId),
            (string) __t('ecommerce.cart.removed')
        );
    }

    /** @param (callable(array): ?array)|null $event l'evento GTM, dal carrello che il server ha appena scritto */
    private static function mutate(callable $mutation, string $notice, bool $createCart = false, ?callable $event = null): never
    {
        self::requirePost();
        $wantsJson = self::wantsJson();
        self::requireCsrf();
        self::resume($wantsJson);

        $errors = [];
        $cart = null;

        try {
            $current = CartSession::current($createCart);
            $cart = $mutation((int) ($current['order']['id'] ?? 0));
        } catch (UserError $error) {
            $errors[] = $error->getMessage();
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.cart.mutate');
            $errors[] = (string) __t('ecommerce.cart.error');
        }

        if ($wantsJson) {
            $written = is_array($cart);
            $cart = $written ? $cart : CartSession::current(false);
            $payload = self::payload($cart, $errors === [], $errors === [] ? $notice : '', $errors);
            if ($errors === [] && $written && $event !== null && ($pushed = $event($cart)) !== null) {
                $payload['event'] = $pushed;
            }
            self::json($payload);
        }

        if ($errors === []) {
            FlashMessage::success($notice, (string) __t('ecommerce.cart.notice_title'));
        } else {
            FlashMessage::error(implode("\n", $errors), (string) __t('ecommerce.cart.error_title'));
        }

        self::redirect(SafeRedirect::fromRequest(
            $_POST['continue'] ?? '',
            self::route('ecommerce.cart.index')
        ));
    }

    /**
     * Il cliente torna al carrello con un ordine in pagamento: l'ordine torna
     * carrello. Se Stripe sta già incassando si va al ritorno del pagamento.
     */
    private static function resume(bool $json): void
    {
        $reopened = OnlinePayment::resume();
        $url = match ($reopened['outcome']) {
            'succeeded', 'processing' => self::route('ecommerce.checkout.return').'?payment_intent='.rawurlencode((string) $reopened['reference']),
            'kept' => self::route('ecommerce.checkout.pay'),
            default => '',
        };

        if ($url === '') {
            if (! $json && $reopened['removed'] !== []) {
                FlashMessage::info(
                    (string) __t('ecommerce.checkout.pay.removed', ['names' => implode(', ', $reopened['removed'])]),
                    (string) __t('ecommerce.cart.notice_title')
                );
            }

            return;
        }

        if ($reopened['outcome'] === 'kept') {
            FlashMessage::error((string) ($reopened['error'] ?? ''), (string) __t('ecommerce.cart.error_title'));
        }

        if ($json) {
            self::json(['success' => false, 'redirect' => $url]);
        }

        self::redirect($url);
    }

    private static function requirePost(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            self::notFound();
        }
    }

    private static function requireCsrf(): void
    {
        if (AuthSession::verify($_POST['csrf_token'] ?? '')) {
            return;
        }

        if (self::wantsJson()) {
            self::json([
                'success' => false,
                'errors' => [(string) __t('ecommerce.cart.error')],
            ], 419);
        }

        http_response_code(419);
        exit('CSRF token invalid');
    }

    private static function number(mixed $value): float
    {
        return (float) str_replace(',', '.', trim((string) $value));
    }

    /** @param array<string, mixed> $cart */
    private static function payload(array $cart, bool $success = true, string $notice = '', array $errors = []): array
    {
        $items = array_values((array) ($cart['items'] ?? []));

        return [
            'success' => $success,
            'notice' => $notice,
            'errors' => array_values(array_filter(array_map('strval', $errors))),
            'count' => CartPresenter::count($items),
            'empty' => $items === [],
            'html' => View::component(Ecommerce::viewPath('components/cart/mini-cart-body.php'), [
                'cart' => $cart,
                'csrf_token' => AuthSession::csrfToken(),
                'notice' => $notice,
                'errors' => $errors,
            ]),
        ];
    }

    private static function wantsJson(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    }

    private static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function seo(): void
    {
        global $SEO;

        $SEO->title = (string) __t('ecommerce.cart.title');
        $SEO->description = (string) __t('ecommerce.cart.seo');
        $SEO->url = self::route('ecommerce.cart.index');
        $SEO->breadcrumb = [];
        $SEO->robots = 'NOINDEX,NOFOLLOW';
    }

    private static function route(string $name, array $parameters = []): string
    {
        return \__r($name, $parameters) ?: '/carrello/';
    }

    private static function redirect(string $url): never
    {
        header('Location: '.$url);
        exit;
    }

    private static function notFound(): never
    {
        http_response_code(404);
        exit;
    }
}
