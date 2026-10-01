<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Cart;

use Wonder\Plugin\Ecommerce\Frontend\Client\CustomerAccount;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;

/** Collega il cookie anonimo al carrello del gestionale e all'eventuale cliente. */
final class CartSession
{
    public const COOKIE = 'ecommerce_cart';
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

        $userId = (int) (self::user()->id ?? 0);
        $contact = CustomerAccount::info($userId);

        if (!($contact->exists ?? false)) {
            CustomerAccount::linkContact($userId);
            $contact = CustomerAccount::info($userId);
        }

        return (int) ($contact->id ?? 0);
    }

    public static function token(bool $create = true): string
    {
        $token = strtolower(trim((string) ($_COOKIE[self::COOKIE] ?? '')));

        if (preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
            return $token;
        }

        if (!$create) {
            return '';
        }

        $token = bin2hex(random_bytes(32));
        $_COOKIE[self::COOKIE] = $token;
        setcookie(self::COOKIE, $token, [
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
