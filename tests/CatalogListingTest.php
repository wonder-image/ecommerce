<?php
/** php tests/CatalogListingTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Catalog\CatalogFilter;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductListing;

check('il filtro prezzo accetta intervallo, minimo, massimo ed esatto', function () {
    return CatalogFilter::range('20-100') === [20.0, 100.0]
        && CatalogFilter::range('50+') === [50.0, null]
        && CatalogFilter::range('-80') === [null, 80.0]
        && CatalogFilter::range('19,90') === [19.9, 19.9]
        && CatalogFilter::range('non valido') === [null, null];
});

check('il catalogo espone tutte le route richieste e un solo handler', function () {
    $root = dirname(__DIR__);
    $routes = (string) file_get_contents($root.'/config/routes/route.frontend.php');
    $handler = (string) file_get_contents($root.'/http/frontend/catalog.php');

    foreach (["'/prodotti/'", "'/offerte/'", "'/novita/'", "'/collezione/'", "'/marchi/{marca}/'", "'/cerca/'"] as $path) {
        if (!str_contains($routes, $path)) return false;
    }

    return str_contains($routes, "['catalog_action' => 'category']")
        && str_contains($handler, 'CatalogController::index')
        && !str_contains($routes, '/ecommerce/product-cards/');
});

check('la lista usa filtri dinamici, pagination del core e griglia a quattro colonne', function () {
    $root = dirname(__DIR__);
    $controller = (string) file_get_contents($root.'/src/Frontend/Catalog/CatalogController.php');
    $filter = (string) file_get_contents($root.'/src/Frontend/Catalog/CatalogFilter.php');
    $view = (string) file_get_contents($root.'/view/pages/frontend/catalog.php');

    return str_contains($controller, 'ProductListing::paginationTable()')
        && str_contains($controller, 'ProductListing::paginationWhere($filter)')
        && str_contains($filter, "'marca'")
        && str_contains($filter, "'prezzo'")
        && str_contains($filter, "'ordina'")
        && str_contains($filter, "'is_filterable' => 'true'")
        && str_contains($view, '->columns(4, 3, 2)')
        && str_contains($view, 'view_item_list')
        && str_contains($view, "'@type' => 'ItemList'")
        && substr_count($view, '<h1') === 1;
});

check('il catalogo puo mostrare modelli o varianti e nascondere le miniature delle altre varianti', function () {
    $root = dirname(__DIR__);
    $config = require $root.'/config/module.php';
    $listing = (string) file_get_contents($root.'/src/Frontend/Catalog/ProductListing.php');
    $view = (string) file_get_contents($root.'/view/pages/frontend/catalog.php');

    return ($config['catalog']['listing_entity'] ?? null) === 'model'
        && ($config['catalog']['card_show_variants'] ?? null) === true
        && str_contains($listing, "=== 'variant'")
        && str_contains($listing, "trim(\$model.' '.\$variant)")
        && str_contains($listing, "'variants' => \$showVariants ? \$variantCards : []")
        && str_contains($view, "['item_variant']")
        && ProductListing::variantName('Dinosauro', 'Verde') === 'Dinosauro Verde'
        && ProductListing::variantName('Dinosauro', 'Dinosauro Verde') === 'Dinosauro Verde';
});

check('i filtri catalogo sono laterali, sticky e aprono i gruppi selezionati anche nel modal', function () {
    $root = dirname(__DIR__);
    $view = (string) file_get_contents($root.'/view/pages/frontend/catalog.php');
    $modal = (string) file_get_contents($root.'/view/components/catalog/filters-modal.php');
    $filters = (string) file_get_contents($root.'/view/components/catalog/filters.php');
    $layout = (string) file_get_contents($root.'/view/layout/frontend/ecommerce.shop.php');
    $css = (string) file_get_contents($root.'/resources/assets/css/catalog.css');

    return str_contains($view, 'catalog-filters-sticky')
        && str_contains($view, 'class="col-1 tablet-none"')
        && str_contains($view, 'data-wi-modal-target="#catalog-filters-mobile"')
        && str_contains($view, 'bi bi-sliders')
        && str_contains($modal, 'class="wi-modal no-interaction pc-none"')
        && str_contains($view, 'class="btn btn-dark-o btn-icon-left"')
        && str_contains($view, "module_asset('ecommerce', 'css/catalog.css')")
        && str_contains($layout, '<?=$overlay?>')
        && str_contains($filters, 'class="wi-dropdown-box p-0')
        && str_contains($filters, "$"."selected ? ' wi-show' : ''")
        && str_contains($css, 'position: sticky');
});

check('marca e attributi usano checkbox, mentre l’ordinamento usa radio', function () {
    $root = dirname(__DIR__);
    $filters = (string) file_get_contents($root.'/view/components/catalog/filters.php');
    $filter = (string) file_get_contents($root.'/src/Frontend/Catalog/CatalogFilter.php');

    return substr_count($filters, '->checkbox()') >= 2
        && str_contains($filters, '->radio($orderOptions)')
        && !str_contains($filters, '->select(')
        && str_contains($filter, 'private static function values')
        && str_contains($filter, '`brand_id` IN');
});

check('il prezzo usa Da/A e il massimo dinamico, con le etichette ordine richieste', function () {
    $root = dirname(__DIR__);
    $filter = (string) file_get_contents($root.'/src/Frontend/Catalog/CatalogFilter.php');
    $listing = (string) file_get_contents($root.'/src/Frontend/Catalog/ProductListing.php');
    $form = (string) file_get_contents($root.'/view/components/catalog/filters.php');
    $it = json_decode((string) file_get_contents($root.'/lang/it/ecommerce.json'), true);
    $order = $it['catalog']['filters']['order'] ?? [];

    return str_contains($filter, "['prezzo_da']")
        && str_contains($filter, "['prezzo_a']")
        && str_contains($filter, 'priceContextWhere')
        && str_contains($listing, 'priceBounds')
        && str_contains($form, "FormField::key('prezzo_da')")
        && str_contains($form, "FormField::key('prezzo_a')")
        && ($order['price_desc'] ?? '') === 'Prezzo: alto-basso'
        && ($order['price_asc'] ?? '') === 'Prezzo: basso-alto'
        && ($order['name_asc'] ?? '') === 'Nome: a-z'
        && ($order['name_desc'] ?? '') === 'Nome: z-a';
});

summary();
