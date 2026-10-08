<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

final class ProductHistory
{
    public const COOKIE = 'ecommerce_recent_products';

    /** @return list<int> */
    public static function read(): array
    {
        $decoded = json_decode((string) ($_COOKIE[self::COOKIE] ?? '[]'), true);
        return is_array($decoded)
            ? array_values(array_unique(array_filter(array_map('intval', $decoded))))
            : [];
    }

    /** @param list<int> $ids */
    public static function remember(int $modelId, array $ids = []): void
    {
        $ids = array_slice(array_values(array_unique([$modelId, ...$ids])), 0, 24);
        setcookie(self::COOKIE, json_encode($ids) ?: '[]', [
            'expires' => time() + 60 * 60 * 24 * 30,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
