<?php

use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Ecommerce;

// Rotte pubbliche del negozio e pagina di riferimento dei suoi componenti.
Route::area('frontend')
    ->response('html')
    ->group(function () {

        Route::get(
            '/ecommerce/product-cards/',
            Ecommerce::viewPath('pages/frontend/product-cards.php')
        )->name('ecommerce.catalog.product-cards');

        Route::name('ecommerce.cart.')
            ->prefix('/cart')
            ->group(function () {
                $handler = Ecommerce::handlerPath('frontend/cart.php');

                Route::get('/', $handler, ['cart_action' => 'index'])->name('index');
                Route::post('/add/', $handler, ['cart_action' => 'add'])->name('add');
                Route::post('/items/{id}/quantity/', $handler, ['cart_action' => 'quantity'])
                    ->where('id', '[0-9]+')
                    ->name('quantity');
                Route::post('/items/{id}/remove/', $handler, ['cart_action' => 'remove'])
                    ->where('id', '[0-9]+')
                    ->name('remove');
            });

        Route::name('ecommerce.checkout.')
            ->prefix('/checkout')
            ->group(function () {
                $handler = Ecommerce::handlerPath('frontend/checkout.php');

                Route::get('/', $handler, ['checkout_action' => 'index'])->name('index');
                Route::post('/', $handler, ['checkout_action' => 'place'])->name('place');
                Route::get('/completed/', $handler, ['checkout_action' => 'completed'])->name('completed');
            });

        Route::name('ecommerce.auth.')
            ->prefix('/account/auth')
            ->group(function () {
                $handler = Ecommerce::handlerPath('frontend/auth.php');

                foreach ([
                    'login' => '/login/',
                    'signup.request' => '/signup/request/',
                    'signup.completion' => '/signup/completion/',
                    'email.sent' => '/email-verification/send/',
                    'email.verify' => '/email-verification/verify/',
                    'password.recovery' => '/password/recovery/',
                    'password.restore' => '/password/restore/',
                ] as $action => $path) {
                    Route::get($path, $handler, ['auth_action' => $action])->name($action);
                    Route::post($path, $handler, ['auth_action' => $action]);
                }

                Route::post('/logout/', $handler, ['auth_action' => 'logout'])->name('logout');
                Route::post('/federated/{provider}/', $handler, ['auth_action' => 'federated'])
                    ->name('federated');
                if (Ecommerce::config('impersonation.enabled', true)) {
                    Route::get('/impersonate/', $handler, ['auth_action' => 'impersonation.start'])
                        ->name('impersonation.start');
                    Route::post('/impersonate/stop/', $handler, ['auth_action' => 'impersonation.stop'])
                        ->name('impersonation.stop');
                }
            });

        Route::name('ecommerce.account.')
            ->prefix('/account')
            ->guarded()
            ->permit(['client'])
            ->group(function () {
                $handler = Ecommerce::handlerPath('frontend/account.php');

                Route::get('/', $handler, ['account_action' => 'index'])->name('index');

                foreach ([
                    'profile' => '/personal-data/',
                    'billing' => '/billing-address/',
                    'shipping' => '/shipping-addresses/',
                    'shipping.create' => '/shipping-addresses/new/',
                    'payment-methods' => '/payment-methods/',
                ] as $action => $path) {
                    Route::get($path, $handler, ['account_action' => $action])->name($action);
                    Route::post($path, $handler, ['account_action' => $action]);
                }

                Route::get('/shipping-addresses/{id}/', $handler, ['account_action' => 'shipping.edit'])
                    ->where('id', '[0-9]+')
                    ->name('shipping.edit');
                Route::post('/shipping-addresses/{id}/', $handler, ['account_action' => 'shipping.edit'])
                    ->where('id', '[0-9]+');
            });

    });
