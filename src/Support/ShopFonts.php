<?php

namespace Wonder\Plugin\Ecommerce\Support;

use Throwable;

/**
 * I font fra cui sceglie il negozio: le righe visibili di `css_font`, che la
 * testa del sito carica già tutte. Si nominano per `name`.
 */
final class ShopFonts
{
    /** @return list<array<string, mixed>> le righe; vuoto senza database */
    public static function visible(): array
    {
        if (!function_exists('sqlSelect')) {
            return [];
        }

        try {
            $rows = (array) (sqlSelect('css_font', ['visible' => 'true'])->row ?? []);
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(
            $rows,
            static fn (mixed $row): bool => is_array($row) && isset($row['id'])
        ));
    }

    /**
     * La riga col nome dato, senza badare a spazi e maiuscole.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    public static function find(string $name, array $rows): ?array
    {
        $name = strtolower(trim($name));

        if ($name === '') {
            return null;
        }

        foreach ($rows as $row) {
            if (strtolower(trim((string) ($row['name'] ?? ''))) === $name) {
                return $row;
            }
        }

        return null;
    }
}
