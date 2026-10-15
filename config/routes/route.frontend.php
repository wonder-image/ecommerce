<?php

use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Discovery\CatalogFeed;
use Wonder\Plugin\Ecommerce\Frontend\Discovery\CatalogSitemap;
use Wonder\Plugin\Ecommerce\Frontend\Account\EcommerceAccountExtension;

// File per motori e assistenti AI, fuori dalle lingue: llms.txt alla radice
// del sito come vuole la convenzione, i dati sotto /shared/.
Route::area('frontend')
    ->response('html')
    ->translatable(false)
    ->name('ecommerce.discovery.')
    ->group(function () {
        $discovery = Ecommerce::handlerPath('frontend/discovery.php');
        Route::get('/llms.txt', $discovery, ['discovery_action' => 'llms'])->name('llms');
        Route::get('/llms-full.txt', $discovery, ['discovery_action' => 'llms-full'])->name('llms_full');
        Route::get(CatalogFeed::PATH, $discovery, ['discovery_action' => 'feed'])->name('feed');
        Route::get(CatalogSitemap::PATH, $discovery, ['discovery_action' => 'sitemap'])->name('sitemap');
    });

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
        Route::get(
            '/prodotto/{slug}/{variante}/',
            Ecommerce::handlerPath('frontend/product.php')
        )->name('ecommerce.catalog.product.variant');

        // Abbonamenti (G10, G10b): `/conferma/` e `/riepilogo/` prima di `/{price}/`.
        Route::name('ecommerce.subscriptions.')
            ->prefix('/abbonamenti')
            ->group(function () {
                $handler = Ecommerce::handlerPath('frontend/subscriptions.php');

                Route::get('/', $handler, ['subscriptions_action' => 'plans'])->name('plans');
                Route::get('/conferma/', $handler, ['subscriptions_action' => 'confirm'])->name('confirm');
                Route::get('/riepilogo/', $handler, ['subscriptions_action' => 'summary'])->name('summary');
                Route::post('/riepilogo/', $handler, ['subscriptions_action' => 'start'])->name('start');
                Route::post('/riepilogo/togli/', $handler, ['subscriptions_action' => 'remove'])->name('remove');
                Route::get('/{price}/', $handler, ['subscriptions_action' => 'configure'])
                    ->where('price', 'ppr_[a-z0-9-]+')
                    ->name('configure');
                Route::post('/{price}/', $handler, ['subscriptions_action' => 'add'])
                    ->where('price', 'ppr_[a-z0-9-]+')
                    ->name('add');
            });

        Route::name('ecommerce.cart.')
            ->prefix('/carrello')
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
                Route::post('/summary/', $handler, ['checkout_action' => 'summary'])->name('summary');
                Route::post('/coupon/', $handler, ['checkout_action' => 'coupon'])->name('coupon');
                Route::get('/completed/', $handler, ['checkout_action' => 'completed'])->name('completed');
                Route::get('/return/', $handler, ['checkout_action' => 'return'])->name('return');
                Route::get('/pay/', $handler, ['checkout_action' => 'pay'])->name('pay');
                Route::post('/reopen/', $handler, ['checkout_action' => 'reopen'])->name('reopen');

                $express = Ecommerce::handlerPath('frontend/express.php');
                Route::post('/express/start/', $express, ['express_action' => 'start'])->name('express.start');
                Route::post('/express/shipping/', $express, ['express_action' => 'shipping'])->name('express.shipping');
                Route::post('/express/place/', $express, ['express_action' => 'place'])->name('express.place');
                Route::post('/express/cancel/', $express, ['express_action' => 'cancel'])->name('express.cancel');
            });

        $authProfileClass = Ecommerce::config('auth.profile', \Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceAuthProfile::class);
        if (!is_a($authProfileClass, \Wonder\Auth\Frontend\AuthProfile::class, true)) {
            throw new \LogicException('Invalid ecommerce auth profile');
        }
        \Wonder\Auth\Frontend\AuthRoutes::register(new $authProfileClass());

        $panelClass = Ecommerce::config('account.panel', \Wonder\Auth\Frontend\AccountPanel::class);
        if (!is_a($panelClass, \Wonder\Auth\Frontend\AccountPanel::class, true)) {
            throw new \LogicException('Invalid ecommerce account panel');
        }
        \Wonder\Auth\Frontend\AccountRoutes::register(new $panelClass(), new $authProfileClass());
        \Wonder\Auth\Frontend\AccountRoutes::extend(new EcommerceAccountExtension());

    });
