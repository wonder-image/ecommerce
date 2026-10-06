<?php
namespace Wonder\Plugin\Ecommerce\Support;
final class SafeRedirect
{
    public static function fromRequest(mixed $value, string $fallback = '/'): string
    {
        return \Wonder\Auth\Frontend\SafeRedirect::fromRequest($value, $fallback);
    }
}
