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
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;
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

check('elenco: a pari data, prima l\'ordine inserito dopo', fn () => prova(static function (): bool {
    [$userId, $scheda] = clienteConScheda('ordini-pari');
    foreach (['PRV-P1', 'PRV-P2', 'PRV-P3'] as $numero) {
        ordineDelCliente($scheda, $numero, '2026-04-01 10:00:00');
    }
    $_SESSION['user_id'] = $userId;
    $html = paginaNegozio('orders');

    return strpos($html, 'PRV-P3') < strpos($html, 'PRV-P2') && strpos($html, 'PRV-P2') < strpos($html, 'PRV-P1');
}));

check('rows(): niente, una riga sola, una lista e voci che non sono righe', function (): bool {
    $rows = static fn (mixed $found): array => EcommerceAccountController::rows($found);

    return $rows(null) === [] && $rows([]) === [] && $rows('testo') === []
        && $rows(['code' => 'ord_x']) === [['code' => 'ord_x']]
        && $rows([['id' => 1], ['id' => 2]]) === [['id' => 1], ['id' => 2]]
        && $rows([['total' => 3]]) === [['total' => 3]]
        && $rows([['id' => 1], 'testo', 7, ['id' => 2]]) === [['id' => 1], ['id' => 2]];
});

check('dettaglio: un ordine non del cliente dà 404', fn () => prova(static function (): bool {
    [$userId, $scheda] = clienteConScheda('dettaglio-404');
    [, $altra] = clienteConScheda('dettaglio-altro');
    [, $altrui] = ordineDelCliente($altra, 'PRV-D-ALTRUI', '2026-03-01 10:00:00');
    [, $ospite] = ordineDelCliente(0, 'PRV-D-OSPITE', '2026-03-01 10:00:00');
    [, $carrello] = ordineDelCliente($scheda, 'PRV-D-CARRELLO', '2026-03-01 10:00:00', ['stage' => 'cart']);
    $_SESSION['user_id'] = $userId;

    foreach ([$altrui, $ospite, $carrello, 'ord_inesistente', ''] as $code) {
        if (paginaNegozio('orders.show', ['code' => $code]) !== '404') {
            return false;
        }
    }
    return true;
}));

check('dettaglio: senza scheda del cliente niente ordini degli ospiti', fn () => prova(static function (): bool {
    [$userId] = clienteConScheda('dettaglio-senza-scheda', false);
    [, $altra] = clienteConScheda('dettaglio-conflitto');
    Contact::update(['email' => \infoUser($userId, 'id')->email], $altra);
    [, $ospite] = ordineDelCliente(0, 'PRV-D-OSPITE2', '2026-03-01 10:00:00');
    $_SESSION['user_id'] = $userId;

    return paginaNegozio('orders.show', ['code' => $ospite]) === '404';
}));

check('dettaglio: informazioni, tracking, prodotti, riepilogo e indirizzi', fn () => prova(static function (): bool {
    [$userId, $scheda] = clienteConScheda('dettaglio');
    $metodo = metodo('Corriere espresso');
    $pagamento = (int) (PaymentMethod::create([
        'code' => 'tst_'.uniqid(), 'name' => 'Carta di prova', 'provider' => 'bank_transfer', 'timing' => 'deferred',
        'fee_type' => 'none', 'fee_value' => '0.00', 'fee_percent' => '0.00', 'available_for' => 'all',
        'active' => 'true', 'position' => 1, 'icons' => 'visa,master',
    ])->insert_id ?? 0);
    $corriere = (int) (Carrier::create([
        'code' => 'car_acc-'.uniqid(), 'name' => 'Corriere di prova',
        'tracking_url_template' => 'https://traccia.example/{tracking}', 'active' => 'true',
    ])->insert_id ?? 0);
    [$id, $code] = ordineDelCliente($scheda, 'PRV-DET', '2026-03-05 09:30:00', [
        'shipping_method_id' => $metodo, 'payment_method_id' => $pagamento,
        'discount_total' => '4.00', 'coupon_code' => 'BENVENUTO', 'fees_total' => '1.50', 'total' => '42.50',
        'shipping_name' => 'Ada', 'shipping_surname' => 'Spedita', 'shipping_street' => 'Via Roma', 'shipping_number' => '1',
        'shipping_cap' => '20100', 'shipping_city' => 'Milano', 'shipping_province' => 'MI',
        'billing_name' => 'Ada', 'billing_surname' => 'Fatturata', 'billing_street' => 'Via Verdi', 'billing_number' => '2',
        'billing_cap' => '10100', 'billing_city' => 'Torino', 'billing_province' => 'TO',
    ]);
    $riga = (int) (OrderItem::create(['order_id' => $id, 'type' => 'product', 'position' => 1, 'name' => 'Tazza <b>blu</b>', 'image' => '', 'quantity' => '2.000', 'unit_price' => '20.00', 'line_total' => '40.00'])->insert_id ?? 0);
    OrderItem::create(['order_id' => $id, 'type' => 'product', 'position' => 2, 'parent_item_id' => $riga, 'bundle_option_id' => 7, 'name' => 'Piattino scelto', 'quantity' => '1.000', 'unit_price' => '0.00', 'line_total' => '0.00']);
    OrderItem::create(['order_id' => $id, 'type' => 'shipping', 'position' => 3, 'name' => 'RIGA-SPEDIZIONE', 'quantity' => '1.000', 'unit_price' => '5.00', 'line_total' => '5.00']);
    Shipment::create(['code' => Code::make(Shipment::class, Codes::SHIPMENT), 'order_id' => $id, 'type' => 'delivery', 'status' => 'in_transit', 'carrier_id' => $corriere, 'tracking_number' => 'TRK123']);
    Shipment::create(['code' => Code::make(Shipment::class, Codes::SHIPMENT), 'order_id' => $id, 'type' => 'delivery', 'status' => 'cancelled', 'carrier_id' => $corriere, 'tracking_number' => 'ANNULLATA']);
    $_SESSION['user_id'] = $userId;
    $html = paginaNegozio('orders.show', ['code' => $code]);
    $money = static fn (string $v): string => e(CartPresenter::money($v, 'EUR'));

    return str_contains($html, e((string) __t('ecommerce.account.orders.order_title', ['number' => 'PRV-DET'])))
        && str_contains($html, '05/03/2026 09:30')
        && str_contains($html, e((string) __t('ecommerce.account.orders.status.confirmed')))
        && str_contains($html, e((string) __t('ecommerce.account.orders.payment_status.paid')))
        // I nomi dei metodi li scrive il modello con le iniziali maiuscole (`sanitizeFirst()`).
        && str_contains($html, 'Corriere Espresso') && str_contains($html, 'Carta Di Prova') && str_contains($html, 'payment-icons/visa.svg')
        && str_contains($html, 'TRK123') && str_contains($html, 'href="https://traccia.example/TRK123"') && !str_contains($html, 'ANNULLATA')
        && str_contains($html, 'Tazza &lt;b&gt;blu&lt;/b&gt;') && !str_contains($html, 'Tazza <b>blu</b>')
        && str_contains($html, 'Piattino scelto') && str_contains($html, e((string) __t('ecommerce.account.orders.choice')))
        && !str_contains($html, 'RIGA-SPEDIZIONE')
        && str_contains($html, 'BENVENUTO') && str_contains($html, $money('4.00')) && str_contains($html, $money('1.50')) && str_contains($html, $money('42.50'))
        && str_contains($html, 'Spedita') && str_contains($html, 'Fatturata')
        && strpos($html, e((string) __t('ecommerce.account.orders.delivery_address'))) < strpos($html, e((string) __t('ecommerce.checkout.billing')));
}));

check('dettaglio: ritiro in sede con la sede, o il ripiego se la sede non c\'è più', fn () => prova(static function (): bool {
    [$userId, $scheda] = clienteConScheda('dettaglio-ritiro');
    $punto = sede();
    [, $conSede] = ordineDelCliente($scheda, 'PRV-RIT', '2026-03-06 10:00:00', ['fulfillment_type' => 'pickup', 'location_id' => $punto, 'shipping_total' => '0.00']);
    [, $senzaSede] = ordineDelCliente($scheda, 'PRV-RIT2', '2026-03-06 11:00:00', ['fulfillment_type' => 'pickup', 'location_id' => 999999, 'shipping_total' => '0.00', 'payment_method_id' => 999999]);
    $_SESSION['user_id'] = $userId;
    $con = paginaNegozio('orders.show', ['code' => $conSede]);
    $senza = paginaNegozio('orders.show', ['code' => $senzaSede]);
    $ritiro = e((string) __t('ecommerce.checkout.fulfillment_pickup'));

    return str_contains($con, 'Prova ritiro') && str_contains($con, e((string) __t('ecommerce.account.orders.pickup_address')))
        && !str_contains($con, e((string) __t('ecommerce.account.orders.delivery_address')))
        && str_contains($senza, $ritiro) && str_contains($senza, e((string) __t('ecommerce.account.orders.payment')))
        && str_contains($senza, 'PRV-RIT2');
}));

check('dettaglio: il tracking non diventa mai un link che non sia http(s)', fn () => prova(static function (): bool {
    [$userId, $scheda] = clienteConScheda('dettaglio-tracking');
    // Il modello del corriere e l'indirizzo salvato sulla spedizione sono testo libero: `e()` non spegne `javascript:` né `data:`.
    $corriereJs = (int) (Carrier::create([
        'code' => 'car_js-'.uniqid(), 'name' => 'Corriere Pericoloso',
        'tracking_url_template' => 'javascript:alert(1)//{tracking}', 'active' => 'true',
    ])->insert_id ?? 0);
    $corriereOk = (int) (Carrier::create([
        'code' => 'car_ok-'.uniqid(), 'name' => 'Corriere Onesto',
        'tracking_url_template' => 'https://traccia.example/{tracking}', 'active' => 'true',
    ])->insert_id ?? 0);
    $casi = [
        'TRK-MODELLO' => [$corriereJs, ''],
        'TRK-SALVATO-JS' => [$corriereOk, 'JavaScript:alert(2)'],
        'TRK-SALVATO-DATA' => [$corriereOk, 'data:text/html,<b>x</b>'],
    ];
    $_SESSION['user_id'] = $userId;
    $n = 0;

    foreach ($casi as $numero => [$corriere, $url]) {
        [$id, $code] = ordineDelCliente($scheda, 'PRV-T'.++$n, '2026-03-07 10:00:00');
        Shipment::create(['code' => Code::make(Shipment::class, Codes::SHIPMENT), 'order_id' => $id, 'type' => 'delivery', 'status' => 'in_transit', 'carrier_id' => $corriere, 'tracking_number' => $numero, 'tracking_url' => $url]);
        $html = paginaNegozio('orders.show', ['code' => $code]);
        $nome = $corriere === $corriereJs ? 'Corriere Pericoloso' : 'Corriere Onesto';

        if (preg_match('~href="\s*(javascript|data):~i', $html) === 1 || !str_contains($html, $nome) || !str_contains($html, $numero)) {
            return false;
        }
    }
    return true;
}));

check('dettaglio: il tracking salvato sulla spedizione vince sul modello del corriere, le spedizioni in ordine', fn () => prova(static function (): bool {
    [$userId, $scheda] = clienteConScheda('dettaglio-tracking-url');
    $corriere = (int) (Carrier::create([
        'code' => 'car_url-'.uniqid(), 'name' => 'Corriere Di Prova',
        'tracking_url_template' => 'https://traccia.example/{tracking}', 'active' => 'true',
    ])->insert_id ?? 0);
    [$id, $code] = ordineDelCliente($scheda, 'PRV-T-URL', '2026-03-07 11:00:00');
    Shipment::create(['code' => Code::make(Shipment::class, Codes::SHIPMENT), 'order_id' => $id, 'type' => 'delivery', 'status' => 'in_transit', 'carrier_id' => $corriere, 'tracking_number' => 'TRK-PRIMO', 'tracking_url' => 'https://salvato.example/xyz']);
    Shipment::create(['code' => Code::make(Shipment::class, Codes::SHIPMENT), 'order_id' => $id, 'type' => 'delivery', 'status' => 'in_transit', 'carrier_id' => $corriere, 'tracking_number' => 'TRK-SECONDO']);
    $_SESSION['user_id'] = $userId;
    $html = paginaNegozio('orders.show', ['code' => $code]);

    return str_contains($html, 'href="https://salvato.example/xyz"') && !str_contains($html, 'https://traccia.example/TRK-PRIMO')
        && str_contains($html, 'href="https://traccia.example/TRK-SECONDO"')
        && str_contains($html, 'rel="noopener noreferrer"') && !str_contains($html, 'rel="noopener"')
        && strpos($html, 'TRK-PRIMO') < strpos($html, 'TRK-SECONDO');
}));

check('dettaglio: ogni stato dell\'ordine e del pagamento ha il suo testo, in italiano e in inglese', function (): bool {
    foreach (['it', 'en'] as $lingua) {
        $testi = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/lang/'.$lingua.'/ecommerce.json'), true, 512, JSON_THROW_ON_ERROR)['account']['orders'] ?? [];
        foreach (Order::LIVE_STATUSES as $stato) {
            if (!is_string($testi['status'][$stato] ?? null) || $testi['status'][$stato] === '') {
                return false;
            }
        }
        foreach (Order::PAYMENT_STATUSES as $stato) {
            if (!is_string($testi['payment_status'][$stato] ?? null) || $testi['payment_status'][$stato] === '') {
                return false;
            }
        }
    }
    // Nella lingua del sito la chiave si risolve davvero (`__t()` lancia se manca).
    foreach (Order::LIVE_STATUSES as $stato) {
        __t('ecommerce.account.orders.status.'.$stato);
    }
    foreach (Order::PAYMENT_STATUSES as $stato) {
        __t('ecommerce.account.orders.payment_status.'.$stato);
    }
    return true;
});

summary();
