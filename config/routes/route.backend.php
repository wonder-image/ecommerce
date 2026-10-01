<?php

use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Ecommerce;

if (Ecommerce::config('impersonation.enabled', true)) {
    // Il registrar dei moduli esegue questo file dentro il gruppo backend del
    // core, che ha già area, prefisso /backend, tema, guard e nome `backend.`.
    Route::name('ecommerce.')
        ->group(function () {
            Route::get('/ecommerce/impersonate/', Ecommerce::handlerPath('backend/impersonation.php'))
                ->name('impersonation.confirm')
                ->permit((array) Ecommerce::config('impersonation.actor_authorities', ['admin']));
            Route::post('/ecommerce/impersonate/', Ecommerce::handlerPath('backend/impersonation.php'))
                ->name('impersonation.issue')
                ->permit((array) Ecommerce::config('impersonation.actor_authorities', ['admin']));
        });
}
