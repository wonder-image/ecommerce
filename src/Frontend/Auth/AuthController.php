<?php
namespace Wonder\Plugin\Ecommerce\Frontend\Auth;
/** Compatibility facade; authentication flows live in the framework. */
final class AuthController
{
    public static function handle(string $action, array $parameters = []): void
    {
        (new \Wonder\Auth\Frontend\AuthController(new EcommerceAuthProfile()))->handle($action, $parameters);
    }
}
