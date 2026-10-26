<?php
/**
 * php tests/integrazione/AccountCsrfScadutoTest.php
 *
 * Token sbagliato (pagina scaduta) sulle azioni degli abbonamenti dell'area
 * cliente: passa dal vero `invalidCsrf()` del core e da `renderCsrfFailure()`
 * dell'ecommerce. Si torna alla pagina col messaggio 929, i valori scritti
 * restano, mai «CSRF» né «token».
 */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$_SERVER['DOCUMENT_ROOT'] = SITE;
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\Auth\Frontend\AccountPanel;
use Wonder\Auth\Frontend\AccountRoutes;
use Wonder\Auth\Frontend\AuthProfile;
use Wonder\Auth\Frontend\AuthRoutes;
use Wonder\Frontend\Support\FlashMessage;
use Wonder\Http\Csrf;
use Wonder\Http\Route;
use Wonder\Plugin\CustomerAccount\Account\CustomerAccountExtension;
use Wonder\Plugin\Ecommerce\Frontend\Account\EcommerceAccountController;
use Wonder\Plugin\Ecommerce\Frontend\Account\EcommerceAccountExtension;
use Wonder\Plugin\Gestionale\Gestionale;

final class UscitaScaduta extends RuntimeException {}

/** Solo le uscite: `invalidCsrf()` resta quello del core. */
final class ControllerScadutoDiProva extends EcommerceAccountController
{
    protected function redirect(string $url): never { throw new UscitaScaduta('redirect '.$url); }
    protected function notFound(): never { throw new UscitaScaduta('404'); }
}

Route::reset();
AccountRoutes::reset();
AuthRoutes::register(new AuthProfile());
AccountRoutes::register(new AccountPanel(), new AuthProfile());
AccountRoutes::extend(new CustomerAccountExtension());
AccountRoutes::extend(new EcommerceAccountExtension());

const ADD_STATE = 'ecommerce_account_subscription_add';
const SUBSCRIPTION_ERROR = 'ecommerce_account_subscription_error';

/** POST col token sbagliato: dove porta il redirect, o '' se non esce con un redirect. */
function scaduta(string $action, array $parameters, array $post): string
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [Csrf::FIELD => 'scaduto'] + $post;
    ob_start();
    try {
        (new ControllerScadutoDiProva(AccountRoutes::panel(), AccountRoutes::auth()))->handle($action, $parameters);
        ob_end_clean();
        return '';
    } catch (UscitaScaduta $e) {
        $body = (string) ob_get_clean();
        $GLOBALS['CORPO'] = $body;
        return str_starts_with($e->getMessage(), 'redirect ') ? substr($e->getMessage(), 9) : $e->getMessage();
    }
}

function senzaCsrf(mixed $value): bool
{
    return !preg_match('/csrf|token/i', (json_encode($value, JSON_UNESCAPED_UNICODE) ?: '').($GLOBALS['CORPO'] ?? ''));
}

if (!Gestionale::feature('online_subscriptions')) {
    echo "  - abbonamenti online spenti nel DB del sito: test saltato\n";
    exit(0);
}

$_SESSION = [];
$token = Csrf::token();
$code = 'ABB-PROVA';
$add = Route::url('account.subscriptions.add', ['code' => $code]);
$elenco = Route::url('account.subscriptions');

$anteprima = scaduta('subscriptions.add.preview', ['code' => $code], [
    'price' => 'MENSILE',
    'quantity' => '2',
    'customization' => ['5' => 'Blu'],
    'parent' => '9',
]);
$stato = (array) ($_SESSION[ADD_STATE] ?? []);
check('anteprima: torna ad Aggiungi servizio col servizio scelto', fn () => $anteprima === $add.'?price=MENSILE');
check('anteprima: messaggio 929 in cima e valori scritti ricordati', fn () =>
    ($stato['message'] ?? '') === Csrf::failureMessage()
    && array_key_exists('field', $stato) && $stato['field'] === null
    && ($stato['values'] ?? []) === ['quantity' => '2', 'customization' => ['5' => 'Blu'], 'parent' => '9']
    && senzaCsrf($stato));

// Conferma con l'anteprima già fatta: l'anteprima resta, basta confermare di nuovo.
$_SESSION[ADD_STATE] = ['code' => $code, 'price' => 'MENSILE', 'values' => ['quantity' => '1', 'customization' => [], 'parent' => ''], 'field' => null, 'message' => '', 'preview' => ['name' => 'Servizio', 'item' => ['plan_price_id' => 4]]];
$conferma = scaduta('subscriptions.add.confirm', ['code' => $code], []);
$stato = (array) ($_SESSION[ADD_STATE] ?? []);
check('conferma: torna ad Aggiungi servizio con anteprima e messaggio 929', fn () =>
    $conferma === $add.'?price=MENSILE'
    && ($stato['preview']['item']['plan_price_id'] ?? 0) === 4
    && ($stato['message'] ?? '') === Csrf::failureMessage());

// Conferma senza anteprima: la pagina non mostrerebbe lo stato, l'avviso va in cima.
unset($_SESSION[ADD_STATE], $_SESSION[FlashMessage::KEY]);
$vuota = scaduta('subscriptions.add.confirm', ['code' => $code], []);
check('conferma senza servizio: torna ad Aggiungi servizio con l\'avviso 929', fn () =>
    $vuota === $add
    && ($_SESSION[FlashMessage::KEY]['message'] ?? '') === Csrf::failureMessage());

foreach ([
    'subscriptions.item.remove' => ['code' => $code, 'item' => 3],
    'subscriptions.item.change' => ['code' => $code, 'item' => 3],
    'subscriptions.cancel' => ['code' => $code],
    'subscriptions.card' => ['code' => $code],
] as $action => $parameters) {
    unset($_SESSION[SUBSCRIPTION_ERROR]);
    $dove = scaduta($action, $parameters, ['price' => 'ANNUALE']);
    check("$action: torna agli abbonamenti col messaggio 929", fn () =>
        $dove === $elenco
        && ($_SESSION[SUBSCRIPTION_ERROR] ?? '') === Csrf::failureMessage()
        && senzaCsrf($_SESSION[SUBSCRIPTION_ERROR]));
}

check('il token della sessione non cambia: il secondo invio passa', fn () =>
    Csrf::token() === $token && Csrf::verify([Csrf::FIELD => $token]));

summary();
