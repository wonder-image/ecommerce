<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

/**
 * L'indirizzo di una scheda: `/prodotto/{modello}/`, la variante sotto il
 * modello, e la query che sceglie l'opzione. È l'unico posto che lo
 * costruisce. Usa le route con nome; senza il sito avviato ricade sui
 * percorsi fissi.
 */
final class ProductUrl
{
    /** @param array<string, mixed> $query */
    public static function make(string $modelSlug, string $variantSlug = '', array $query = []): string
    {
        $modelSlug = trim($modelSlug);
        $variantSlug = trim($variantSlug);

        if ($variantSlug === '') {
            $path = self::route('ecommerce.catalog.product', ['slug' => $modelSlug]);
            if ($path === '') {
                $path = '/prodotto/'.rawurlencode($modelSlug).'/';
            }
        } else {
            $path = self::route('ecommerce.catalog.product.variant', ['slug' => $modelSlug, 'variante' => $variantSlug]);
            if ($path === '') {
                $path = '/prodotto/'.rawurlencode($modelSlug).'/'.rawurlencode($variantSlug).'/';
            }
        }

        $query = self::query($query);

        return $query === [] ? $path : $path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** Lo slug da mettere nell'indirizzo: nessuno se la variante è l'unica visibile. */
    public static function variantSlugFor(array $variant, int $visibleVariants): string
    {
        if ($visibleVariants < 2) {
            return '';
        }

        return trim((string) ($variant['slug'] ?? ''));
    }

    /**
     * I valori semplici e non vuoti; di una lista (`?taglia[]=s`) conta il primo.
     *
     * @param array<mixed> $query
     * @return array<string, string>
     */
    public static function query(array $query): array
    {
        $clean = [];

        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $value = reset($value);
            }

            if (!is_string($key) || $key === '' || !is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /** @param array<string, string> $parameters */
    private static function route(string $name, array $parameters): string
    {
        if (!function_exists('__r')) {
            return '';
        }

        return (string) __r($name, $parameters);
    }
}
