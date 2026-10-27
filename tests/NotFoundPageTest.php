<?php
/** php tests/NotFoundPageTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Http\Exceptions\NotFoundHttpException;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartController;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutController;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionsController;

/*
 * Le pagine che non esistono lanciano NotFoundHttpException: il RouteDispatcher
 * del core mostra la pagina 404 del sito invece di una pagina bianca.
 */

/** Vero se la chiamata lancia il 404 del core. */
function nonTrovata(callable $chiamata): bool
{
    try {
        $chiamata();
    } catch (NotFoundHttpException $e) {
        return $e->statusCode() === 404;
    }

    return false;
}

check('carrello: un\'azione sconosciuta è la pagina 404', fn () =>
    nonTrovata(static fn () => CartController::handle('inesistente')));

check('checkout: un\'azione sconosciuta è la pagina 404', fn () =>
    nonTrovata(static fn () => CheckoutController::handle('inesistente')));

check('abbonamenti: un\'azione sconosciuta (o la funzionalità spenta) è la pagina 404', fn () =>
    nonTrovata(static fn () => SubscriptionsController::handle('inesistente')));

check('nessun controller delle pagine chiude un 404 con il solo codice di stato', function () {
    $root = dirname(__DIR__).'/src/Frontend/';

    foreach (['Cart/CartController', 'Checkout/CheckoutController', 'Subscriptions/SubscriptionsController', 'Catalog/ProductController', 'Catalog/CatalogController'] as $file) {
        $sorgente = (string) file_get_contents($root.$file.'.php');

        if (str_contains($sorgente, 'http_response_code(404)') || !str_contains($sorgente, 'throw new NotFoundHttpException()')) {
            return false;
        }
    }

    return true;
});

summary();
