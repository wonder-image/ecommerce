<?php
/** php tests/CatalogListingTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Catalog\CatalogFilter;

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

    return str_contains($controller, 'pagination(ProductModel::$table')
        && str_contains($filter, "'marca'")
        && str_contains($filter, "'prezzo'")
        && str_contains($filter, "'ordina'")
        && str_contains($filter, "'is_filterable' => 'true'")
        && str_contains($view, '->columns(4, 3, 2)')
        && str_contains($view, 'view_item_list')
        && str_contains($view, "'@type' => 'ItemList'")
        && substr_count($view, '<h1') === 1;
});

summary();
