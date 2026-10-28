<?php
/** php tests/ProductListsTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Catalog\CatalogFilter;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductCard;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductListing;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductLists;
use Wonder\Plugin\Ecommerce\Frontend\Tracking\DataLayer;
use Wonder\Sql\Query;

/**
 * Un filtro senza database: il contesto (categoria o tag) come se l'avesse
 * trovato la rotta, poi l'ordine di chi visita. Senza impostazioni il
 * catalogo vale `position`.
 */
$filtro = static function (string $ordina = '', array $contesto = []): CatalogFilter {
    $filter = (new ReflectionClass(CatalogFilter::class))->newInstanceWithoutConstructor();

    if ($contesto !== []) {
        (new ReflectionMethod(CatalogFilter::class, 'useContext'))->invoke($filter, $contesto[0], $contesto[1]);
    }

    (new ReflectionMethod(CatalogFilter::class, 'applyOrder'))->invoke($filter, $ordina);

    return $filter;
};

$ordineVarianti = static fn (CatalogFilter $filter): string => Query::orderClause(
    (string) (new ReflectionMethod(ProductListing::class, 'variantOrder'))->invoke(null, $filter),
    'ASC'
);

check('in una categoria gli articoli in evidenza vanno in cima, poi la regola', function () use ($filtro) {
    $filter = $filtro('', ['category', ['id' => 5, 'sort_mode' => 'catalog', 'show_placements' => 1]]);
    $order = Query::orderClause($filter->order(), $filter->direction());

    return $filter->pinned()
        && $filter->sort() === 'position'
        && $filter->context() === ['type' => 'category', 'id' => 5]
        && str_starts_with($order, " ORDER BY COALESCE((SELECT MIN(pl.`position`) FROM `gst_product_placements` pl WHERE pl.`context_type` = 'category' AND pl.`context_id` = 5 AND pl.`deleted` = 'false' AND pl.`product_model_id` = `gst_product_models`.`id`), 2147483647), ")
        && str_ends_with($order, 'position, name ASC');
});

check('la regola della categoria batte quella del catalogo', function () use ($filtro) {
    $filter = $filtro('', ['tag', ['id' => 3, 'sort_mode' => 'price_desc', 'show_placements' => 1]]);
    $order = Query::orderClause($filter->order(), $filter->direction());

    return $filter->pinned()
        && $filter->sort() === 'price_desc'
        && str_contains($order, "pl.`context_type` = 'tag' AND pl.`context_id` = 3")
        && str_ends_with($order, "p.`deleted` = 'false') DESC");
});

check('se chi visita sceglie un ordine, vale il suo e niente in evidenza', function () use ($filtro) {
    $filter = $filtro('prezzo_asc', ['category', ['id' => 5, 'sort_mode' => 'newest', 'show_placements' => 1]]);

    return !$filter->pinned()
        && $filter->sort() === 'price_asc'
        && !str_contains($filter->order(), 'gst_product_placements')
        && $filter->direction() === 'ASC';
});

check('senza contesto niente in evidenza; un ordine sconosciuto vale la regola', function () use ($filtro) {
    $filter = $filtro('a caso');

    return !$filter->pinned()
        && $filter->sort() === 'position'
        && $filter->order() === 'position, name'
        && $filter->context() === ['type' => '', 'id' => 0];
});

check('una regola sconosciuta della categoria torna «come il catalogo»', function () use ($filtro) {
    return $filtro('', ['category', ['id' => 5, 'sort_mode' => 'boh']])->sort() === 'position';
});

check('per variante: prima la riga della variante, poi quella dell\'articolo intero', function () use ($filtro, $ordineVarianti) {
    $order = $ordineVarianti($filtro('', ['category', ['id' => 5, 'sort_mode' => 'catalog', 'show_placements' => 1]]));

    return str_starts_with($order, ' ORDER BY COALESCE((SELECT MIN(pl.`position`)')
        && str_contains($order, 'AND pl.`product_variant_id` = `gst_product_variants`.`id`), (SELECT MIN(pl.`position`)')
        && str_contains($order, 'AND pl.`product_model_id` = `gst_product_variants`.`product_model_id` AND COALESCE(pl.`product_variant_id`, 0) = 0), 2147483647), ')
        && str_ends_with($order, 'm.`id` = `gst_product_variants`.`product_model_id`) ASC, position ASC, name ASC');
});

check('per variante l\'ordine di chi visita resta quello di prima', function () use ($filtro, $ordineVarianti) {
    $order = $ordineVarianti($filtro('nome_desc', ['category', ['id' => 5, 'sort_mode' => 'catalog', 'show_placements' => 1]]));

    return !str_contains($order, 'gst_product_placements')
        && str_contains($order, '`product_model_id`) DESC, position ASC, name ASC');
});

check('senza «Mostra articoli in evidenza» la categoria o il tag tiene la sua regola, ma niente in evidenza', function () use ($filtro, $ordineVarianti) {
    $ok = true;

    // Spento, colonna assente o valore strano: come se non ci fossero righe.
    foreach ([0, '0', '', null, 'boh'] as $valore) {
        $row = ['id' => 5, 'sort_mode' => 'price_asc'] + ($valore === null ? [] : ['show_placements' => $valore]);
        $filter = $filtro('', ['category', $row]);
        $ok = $ok
            && !$filter->pinned()
            && !$filter->showsPlacements()
            && $filter->sort() === 'price_asc'
            && $filter->context() === ['type' => 'category', 'id' => 5]
            && !str_contains($filter->order(), 'gst_product_placements')
            && !str_contains($ordineVarianti($filter), 'gst_product_placements');
    }

    foreach ([1, '1', true, 'true'] as $valore) {
        $ok = $ok && $filtro('', ['tag', ['id' => 3, 'sort_mode' => 'catalog', 'show_placements' => $valore]])->pinned();
    }

    return $ok;
});

check('le fonti automatiche della selezione: ordine della fonte, niente in evidenza', function () {
    $nuovi = CatalogFilter::forSelection('newest');
    $novita = CatalogFilter::forSelection('novelty');
    $offerte = CatalogFilter::forSelection('offers');

    return CatalogFilter::forSelection('manual') === null
        && CatalogFilter::forSelection('boh') === null
        && CatalogFilter::forSelection('category', 0) === null
        && CatalogFilter::forSelection('tag', 0) === null
        && $nuovi !== null && $nuovi->sort() === 'newest' && !$nuovi->pinned() && $nuovi->context()['type'] === ''
        && $novita !== null && $novita->sort() === 'newest' && str_contains($novita->where(), 'DATE_SUB(NOW(), INTERVAL')
        && $offerte !== null && str_contains($offerte->where(), 'p.`sale_price` < p.`price`') && !$offerte->pinned();
});

check('un contesto sconosciuto non entra nell\'SQL', function () {
    return CatalogFilter::pinOrder("tag' OR 1=1 --", 5) === '2147483647'
        && CatalogFilter::pinOrder('category', 0) === '2147483647';
});

check('le regole della selezione riordinano le card, a parità nell\'ordine dato', function () {
    $card = static fn (int $id, string $name, float $price): array => ['id' => $id, 'model_id' => $id, 'name' => $name, 'meta' => ['price' => $price]];
    $cards = [$card(1, 'Nuvia', 80.0), $card(2, 'aurea', 60.0), $card(3, 'Revia', 80.0), $card(4, 'Alba', 45.5)];
    $ids = static fn (array $cards): array => array_column($cards, 'id');
    $date = static fn (array $ids): array => [1 => '2026-01-01 10:00:00', 2 => '2026-03-01 10:00:00', 3 => '2026-02-01 10:00:00'];

    return $ids(ProductLists::sorted($cards, 'manual')) === [1, 2, 3, 4]
        && $ids(ProductLists::sorted($cards, 'name')) === [4, 2, 1, 3]
        && $ids(ProductLists::sorted($cards, 'price_asc')) === [4, 2, 1, 3]
        && $ids(ProductLists::sorted($cards, 'price_desc')) === [1, 3, 2, 4]
        // Senza data (l'articolo 4) va in fondo.
        && $ids(ProductLists::sorted($cards, 'newest', $date)) === [2, 3, 1, 4];
});

check('view_item_list di un elenco: id e nome su ogni item, indice da 1 nell\'ordine in pagina', function () {
    $event = DataLayer::itemListEvent('home_featured', 'In evidenza', [
        ['id' => 12, 'name' => 'Revia', 'meta' => ['item_id' => '12', 'item_name' => 'Revia', 'price' => 80.0, 'item_variant' => '']],
        ['id' => 34, 'name' => 'Nuvia 30 ml', 'meta' => ['item_id' => '34', 'item_name' => 'Nuvia 30 ml', 'price' => 79.9, 'item_variant' => '30 ml']],
    ], 'EUR');
    $items = $event['ecommerce']['items'] ?? [];

    return $event['event'] === 'view_item_list'
        && $event['ecommerce']['currency'] === 'EUR'
        && $event['ecommerce']['item_list_id'] === 'home_featured'
        && array_column($items, 'index') === [1, 2]
        && $items[0]['item_id'] === '12'
        && !isset($items[0]['item_variant'])
        && $items[1]['item_variant'] === '30 ml'
        && $items[1]['price'] === 79.9
        && $items[1]['item_list_name'] === 'In evidenza';
});

check('la card porta la foto di contesto, vuota se non c\'è', function () {
    return ProductCard::make(['id' => 1, 'name' => 'Alba', 'context_image' => '/assets/upload/alba.jpg'])->data()['context_image'] === '/assets/upload/alba.jpg'
        && ProductCard::make(['id' => 1, 'name' => 'Alba'])->data()['context_image'] === '';
});

summary();
