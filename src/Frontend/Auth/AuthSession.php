<?php
namespace Wonder\Plugin\Ecommerce\Frontend\Auth;
final class AuthSession
{
    public static function csrfToken(): string { return \Wonder\Auth\Frontend\AuthSession::csrfToken(); }
    public static function verify(mixed $token): bool { return \Wonder\Auth\Frontend\AuthSession::verify($token); }
}
