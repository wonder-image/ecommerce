<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;

/** Carica i modelli reali e li presenta nel formato condiviso delle card. */
final class ProductListing
{
    public static function entity(): string
    {
        return Ecommerce::config('catalog.listing_entity', 'model') === 'variant' ? 'variant' : 'model';
    }

    public static function paginationTable(): string
    {
        return self::entity() === 'variant' ? ProductVariant::$table : ProductModel::$table;
    }

    public static function paginationWhere(CatalogFilter $filter): string
    {
        if (self::entity() !== 'variant') return $filter->where();

        $modelWhere = preg_replace('/^\s*WHERE\s+/i', '', $filter->where()) ?: '1 = 0';

        return "WHERE `visible` = 'true' AND `deleted` = 'false' "
            ."AND EXISTS (SELECT 1 FROM `gst_products` vp WHERE vp.`product_variant_id` = `gst_product_variants`.`id` AND vp.`active` = 'true' AND vp.`deleted` = 'false') "
            ."AND EXISTS (SELECT 1 FROM `gst_product_models` WHERE `gst_product_models`.`id` = `gst_product_variants`.`product_model_id` AND {$modelWhere})";
    }

    public static function cards(CatalogFilter $filter, string $limit): array
    {
        if (self::entity() === 'variant') {
            $variants = self::rows(ProductVariant::find(
                self::paginationWhere($filter),
                $limit,
                self::variantOrder($filter),
                $filter->direction()
            ));

            return array_values(array_filter(array_map(static function (array $variant): ?array {
                $model = ProductModel::find([
                    'id' => (int) ($variant['product_model_id'] ?? 0),
                    'visible' => 'true',
                    'visible_online' => 'true',
                    'deleted' => 'false',
                ], 1);

                return is_array($model) ? self::card($model, $variant) : null;
            }, $variants)));
        }

        $models = self::rows(ProductModel::find(
            $filter->where(),
            $limit,
            $filter->order(),
            $filter->direction()
        ));

        return array_values(array_filter(array_map(static fn (array $model): ?array => self::card($model), $models)));
    }

    /** @param list<int> $ids @return list<array<string, mixed>> */
    public static function byModelIds(array $ids, int $limit = 12): array
    {
        $cards = [];
        foreach (array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, $limit) as $id) {
            $model = ProductModel::find([
                'id' => $id,
                'visible' => 'true',
                'visible_online' => 'true',
                'deleted' => 'false',
            ], 1);
            if (!is_array($model)) continue;

            if (self::entity() === 'variant') {
                foreach (self::rows(ProductVariant::find([
                    'product_model_id' => $id,
                    'visible' => 'true',
                    'deleted' => 'false',
                ], null, 'position', 'ASC')) as $variant) {
                    if (($card = self::card($model, $variant)) !== null) $cards[] = $card;
                    if (count($cards) >= $limit) break 2;
                }
                continue;
            }

            if (($card = self::card($model)) !== null) $cards[] = $card;
        }

        return $cards;
    }

    public static function brands(): array
    {
        return self::rows(Brand::find(
            ['visible' => 'true', 'deleted' => 'false'],
            null,
            'position, name',
            'ASC'
        ));
    }

    /** @return array{min:float,max:float} */
    public static function priceBounds(CatalogFilter $filter): array
    {
        $modelWhere = preg_replace('/^\s*WHERE\s+/i', '', $filter->priceContextWhere()) ?: '1 = 0';
        $effectivePrice = '(CASE WHEN `sale_price` > 0 AND `sale_price` < `price` THEN `sale_price` ELSE `price` END)';
        $product = Product::find(
            "WHERE `active` = 'true' AND `deleted` = 'false' AND EXISTS (SELECT 1 FROM `gst_product_models` WHERE `gst_product_models`.`id` = `gst_products`.`product_model_id` AND {$modelWhere})",
            1,
            $effectivePrice,
            'DESC',
            ['price', 'sale_price']
        );

        return [
            'min' => 0.0,
            'max' => is_array($product) ? self::effectivePrice($product) : 0.0,
        ];
    }

    private static function card(array $model, ?array $selectedVariant = null): ?array
    {
        $modelId = (int) ($model['id'] ?? 0);
        if ($modelId <= 0) return null;

        $variants = self::rows(ProductVariant::find(
            ['product_model_id' => $modelId, 'visible' => 'true', 'deleted' => 'false'],
            null,
            'position',
            'ASC'
        ));
        $productWhere = ['product_model_id' => $modelId, 'active' => 'true', 'deleted' => 'false'];
        if (is_array($selectedVariant)) $productWhere['product_variant_id'] = (int) ($selectedVariant['id'] ?? 0);
        $products = self::rows(Product::find(
            $productWhere,
            null,
            'position',
            'ASC'
        ));
        if ($variants === [] || $products === []) return null;

        $prices = array_map(static fn (array $product): float => self::effectivePrice($product), $products);
        $regularPrices = array_map(static fn (array $product): float => (float) ($product['price'] ?? 0), $products);
        $price = $prices !== [] ? min($prices) : 0.0;
        $regular = $regularPrices !== [] ? min($regularPrices) : $price;
        $onSale = $price > 0 && $price < $regular;
        $allImages = self::rows(ProductImage::find(
            ['product_model_id' => $modelId, 'deleted' => 'false'],
            null,
            'position',
            'ASC'
        ));
        $firstVariant = $selectedVariant ?? $variants[0];
        $images = ProductImages::for($allImages, (int) $firstVariant['id']);
        $firstImage = $images[0] ?? [];
        $image = is_array($firstImage) ? ProductImages::url($firstImage) : '';

        $variantCards = [];
        foreach ($variants as $index => $variant) {
            $variantImages = ProductImages::for($allImages, (int) $variant['id']);
            $variantImage = $variantImages[0] ?? [];
            $variantCards[] = [
                'name' => (string) ($variant['name'] ?? ''),
                'url' => ProductUrl::make((string) ($model['slug'] ?? ''), ProductUrl::variantSlugFor($variant, count($variants))),
                'image' => is_array($variantImage) ? ProductImages::url($variantImage) : '',
                'active' => (int) ($variant['id'] ?? 0) === (int) ($firstVariant['id'] ?? 0),
            ];
        }

        $showVariants = !in_array(
            Ecommerce::config('catalog.card_show_variants', true),
            [false, 0, '0', 'false', 'off'],
            true
        );
        $modelName = trim((string) ($model['name'] ?? ''));
        $variantName = trim((string) ($firstVariant['name'] ?? ''));
        $name = is_array($selectedVariant) ? self::variantName($modelName, $variantName) : $modelName;
        $cardId = is_array($selectedVariant) ? (int) ($firstVariant['id'] ?? 0) : $modelId;

        return [
            'id' => $cardId,
            'name' => $name,
            // La card del modello porta al modello, quella di una variante alla variante.
            'url' => is_array($selectedVariant)
                ? ProductUrl::make((string) ($model['slug'] ?? ''), ProductUrl::variantSlugFor($firstVariant, count($variants)))
                : ProductUrl::make((string) ($model['slug'] ?? '')),
            'image' => $image,
            'image_alt' => (string) ($firstImage['alt'] ?? $name),
            'price' => $regular,
            'sale_price' => $onSale ? $price : 0,
            'short_description' => (string) ($model['short_description'] ?? ''),
            'variants' => $showVariants ? $variantCards : [],
            'meta' => [
                'item_id' => (string) $cardId,
                'item_name' => $name,
                'price' => $price,
                'item_variant' => is_array($selectedVariant) ? $variantName : '',
            ],
        ];
    }

    public static function variantName(string $model, string $variant): string
    {
        $model = trim($model);
        $variant = trim($variant);
        if ($variant === '') return $model;
        if ($model === '' || str_starts_with(mb_strtolower($variant), mb_strtolower($model))) return $variant;
        return trim($model.' '.$variant);
    }

    private static function variantOrder(CatalogFilter $filter): string
    {
        $order = $filter->order();
        if (str_contains($order, 'SELECT MIN')) {
            return str_replace('`gst_product_models`.`id`', '`gst_product_variants`.`product_model_id`', $order).', position';
        }

        $column = match ($order) {
            'name' => 'name',
            'creation' => 'creation',
            default => 'position',
        };

        return "(SELECT m.`{$column}` FROM `gst_product_models` m WHERE m.`id` = `gst_product_variants`.`product_model_id`), position, name";
    }

    private static function effectivePrice(array $product): float
    {
        $price = (float) ($product['price'] ?? 0);
        $sale = (float) ($product['sale_price'] ?? 0);
        return $sale > 0 && $sale < $price ? $sale : $price;
    }

    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) return [];
        return array_is_list($rows) ? array_values(array_filter($rows, 'is_array')) : [$rows];
    }
}
