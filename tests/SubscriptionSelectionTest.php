<?php
/** php tests/SubscriptionSelectionTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionSelection;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionView;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

$_SESSION = [];

// Il controllo vero (OnlineSubscriptions::lines) legge il database: qui un finto
// che ricorda le liste viste e rifiuta quello che il test gli dice.
$visti = [];
$rifiuta = null;
SubscriptionSelection::$owner = static fn (): int => 7;
SubscriptionSelection::$validate = static function (array $items) use (&$visti, &$rifiuta): void {
    $visti[] = $items;

    if ($rifiuta !== null && $rifiuta($items)) {
        throw UserError::make('subscription.interval_mismatch');
    }
};

check('aggiungere: la riga si normalizza e il controllo vede la selezione intera', function () use (&$visti) {
    SubscriptionSelection::clear();
    $visti = [];
    $primo = SubscriptionSelection::add(['price_code' => 'ppr_base1', 'quantity' => '2', 'customization' => ['5' => ' Rossi ', 'x' => 'no'], 'parent' => '']);
    $secondo = SubscriptionSelection::add(['price_code' => 'ppr_extra1', 'parent' => 0]);
    $items = SubscriptionSelection::items();

    return $primo === 0 && $secondo === 1 && count($items) === 2
        && $items[0] === ['price_code' => 'ppr_base1', 'quantity' => 2, 'customization' => [5 => 'Rossi'], 'parent' => -1]
        && $items[1] === ['price_code' => 'ppr_extra1', 'quantity' => 1, 'customization' => [], 'parent' => 0]
        && count($visti) === 2 && count($visti[1]) === 2 && $visti[1][1]['price_code'] === 'ppr_extra1';
});

check('la quantità resta fra 1 e il massimo, il codice vuoto non entra', function () {
    SubscriptionSelection::clear();
    SubscriptionSelection::add(['price_code' => 'ppr_base1', 'quantity' => 0]);
    SubscriptionSelection::add(['price_code' => 'ppr_base1', 'quantity' => 100000]);

    try {
        SubscriptionSelection::add(['price_code' => '  ']);
        $vuoto = false;
    } catch (UserError $error) {
        $vuoto = $error->key() === 'subscription.price_unavailable';
    }

    $items = SubscriptionSelection::items();

    return $items[0]['quantity'] === 1 && $items[1]['quantity'] === SubscriptionSelection::MAX_QUANTITY && $vuoto && count($items) === 2;
});

check('un errore del controllo lascia la selezione com\'era', function () use (&$rifiuta) {
    SubscriptionSelection::clear();
    SubscriptionSelection::add(['price_code' => 'ppr_mese1']);
    $rifiuta = static fn (array $items): bool => count($items) > 1;

    try {
        SubscriptionSelection::add(['price_code' => 'ppr_bimestre1']);
        $errore = '';
    } catch (UserError $error) {
        $errore = $error->key();
    } finally {
        $rifiuta = null;
    }

    return $errore === 'subscription.interval_mismatch' && count(SubscriptionSelection::items()) === 1;
});

check('togliere una madre toglie anche le figlie e rinumera le altre', function () {
    SubscriptionSelection::clear();
    SubscriptionSelection::add(['price_code' => 'ppr_a']);
    SubscriptionSelection::add(['price_code' => 'ppr_b', 'parent' => 0]);
    SubscriptionSelection::add(['price_code' => 'ppr_c']);
    SubscriptionSelection::add(['price_code' => 'ppr_d', 'parent' => 2]);
    SubscriptionSelection::add(['price_code' => 'ppr_e', 'parent' => 0]);
    SubscriptionSelection::remove(0);
    $items = SubscriptionSelection::items();

    return array_column($items, 'price_code') === ['ppr_c', 'ppr_d'] && $items[1]['parent'] === 0 && $items[0]['parent'] === -1;
});

check('togliere una figlia lascia la madre; un indice che non c\'è non tocca niente', function () {
    SubscriptionSelection::clear();
    SubscriptionSelection::add(['price_code' => 'ppr_a']);
    SubscriptionSelection::add(['price_code' => 'ppr_b', 'parent' => 0]);
    SubscriptionSelection::add(['price_code' => 'ppr_c', 'parent' => 0]);
    SubscriptionSelection::remove(1);
    SubscriptionSelection::remove(9);
    $items = SubscriptionSelection::items();

    return array_column($items, 'price_code') === ['ppr_a', 'ppr_c'] && $items[1]['parent'] === 0;
});

check('figlie(): le righe che si appoggiano a una madre', function () {
    SubscriptionSelection::clear();
    SubscriptionSelection::add(['price_code' => 'ppr_a']);
    SubscriptionSelection::add(['price_code' => 'ppr_b', 'parent' => 0]);
    SubscriptionSelection::add(['price_code' => 'ppr_c']);

    return SubscriptionSelection::children(0) === [1] && SubscriptionSelection::children(2) === [] && SubscriptionSelection::children(1) === [];
});

check('al massimo di righe il controllo non viene chiamato e la selezione non cresce', function () use (&$visti) {
    SubscriptionSelection::clear();
    $_SESSION[SubscriptionSelection::KEY] = ['owner' => 7, 'items' => array_fill(0, SubscriptionSelection::MAX_ITEMS, ['price_code' => 'ppr_a', 'quantity' => 1, 'customization' => [], 'parent' => -1])];
    $visti = [];

    try {
        SubscriptionSelection::add(['price_code' => 'ppr_b']);
        $errore = '';
    } catch (UserError $error) {
        $errore = $error->key();
    }

    return $errore === 'subscription.too_many_items' && $visti === [] && count(SubscriptionSelection::items()) === SubscriptionSelection::MAX_ITEMS;
});

check('la selezione è di chi l\'ha composta: un altro cliente la trova vuota', function () {
    SubscriptionSelection::clear();
    SubscriptionSelection::add(['price_code' => 'ppr_a']);
    SubscriptionSelection::$owner = static fn (): int => 8;
    $altro = SubscriptionSelection::items();
    SubscriptionSelection::$owner = static fn (): int => 7;

    return $altro === [] && count(SubscriptionSelection::items()) === 1;
});

check('svuotare; senza sessione la selezione è vuota e non si rompe', function () {
    SubscriptionSelection::clear();
    $vuota = SubscriptionSelection::items() === [];
    $salvata = $_SESSION;
    unset($_SESSION);
    $senza = SubscriptionSelection::items() === [];
    $_SESSION = $salvata;

    return $vuota && $senza;
});

check('dati in sessione rovinati non entrano: righe senza codice scartate', function () {
    $_SESSION[SubscriptionSelection::KEY] = ['owner' => 7, 'items' => ['x', ['price_code' => ''], ['price_code' => 'ppr_a', 'quantity' => 3, 'customization' => 'no', 'parent' => 'z']]];
    $items = SubscriptionSelection::items();

    return $items === [['price_code' => 'ppr_a', 'quantity' => 3, 'customization' => [], 'parent' => -1]];
});

// Gli importi come li dà OnlineSubscriptions::preview(): la riga 1 non ha una tantum.
$importi = [
    'subtotal' => '30.00', 'discount_total' => '6.00', 'tax_total' => '4.33', 'total' => '24.00',
    'one_time' => ['subtotal' => '61.00', 'tax_total' => '11.00', 'total' => '61.00', 'lines' => [
        ['index' => 0, 'unit_price' => '30.50', 'line_total' => '61.00', 'tax_rate' => 22.0],
        ['index' => 2, 'unit_price' => '9.99', 'line_total' => '9.99', 'tax_rate' => 22.0],
    ]],
    'first_charge_total' => '85.00',
];

check('riepilogo: l\'una tantum di ogni servizio per indice della selezione', function () use ($importi) {
    return SubscriptionView::oneTimeLines($importi) === [0 => 61.0, 2 => 9.99]
        && SubscriptionView::oneTimeLines(['total' => '10.00']) === [];
});

check('riepilogo: il primo addebito somma canone e una tantum, in prova solo l\'una tantum', function () use ($importi) {
    $subito = SubscriptionView::firstCharge($importi, 0);
    $prova = SubscriptionView::firstCharge(['first_charge_total' => '61.00'] + $importi, 14);
    $senza = SubscriptionView::firstCharge(['subtotal' => '10.00', 'discount_total' => '0.00', 'tax_total' => '1.80', 'total' => '10.00'], 0);

    return $subito === ['subtotal' => 30.0, 'discount' => 6.0, 'one_time' => 61.0, 'tax' => 15.33, 'total' => 85.0, 'after_trial' => null]
        && $prova === ['subtotal' => null, 'discount' => 0.0, 'one_time' => 61.0, 'tax' => 11.0, 'total' => 61.0, 'after_trial' => 24.0]
        && $senza['total'] === 10.0 && $senza['one_time'] === 0.0;
});

check('checkout: begin_checkout vale il totale di oggi, servizi compresi', function () {
    $vista = (string) file_get_contents(dirname(__DIR__).'/view/pages/checkout/index.php');
    $servizi = (string) file_get_contents(dirname(__DIR__).'/src/Frontend/Checkout/CheckoutSubscriptions.php');

    return str_contains($vista, "\$services !== null ? \$services['today_total']")
        && str_contains($vista, "\$gaItems[] = \$item['tracking'];")
        && str_contains($servizi, 'SubscriptionView::firstCharge($amounts, $trial)')
        && str_contains($servizi, "'ecommerce.subscriptions.summary.one_time_line'");
});

summary();
