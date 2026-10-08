<?php

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Elements\Components\Breadcrumb;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\CatalogFilter;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductList;
use Wonder\Plugin\Ecommerce\Frontend\Tracking\DataLayer;
use Wonder\View\View;

if (!$filter instanceof CatalogFilter) {
    http_response_code(404);
    return;
}

$products = is_array($products ?? null) ? $products : [];
$breadcrumbs = is_array($breadcrumbs ?? null) ? $breadcrumbs : [];
$currency = (string) ($currency ?? 'EUR');
$priceBounds = is_array($price_bounds ?? null) ? $price_bounds : ['min' => 0, 'max' => 0];
$pagination = is_object($pagination ?? null) ? $pagination : (object) ['max_row' => count($products), 'html' => ''];
$site = rtrim((string) ($GLOBALS['PATH']->site ?? (defined('APP_URL') ? APP_URL : '')), '/');
$items = [];
$trackingItems = [];
foreach ($products as $index => $product) {
    $url = (string) ($product['url'] ?? '');
    $absoluteUrl = preg_match('~^https?://~', $url) ? $url : $site.'/'.ltrim($url, '/');
    $items[] = [
        '@type' => 'ListItem',
        'position' => $index + 1,
        'url' => $absoluteUrl,
        'name' => (string) ($product['name'] ?? ''),
    ];
    $trackingItem = [
        'item_id' => (string) ($product['meta']['item_id'] ?? $product['id'] ?? ''),
        'item_name' => (string) ($product['name'] ?? ''),
        'price' => (float) ($product['meta']['price'] ?? 0),
        'index' => $index,
    ];
    if (($product['meta']['item_variant'] ?? '') !== '') {
        $trackingItem['item_variant'] = (string) $product['meta']['item_variant'];
    }
    $trackingItems[] = $trackingItem;
}

$SEO->schemaOrg = [
    '@context' => 'https://schema.org',
    '@type' => 'ItemList',
    'name' => $filter->title(),
    'numberOfItems' => count($items),
    'itemListElement' => $items,
];

$event = [
    'event' => 'view_item_list',
    'ecommerce' => [
        'item_list_name' => $filter->title(),
        'currency' => $currency,
        'items' => $trackingItems,
    ],
];

$catalogStyle = module_asset('ecommerce', 'css/catalog.css');

if ($catalogStyle !== '') View::head('<link rel="stylesheet" href="'.e($catalogStyle).'">');

View::head(DataLayer::script([
    'type' => 'product_list',
    'language' => __l(),
    'currency' => $currency,
], $event, (int) ($_SESSION['user_id'] ?? 0)));

$filterViewData = [
    'query' => $filter->query(),
    'brands' => $brands ?? [],
    'attributes' => $filter->attributes(),
    'price_bounds' => $priceBounds,
    'selected_price' => $filter->selectedPriceRange(),
    'currency' => $currency,
];

$catalogOverlay = View::component(
    Ecommerce::viewPath('components/catalog/filters-modal.php'),
    $filterViewData
);

Ecommerce::layout('shop', ['overlay' => $catalogOverlay]);
?>

<article class="d-grid col-1 gap-6 w-100">

<?=Breadcrumb::make($breadcrumbs)?>

<div>
    <h1 class="title"><?=e($filter->title())?></h1>
    <p class="text mt-2"><?=e($filter->description())?></p>
</div>

<div class="pc-none">
    <button type="button" class="btn btn-dark-o btn-icon-left" data-wi-modal-target="#catalog-filters-mobile" aria-controls="catalog-filters-mobile">
        <i class="bi bi-sliders" aria-hidden="true"></i>
        <?=e(__t('ecommerce.catalog.filters.open'))?>
    </button>
</div>

<div class="w-100">
<div class="d-grid col-4 col-t-1 col-p-1 gap-6 w-100">
    <aside class="col-1 tablet-none" aria-label="<?=e(__t('ecommerce.catalog.filters.label'))?>">
        <div class="catalog-filters-sticky">
            <?=View::component(Ecommerce::viewPath('components/catalog/filters.php'), [
                'form_id' => 'catalog_filters_desktop',
                ...$filterViewData,
            ])?>
        </div>
    </aside>

    <div class="col-3">
        <div id="catalog-products" class="w-100">
            <p class="text-small tx-secondary mb-4"><?=e(__t('ecommerce.catalog.listing.results', ['count' => (int) $pagination->max_row]))?></p>
            <?=ProductList::make($products)
                ->columns(4, 3, 2)
                ->emptyMessage((string) __t('ecommerce.catalog.listing.empty'))
                ->renderGrid()?>
        </div>

        <?php if ((int) ($pagination->max_page ?? 1) > 1): ?>
            <nav class="mt-4" aria-label="<?=e(__t('ecommerce.catalog.pagination.label'))?>">
                <?=$pagination->html?>
            </nav>
        <?php endif; ?>
    </div>
</div>
</div>
</article>

<?php View::end(); ?>
