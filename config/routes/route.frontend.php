<?php

use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Ecommerce;

// Rotte pubbliche del negozio. Le demo dei componenti non sono esposte dal modulo.
Route::area('frontend')
    ->response('html')
    ->group(function () {

        $catalog = Ecommerce::handlerPath('frontend/catalog.php');
        Route::get('/prodotti/', $catalog, ['catalog_action' => 'index'])
            ->name('ecommerce.catalog.index');

        $categoryPath = '/prodotti';
        $categoryDepth = max(1, min(12, (int) Ecommerce::config('catalog.category_max_depth', 8)));
        for ($depth = 1; $depth <= $categoryDepth; $depth++) {
            $categoryPath .= '/{category_'.$depth.'}';
            Route::get($categoryPath.'/', $catalog, ['catalog_action' => 'category'])
                ->name('ecommerce.catalog.category.'.$depth);
        }

        Route::get('/offerte/', $catalog, ['catalog_action' => 'offers'])
            ->name('ecommerce.catalog.offers');
        Route::get('/novita/', $catalog, ['catalog_action' => 'new'])
            ->name('ecommerce.catalog.new');
        Route::get('/collezione/', $catalog, ['catalog_action' => 'collection'])
            ->name('ecommerce.catalog.collection');
        Route::get('/marchi/{marca}/', $catalog, ['catalog_action' => 'brand'])
            ->name('ecommerce.catalog.brand');
        Route::get('/cerca/', $catalog, ['catalog_action' => 'search'])
            ->name('ecommerce.catalog.search');

        Route::get(
            '/prodotto/{slug}/',
            Ecommerce::handlerPath('frontend/product.php')
        )->name('ecommerce.catalog.product');

        Route::name('ecommerce.cart.')
            ->prefix('/cart')
            ->group(function () {
                $handler = Ecommerce::handlerPath('frontend/cart.php');

                Route::get('/', $handler, ['cart_action' => 'index'])->name('index');
                Route::get('/preview/', $handler, ['cart_action' => 'preview'])->name('preview');
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

        $authProfileClass = Ecommerce::config('auth.profile', \Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceAuthProfile::class);
        if (!is_a($authProfileClass, \Wonder\Auth\Frontend\AuthProfile::class, true)) {
            throw new \LogicException('Invalid ecommerce auth profile');
        }
        \Wonder\Auth\Frontend\AuthRoutes::register(new $authProfileClass());

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
                    'password' => '/password/',
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
