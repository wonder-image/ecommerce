<?php

use Wonder\Plugin\Ecommerce\Frontend\Catalog\CatalogController;

CatalogController::index(
    (string) ($ROUTE_META['catalog_action'] ?? 'index'),
    (array) ($ROUTE_PARAMETERS ?? []),
    (array) ($_GET ?? [])
);
