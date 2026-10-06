<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\View\View;

final class CatalogController
{
    public static function index(string $action, array $parameters, array $query): void
    {
        $filter = CatalogFilter::fromRequest($action, $parameters, $query);
        if (!$filter->valid()) {
            http_response_code(404);
            return;
        }

        $perPage = max(1, min(60, (int) Ecommerce::config('catalog.per_page', 12)));
        $pagination = pagination(ProductModel::$table, $filter->where(), $perPage, 'catalog-products');
        $products = ProductListing::cards($filter, (string) $pagination->limit);
        $breadcrumbs = self::breadcrumbs($filter);
        self::seo($filter, $breadcrumbs);

        View::make(Ecommerce::viewPath('pages/frontend/catalog.php'), [
            'filter' => $filter,
            'products' => $products,
            'brands' => ProductListing::brands(),
            'pagination' => $pagination,
            'breadcrumbs' => $breadcrumbs,
            'currency' => (string) Ecommerce::config('catalog.currency', 'EUR'),
        ])->render();
    }

    private static function seo(CatalogFilter $filter, array $breadcrumbs): void
    {
        global $SEO, $SOCIETY;
        $site = rtrim((string) ($GLOBALS['PATH']->site ?? (defined('APP_URL') ? APP_URL : '')), '/');
        $canonical = $site.'/'.ltrim($filter->canonical(), '/');
        $society = trim((string) ($SOCIETY->name ?? ''));
        $SEO->title = $filter->title().($society !== '' ? ' - '.$society : '');
        $SEO->description = $filter->description();
        $SEO->url = $canonical;
        $SEO->robots = 'INDEX,FOLLOW';
        $SEO->breadcrumb = [];
        foreach ($breadcrumbs as $item) {
            $url = (string) $item['url'];
            $SEO->breadcrumb[preg_match('~^https?://~', $url) ? $url : $site.'/'.ltrim($url, '/')] = (string) $item['name'];
        }
    }

    private static function breadcrumbs(CatalogFilter $filter): array
    {
        return [
            ['url' => '/', 'name' => (string) __t('ecommerce.catalog.breadcrumb_home')],
            ['url' => '/prodotti/', 'name' => (string) __t('ecommerce.catalog.breadcrumb_products')],
            ...$filter->breadcrumbs(),
        ];
    }
}
