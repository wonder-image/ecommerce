<?php

use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Ecommerce;

// Le pagine del negozio arrivano con i piani 2 e 3. Per ora una sola rotta,
// che serve a vedere se il modulo è installato e risponde.
Route::area('frontend')
    ->response('html')
    ->group(function () {

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
