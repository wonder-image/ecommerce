<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Throwable;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;

/**
 * Il bottone di una selezione (`cta_*` in `block_meta`): dove porta.
 *
 * - `all`: tutti i prodotti;
 * - `custom`: il link scritto, solo `/…`, `http(s)://`, `mailto:` e `tel:`;
 * - `category`, `tag`, `brand`, `product`: la pagina della voce scelta, se
 *   il sito la mostra.
 *
 * Senza testo, o con una voce che non c'è più, niente bottone.
 */
final class SelectionLink
{
    /**
     * @param array<string, string> $meta i campi in più della selezione
     * @param (callable(string, string): ?array)|null $find la riga visibile di una voce, per tipo e slug: per i test
     * @return array{label:string,url:string}|null
     */
    public static function from(array $meta, ?callable $find = null): ?array
    {
        $label = trim((string) ($meta['cta_label'] ?? ''));
        if ($label === '') return null;

        $url = self::url($meta, $find ?? self::find(...));

        return $url !== '' ? ['label' => $label, 'url' => $url] : null;
    }

    /** @param callable(string, string): ?array $find */
    private static function url(array $meta, callable $find): string
    {
        $link = (string) ($meta['cta_link'] ?? 'all');
        $slug = trim((string) ($meta['cta_'.$link] ?? ''));

        switch ($link) {
            case 'all':
                return self::route('ecommerce.catalog.index', [], (string) Ecommerce::config('catalog.index_url', '/prodotti/'));

            case 'custom':
                $url = trim((string) ($meta['cta_url'] ?? ''));
                return preg_match('~^(/(?!/)|https?://|mailto:|tel:)~i', $url) ? $url : '';

            case 'category':
                $path = $slug !== '' ? self::categoryPath($slug, $find) : [];
                if ($path === []) return '';
                $parameters = [];
                foreach ($path as $i => $part) {
                    $parameters['category_'.($i + 1)] = $part;
                }
                return self::route('ecommerce.catalog.category.'.count($path), $parameters, '/prodotti/'.implode('/', array_map('rawurlencode', $path)).'/');

            case 'tag':
                if ($slug === '' || $find('tag', $slug) === null) return '';
                // Il tag della collezione ha la sua pagina.
                return $slug === (string) Ecommerce::config('catalog.collection_tag', 'collezione')
                    ? self::route('ecommerce.catalog.collection', [], '/collezione/')
                    : self::route('ecommerce.catalog.tag', ['tag' => $slug], '/tag/'.rawurlencode($slug).'/');

            case 'brand':
                if ($slug === '' || $find('brand', $slug) === null) return '';
                return self::route('ecommerce.catalog.brand', ['marca' => $slug], '/marchi/'.rawurlencode($slug).'/');

            case 'product':
                if ($slug === '' || $find('product', $slug) === null) return '';
                return ProductUrl::make($slug);
        }

        return '';
    }

    /**
     * Gli slug dalla radice alla categoria; vuoto se lei o un antenato è
     * nascosto, o se l'albero è più profondo delle rotte.
     *
     * @param callable(string, string): ?array $find
     * @return list<string>
     */
    private static function categoryPath(string $slug, callable $find): array
    {
        $maxDepth = max(1, min(12, (int) Ecommerce::config('catalog.category_max_depth', 8)));
        $row = $find('category', $slug);
        $path = [];

        while (is_array($row)) {
            array_unshift($path, (string) $row['slug']);
            if (count($path) > $maxDepth) return [];

            $parent = (int) ($row['parent_id'] ?? 0);
            if ($parent <= 0) return $path;

            $row = $find('category#', (string) $parent);
        }

        return [];
    }

    /** La riga visibile; `category#` cerca la categoria per id. */
    private static function find(string $type, string $key): ?array
    {
        $visible = ['visible' => 'true', 'deleted' => 'false'];

        try {
            $row = match ($type) {
                'category' => Category::find(['slug' => $key] + $visible, 1),
                'category#' => Category::find(['id' => (int) $key] + $visible, 1),
                'tag' => Tag::find(['slug' => $key] + $visible, 1),
                'brand' => Brand::find(['slug' => $key] + $visible, 1),
                'product' => ProductModel::find(['slug' => $key, 'visible_online' => 'true'] + $visible, 1),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        return is_array($row) && (int) ($row['id'] ?? 0) > 0 ? $row : null;
    }

    /** @param array<string, string> $parameters */
    private static function route(string $name, array $parameters, string $fallback): string
    {
        $url = function_exists('__r') ? (string) __r($name, $parameters) : '';

        return $url !== '' ? $url : $fallback;
    }
}
