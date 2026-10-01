<?php

namespace Wonder\Plugin\Ecommerce\Support;

final class SafeRedirect
{
    public static function fromRequest(mixed $value, string $fallback = '/'): string
    {
        $value = trim(str_replace(["\r", "\n"], '', (string) $value));

        if ($value === '' || str_starts_with($value, '//')) {
            return $fallback;
        }

        if (str_starts_with($value, '/')) {
            return $value;
        }

        $appUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        $targetHost = parse_url($value, PHP_URL_HOST);
        $appHost = parse_url($appUrl, PHP_URL_HOST);

        return $targetHost !== null && $appHost !== null && strcasecmp($targetHost, $appHost) === 0
            ? $value
            : $fallback;
    }
}
