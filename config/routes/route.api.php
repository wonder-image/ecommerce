<?php

use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Ecommerce;

Route::area('api')->response('json')->frontend()->translatable(false)
    ->name('api.ecommerce.')->prefix('/ecommerce')->group(function () {
        Route::post('/catalog/products/search/', Ecommerce::handlerPath('api/catalog/search.php'))
            ->name('catalog.products.search');
    });
