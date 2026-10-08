<?php
/** WI_TEST_SITE=… php tests/integrazione/AccountOrdersTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$_SERVER['DOCUMENT_ROOT'] = SITE; // il layout del sito legge custom/config da qui
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

$supporto = dirname(__DIR__, 3).'/gestionale/tests/integrazione/supporto';
require $supporto.'/compra.php';
require $supporto.'/spedizioni.php';

use Wonder\App\Models\Contacts\Contact;
use Wonder\App\Models\User\User;
use Wonder\Auth\Frontend\AccountPanel;
use Wonder\Auth\Frontend\AccountRoutes;
use Wonder\Auth\Frontend\AuthProfile;
use Wonder\Auth\Frontend\AuthRoutes;
use Wonder\Auth\Frontend\ContactAccount;
use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Frontend\Account\EcommerceAccountController;
use Wonder\Plugin\Ecommerce\Frontend\Account\EcommerceAccountExtension;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\Transaction;

final class AnnullaOrdini extends RuntimeException {}
final class UscitaOrdini extends RuntimeException {}

final class ControllerOrdiniDiProva extends EcommerceAccountController
{
    protected function redirect(string $url): never { throw new UscitaOrdini('redirect '.$url); }
    protected function notFound(): never { throw new UscitaOrdini('404'); }
    protected function invalidCsrf(): never { throw new UscitaOrdini('419'); }
}

/** Esegue il corpo in una transazione e la annulla; rilegge le funzionalità dopo. */
function prova(callable $corpo): mixed
{
    $esito = null;
    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();
            throw new AnnullaOrdini();
        });
    } catch (AnnullaOrdini) {
    } finally {
        Gestionale::reset();
    }
    return $esito;
}

/** Un cliente con login e, se `$collega`, la scheda collegata: [id utente, id scheda o 0]. */
function clienteConScheda(string $prefix, bool $collega = true): array
{
    $email = $prefix.'-'.bin2hex(random_bytes(6)).'@example.com';
    $userId = (int) (User::create([
        'name' => 'Ada', 'surname' => 'Lovelace', 'email' => $email,
        'username' => create_link(explode('@', $email)[0], 'user', 'username'),
        'authority' => json_encode(['client'], JSON_THROW_ON_ERROR),
        'area' => json_encode(['frontend'], JSON_THROW_ON_ERROR),
        'active' => 'true',
    ])->insert_id ?? 0);
    markUserEmailVerified($userId, date('Y-m-d H:i:s'));
    $GLOBALS['ALERT'] = null;

    return [$userId, $collega ? (int) ContactAccount::link($userId)->contact_id : 0];
}

/** Un ordine confermato della scheda data: [id, code]. */
function ordineDelCliente(int $scheda, string $numero, string $quando, array $valori = []): array
{
    $code = Code::make(Order::class, Codes::ORDER);
    $id = (int) (Order::create($valori + [
        'code' => $code, 'order_number' => $numero, 'ordered_at' => $quando,
        'customer_id' => $scheda, 'stage' => 'order', 'status' => 'confirmed', 'payment_status' => 'paid',
        'fulfillment_type' => 'shipping', 'currency' => 'EUR',
        'products_total' => '40.00', 'discount_total' => '0.00', 'shipping_total' => '5.00', 'fees_total' => '0.00', 'total' => '45.00',
    ])->insert_id ?? 0);

    return [$id, $code];
}

/** La pagina dell'azione come la vede l'utente in sessione; 404 e rimandi tornano come testo. */
function paginaNegozio(string $action, array $parameters = [], array $get = []): string
{
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = $get;
    $_POST = [];
    // Il layout del sito chiede alle route nomi che qui non sono registrati: si rimette il pannello con la sua estensione.
    ($GLOBALS['REGISTRA'])();
    ob_start();
    try {
        (new ControllerOrdiniDiProva(AccountRoutes::panel(), AccountRoutes::auth()))->handle($action, $parameters);
        // I <script> portano il dizionario delle traduzioni: con quelli dentro un controllo sul testo passerebbe a vuoto.
        return (string) preg_replace('~<script\b[^>]*>.*?</script>~si', '', (string) ob_get_clean());
    } catch (UscitaOrdini $e) {
        ob_end_clean();
        return $e->getMessage();
    }
}

$GLOBALS['REGISTRA'] = static function (): void {
    Route::reset();
    AccountRoutes::reset();
    AuthRoutes::register(new AuthProfile());
    AccountRoutes::register(new AccountPanel(), new AuthProfile());
    AccountRoutes::extend(new EcommerceAccountExtension());
};
($GLOBALS['REGISTRA'])();

check('route degli ordini con le URL italiane', fn () =>
    str_ends_with(Route::url('account.orders'), '/account/ordini/')
    && str_ends_with(Route::url('account.orders.show', ['code' => 'ord_abc']), '/account/ordini/ord_abc/'));

check('le route degli ordini sono private, per i clienti', function (): bool {
    $route = array_values(array_filter(Route::all(), static fn ($r) => str_contains((string) ($r['path'] ?? ''), '/account/ordini/')));

    return count($route) === 2
        && array_filter($route, static fn ($r) => ($r['private'] ?? false) !== true || ($r['permit'] ?? null) !== ['client']) === [];
});

check('Ordini nel menu subito dopo Panoramica, attivo sulla sua pagina', fn () => prova(static function (): bool {
    [$userId] = clienteConScheda('ordini-menu');
    $_SESSION['user_id'] = $userId;
    $keys = array_keys(AccountRoutes::panel()->navigation(\infoUser($userId, 'id'), 'orders'));
    $html = paginaNegozio('orders');

    return array_slice($keys, 0, 2) === ['overview', 'orders']
        && str_contains($html, 'wi-side-nav__link" href="'.Route::url('account.orders').'" aria-current="page"');
}));

check('elenco: solo gli ordini del cliente, dal più recente, 10 per pagina', fn () => prova(static function (): bool {
    [$userId, $scheda] = clienteConScheda('ordini-elenco');
    [, $altra] = clienteConScheda('ordini-altro');
    for ($i = 1; $i <= 12; $i++) {
        ordineDelCliente($scheda, sprintf('PRV-%02d', $i), date('Y-m-d H:i:s', strtotime('2026-01-01 10:00 +'.$i.' hours')));
    }
    ordineDelCliente($altra, 'PRV-ALTRUI', '2026-02-01 10:00:00');
    ordineDelCliente(0, 'PRV-OSPITE', '2026-02-01 10:00:00');
    ordineDelCliente($scheda, 'PRV-CARRELLO', '2026-02-01 10:00:00', ['stage' => 'cart']);
    $_SESSION['user_id'] = $userId;

    $prima = paginaNegozio('orders');
    $seconda = paginaNegozio('orders', [], ['pagina' => '2']);
    $oltre = paginaNegozio('orders', [], ['pagina' => '99']);
    $strana = paginaNegozio('orders', [], ['pagina' => 'abc']);
    $summary = static fn (int $from, int $to): string => e((string) __t('account.pagination.summary', ['from' => $from, 'to' => $to, 'total' => 12]));

    return str_contains($prima, 'PRV-12') && str_contains($prima, 'PRV-03') && !str_contains($prima, 'PRV-02')
        && strpos($prima, 'PRV-12') < strpos($prima, 'PRV-11')
        && !str_contains($prima, 'PRV-ALTRUI') && !str_contains($prima, 'PRV-OSPITE') && !str_contains($prima, 'PRV-CARRELLO')
        && str_contains($prima, $summary(1, 10))
        && str_contains($prima, 'href="'.Route::url('account.orders').'?pagina=2"')
        && str_contains($prima, CartPresenter::money('45.00', 'EUR'))
        && str_contains($prima, 'href="'.Route::url('account.orders.show', ['code' => (string) (Order::find(['order_number' => 'PRV-12'], 1)['code'] ?? '')]).'"')
        && str_contains($seconda, $summary(11, 12)) && str_contains($seconda, 'PRV-01') && !str_contains($seconda, 'PRV-12')
        && str_contains($oltre, $summary(11, 12))
        && str_contains($strana, $summary(1, 10));
}));

check('?pagina= strano: 0, negativo, decimale, array e testo tornano a una pagina valida', fn () => prova(static function (): bool {
    [$userId, $scheda] = clienteConScheda('ordini-pagina');
    for ($i = 1; $i <= 12; $i++) {
        ordineDelCliente($scheda, sprintf('PRV-%02d', $i), date('Y-m-d H:i:s', strtotime('2026-01-01 10:00 +'.$i.' hours')));
    }
    $_SESSION['user_id'] = $userId;
    $summary = static fn (int $from, int $to): string => e((string) __t('account.pagination.summary', ['from' => $from, 'to' => $to, 'total' => 12]));
    $attese = ['0' => [1, 10], '-3' => [1, 10], '2.7' => [11, 12], 'abc' => [1, 10], '' => [1, 10], '9999999999' => [11, 12]];
    foreach ($attese as $richiesta => [$da, $a]) {
        if (!str_contains(paginaNegozio('orders', [], ['pagina' => (string) $richiesta]), $summary($da, $a))) {
            return false;
        }
    }

    return str_contains(paginaNegozio('orders', [], ['pagina' => ['2']]), $summary(1, 10));
}));

check('senza ordini: stato vuoto e niente paginazione', fn () => prova(static function (): bool {
    [$userId] = clienteConScheda('ordini-vuoto');
    $_SESSION['user_id'] = $userId;
    $html = paginaNegozio('orders');

    return str_contains($html, 'wi-empty-state') && str_contains($html, e((string) __t('ecommerce.account.orders.empty')))
        && !str_contains($html, 'wi-pagination');
}));

check('scheda in conflitto: errore della scheda e nessun ordine degli ospiti', fn () => prova(static function (): bool {
    // Come nel piano 1: la scheda di un altro utente ha già l'email del cliente.
    [$userId] = clienteConScheda('ordini-senza-scheda', false);
    [, $altra] = clienteConScheda('ordini-conflitto');
    Contact::update(['email' => \infoUser($userId, 'id')->email], $altra);
    ordineDelCliente(0, 'PRV-OSPITE2', '2026-02-01 10:00:00');
    $_SESSION['user_id'] = $userId;
    $html = paginaNegozio('orders');

    return !str_contains($html, 'PRV-OSPITE2') && str_contains($html, 'wi-empty-state')
        && str_contains($html, e((string) __t('account.errors.contact')));
}));

check('ogni submit della pagina ha wi-input-submit', fn () => prova(static function (): bool {
    [$userId] = clienteConScheda('ordini-submit');
    $_SESSION['user_id'] = $userId;
    preg_match_all('/<(button|input)\b[^>]*type="submit"[^>]*>/', paginaNegozio('orders'), $submits);

    return $submits[0] !== [] && array_filter($submits[0], static fn ($tag) => !str_contains($tag, 'wi-input-submit')) === [];
}));

summary();
