<?php

use Wonder\Plugin\Ecommerce\Ecommerce;
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
    $trackingItems[] = [
        'item_id' => (string) ($product['meta']['item_id'] ?? $product['id'] ?? ''),
        'item_name' => (string) ($product['name'] ?? ''),
        'price' => (float) ($product['meta']['price'] ?? 0),
        'index' => $index,
    ];
}
$schema = json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'ItemList',
    'name' => $filter->title(),
    'numberOfItems' => count($items),
    'itemListElement' => $items,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?: '{}';
$event = [
    'event' => 'view_item_list',
    'ecommerce' => [
        'item_list_name' => $filter->title(),
        'currency' => $currency,
        'items' => $trackingItems,
    ],
];

Ecommerce::layout('shop');
?>
<script type="application/ld+json"><?=$schema?></script>
<?=DataLayer::script([
    'type' => 'product_list',
    'language' => __l(),
    'currency' => $currency,
], $event, (int) ($_SESSION['user_id'] ?? 0))?>

<article class="d-grid col-1 gap-6 w-100">
<nav aria-label="<?=e(__t('ecommerce.catalog.breadcrumb_label'))?>">
    <ol class="d-flex f-wrap gap-2 text-small">
        <?php foreach ($breadcrumbs as $index => $item): ?>
            <li>
                <?php if ($index === array_key_last($breadcrumbs)): ?>
                    <span aria-current="page"><?=e($item['name'] ?? '')?></span>
                <?php else: ?>
                    <a href="<?=e(__u(ltrim((string) ($item['url'] ?? ''), '/')))?>"><?=e($item['name'] ?? '')?></a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>

<div>
    <h1 class="title"><?=e($filter->title())?></h1>
    <p class="text mt-2"><?=e($filter->description())?></p>
</div>

<?=View::component(Ecommerce::viewPath('components/catalog/filters.php'), [
    'query' => $filter->query(),
    'brands' => $brands ?? [],
    'attributes' => $filter->attributes(),
])?>

<div id="catalog-products" class="w-100">
    <p class="text-small tx-secondary mb-4"><?=e(__t('ecommerce.catalog.listing.results', ['count' => (int) $pagination->max_row]))?></p>
    <?=ProductList::make($products)
        ->columns(4, 3, 2)
        ->emptyMessage((string) __t('ecommerce.catalog.listing.empty'))
        ->renderGrid()?>
</div>

<?php if ((int) ($pagination->max_page ?? 1) > 1): ?>
    <nav aria-label="<?=e(__t('ecommerce.catalog.pagination.label'))?>">
        <?=$pagination->html?>
    </nav>
<?php endif; ?>
</article>

<?php View::end(); ?>
