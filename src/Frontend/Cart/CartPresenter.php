<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Cart;

final class CartPresenter
{
    public static function money(mixed $value, string $currency = 'EUR'): string
    {
        $amount = number_format((float) $value, 2, ',', '.');

        return strtoupper($currency) === 'EUR' ? $amount.' €' : $amount.' '.strtoupper($currency);
    }

    public static function quantity(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, ',', ''), '0'), ',');
    }

    /** @param list<array<string, mixed>> $items */
    public static function count(array $items): string
    {
        $quantity = array_sum(array_map(
            static fn (array $item): float => (string) ($item['type'] ?? '') === 'product'
                ? (float) ($item['quantity'] ?? 0)
                : 0.0,
            $items
        ));

        return self::quantity($quantity);
    }
}
