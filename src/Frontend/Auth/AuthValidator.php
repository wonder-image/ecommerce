<?php
namespace Wonder\Plugin\Ecommerce\Frontend\Auth;
final class AuthValidator
{
    public static function signupRequest(array $input): array
    {
        return \Wonder\Auth\Frontend\AuthValidator::signupRequest($input);
    }
    public static function completion(array $input, bool $passwordRequired = true): array
    {
        return \Wonder\Auth\Frontend\AuthValidator::completion($input, $passwordRequired);
    }
    public static function canonicalPhone(array $input): string
    {
        return \Wonder\Auth\Frontend\AuthValidator::canonicalPhone($input);
    }
}
