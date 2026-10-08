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
    public static function cards(CatalogFilter $filter, string $limit): array
    {
        $models = self::rows(ProductModel::find(
            $filter->where(),
            $limit,
            $filter->order(),
            $filter->direction()
        ));

        return array_values(array_filter(array_map([self::class, 'card'], $models)));
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

    private static function card(array $model): ?array
    {
        $modelId = (int) ($model['id'] ?? 0);
        if ($modelId <= 0) return null;

        $variants = self::rows(ProductVariant::find(
            ['product_model_id' => $modelId, 'visible' => 'true', 'deleted' => 'false'],
            null,
            'position',
            'ASC'
        ));
        $products = self::rows(Product::find(
            ['product_model_id' => $modelId, 'active' => 'true', 'deleted' => 'false'],
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
        $firstVariant = $variants[0];
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
                'active' => $index === 0,
            ];
        }

        return [
            'id' => $modelId,
            'name' => (string) ($model['name'] ?? ''),
            // La card è del modello, e porta al modello.
            'url' => ProductUrl::make((string) ($model['slug'] ?? '')),
            'image' => $image,
            'image_alt' => (string) ($firstImage['alt'] ?? $model['name'] ?? ''),
            'price' => $regular,
            'sale_price' => $onSale ? $price : 0,
            'short_description' => (string) ($model['short_description'] ?? ''),
            'variants' => $variantCards,
            'meta' => [
                'item_id' => (string) $modelId,
                'item_name' => (string) ($model['name'] ?? ''),
                'price' => $price,
            ],
        ];
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
