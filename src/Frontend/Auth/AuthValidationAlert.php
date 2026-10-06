<?php
namespace Wonder\Plugin\Ecommerce\Frontend\Auth;
use Wonder\Elements\Components\Alert;
final class AuthValidationAlert
{
    public static function messageKeys(array $errors): array
    {
        return array_map(static fn (string $key): string => 'ecommerce.'.$key, \Wonder\Auth\Frontend\AuthValidationAlert::messageKeys($errors));
    }
    public static function make(array $errors = [], mixed $alert = null, ?string $federatedError = null): ?Alert
    {
        return \Wonder\Auth\Frontend\AuthValidationAlert::make($errors, $alert, $federatedError);
    }
}
