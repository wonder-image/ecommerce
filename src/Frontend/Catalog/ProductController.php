<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Wonder\Http\Exceptions\NotFoundHttpException;
use Wonder\Plugin\Gestionale\Support\Catalog\Faqs;
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
            throw new NotFoundHttpException();
        }

        self::seo($product);

        $data = $product->data();
        $limit = max(1, (int) Ecommerce::config('catalog.swiper.max', 12));
        $recentIds = array_values(array_diff(ProductHistory::read(), [(int) $data['id']]));
        $similarIds = ProductRecommendations::similar((int) $data['id'], $limit);
        $suggestedIds = array_values(array_diff(
            ProductRecommendations::suggested((int) $data['id'], $limit * 2),
            $similarIds,
            [(int) $data['id']]
        ));
        $priceList = CustomerPriceList::current();
        $similar = ProductListing::byModelIds($similarIds, $limit, $priceList);
        $recent = ProductListing::byModelIds($recentIds, $limit, $priceList);
        $suggested = ProductListing::byModelIds($suggestedIds, $limit, $priceList);
        // Sotto il minimo dello slider la sezione non compare, e nemmeno il suo evento.
        $min = max(1, (int) Ecommerce::config('catalog.swiper.min', 1));
        [$similar, $recent, $suggested] = array_map(
            static fn (array $cards): array => count($cards) >= $min ? $cards : [],
            [$similar, $recent, $suggested]
        );
        ProductHistory::remember((int) $data['id'], $recentIds);

        View::make(Ecommerce::viewPath('pages/frontend/product.php'), [
            'product' => $product,
            'similar_products' => $similar,
            'recent_products' => $recent,
            'suggested_products' => $suggested,
            'faqs' => Faqs::forModel((int) $data['id']),
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
        // Open Graph prende la misura da 1200 px; il JSON-LD resta sugli originali.
        $SEO->image = $data['images'][0]['preview'] ?? '';
        $SEO->robots = 'INDEX,FOLLOW';
        $SEO->searchUrl = CatalogController::searchUrl();
        $SEO->ogType = 'product';
        $SEO->ogMeta = self::openGraph($data);
        $SEO->breadcrumb = [];

        foreach ($data['breadcrumbs'] as $item) {
            $SEO->breadcrumb[(string) ($item['url'] ?? '')] = (string) ($item['name'] ?? '');
        }
    }

    /**
     * Meta Open Graph del prodotto: prezzo della variante mostrata e, solo
     * se la giacenza è gestita, disponibilità (come nel JSON-LD).
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public static function openGraph(array $data): array
    {
        if (($data['offers'] ?? []) === []) {
            return [];
        }

        $meta = [
            'product:price:amount' => number_format((float) ($data['catalog_price'] ?? $data['price']), 2, '.', ''),
            'product:price:currency' => (string) $data['currency'],
        ];
        if (!empty($data['stock_managed'])) {
            $meta['product:availability'] = !empty($data['available']) ? 'in stock' : 'out of stock';
        }
        if (($data['brand'] ?? '') !== '') {
            $meta['product:brand'] = (string) $data['brand'];
        }

        return $meta;
    }
}
