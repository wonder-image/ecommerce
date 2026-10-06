<?php
/** php tests/ProductComponentsTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../vendor/wonder-image/app/app/function/helper.php';
require __DIR__.'/harness.php';

defined('APP_URL') || define('APP_URL', '');
defined('RESPONSIVE_IMAGE_SIZES') || define('RESPONSIVE_IMAGE_SIZES', [240, 480, 960, 1440]);

use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductCard;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductList;

$products = [
    [
        'id' => 12,
        'name' => 'T-shirt <Blu>',
        'url' => '/shop/t-shirt-blu/',
        'image' => '/media/t-shirt.jpg',
        'price' => 39.9,
        'sale_price' => 29.9,
        'short_description' => 'Cotone & lino',
        'variants' => [
            ['name' => 'Blu', 'url' => '/shop/t-shirt-blu/', 'active' => true],
            ['name' => 'Nero'],
        ],
    ],
    [
        'id' => 18,
        'name' => 'Felpa',
        'image' => '/media/felpa.jpg',
        'price_formatted' => '59,00 €',
    ],
];

check('la card normalizza i dati nel formato corrente', function () use ($products) {
    $sale = ProductCard::make($products[0])->data();
    $regular = ProductCard::make($products[1])->data();

    return $sale['price'] === '29,90 €'
        && $sale['compare_at_price'] === '39,90 €'
        && $sale['badge'] === 'In offerta'
        && $regular['name'] === 'Felpa'
        && $regular['image'] === '/media/felpa.jpg'
        && $regular['price'] === '59,00 €';
});

check('la griglia rende card sicure e classi responsive', function () use ($products) {
    $html = ProductList::make($products)
        ->title('Prodotti')
        ->columns(5, 3, 1)
        ->gap(4)
        ->renderGrid();

    return str_contains($html, 'product-list--grid')
        && str_contains($html, 'product-list--grid w-100 d-grid col-5 col-t-3 col-p-1 gap-4')
        && str_contains($html, '<div class="product-list__header mb-6 w-100')
        && !str_contains($html, '<header')
        && str_contains($html, 'product-card__media p-r d-block f-1-1')
        && str_contains($html, 'class="bg bg-cover"')
        && !str_contains($html, 'class="p-a top start w-100 h-100 bg bg-cover"')
        && !str_contains($html, 'product-card product-card--grid wi-box')
        && str_contains($html, 'T-shirt &lt;Blu&gt;')
        && str_contains($html, '29,90 €')
        && !str_contains($html, 'T-shirt <Blu>');
});

check('la lista usa la card orizzontale e conserva gli id mostrati', function () use ($products) {
    $list = ProductList::make($products);
    $html = $list->renderList();

    return str_contains($html, 'product-list--list')
        && str_contains($html, 'product-list--list d-grid gap-4 w-100')
        && str_contains($html, 'product-card--list')
        && str_contains($html, 'product-card__media p-r d-block f-1-1')
        && !str_contains($html, 'product-card product-card--list wi-box')
        && str_contains($html, 'Cotone &amp; lino')
        && $list->shownIds() === [12, 18];
});

check('le card usano solo componenti e utility del design system', function () use ($products) {
    $html = ProductList::make($products)->renderGrid().ProductList::make($products)->renderList();

    return str_contains($html, 'badge badge-primary')
        && str_contains($html, 'product-card__variant p-r d-block w-15 f-1-1 b-1 b-r')
        && !str_contains($html, 'style=')
        && !str_contains($html, 'px-')
        && !str_contains($html, 'py-');
});

check('lo stato vuoto mantiene titolo e messaggio personalizzato', function () {
    $html = ProductList::make()
        ->title('Novità')
        ->emptyMessage('Torna presto')
        ->renderGrid();

    return str_contains($html, 'Novità')
        && str_contains($html, 'Torna presto')
        && str_contains($html, 'role="status"');
});

check('le viste e i valori di layout non validi falliscono subito', function () {
    try {
        ProductList::make()->view('tabella');
        return false;
    } catch (InvalidArgumentException) {
    }

    try {
        ProductList::make()->columns(0);
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
});

check('la vista swiper rende card e configurazione responsive del core', function () use ($products) {
    $html = ProductList::make($products)
        ->swiper(1.25, 10, [720 => ['slidesPerView' => 3, 'spaceBetween' => 20]])
        ->renderSwiper();

    return str_contains($html, 'product-list--swiper')
        && str_contains($html, 'swiper w-100')
        && str_contains($html, 'swiper-slide')
        && str_contains($html, 'slidesPerView: 1.25')
        && str_contains($html, '720')
        && str_contains($html, 'navigation');
});

check('la pagina dimostrativa non e esposta dalle route pubbliche', function () {
    $root = dirname(__DIR__);
    $routes = (string) file_get_contents($root.'/config/routes/route.frontend.php');
    $docs = (string) file_get_contents($root.'/docs/product-cards.md');

    return !is_file($root.'/view/pages/frontend/product-cards.php')
        && !str_contains($routes, '/ecommerce/product-cards/')
        && str_contains($docs, 'demo/product-cards.php');
});

check('il catalogo non contiene compatibilita con i campi legacy', function () {
    $root = dirname(__DIR__);
    $sources = implode("\n", [
        (string) file_get_contents($root.'/src/Frontend/Catalog/ProductCard.php'),
        (string) file_get_contents($root.'/docs/product-cards.md'),
    ]);

    foreach (['fullName', 'imageS', 'prettyPrice', 'prettySale'] as $legacyField) {
        if (str_contains($sources, $legacyField)) {
            return false;
        }
    }

    return true;
});

summary();
