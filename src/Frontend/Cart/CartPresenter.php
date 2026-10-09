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
        return array_values(array_map(
            static fn (array $item): array => ['name' => self::name((string) ($item['name'] ?? ''))] + $item,
            array_filter(
                $items,
                static fn (array $item): bool => !in_array((string) ($item['type'] ?? 'product'), ['shipping', 'fee'], true)
            )
        ));
    }

    /**
     * Il nome per il cliente, senza i separatori dell'ordine: "Maglia — Blu / M"
     * diventa "Maglia Blu M". Una barra dentro una parola ("24/7") resta.
     */
    public static function name(string $name): string
    {
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('~\s+[—–/|]\s+~u', ' ', $name));
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
