<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelTag;

final class ProductRecommendations
{
    /** @return list<int> */
    public static function similar(int $modelId, int $limit = 12): array
    {
        $categoryIds = self::column(ProductModelCategory::find(['product_model_id' => $modelId]), 'category_id');
        return self::relatedIds(ProductModelCategory::class, 'category_id', $categoryIds, $modelId, $limit);
    }

    /** Tag in comune, poi stesso marchio: complementare ai prodotti simili per categoria. @return list<int> */
    public static function suggested(int $modelId, int $limit = 12): array
    {
        $tagIds = self::column(ProductModelTag::find(['product_model_id' => $modelId]), 'tag_id');
        $ids = self::relatedIds(ProductModelTag::class, 'tag_id', $tagIds, $modelId, $limit);
        $model = ProductModel::find(['id' => $modelId], 1);
        $brandId = is_array($model) ? (int) ($model['brand_id'] ?? 0) : 0;

        if (count($ids) < $limit && $brandId > 0) {
            $rows = self::rows(ProductModel::find([
                'brand_id' => $brandId,
                'visible' => 'true',
                'visible_online' => 'true',
                'deleted' => 'false',
            ], null, 'position', 'ASC'));
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0 && $id !== $modelId && !in_array($id, $ids, true)) $ids[] = $id;
                if (count($ids) >= $limit) break;
            }
        }

        return array_slice($ids, 0, $limit);
    }

    /** @param class-string $model */
    private static function relatedIds(string $model, string $key, array $values, int $exclude, int $limit): array
    {
        $ids = [];
        foreach ($values as $value) {
            foreach (self::rows($model::find([$key => $value], null, 'id', 'DESC')) as $row) {
                $id = (int) ($row['product_model_id'] ?? 0);
                if ($id > 0 && $id !== $exclude && !in_array($id, $ids, true)) $ids[] = $id;
                if (count($ids) >= $limit) return $ids;
            }
        }
        return $ids;
    }

    private static function column(mixed $rows, string $key): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row[$key] ?? 0), self::rows($rows)
        ))));
    }

    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) return [];
        return array_is_list($rows) ? array_values(array_filter($rows, 'is_array')) : [$rows];
    }
}
