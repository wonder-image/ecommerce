<?php
/** php tests/SelectionLinkTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Catalog\SelectionLink;

/** Il catalogo finto: divani dentro casa, una sedia nascosta, un marchio, un tag e un articolo. */
$trova = static function (string $type, string $key): ?array {
    $rows = [
        'category' => ['divani' => ['id' => 2, 'slug' => 'divani', 'parent_id' => 1], 'casa' => ['id' => 1, 'slug' => 'casa', 'parent_id' => 0]],
        'category#' => ['1' => ['id' => 1, 'slug' => 'casa', 'parent_id' => 0]],
        'brand' => ['acme' => ['id' => 3, 'slug' => 'acme']],
        'tag' => ['estate' => ['id' => 4, 'slug' => 'estate'], 'collezione' => ['id' => 5, 'slug' => 'collezione']],
        'product' => ['lampada' => ['id' => 6, 'slug' => 'lampada']],
    ];

    return $rows[$type][$key] ?? null;
};

$link = static fn (array $meta) => SelectionLink::from(['cta_label' => 'Scopri'] + $meta, $trova);

check('senza testo niente bottone; di serie porta a tutti i prodotti', function () use ($link, $trova) {
    return SelectionLink::from(['cta_link' => 'all'], $trova) === null
        && $link(['cta_link' => 'all']) === ['label' => 'Scopri', 'url' => '/prodotti/'];
});

check('il link personalizzato accetta solo percorsi e indirizzi sicuri', function () use ($link) {
    return $link(['cta_link' => 'custom', 'cta_url' => '/chi-siamo/'])['url'] === '/chi-siamo/'
        && $link(['cta_link' => 'custom', 'cta_url' => 'https://esempio.it'])['url'] === 'https://esempio.it'
        && $link(['cta_link' => 'custom', 'cta_url' => 'javascript:alert(1)']) === null
        && $link(['cta_link' => 'custom', 'cta_url' => '//altro.it']) === null
        && $link(['cta_link' => 'custom', 'cta_url' => '']) === null;
});

check('categoria con il percorso dei genitori, marchio, tag e articolo; le voci mancanti tolgono il bottone', function () use ($link) {
    return $link(['cta_link' => 'category', 'cta_category' => 'divani'])['url'] === '/prodotti/casa/divani/'
        && $link(['cta_link' => 'category', 'cta_category' => 'sedie']) === null
        && $link(['cta_link' => 'brand', 'cta_brand' => 'acme'])['url'] === '/marchi/acme/'
        && $link(['cta_link' => 'tag', 'cta_tag' => 'estate'])['url'] === '/tag/estate/'
        && $link(['cta_link' => 'tag', 'cta_tag' => 'collezione'])['url'] === '/collezione/'
        && $link(['cta_link' => 'product', 'cta_product' => 'lampada'])['url'] === '/prodotto/lampada/'
        && $link(['cta_link' => 'product', 'cta_product' => 'sparita']) === null
        && $link(['cta_link' => 'boh']) === null;
});

summary();
