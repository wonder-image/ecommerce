<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Cart;

use Throwable;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthSession;
use Wonder\Plugin\Ecommerce\Support\SafeRedirect;
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
        $cart = CartSession::current(false);
        $flash = self::pullFlash();

        self::seo();
        View::make(Ecommerce::viewPath('pages/cart/index.php'), [
            'cart' => $cart,
            'csrf_token' => AuthSession::csrfToken(),
            'errors' => $flash['errors'],
            'notice' => $flash['notice'],
            'coupons' => Gestionale::feature('coupons'),
            'step' => 'cart',
        ])->render();
    }

    private static function add(): void
    {
        self::mutate(static function (int $cartId): array {
            return Cart::add($cartId, [
                'product_id' => (int) ($_POST['product_id'] ?? 0),
                'quantity' => self::number($_POST['quantity'] ?? 1),
            ]);
        }, (string) __t('ecommerce.cart.added'), true);
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

    private static function mutate(callable $mutation, string $notice, bool $createCart = false): never
    {
        self::requirePost();
        $wantsJson = self::wantsJson();
        self::requireCsrf();

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
            $cart = is_array($cart) ? $cart : CartSession::current(false);
            self::json(self::payload($cart, $errors === [], $errors === [] ? $notice : '', $errors));
        }

        self::flash($errors, $errors === [] ? $notice : '');

        self::redirect(SafeRedirect::fromRequest(
            $_POST['continue'] ?? '',
            self::route('ecommerce.cart.index')
        ));
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

    public static function flash(array $errors = [], string $notice = ''): void
    {
        $_SESSION['ecommerce_cart_flash'] = [
            'errors' => array_values(array_filter(array_map('strval', $errors))),
            'notice' => trim($notice),
        ];
    }

    /** @return array{errors: list<string>, notice: string} */
    private static function pullFlash(): array
    {
        $flash = (array) ($_SESSION['ecommerce_cart_flash'] ?? []);
        unset($_SESSION['ecommerce_cart_flash']);

        return [
            'errors' => array_values(array_filter(array_map('strval', (array) ($flash['errors'] ?? [])))),
            'notice' => trim((string) ($flash['notice'] ?? '')),
        ];
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
        return \__r($name, $parameters) ?: '/cart/';
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
