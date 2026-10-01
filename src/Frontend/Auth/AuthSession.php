<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Auth;

final class AuthSession
{
    private const KEY = 'ecommerce_auth_csrf';

    public static function csrfToken(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::KEY];
    }

    public static function verify(mixed $token): bool
    {
        $stored = (string) ($_SESSION[self::KEY] ?? '');
        $token = (string) $token;

        return $stored !== '' && $token !== '' && hash_equals($stored, $token);
    }
}
