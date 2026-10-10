<?php
/** php tests/integrazione/ExpressHttpTest.php — le rotte del check-out rapido sul sito di prova. */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Checkout\GuestCheckout;
use Wonder\Plugin\Gestionale\Gestionale;

$base = getenv('WI_TEST_URL') ?: 'https://ecommerce.test';

// Senza il sito di prova acceso il test si salta, come gli altri HTTP.
$probe = curl_init($base.'/');
curl_setopt_array($probe, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
if (curl_exec($probe) === false) {
    echo "Saltato: {$base} non risponde.\n";
    exit(0);
}
unset($probe);

$cookie = '';
/** @return array{0: int, 1: mixed} stato e corpo JSON */
$request = static function (string $path, ?array $post = null) use (&$cookie, $base): array {
    $curl = curl_init($base.$path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']]);
    if ($cookie !== '') { curl_setopt($curl, CURLOPT_COOKIE, $cookie); }
    if ($post !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $response = curl_exec($curl);
    if (!is_string($response)) { throw new RuntimeException(curl_error($curl)); }
    $headerLength = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if (preg_match('/Set-Cookie:\s*(PHPSESSID=[^;\r\n]+)/i', substr($response, 0, $headerLength), $match)) {
        $cookie = $match[1];
    }
    unset($curl);
    return [$status, json_decode(substr($response, $headerLength), true)];
};

[$status] = $request('/checkout/express/start/');
check('le rotte rapide vogliono il POST', fn () => $status === 404);

foreach (['start', 'shipping', 'place', 'cancel'] as $azione) {
    [$status, $risposta] = $request("/checkout/express/{$azione}/", ['csrf_token' => 'invalid']);
    check("{$azione} con CSRF invalido risponde 419 in JSON", fn () =>
        $status === 419 && is_array($risposta) && ($risposta['success'] ?? null) === false);
}

// Il token valido viene dalla pagina di login, nella stessa sessione.
$curl = curl_init($base.'/account/auth/login/');
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_COOKIE => $cookie]);
$login = (string) curl_exec($curl);
unset($curl);
if (preg_match('/Set-Cookie:\s*(PHPSESSID=[^;\r\n]+)/i', $login, $match)) {
    $cookie = $match[1];
}
preg_match('/name="csrf_token" value="([^"]+)"/', $login, $token);
$csrf = $token[1] ?? '';

// L'ospite passa solo se il negozio accetta gli ordini senza account.
$ospite = GuestCheckout::enabled();

foreach (['shipping', 'place'] as $azione) {
    [$status, $risposta] = $request("/checkout/express/{$azione}/", ['csrf_token' => $csrf]);
    check("{$azione} senza carrello rapido: ".($ospite ? '409' : '401 per l\'ospite'), fn () =>
        $csrf !== '' && $status === ($ospite ? 409 : 401) && is_array($risposta) && ($risposta['success'] ?? null) === false);
}

[$status, $risposta] = $request('/checkout/express/start/', ['csrf_token' => $csrf, 'source' => 'cart']);
check('start dal carrello vuoto: '.($ospite ? '422 con un messaggio' : '401 per l\'ospite'), fn () =>
    $status === ($ospite ? 422 : 401) && is_array($risposta) && ($risposta['success'] ?? null) === false
    && (!$ospite || ($risposta['message'] ?? '') !== ''));

[$status, $risposta] = $request('/checkout/express/cancel/', ['csrf_token' => $csrf]);
check('cancel senza niente in corso: '.($ospite ? 'va bene' : '401 per l\'ospite'), fn () =>
    $status === ($ospite ? 200 : 401) && is_array($risposta) && ($risposta['success'] ?? null) === $ospite);

if ($ospite) {
    // Il primo articolo acquistabile del sito: il carrello a parte nasce davvero.
    $avviato = null;
    foreach ((array) sqlSelect('gst_products', ['active' => 'true'], 20, 'id', 'ASC')->row as $riga) {
        [$status, $risposta] = $request('/checkout/express/start/', ['csrf_token' => $csrf, 'source' => 'product', 'product_id' => $riga['id'], 'quantity' => '1']);
        if ($status === 200) {
            $avviato = $risposta;
            break;
        }
    }
    check('start da un prodotto: importo in centesimi, totale e valuta', fn () =>
        is_array($avviato) && ($avviato['success'] ?? null) === true && is_int($avviato['amount'] ?? null) && $avviato['amount'] > 0
        && is_numeric($avviato['total'] ?? null) && ($avviato['currency'] ?? '') === 'eur');

    $spedizione = Gestionale::feature('shipping');
    $indirizzo = json_encode(['country' => 'IT', 'state' => 'MI', 'city' => 'Milano', 'postal_code' => '20121']);
    [$status, $risposta] = $request('/checkout/express/shipping/', ['csrf_token' => $csrf, 'address' => $indirizzo, 'rate' => 'pending']);
    $tariffe = (array) ($risposta['rates'] ?? []);
    check('shipping in Italia: tariffe con id testuale e importo in centesimi', fn () =>
        $status === 200 && $tariffe !== [] && is_int($risposta['amount'] ?? null)
        && array_filter($tariffe, static fn ($t): bool => !is_string($t['id'] ?? null) || !is_int($t['amount'] ?? null) || ($t['displayName'] ?? '') === '') === []
        && ($spedizione ? $tariffe[0]['id'] !== '0' : $tariffe === [['id' => '0', 'displayName' => $tariffe[0]['displayName'], 'amount' => 0]]));

    if ($spedizione) {
        [$status, $risposta] = $request('/checkout/express/shipping/', ['csrf_token' => $csrf, 'address' => json_encode(['country' => 'AQ']), 'rate' => '']);
        check('shipping fuori zona: 422 no_shipping', fn () => $status === 422 && ($risposta['error'] ?? '') === 'no_shipping');
    }

    [$status] = $request('/checkout/express/cancel/', ['csrf_token' => $csrf]);
    [$dopo] = $request('/checkout/express/shipping/', ['csrf_token' => $csrf, 'address' => $indirizzo]);
    check('cancel butta il carrello rapido: shipping poi dà 409', fn () => $status === 200 && $dopo === 409);
}

summary();
