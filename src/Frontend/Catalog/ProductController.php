<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

final class ProductController
{
    public static function show(string $slug): void
    {
        $product = ProductCatalog::find($slug);

        if ($product === null) {
            http_response_code(404);
            exit;
        }

        self::seo($product);

        $data = $product->data();
        $limit = max(1, (int) Ecommerce::config('catalog.recommendations_limit', 12));
        $recentIds = array_values(array_diff(ProductHistory::read(), [(int) $data['id']]));
        $similarIds = ProductRecommendations::similar((int) $data['id'], $limit);
        $suggestedIds = array_values(array_diff(
            ProductRecommendations::suggested((int) $data['id'], $limit * 2),
            $similarIds,
            [(int) $data['id']]
        ));
        $similar = ProductListing::byModelIds($similarIds, $limit);
        $recent = ProductListing::byModelIds($recentIds, $limit);
        $suggested = ProductListing::byModelIds($suggestedIds, $limit);
        ProductHistory::remember((int) $data['id'], $recentIds);

        View::make(Ecommerce::viewPath('pages/frontend/product.php'), [
            'product' => $product,
            'similar_products' => $similar,
            'recent_products' => $recent,
            'suggested_products' => $suggested,
        ])->render();
    }

    private static function seo(ProductDetail $product): void
    {
        global $SEO, $SOCIETY;

        $data = $product->data();
        $society = trim((string) ($SOCIETY->name ?? ''));
        $SEO->title = $data['name'].($society !== '' ? ' - '.$society : '');
        $SEO->description = $product->seoDescription();
        $SEO->url = $data['url'];
        $SEO->image = $data['images'][0]['url'] ?? '';
        $SEO->robots = 'INDEX,FOLLOW';
        $SEO->breadcrumb = [];

        foreach ($data['breadcrumbs'] as $item) {
            $SEO->breadcrumb[(string) ($item['url'] ?? '')] = (string) ($item['name'] ?? '');
        }
    }
}
