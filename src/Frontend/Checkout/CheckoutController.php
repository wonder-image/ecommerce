<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use RuntimeException;
use Throwable;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\Security\RecaptchaGuard;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthSession;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\View\View;

final class CheckoutController
{
    private const FLASH = 'ecommerce_checkout_flash';
    private const COMPLETED = 'ecommerce_checkout_completed';

    public static function handle(string $action): void
    {
        match ($action) {
            'index' => self::index(),
            'place' => self::place(),
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

        $flash = self::pullFlash();
        $values = self::defaults($flash['values']);
        $methods = self::paymentMethods();

        self::seo((string) __t('ecommerce.checkout.title'), self::route('ecommerce.checkout.index'));
        View::make(Ecommerce::viewPath('pages/checkout/index.php'), [
            'cart' => $cart,
            'billing_fields' => self::fields(Order::billingAddress()->formSchema($values['billing_country'] ?? null), $values),
            'shipping_fields' => self::fields(Order::shippingAddress()->formSchema($values['shipping_country'] ?? null), $values),
            'payment_field' => self::paymentField($methods, $values),
            'payment_methods' => $methods,
            'guest' => !CartSession::authenticated(),
            'csrf_token' => AuthSession::csrfToken(),
            'errors' => $flash['errors'],
            'notice' => '',
            'values' => $values,
        ])->render();
    }

    private static function place(): void
    {
        self::requirePost();
        self::requireCsrf();

        if (!self::guestAllowed() && !CartSession::authenticated()) {
            self::redirect(self::loginUrl());
        }

        try {
            if (!CartSession::authenticated()) {
                RecaptchaGuard::for('ecommerce_checkout')
                    ->withService('ecommerce-checkout')
                    ->withLogAction('place')
                    ->verify();
            }

            $cart = CartSession::current(false);
            if ((array) ($cart['items'] ?? []) === []) {
                throw new RuntimeException((string) __t('ecommerce.checkout.errors.empty'));
            }

            $methodId = (int) ($_POST['payment_method_id'] ?? 0);
            $method = self::method($methodId);
            if (!is_array($method)) {
                throw new RuntimeException((string) __t('ecommerce.checkout.errors.payment_method'));
            }
            if ((string) ($method['provider'] ?? 'manual') !== 'manual') {
                throw new RuntimeException((string) __t('ecommerce.checkout.errors.provider_pending'));
            }

            $user = CartSession::user();
            $result = Checkout::place((int) ($cart['order']['id'] ?? 0), [
                'email' => trim((string) ($_POST['email'] ?? ($user->email ?? ''))),
                'phone' => trim((string) ($_POST['phone'] ?? ($user->phone ?? ''))),
                'customer_id' => CartSession::customerId(),
                'payment_method_id' => $methodId,
                'shipping_method_id' => 0,
                'location_id' => 0,
                'fulfillment_type' => 'shipping',
                'customer_note' => trim((string) ($_POST['customer_note'] ?? '')),
                'billing' => self::address($_POST, 'billing_'),
                'shipping' => self::address($_POST, 'shipping_'),
                'source' => 'ecommerce',
                'user_id' => (int) ($user->id ?? 0),
            ]);

            $_SESSION[self::COMPLETED] = [
                'order_id' => (int) ($result['order_id'] ?? 0),
                'order_number' => (string) ($result['order_number'] ?? ''),
                'total' => (string) ($result['total'] ?? ''),
                'status' => (string) ($result['status'] ?? 'pending'),
                'instructions' => (string) ($method['instructions'] ?? ''),
            ];
            self::redirect(self::route('ecommerce.checkout.completed'));
        } catch (UserError $error) {
            self::flash([$error->getMessage()], $_POST);
        } catch (RuntimeException $error) {
            self::flash([$error->getMessage()], $_POST);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.place');
            self::flash([(string) __t('ecommerce.checkout.errors.generic')], $_POST);
        }

        self::redirect(self::route('ecommerce.checkout.index'));
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
            'errors' => [],
            'notice' => '',
        ])->render();
    }

    /** @return list<array<string, mixed>> */
    private static function paymentMethods(): array
    {
        $rows = PaymentMethod::find([
            'active' => 'true',
            'applies_online' => 'true',
            'deleted' => 'false',
        ], null, 'position', 'ASC');

        return is_array($rows)
            ? array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row)
                && in_array((string) ($row['available_for'] ?? 'all'), ['all', 'shipping'], true)))
            : [];
    }

    /** @param list<array<string, mixed>> $methods */
    private static function paymentField(array $methods, array $values): object
    {
        $options = [];
        foreach ($methods as $method) {
            $options[(int) $method['id']] = (string) ($method['name'] ?? '');
        }

        $field = FormField::key('payment_method_id')
            ->select($options)
            ->label((string) __t('ecommerce.checkout.payment_method'))
            ->required();
        if (!empty($values['payment_method_id'])) {
            $field->value((string) $values['payment_method_id']);
        }

        return $field;
    }

    /** @return array<string, mixed>|null */
    private static function method(int $id): ?array
    {
        foreach (self::paymentMethods() as $method) {
            if ((int) ($method['id'] ?? 0) === $id) {
                return $method;
            }
        }

        return null;
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

        $values = [
            'email' => (string) ($user->email ?? $contact['email'] ?? ''),
            'phone' => (string) ($user->phone ?? $contact['phone'] ?? ''),
        ];
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

        return $values;
    }

    /** @return list<object> */
    private static function fields(array $schema, array $values): array
    {
        foreach ($schema as $key => $field) {
            if (is_object($field) && method_exists($field, 'value') && array_key_exists($key, $values)) {
                $field->value((string) $values[$key]);
            }
        }

        return array_values($schema);
    }

    /** @return array<string, string> */
    private static function address(array $input, string $prefix): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            if (is_string($key) && str_starts_with($key, $prefix) && is_scalar($value)) {
                $result[substr($key, strlen($prefix))] = trim((string) $value);
            }
        }

        return $result;
    }

    private static function guestAllowed(): bool
    {
        return Ecommerce::config('checkout.guest_enabled', false) === true;
    }

    private static function loginUrl(): string
    {
        return self::route('ecommerce.auth.login').'?continue='.rawurlencode(self::route('ecommerce.checkout.index'));
    }

    private static function flash(array $errors, array $values = []): void
    {
        unset(
            $values['csrf_token'],
            $values['g-recaptcha-token'],
            $values['g-recaptcha-action']
        );
        $_SESSION[self::FLASH] = [
            'errors' => array_values(array_filter(array_map('strval', $errors))),
            'values' => $values,
        ];
    }

    /** @return array{errors: list<string>, values: array<string, mixed>} */
    private static function pullFlash(): array
    {
        $flash = (array) ($_SESSION[self::FLASH] ?? []);
        unset($_SESSION[self::FLASH]);

        return [
            'errors' => array_values(array_filter(array_map('strval', (array) ($flash['errors'] ?? [])))),
            'values' => is_array($flash['values'] ?? null) ? $flash['values'] : [],
        ];
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
}
