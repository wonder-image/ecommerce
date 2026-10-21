<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Cart;

use Wonder\Plugin\CustomerAccount\Account\CustomerContact;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;

/** Collega il cookie anonimo al carrello del gestionale e all'eventuale cliente. */
final class CartSession
{
    /** Il nome in http, che vale solo in locale. */
    public const COOKIE = 'ecommerce_cart';
    /** In https: il prefisso vieta Domain e chiede Secure, così un sottodominio non può fissare il carrello. */
    public const SECURE_COOKIE = '__Host-ecommerce_cart';
    private const COOKIE_TTL = 2592000;

    /** @return array{order: array<string, mixed>, items: list<array<string, mixed>>, removed: list<string>} */
    public static function current(bool $create = true): array
    {
        $token = self::token($create);
        $customerId = self::customerId();

        if ($customerId <= 0) {
            $guest = self::guest($token);

            if (!is_array($guest)) {
                if (!$create) {
                    return self::empty();
                }
                $cart = Cart::open(['cart_token' => $token, 'channel' => 'online']);
            } else {
                $cart = $guest;
            }

            return Cart::recalculate((int) $cart['id']);
        }

        $customerCart = self::customerCart($customerId);
        $guest = self::guest($token);

        if (is_array($guest)) {
            $target = is_array($customerCart)
                ? $customerCart
                : Cart::open(['customer_id' => $customerId, 'channel' => 'online']);

            return Cart::merge((int) $guest['id'], (int) $target['id']);
        }

        $cart = is_array($customerCart)
            ? $customerCart
            : ($create
                ? Cart::open(['customer_id' => $customerId, 'channel' => 'online'])
                : null);

        if (!is_array($cart)) {
            return self::empty();
        }

        return Cart::recalculate((int) $cart['id']);
    }

    /**
     * Quanti articoli ci sono nel carrello, senza aprirlo né ricalcolarlo:
     * serve a ogni pagina per il numerino sull'icona del nav.
     */
    public static function count(): string
    {
        $token = self::token(false);
        $customerId = self::customerId();
        $carts = array_filter([
            self::guest($token),
            $customerId > 0 ? self::customerCart($customerId) : null,
        ]);
        $items = [];

        foreach ($carts as $cart) {
            array_push($items, ...Cart::contents((int) $cart['id'])['items']);
        }

        return CartPresenter::count($items);
    }

    public static function user(): object
    {
        return \infoUser((int) ($_SESSION['user_id'] ?? 0), 'id');
    }

    public static function authenticated(): bool
    {
        $user = self::user();

        return ($user->exists ?? false)
            && in_array('frontend', (array) ($user->area ?? []), true)
            && in_array('client', (array) ($user->authority ?? []), true);
    }

    public static function customerId(): int
    {
        if (!self::authenticated()) {
            return 0;
        }

        // La scheda la legge (e se manca la collega) customer-account, una volta per richiesta.
        return (int) (CustomerContact::forUser((int) (self::user()->id ?? 0))['id'] ?? 0);
    }

    public static function token(bool $create = true): string
    {
        // In https si legge solo il cookie __Host-: quello semplice può arrivare da un sottodominio.
        $name = self::cookieName();
        $token = strtolower(trim((string) ($_COOKIE[$name] ?? '')));

        if (preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
            return $token;
        }

        if (!$create) {
            return '';
        }

        $token = bin2hex(random_bytes(32));
        $_COOKIE[$name] = $token;
        setcookie($name, $token, [
            'expires' => time() + self::COOKIE_TTL,
            'path' => '/',
            'secure' => self::secureCookie(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return $token;
    }

    /** @return array<string, mixed>|null */
    private static function guest(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $row = Order::find([
            'stage' => 'cart',
            'cart_token' => $token,
            'customer_id' => 0,
            'deleted' => 'false',
        ], 1);

        return is_array($row) && !empty($row['id']) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private static function customerCart(int $customerId): ?array
    {
        $row = Order::find([
            'stage' => 'cart',
            'customer_id' => $customerId,
            'deleted' => 'false',
        ], 1);

        return is_array($row) && !empty($row['id']) ? $row : null;
    }

    private static function cookieName(): string
    {
        return self::secureCookie() ? self::SECURE_COOKIE : self::COOKIE;
    }

    private static function secureCookie(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** @return array{order: array<string, mixed>, items: list<array<string, mixed>>, removed: list<string>} */
    private static function empty(): array
    {
        return ['order' => [], 'items' => [], 'removed' => []];
    }
}
