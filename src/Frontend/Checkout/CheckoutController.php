<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use RuntimeException;
use Throwable;
use Wonder\App\Security\RecaptchaGuard;
use Wonder\Frontend\Support\FlashMessage;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthSession;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\View\View;

final class CheckoutController
{
    private const FORM_STATE = 'ecommerce_checkout_form_state';
    private const COMPLETED = 'ecommerce_checkout_completed';
    private const CART_KEYS = ['email', 'phone', 'fulfillment_type', 'shipping_method_id', 'location_id', 'payment_method_id', 'customer_note'];

    public static function handle(string $action): void
    {
        match ($action) {
            'index' => self::index(),
            'place' => self::place(),
            'summary' => self::summary(),
            'coupon' => self::coupon(),
            'completed' => self::completed(),
            default => self::notFound(),
        };
    }


    private static function index(): void
    {
        if (!self::guestAllowed() && !CartSession::authenticated()) {
            self::redirect(self::loginUrl());
        }

        $cart = CartSession::current(false);
        if ((array) ($cart['items'] ?? []) === []) {
            self::redirect(self::route('ecommerce.cart.index'));
        }

        $formState = self::pullFormState();
        $fromCart = array_filter(self::fromCart((array) ($cart['order'] ?? [])), static fn (string $v): bool => $v !== '' && $v !== '0');
        $values = $formState !== [] ? self::defaults($formState) : $fromCart + self::defaults([]);
        // Alla prima visita le scelte di consegna e pagamento restano quelle del carrello.
        $summary = self::initialSummary((int) ($cart['order']['id'] ?? 0), $values, $formState === []);
        // L'anteprima riscrive spedizione e commissione sul carrello: la pagina parte da quello aggiornato.
        $cart = CartSession::current(false);
        $user = CartSession::user();
        $billing = Order::billingAddress()->formSchema($values['billing_country'] ?? null);

        self::seo((string) __t('ecommerce.checkout.title'), self::route('ecommerce.checkout.index'));
        View::make(Ecommerce::viewPath('pages/checkout/index.php'), [
            'cart' => $cart,
            'summary' => $summary,
            'values' => $values,
            'shipping_fields' => self::fields(array_diff_key(
                Order::shippingAddress()->formSchema($values['shipping_country'] ?? null),
                array_flip(['shipping_name', 'shipping_surname', 'shipping_phone', 'shipping_phone_prefix'])
            ), $values),
            // Il cellulare si chiede una volta, nei contatti.
            'billing_fields' => self::fields(array_diff_key(
                $billing,
                array_flip(['billing_type', 'billing_business_name', 'billing_cf', 'billing_pi', 'billing_sdi', 'billing_pec', 'billing_phone_prefix', 'billing_phone'])
            ), $values),
            'invoice_fields' => self::fields(CheckoutFields::pick($billing, CheckoutFields::INVOICE), $values),
            'consents' => CheckoutRules::askedConsents((int) ($user->id ?? 0)),
            'express' => ExpressCheckout::buttons((array) ($cart['order'] ?? [])),
            'user_email' => CartSession::authenticated() ? trim((string) ($user->email ?? '')) : '',
            'guest' => !CartSession::authenticated(),
            'csrf_token' => AuthSession::csrfToken(),
        ])->render();
    }

    /**
     * La prima anteprima, già nella pagina: le scelte di consegna si vedono
     * anche senza JavaScript. Se il gestionale non risponde, la pagina resta
     * quella di prima e il JavaScript riprova al primo cambiamento.
     *
     * @param array<string, string> $values
     * @return array<string, mixed>|null
     */
    private static function initialSummary(int $cartId, array $values, bool $keepCart): ?array
    {
        try {
            return CheckoutSummary::payload($cartId, CheckoutRules::post($values, CartSession::user()), CartSession::user(), $keepCart);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.index');

            return null;
        }
    }


    private static function place(): void
    {
        self::requirePost();
        self::requireCsrf();

        if (!self::guestAllowed() && !CartSession::authenticated()) {
            self::redirect(self::loginUrl());
        }

        $post = CheckoutRules::post($_POST, CartSession::user());
        $user = CartSession::user();

        try {
            if (!CartSession::authenticated()) {
                RecaptchaGuard::for('ecommerce_checkout')
                    ->withService('ecommerce-checkout')
                    ->withLogAction('place')
                    ->verify();
            }

            $cartId = self::cartId();
            if ($cartId === 0) {
                throw new RuntimeException((string) __t('ecommerce.checkout.errors.empty'));
            }

            // L'anteprima scrive contatti e consegna sul carrello; poi si rilegge.
            $preview = CheckoutSummary::payload($cartId, $post, $user);
            $order = (array) Order::findById($cartId);
            $userId = (int) ($user->id ?? 0);
            $asked = CheckoutRules::askedConsents($userId);
            $billing = CheckoutRules::billing($post, $order);
            $missing = array_values(array_unique(array_merge(
                CheckoutRules::deliveryErrors($order, $preview, Gestionale::feature('shipping')),
                CheckoutRules::paymentErrors($post, $billing, $asked)
            )));
            if ($missing !== []) {
                self::rememberErrors(array_map(static fn (string $key): string => (string) __t('ecommerce.checkout.errors.'.$key), $missing), $post);
                self::redirect(self::route('ecommerce.checkout.index'));
            }

            // Vale solo un metodo che la pagina ha offerto.
            $method = CheckoutRules::method($preview, (int) ($post['payment_method_id'] ?? 0));
            if ($method === null) {
                throw new RuntimeException((string) __t('ecommerce.checkout.errors.payment_method'));
            }
            if (!CheckoutForm::isManual($method)) {
                throw new RuntimeException((string) __t('ecommerce.checkout.errors.provider_pending'));
            }

            $data = CheckoutForm::data([
                'payment_method_id' => (string) $method['id'],
                'customer_note' => (string) ($post['customer_note'] ?? ''),
            ] + $billing + self::fromCart($order), $user);

            // L'ospite ordina sempre su un account: si trova o nasce dalla sua email.
            $guest = !CartSession::authenticated();
            $customerId = CartSession::customerId();
            $passwordLink = '';
            if ($guest) {
                $account = GuestCheckout::account($order, $post, $asked);
                $userId = $account['user_id'];
                $customerId = $account['customer_id'];
                $passwordLink = GuestCheckout::passwordLink($userId, self::route('ecommerce.auth.password.restore'));
            }

            $result = Checkout::place($cartId, $data + [
                'customer_id' => $customerId,
                'source' => 'ecommerce',
                'user_id' => $userId,
                'customer_email' => $passwordLink === '' ? [] : ['account_url' => $passwordLink],
            ]);

            if (!$guest && $userId > 0 && $asked !== []) {
                try {
                    consentService()->registerBaseConsents($userId, $post, ['required_document_types' => $asked, 'ui_surface' => 'checkout']);
                } catch (Throwable $error) {
                    // L'ordine è nato: un consenso non registrato non lo ferma.
                    Errors::internal($error, 'ecommerce.checkout.consents');
                }
            }

            $_SESSION[self::COMPLETED] = [
                'order_id' => (int) ($result['order_id'] ?? 0),
                'order_number' => (string) ($result['order_number'] ?? ''),
                'total' => (string) ($result['total'] ?? ''),
                'status' => (string) ($result['status'] ?? 'pending'),
                'instructions' => (string) ($method['instructions'] ?? ''),
                'guest' => $guest,
                'email_sent' => $guest && ($result['customer_email_sent'] ?? false),
            ];
            self::redirect(self::route('ecommerce.checkout.completed'));
        } catch (UserError $error) {
            self::rememberErrors([$error->getMessage()], $post);
        } catch (RuntimeException $error) {
            self::rememberErrors([$error->getMessage()], $post);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.place');
            self::rememberErrors([(string) __t('ecommerce.checkout.errors.generic')], $post);
        }

        self::redirect(self::route('ecommerce.checkout.index'));
    }

    private static function summary(): never
    {
        $cartId = self::guardJson();

        try {
            self::json(['success' => true] + CheckoutSummary::payload($cartId, CheckoutRules::post($_POST, CartSession::user()), CartSession::user()));
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.summary');
            self::json(['success' => false, 'error' => (string) __t('ecommerce.checkout.summary_error')], 500);
        }
    }

    private static function coupon(): void
    {
        $return = ($_POST['return'] ?? '') === 'cart' ? 'cart' : 'checkout';
        $json = self::wantsJson();
        // Il coupon si prova già dal carrello, prima del login.
        $cartId = $json ? self::guardJson($return !== 'cart') : self::guardPage($return !== 'cart');
        $action = (string) ($_POST['action'] ?? 'apply') === 'remove' ? 'remove' : 'apply';
        $back = $return === 'cart' ? self::route('ecommerce.cart.index') : self::route('ecommerce.checkout.index');

        try {
            // Senza JavaScript il form del coupon non porta il resto del modulo: valgono le scelte del carrello.
            $payload = CheckoutSummary::coupon($cartId, $action, (string) ($_POST['code'] ?? ''), $_POST, CartSession::user(), !$json);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.coupon');

            if ($json) {
                self::json(['success' => false, 'error' => (string) __t('ecommerce.checkout.summary_error')], 500);
            }

            $payload = ['error' => (string) __t('ecommerce.checkout.errors.generic')];
        }

        if ($json) {
            self::json(['success' => $payload['error'] === ''] + $payload);
        }

        $errors = $payload['error'] !== '' ? [$payload['error']] : [];
        $notice = $errors === [] ? (string) __t('ecommerce.checkout.'.($action === 'remove' ? 'coupon_removed' : 'coupon_applied')) : '';
        $title = (string) __t('ecommerce.'.($return === 'cart' ? 'cart' : 'checkout').'.'.($errors === [] ? 'notice_title' : 'error_title'));
        if ($errors === []) {
            FlashMessage::success($notice, $title);
        } else {
            FlashMessage::error(implode("\n", $errors), $title);
        }
        self::redirect($back);
    }

    /** Le guardie delle rotte JSON: POST, CSRF, login; ridà l'id del carrello. */
    private static function guardJson(bool $login = true): int
    {
        self::requirePost();

        if (!AuthSession::verify($_POST['csrf_token'] ?? '')) {
            self::json(['success' => false, 'error' => (string) __t('ecommerce.checkout.summary_error')], 419);
        }

        if ($login && !self::guestAllowed() && !CartSession::authenticated()) {
            self::json(['success' => false, 'redirect' => self::loginUrl()], 401);
        }

        $cartId = self::cartId();

        return $cartId > 0
            ? $cartId
            : self::json(['success' => false, 'redirect' => self::route('ecommerce.cart.index')], 409);
    }

    /** Le stesse guardie per la pagina senza JavaScript. */
    private static function guardPage(bool $login = true): int
    {
        self::requirePost();
        self::requireCsrf();

        if ($login && !self::guestAllowed() && !CartSession::authenticated()) {
            self::redirect(self::loginUrl());
        }

        $cartId = self::cartId();

        return $cartId > 0 ? $cartId : self::redirect(self::route('ecommerce.cart.index'));
    }

    /** L'id del carrello del cliente, 0 se non c'è o è vuoto. */
    private static function cartId(): int
    {
        $cart = CartSession::current(false);

        return (array) ($cart['items'] ?? []) === [] ? 0 : (int) ($cart['order']['id'] ?? 0);
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

    private static function completed(): void
    {
        $result = (array) ($_SESSION[self::COMPLETED] ?? []);
        if (empty($result['order_id'])) {
            self::redirect(self::route('ecommerce.cart.index'));
        }

        self::seo((string) __t('ecommerce.checkout.completed.title'), self::route('ecommerce.checkout.completed'));
        View::make(Ecommerce::viewPath('pages/checkout/completed.php'), [
            'result' => $result,
        ])->render();
    }

    /** @return array<string, string> */
    private static function defaults(array $submitted): array
    {
        if ($submitted !== []) {
            return array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $submitted);
        }

        $user = CartSession::user();
        if (!CartSession::authenticated()) {
            return ['billing_country' => 'IT', 'shipping_country' => 'IT'];
        }

        $contactId = CartSession::customerId();
        $contact = Contact::findById($contactId);
        $contact = is_array($contact) ? $contact : [];
        $shipping = ContactAddress::find(['contact_id' => $contactId, 'is_default' => 'true', 'deleted' => 'false'], 1)
            ?: ContactAddress::find(['contact_id' => $contactId, 'deleted' => 'false'], 1);
        $shipping = is_array($shipping) ? $shipping : [];

        $values = ['email' => (string) ($user->email ?? $contact['email'] ?? '')];
        foreach (Order::billingAddress()->keys() as $key) {
            $source = substr($key, strlen('billing_'));
            $values[$key] = (string) ($contact[$source] ?? '');
        }
        foreach (Order::shippingAddress()->keys() as $key) {
            $source = substr($key, strlen('shipping_'));
            $values[$key] = (string) ($shipping[$source] ?? '');
        }
        $values['billing_country'] = $values['billing_country'] ?: 'IT';
        $values['shipping_country'] = $values['shipping_country'] ?: 'IT';

        // Il cellulare del contatto: il numero salvato porta il prefisso davanti.
        if ($values['shipping_phone'] === '') {
            $prefix = (string) ($contact['phone_prefix'] ?? '');
            $phone = (string) (($contact['phone'] ?? '') ?: ($user->phone ?? ''));
            $digits = (string) preg_replace('/\D+/', '', $phone);
            $prefixDigits = (string) preg_replace('/\D+/', '', $prefix);
            if ($prefixDigits !== '' && str_starts_with($digits, $prefixDigits)) {
                $phone = substr($digits, strlen($prefixDigits));
            }
            $values['shipping_phone_prefix'] = $prefix;
            $values['shipping_phone'] = $phone;
        }
        if ($values['shipping_phone_prefix'] === '' && function_exists('countryPhonePrefix')) {
            $values['shipping_phone_prefix'] = (string) countryPhonePrefix($values['shipping_country']);
        }

        return $values;
    }

    /**
     * I campi con valore e label della lingua: lo schema dell'indirizzo non
     * ne ha e il campo scriverebbe la sua chiave.
     *
     * @return array<string, object>
     */
    private static function fields(array $schema, array $values): array
    {
        $labels = Order::shippingAddress()->labels() + Order::billingAddress()->labels();

        foreach ($schema as $key => $field) {
            if (!is_object($field)) {
                continue;
            }
            if (isset($labels[$key]) && method_exists($field, 'label')) {
                $field->label((string) $labels[$key]);
            }
            if (CheckoutFields::required($key) && method_exists($field, 'required')) {
                $field->required();
            }
            if (method_exists($field, 'value') && array_key_exists($key, $values)) {
                $field->value((string) $values[$key]);
            }
        }

        return $schema;
    }

    private static function guestAllowed(): bool
    {
        return GuestCheckout::enabled();
    }

    private static function loginUrl(): string
    {
        return self::route('ecommerce.auth.login').'?continue='.rawurlencode(self::route('ecommerce.checkout.index'));
    }

    private static function rememberErrors(array $errors, array $values = []): void
    {
        unset(
            $values['csrf_token'],
            $values['g-recaptcha-token'],
            $values['g-recaptcha-action']
        );
        $errors = array_values(array_filter(array_map('strval', $errors)));
        if ($errors !== []) {
            FlashMessage::error(implode("\n", $errors), (string) __t('ecommerce.checkout.error_title'));
        }

        $_SESSION[self::FORM_STATE] = $values;
    }

    /** @return array<string, mixed> */
    private static function pullFormState(): array
    {
        $values = $_SESSION[self::FORM_STATE] ?? [];
        unset($_SESSION[self::FORM_STATE]);

        return is_array($values) ? $values : [];
    }

    private static function seo(string $title, string $url): void
    {
        global $SEO;

        $SEO->title = $title;
        $SEO->description = (string) __t('ecommerce.checkout.seo');
        $SEO->url = $url;
        $SEO->breadcrumb = [];
        $SEO->robots = 'NOINDEX,NOFOLLOW';
    }

    private static function requirePost(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            self::notFound();
        }
    }

    private static function requireCsrf(): void
    {
        if (!AuthSession::verify($_POST['csrf_token'] ?? '')) {
            http_response_code(419);
            exit('CSRF token invalid');
        }
    }

    private static function route(string $name): string
    {
        return \__r($name) ?: '/';
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

    /**
     * I valori dei moduli presi dal carrello: contatto, scelte, nota e indirizzi.
     *
     * @param array<string, mixed> $order
     * @return array<string, string>
     */
    private static function fromCart(array $order): array
    {
        $values = [];
        foreach (array_merge(self::CART_KEYS, Order::shippingAddress()->keys(), Order::billingAddress()->keys()) as $key) {
            $values[$key] = trim((string) ($order[$key] ?? ''));
        }

        return $values;
    }
}
