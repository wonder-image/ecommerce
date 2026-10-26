<?php
/**
 * Processo figlio di CsrfScadutoTest: una richiesta POST col token sbagliato
 * a un controller vero. Gira sotto php-cgi perché `headers_list()` dia il
 * Location; scrive in WI_CSRF_OUT stato, header, sessione e corpo.
 */
declare(strict_types=1);

// Prima del bootstrap: la sessione parte solo se nulla è già uscito.
ob_start();

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$_SERVER['DOCUMENT_ROOT'] = SITE;
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';

use Wonder\Http\Csrf;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartController;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutController;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionsController;

$case = json_decode((string) getenv('WI_CSRF_CASE'), true, 512, JSON_THROW_ON_ERROR);
$out = (string) getenv('WI_CSRF_OUT');

$_SESSION = (array) ($case['session'] ?? []);
$token = Csrf::token();
$_SERVER['REQUEST_METHOD'] = 'POST';
foreach ((array) ($case['server'] ?? []) as $key => $value) {
    $_SERVER[$key] = $value;
}
$_POST = (array) ($case['post'] ?? []);

register_shutdown_function(static function () use ($out, $token): void {
    $body = '';
    while (ob_get_level() > 0) {
        $body = ob_get_clean().$body;
    }
    file_put_contents($out, json_encode([
        'status' => http_response_code(),
        'headers' => headers_list(),
        'session' => $_SESSION,
        'body' => $body,
        // Il token non ruota: il secondo invio, col token della pagina nuova, passa.
        'token_kept' => Csrf::token() === $token && Csrf::verify([Csrf::FIELD => $token]),
    ], JSON_PARTIAL_OUTPUT_ON_ERROR));
});

$parameters = (array) ($case['parameters'] ?? []);
match ((string) $case['controller']) {
    'checkout' => CheckoutController::handle((string) $case['action']),
    'cart' => CartController::handle((string) $case['action'], $parameters),
    'subscriptions' => SubscriptionsController::handle((string) $case['action'], $parameters),
    'impersonation' => (static function (): void {
        require dirname(__DIR__, 2).'/http/backend/impersonation.php';
    })(),
};
