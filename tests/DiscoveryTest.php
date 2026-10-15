<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductController;
use Wonder\Plugin\Ecommerce\Frontend\Discovery\CatalogFeed;
use Wonder\Plugin\Ecommerce\Frontend\Discovery\CatalogSitemap;

$GLOBALS['PATH'] = (object) ['site' => 'https://negozio.it/'];

$card = [
    'id' => 7, 'name' => ' Maglietta ', 'url' => 'https://negozio.it/prodotto/maglietta/blu/',
    'image' => 'assets/upload/maglietta.jpg', 'price' => 29.9, 'sale_price' => 19.9,
    'short_description' => '<p>Cotone</p>', 'meta' => ['item_id' => '276383'],
    'variants' => [
        ['name' => 'Blu', 'url' => 'https://negozio.it/prodotto/maglietta/blu/'],
        ['name' => 'Rosso', 'url' => 'https://negozio.it/prodotto/maglietta/rosso/'],
        ['name' => 'Senza URL', 'url' => ''],
    ],
];

check('il feed usa il prezzo scontato e tiene quello pieno a parte', function () use ($card) {
    $product = CatalogFeed::product($card, 'EUR');
    return $product['id'] === '276383'
        && $product['name'] === 'Maglietta'
        && $product['description'] === 'Cotone'
        && $product['image'] === 'https://negozio.it/assets/upload/maglietta.jpg'
        && $product['price'] === 19.9
        && $product['regular_price'] === 29.9
        && array_column($product['variants'], 'name') === ['Blu', 'Rosso'];
});

check('il feed ignora sconti non validi e varianti singole', function () use ($card) {
    $product = CatalogFeed::product(['sale_price' => 35, 'variants' => [$card['variants'][0]], 'image' => 'https://cdn.it/a.jpg'] + $card, 'EUR');
    return $product['price'] === 29.9
        && !isset($product['regular_price'])
        && !isset($product['variants'])
        && $product['image'] === 'https://cdn.it/a.jpg';
});

check('la sitemap del catalogo è un urlset con gli URL escapati', function () {
    $xml = CatalogSitemap::render(['https://negozio.it/prodotti/', 'https://negozio.it/cerca/?q=a&b=c']);
    $doc = simplexml_load_string($xml);
    return $doc !== false
        && $doc->getName() === 'urlset'
        && count($doc->url) === 2
        && (string) $doc->url[1]->loc === 'https://negozio.it/cerca/?q=a&b=c'
        && str_contains($xml, '&amp;b=c');
});

check('Open Graph del prodotto con prezzo, disponibilità e marca', function () {
    $meta = ProductController::openGraph(['offers' => [['@type' => 'Offer']], 'price' => 19.9, 'currency' => 'EUR',
        'stock_managed' => true, 'available' => false, 'brand' => 'Wonder']);
    return $meta === ['product:price:amount' => '19.90', 'product:price:currency' => 'EUR',
        'product:availability' => 'out of stock', 'product:brand' => 'Wonder'];
});

check('Open Graph senza offerte non dichiara prezzi, senza magazzino non dichiara disponibilità', function () {
    $meta = ProductController::openGraph(['offers' => [['@type' => 'Offer']], 'price' => 5, 'currency' => 'EUR']);
    return ProductController::openGraph(['offers' => [], 'price' => 10, 'currency' => 'EUR']) === []
        && !isset($meta['product:availability'], $meta['product:brand']);
});

summary();
