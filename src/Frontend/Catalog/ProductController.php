<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

final class ProductController
{
    public static function show(string $slug, string $variante = ''): void
    {
        $resolved = ProductCatalog::resolve($slug, $variante, $_GET);

        if ($resolved['redirect'] !== null) {
            header('Location: '.$resolved['redirect'], true, 301);
            exit;
        }

        $product = $resolved['detail'];

        if ($product === null) {
            http_response_code(404);
            exit;
        }

        self::seo($product);

        View::make(Ecommerce::viewPath('pages/frontend/product.php'), [
            'product' => $product,
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
