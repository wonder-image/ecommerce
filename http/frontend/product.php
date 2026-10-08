<?php

use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductController;

ProductController::show(
    trim((string) ($ROUTE_PARAMETERS['slug'] ?? '')),
    trim((string) ($ROUTE_PARAMETERS['variante'] ?? ''))
);
