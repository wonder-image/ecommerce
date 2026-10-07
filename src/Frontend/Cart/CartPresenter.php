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

    /**
     * Le righe da mostrare come articoli: spedizione e commissione le scrive
     * l'anteprima del checkout e stanno solo nei totali.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function lines(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (array $item): bool => !in_array((string) ($item['type'] ?? 'product'), ['shipping', 'fee'], true)
        ));
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
