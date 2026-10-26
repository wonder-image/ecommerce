<?php
/**
 * php tests/integrazione/CsrfScadutoTest.php
 *
 * Token CSRF sbagliato (pagina scaduta) sulle superfici HTML dell'ecommerce:
 * redirect con l'avviso generico 929, i valori scritti tornano senza token
 * né segreti, il token della sessione resta quello e in pagina non compare
 * mai «CSRF» o «token». Ogni richiesta è un processo php-cgi a parte
 * (csrf-scaduto-figlio.php): il controller esce con `exit`.
 */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$_SERVER['DOCUMENT_ROOT'] = SITE;
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\Backend\Support\FlashAlert;
use Wonder\Frontend\Support\FlashMessage;
use Wonder\Http\Csrf;

/** @return array{status: int, location: string, session: array<string, mixed>, body: string, token_kept: bool} */
function scaduto(array $case): array
{
    $cgi = dirname(PHP_BINARY).'/php-cgi';
    $cgi = is_executable($cgi) ? $cgi : 'php-cgi';
    $out = tempnam(sys_get_temp_dir(), 'csrf');
    $script = __DIR__.'/csrf-scaduto-figlio.php';
    // Vera modalità CGI: con `-q` o senza GATEWAY_INTERFACE gli header risultano già inviati e la sessione non parte.
    $env = [
        'GATEWAY_INTERFACE' => 'CGI/1.1',
        'REQUEST_METHOD' => 'POST',
        'SCRIPT_FILENAME' => $script,
        'REDIRECT_STATUS' => '1',
        'WI_TEST_SITE' => SITE,
        'WI_CSRF_CASE' => json_encode($case, JSON_THROW_ON_ERROR),
        'WI_CSRF_OUT' => $out,
        'PATH' => (string) getenv('PATH'),
    ];
    $command = escapeshellarg($cgi).' -d display_errors=stderr -d session.save_path='.escapeshellarg(sys_get_temp_dir());
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, SITE, $env);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    proc_close($process);

    $result = json_decode((string) file_get_contents($out), true) ?: [];
    unlink($out);
    if ($result === []) {
        echo "    figlio senza risultato: ".trim($stderr.$stdout)."\n";
    }

    $location = '';
    foreach ((array) ($result['headers'] ?? []) as $header) {
        if (stripos((string) $header, 'Location:') === 0) {
            $location = trim(substr((string) $header, 9));
        }
    }

    return [
        'status' => (int) ($result['status'] ?? 0),
        'location' => $location,
        'session' => (array) ($result['session'] ?? []),
        'body' => (string) ($result['body'] ?? ''),
        'token_kept' => (bool) ($result['token_kept'] ?? false),
    ];
}

/** L'avviso in coda è il 929 del core, e nulla parla di CSRF o token. */
function avviso929(array $r): bool
{
    $flash = (array) ($r['session'][FlashMessage::KEY] ?? []);
    $text = json_encode($flash, JSON_UNESCAPED_UNICODE) ?: '';

    return str_contains($text, Csrf::failureMessage())
        && str_contains($text, (string) __t('notifications.929.title'))
        && !preg_match('/csrf|token/i', $text.$r['body']);
}

/** Risposta JSON valida che porta il messaggio atteso, e nulla parla di CSRF o token. */
function jsonCon(array $r, string $message): bool
{
    $data = json_decode($r['body'], true);

    return is_array($data)
        && str_contains((string) json_encode($data, JSON_UNESCAPED_UNICODE), $message)
        && !preg_match('/csrf|token/i', $r['body']);
}

function percorso(string $url): string
{
    return (string) (parse_url($url, PHP_URL_PATH) ?? '');
}

$checkout = percorso((string) __r('ecommerce.checkout.index'));
$cart = percorso((string) __r('ecommerce.cart.index'));
$sbagliato = [Csrf::FIELD => 'scaduto'];

check('il testo 929 è generico: niente «CSRF» né «token»', fn () =>
    Csrf::failureMessage() !== ''
    && !preg_match('/csrf|token/i', Csrf::failureMessage().__t('notifications.929.title')));

// Checkout «Paga» senza JavaScript: il modulo torna com'era.
$paga = scaduto([
    'controller' => 'checkout',
    'action' => 'place',
    'post' => $sbagliato + [
        'email' => 'cliente@example.com',
        'shipping_name' => 'Mario',
        'shipping_city' => 'Brescia',
        'customer_note' => 'Citofono rotto',
        'payment_method_id' => '12',
        'csrf_token' => 'vecchio',
        'password' => 'segreta',
        'card_number' => '4242424242424242',
        'card_cvc' => '123',
        'client_secret' => 'pi_123_secret_456',
        'g-recaptcha-token' => 'recaptcha',
    ],
]);
$stato = (array) ($paga['session']['ecommerce_checkout_form_state'] ?? []);
check('checkout place: 302 al checkout con l\'avviso 929', fn () =>
    $paga['status'] === 302 && percorso($paga['location']) === $checkout && avviso929($paga));
check('checkout place: i campi scritti tornano nel modulo', fn () =>
    ($stato['email'] ?? '') === 'cliente@example.com'
    && ($stato['shipping_name'] ?? '') === 'Mario'
    && ($stato['customer_note'] ?? '') === 'Citofono rotto'
    && (string) ($stato['payment_method_id'] ?? '') === '12');
check('checkout place: token, password, carta e segreti non tornano', function () use ($stato): bool {
    foreach ([Csrf::FIELD, 'csrf_token', 'password', 'card_number', 'card_cvc', 'client_secret', 'g-recaptcha-token'] as $key) {
        if (($stato[$key] ?? '') !== '') {
            return false;
        }
    }

    return true;
});
check('checkout place: il token della sessione non cambia', fn () => $paga['token_kept']);

// Il JSON di «Paga» resta il 419 col messaggio generico del riepilogo.
$json = scaduto([
    'controller' => 'checkout',
    'action' => 'place',
    'server' => ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
    'post' => $sbagliato,
]);
check('checkout place JSON: 419 con summary_error, niente CSRF nel corpo', fn () =>
    $json['status'] === 419 && jsonCon($json, (string) __t('ecommerce.checkout.summary_error')));

// Coupon senza JavaScript: si torna alla pagina da cui è partito.
foreach (['cart' => $cart, 'checkout' => $checkout] as $return => $path) {
    $coupon = scaduto(['controller' => 'checkout', 'action' => 'coupon', 'post' => $sbagliato + ['return' => $return, 'code' => 'SCONTO']]);
    check("coupon dal $return: 302 a $path con l'avviso 929", fn () =>
        $coupon['status'] === 302 && percorso($coupon['location']) === $path && avviso929($coupon) && $coupon['token_kept']);
}

$reopen = scaduto(['controller' => 'checkout', 'action' => 'reopen', 'post' => $sbagliato]);
check('checkout reopen: 302 al checkout con l\'avviso 929', fn () =>
    $reopen['status'] === 302 && percorso($reopen['location']) === $checkout && avviso929($reopen));

// Carrello senza JavaScript: si torna a `continue` (solo interno) o al carrello.
$aggiungi = scaduto(['controller' => 'cart', 'action' => 'add', 'post' => $sbagliato + ['product_id' => '1', 'quantity' => '2', 'continue' => '/prodotto/prova/']]);
check('carrello add: 302 a continue con l\'avviso 929', fn () =>
    $aggiungi['status'] === 302 && percorso($aggiungi['location']) === '/prodotto/prova/' && avviso929($aggiungi) && $aggiungi['token_kept']);
$fuori = scaduto(['controller' => 'cart', 'action' => 'remove', 'parameters' => ['id' => 1], 'post' => $sbagliato + ['continue' => 'https://altro.example/']]);
check('carrello remove: continue esterno ignorato, 302 al carrello', fn () =>
    $fuori['status'] === 302 && percorso($fuori['location']) === $cart && !str_contains($fuori['location'], 'altro.example') && avviso929($fuori));
$jsonCart = scaduto([
    'controller' => 'cart',
    'action' => 'quantity',
    'parameters' => ['id' => 1],
    'server' => ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
    'post' => $sbagliato,
]);
check('carrello JSON: 419 con ecommerce.cart.error', fn () =>
    $jsonCart['status'] === 419 && jsonCon($jsonCart, (string) __t('ecommerce.cart.error')));

// Abbonamenti senza JavaScript: avviso 929 e valori scritti ricordati.
$aggiungiPiano = scaduto(['controller' => 'subscriptions', 'action' => 'add', 'parameters' => ['price' => 'PROVA'], 'post' => $sbagliato + [
    'quantity' => '3',
    'customization' => ['7' => 'Rosso'],
    'parent' => '2',
]]);
$scelta = (array) ($aggiungiPiano['session']['ecommerce_subscription_configure'] ?? []);
check('abbonamenti add: 302 alla configurazione con l\'avviso 929 e la scelta ricordata', fn () =>
    $aggiungiPiano['status'] === 302
    && percorso($aggiungiPiano['location']) === percorso((string) __r('ecommerce.subscriptions.configure', ['price' => 'PROVA']))
    && avviso929($aggiungiPiano)
    && ($scelta['quantity'] ?? '') === '3'
    && ($scelta['customization'] ?? []) === ['7' => 'Rosso']
    && ($scelta['parent'] ?? '') === '2'
    && ($scelta['message'] ?? 'x') === ''
    && $aggiungiPiano['token_kept']);
$riepilogo = percorso((string) __r('ecommerce.subscriptions.summary'));
$avvia = scaduto(['controller' => 'subscriptions', 'action' => 'start', 'post' => $sbagliato + [
    'billing_name' => 'Mario',
    'billing_email' => 'cliente@example.com',
    'coupon' => 'SCONTO',
    'password' => 'segreta',
]]);
$fatturazione = (array) ($avvia['session']['ecommerce_subscription_form'] ?? []);
check('abbonamenti start: 302 al riepilogo con l\'avviso 929 e la fatturazione ricordata', fn () =>
    $avvia['status'] === 302 && percorso($avvia['location']) === $riepilogo && avviso929($avvia)
    && ($fatturazione['billing_name'] ?? '') === 'Mario'
    && ($fatturazione['coupon'] ?? '') === 'SCONTO'
    && !isset($fatturazione['password'], $fatturazione[Csrf::FIELD]));
$togli = scaduto(['controller' => 'subscriptions', 'action' => 'remove', 'post' => $sbagliato + ['index' => '0']]);
check('abbonamenti remove: 302 al riepilogo con l\'avviso 929', fn () =>
    $togli['status'] === 302 && percorso($togli['location']) === $riepilogo && avviso929($togli) && $togli['token_kept']);

// Impersonazione dal backend: si torna alla conferma con l'avviso 929, nessun token emesso.
$conferma = percorso((string) __r('backend.ecommerce.impersonation.confirm'));
$imp = scaduto(['controller' => 'impersonation', 'post' => [
    'csrf_token' => 'scaduto',
    'user_id' => '42',
    'continue' => '/account/ordini/',
    'return' => 'https://altro.example/',
]]);
parse_str((string) parse_url($imp['location'], PHP_URL_QUERY), $query);
check('impersonazione: 302 alla conferma con user_id e continue, return esterno ignorato', fn () =>
    $imp['status'] === 302 && percorso($imp['location']) === $conferma
    && ($query['user_id'] ?? '') === '42'
    && ($query['continue'] ?? '') === '/account/ordini/'
    && ($query['return'] ?? '') === '/backend/');
check('impersonazione: avviso 929 del backend, niente token emesso né «CSRF» in pagina', fn () =>
    ($imp['session'][FlashAlert::KEY] ?? []) === ['code' => (string) Csrf::ALERT]
    && !str_contains($imp['location'], 'token=')
    && !preg_match('/csrf|token/i', $imp['body']));

summary();
