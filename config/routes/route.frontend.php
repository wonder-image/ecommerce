<?php

use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Ecommerce;

// Le pagine del negozio arrivano con i piani 2 e 3. Per ora una sola rotta,
// che serve a vedere se il modulo è installato e risponde.
Route::area('frontend')
    ->response('html')
    ->group(function () {

        Route::get('/negozio/stato/', Ecommerce::viewPath('pages/frontend/stato.php'))
            ->name('ecommerce.stato');

    });
