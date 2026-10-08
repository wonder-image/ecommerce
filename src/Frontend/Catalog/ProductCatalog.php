<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;

/** Legge una scheda pubblica dalle tabelle catalogo del gestionale. */
final class ProductCatalog
{
    /**
     * La scheda di `/prodotto/{modello}/` o `/prodotto/{modello}/{variante}/`,
     * oppure dove rimandare. Tutti e due null: il modello non c'è, è un 404.
     *
     * @param array<mixed> $query la query della richiesta: sceglie l'opzione
     * @return array{detail: ?ProductDetail, redirect: ?string}
     */
    public static function resolve(string $modelSlug, string $variantSlug = '', array $query = []): array
    {
        $none = ['detail' => null, 'redirect' => null];
        $modelSlug = trim($modelSlug);
        $variantSlug = trim($variantSlug);

        if ($modelSlug === '') {
            return $none;
        }

        $model = ProductModel::find([
            'slug' => $modelSlug,
            'visible' => 'true',
            'visible_online' => 'true',
            'deleted' => 'false',
        ], 1);

        if (!is_array($model) || (int) ($model['id'] ?? 0) <= 0) {
            return $none;
        }

        $variants = self::rows(ProductVariant::find(
            ['product_model_id' => (int) $model['id'], 'visible' => 'true', 'deleted' => 'false'],
            null,
            'position',
            'ASC'
        ));
        $variant = $variants[0] ?? null;

        if ($variantSlug !== '') {
            $variant = null;

            foreach ($variants as $row) {
                if ((string) ($row['slug'] ?? '') === $variantSlug) {
                    $variant = $row;
                    break;
                }
            }

            // Variante sconosciuta o nascosta, o modello con una sola
            // variante: l'indirizzo giusto è quello del modello.
            if ($variant === null || count($variants) < 2) {
                return ['detail' => null, 'redirect' => ProductUrl::make($modelSlug, '', $query)];
            }
        }

        if (!is_array($variant) || (int) ($variant['id'] ?? 0) <= 0) {
            return $none;
        }

        return ['detail' => self::build($model, $variant, $variants), 'redirect' => null];
    }

    /** Solo la scheda del modello, senza rimandi. */
    public static function find(string $slug): ?ProductDetail
    {
        return self::resolve($slug)['detail'];
    }

    /** @param list<array<string, mixed>> $variants le varianti visibili, per posizione */
    private static function build(array $model, array $variant, array $variants): ?ProductDetail
    {
        $modelId = (int) $model['id'];
        $variantId = (int) $variant['id'];
        $visible = count($variants);
        $modelSlug = (string) ($model['slug'] ?? '');
        $products = self::rows(Product::find([
            'product_model_id' => $modelId,
            'product_variant_id' => $variantId,
            'active' => 'true',
        ], null, 'position', 'ASC'));

        if ($products === []) {
            return null;
        }

        $allImages = self::rows(ProductImage::find(
            ['product_model_id' => $modelId],
            null,
            'position',
            'ASC'
        ));
        $images = [];

        foreach (ProductImages::for($allImages, $variantId) as $image) {
            $url = ProductImages::url($image);
            if ($url !== '') {
                $images[] = [
                    'url' => self::absolute($url),
                    'alt' => trim((string) ($image['alt'] ?? '')),
                ];
            }
        }

        $brand = (int) ($model['brand_id'] ?? 0) > 0
            ? Brand::find(['id' => (int) $model['brand_id'], 'visible' => 'true'], 1)
            : null;
        $categories = self::categories($modelId);
        $mainCategory = $categories !== [] ? $categories[array_key_last($categories)] : [];
        $variantName = trim((string) ($variant['name'] ?? ''));
        $modelName = trim((string) ($model['name'] ?? ''));
        $name = self::productName($modelName, $variantName, count($variants));
        $url = self::absolute(ProductUrl::make($modelSlug, ProductUrl::variantSlugFor($variant, $visible)));
        $offers = [];

        foreach ($products as $product) {
            $quantity = 0.0;
            foreach (self::rows(StockRow::find(['product_id' => (int) $product['id']])) as $stock) {
                $quantity += (float) ($stock['quantity'] ?? 0);
            }

            $sku = trim((string) ($product['sku'] ?? ''));
            $offers[] = [
                'product_id' => (int) $product['id'],
                'item_id' => (string) $product['id'],
                'name' => trim((string) ($product['name'] ?? '')),
                'sku' => $sku,
                'gtin' => trim((string) ($product['ean'] ?? '')),
                'mpn' => trim((string) ($product['mpn'] ?? '')),
                'regular_price' => (float) ($product['price'] ?? 0),
                'sale_price' => (float) ($product['sale_price'] ?? 0),
                'available' => $quantity > 0 || Stock::allowsBackorder($product),
            ];
        }

        $variantCards = [];
        foreach ($variants as $row) {
            $variantCards[] = [
                'name' => self::productName($modelName, (string) ($row['name'] ?? ''), count($variants)),
                'url' => ProductUrl::make($modelSlug, ProductUrl::variantSlugFor($row, $visible)),
                'active' => (int) ($row['id'] ?? 0) === $variantId,
            ];
        }

        $breadcrumbs = [[
            'url' => self::absolute('/'),
            'name' => (string) __t('ecommerce.catalog.breadcrumb_home'),
        ], [
            'url' => self::absolute((string) Ecommerce::config('catalog.index_url', '/prodotti/')),
            'name' => (string) __t('ecommerce.catalog.breadcrumb_products'),
        ]];

        $categoryPath = [];
        foreach ($categories as $category) {
            $categoryPath[] = (string) ($category['slug'] ?? '');
            $breadcrumbs[] = [
                'url' => self::absolute(self::categoryUrl($categoryPath)),
                'name' => self::breadcrumbName((string) ($category['name'] ?? '')),
            ];
        }

        $breadcrumbs[] = ['url' => $url, 'name' => self::breadcrumbName($name)];

        return ProductDetail::make([
            'id' => $modelId,
            'name' => $name,
            'slug' => ProductUrl::variantSlugFor($variant, $visible) ?: $modelSlug,
            'url' => $url,
            'short_description' => (string) ($model['short_description'] ?? ''),
            'description_html' => (string) ($model['description'] ?? ''),
            'brand' => is_array($brand) ? (string) ($brand['name'] ?? '') : '',
            'category' => (string) ($mainCategory['name'] ?? ''),
            'category_url' => $categoryPath !== []
                ? self::categoryUrl($categoryPath)
                : '',
            'variant' => count($variants) > 1 ? $variantName : '',
            'variants' => $variantCards,
            'breadcrumbs' => $breadcrumbs,
            'images' => $images,
            'offers' => $offers,
            'currency' => (string) Ecommerce::config('catalog.currency', 'EUR'),
            'details' => self::details($model),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function categories(int $modelId): array
    {
        $links = self::rows(ProductModelCategory::find(
            ['product_model_id' => $modelId],
            null,
            'position',
            'ASC'
        ));
        $main = null;

        foreach ($links as $link) {
            if (($link['is_main'] ?? 'false') === 'true') {
                $main = Category::findById((int) ($link['category_id'] ?? 0));
                break;
            }
        }

        if (!is_array($main) && isset($links[0])) {
            $main = Category::findById((int) ($links[0]['category_id'] ?? 0));
        }

        $path = [];
        $seen = [];

        while (is_array($main) && (int) ($main['id'] ?? 0) > 0 && !isset($seen[(int) $main['id']])) {
            $seen[(int) $main['id']] = true;
            array_unshift($path, $main);
            $parentId = (int) ($main['parent_id'] ?? 0);
            $main = $parentId > 0 ? Category::find(['id' => $parentId, 'visible' => 'true'], 1) : null;
        }

        return $path;
    }

    /** @return list<array{label:string,value:string}> */
    private static function details(array $model): array
    {
        $details = [];

        foreach ([
            'weight' => 'ecommerce.catalog.details.weight',
            'length' => 'ecommerce.catalog.details.length',
            'width' => 'ecommerce.catalog.details.width',
            'height' => 'ecommerce.catalog.details.height',
        ] as $key => $translation) {
            $value = trim((string) ($model[$key] ?? ''));
            if ($value !== '' && (float) $value > 0) {
                $details[] = ['label' => (string) __t($translation), 'value' => $value];
            }
        }

        return $details;
    }

    private static function productName(string $model, string $variant, int $variantCount): string
    {
        if ($variantCount <= 1 || $variant === '' || mb_strtolower($variant) === mb_strtolower($model)) {
            return $model;
        }

        return trim($model.' '.$variant);
    }

    /** @param string|list<string> $slug */
    private static function categoryUrl(string|array $slug): string
    {
        $path = is_array($slug) ? $slug : [$slug];
        $path = array_values(array_filter(array_map('trim', $path)));

        return rtrim((string) Ecommerce::config('catalog.category_url', '/prodotti/categoria/'), '/')
            .'/'.implode('/', array_map('rawurlencode', $path)).'/';
    }

    private static function absolute(string $url): string
    {
        if ($url === '' || preg_match('~^https?://~i', $url)) {
            return $url;
        }

        $site = rtrim((string) ($GLOBALS['PATH']->site ?? (defined('APP_URL') ? APP_URL : '')), '/');

        return $site !== '' ? $site.'/'.ltrim($url, '/') : $url;
    }

    private static function breadcrumbName(string $name): string
    {
        return trim(str_replace(['\\', '"'], '', $name));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return array_is_list($rows) ? array_values(array_filter($rows, 'is_array')) : [$rows];
    }
}
