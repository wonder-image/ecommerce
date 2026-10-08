<?php

namespace Wonder\Plugin\Ecommerce\Support;

use Throwable;

/**
 * I font fra cui sceglie il negozio: le righe visibili di `css_font`, che la
 * testa del sito carica già tutte. Si scelgono per `id`.
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
            $rows = (array) (sqlSelect('css_font', ['visible' => 'true', 'deleted' => 'false'])->row ?? []);
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(
            $rows,
            static fn (mixed $row): bool => is_array($row) && isset($row['id'])
        ));
    }

    /**
     * La riga con l'id dato, anche scritto come testo.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    public static function find(int|string|null $id, array $rows): ?array
    {
        $id = trim((string) $id);

        if (!ctype_digit($id) || (int) $id <= 0) {
            return null;
        }

        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === (int) $id) {
                return $row;
            }
        }

        return null;
    }
}
