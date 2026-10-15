<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Discovery;

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\CatalogFilter;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductListing;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;

/**
 * Catalogo pubblico in forma di dati, per motori e assistenti AI: gli stessi
 * prodotti e gli stessi prezzi delle card del catalogo, niente di più.
 */
final class CatalogFeed
{
    public const PATH = '/shared/catalogo.json';
    public const PER_PAGE = 50;
    public const MAX_PER_PAGE = 200;

    /** @return array<string, mixed> */
    public static function page(int $page, int $perPage = self::PER_PAGE): array
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $total = self::total();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $currency = self::currency();
        $base = self::site().self::PATH;

        $products = array_map(
            static fn (array $card): array => self::product($card, $currency),
            ProductListing::cards(self::filter(), (($page - 1) * $perPage).', '.$perPage)
        );

        return [
            'name' => self::siteName(),
            'url' => self::site().'/',
            'currency' => $currency,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'next' => $page < $pages ? $base.'?page='.($page + 1) : null,
            'products' => $products,
        ];
    }

    /**
     * Tutti i prodotti, a blocchi, fino a un massimo.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function all(int $limit = 50000): \Generator
    {
        $currency = self::currency();
        $filter = self::filter();
        $count = 0;
        for ($offset = 0; $count < $limit; $offset += self::MAX_PER_PAGE) {
            $cards = ProductListing::cards($filter, $offset.', '.self::MAX_PER_PAGE);
            foreach ($cards as $card) {
                if ($count++ >= $limit) {
                    return;
                }
                yield self::product($card, $currency);
            }
            if (count($cards) < self::MAX_PER_PAGE) {
                return;
            }
        }
    }

    public static function total(): int
    {
        return (int) sqlCount(ProductListing::paginationTable(), ProductListing::paginationWhere(self::filter()));
    }

    /**
     * Categorie visibili con il loro URL pubblico, genitori prima dei figli.
     *
     * @return list<array{id: int, name: string, url: string, depth: int, description: string}>
     */
    public static function categories(): array
    {
        $rows = Category::find(['visible' => 'true', 'deleted' => 'false'], null, 'position', 'ASC');
        $rows = !is_array($rows) || $rows === [] ? [] : (array_is_list($rows) ? array_values(array_filter($rows, 'is_array')) : [$rows]);

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $base = rtrim((string) Ecommerce::config('catalog.category_url', '/prodotti/'), '/');
        $maxDepth = max(1, min(12, (int) Ecommerce::config('catalog.category_max_depth', 8)));
        $out = [];
        foreach ($byId as $id => $row) {
            $path = [];
            $cursor = $row;
            while (is_array($cursor) && count($path) <= $maxDepth) {
                array_unshift($path, rawurlencode((string) $cursor['slug']));
                $parent = (int) ($cursor['parent_id'] ?? 0);
                $cursor = $parent > 0 ? ($byId[$parent] ?? false) : null;
            }
            // Un antenato nascosto rende irraggiungibile la categoria.
            if ($cursor === false || count($path) > $maxDepth) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => trim((string) $row['name']),
                'url' => self::site().$base.'/'.implode('/', $path).'/',
                'depth' => count($path),
                'description' => trim(strip_tags((string) ($row['description'] ?? ''))),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['url'], $b['url']));

        return $out;
    }

    /**
     * @param array<string, mixed> $card
     * @return array<string, mixed>
     */
    public static function product(array $card, string $currency): array
    {
        $regular = round((float) ($card['price'] ?? 0), 2);
        $sale = round((float) ($card['sale_price'] ?? 0), 2);
        $onSale = $sale > 0 && $sale < $regular;

        $product = [
            'id' => (string) ($card['meta']['item_id'] ?? $card['id'] ?? ''),
            'name' => trim((string) ($card['name'] ?? '')),
            'url' => (string) ($card['url'] ?? ''),
            'description' => trim(strip_tags((string) ($card['short_description'] ?? ''))),
            'image' => self::absolute((string) ($card['image'] ?? '')),
            'price' => $onSale ? $sale : $regular,
            'currency' => $currency,
        ];
        if ($onSale) {
            $product['regular_price'] = $regular;
        }
        $variants = [];
        foreach ((array) ($card['variants'] ?? []) as $variant) {
            if (is_array($variant) && ($variant['url'] ?? '') !== '') {
                $variants[] = ['name' => (string) ($variant['name'] ?? ''), 'url' => (string) $variant['url']];
            }
        }
        if (count($variants) > 1) {
            $product['variants'] = $variants;
        }

        return $product;
    }

    public static function site(): string
    {
        return rtrim((string) ($GLOBALS['PATH']->site ?? (defined('APP_URL') ? APP_URL : '')), '/');
    }

    public static function siteName(): string
    {
        return trim((string) ($GLOBALS['SOCIETY']->name ?? ''));
    }

    public static function currency(): string
    {
        return (string) Ecommerce::config('catalog.currency', 'EUR');
    }

    private static function filter(): CatalogFilter
    {
        return CatalogFilter::fromRequest('index', [], []);
    }

    private static function absolute(string $url): string
    {
        if ($url === '' || preg_match('~^https?://~i', $url)) {
            return $url;
        }

        return self::site().'/'.ltrim($url, '/');
    }
}
