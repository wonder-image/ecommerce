<?php
/** php tests/integrazione/CheckoutSummaryTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/dns-fixture.php';

$supporto = dirname(__DIR__, 3).'/gestionale/tests/integrazione/supporto';
require $supporto.'/compra.php';
require $supporto.'/spedizioni.php';

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutForm;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutSummary;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Seeding\ShippingDemo;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Parte senza metodi e sedi del sito di prova, con shipping e coupons accese; la transazione rimette tutto. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            ShippingDemo::clear();
            sqlModify(ShippingMethod::$table, ['active' => 'false'], 'active', 'true');
            sqlModify(Location::$table, ['is_pickup_point' => 'false'], 'is_pickup_point', 'true');
            accendiFunzionalita(['orders', 'shipping', 'coupons']);
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    return $esito;
}

/** Un metodo per tutta Italia: 8 € fino a 5 kg. */
function standard(): int
{
    $metodo = metodo('Standard');
    listino($metodo, zona('Italia Standard', [['IT', '']]), [[5, 8.0], [20, 15.0]]);

    return $metodo;
}

function modulo(array $extra = []): array
{
    return $extra + ['shipping_country' => 'IT', 'shipping_province' => 'MI', 'shipping_city' => 'Milano',
        'shipping_cap' => '20100', 'shipping_street' => 'Via Prova', 'shipping_number' => '1'];
}

function fatturazione(): array
{
    return ['email' => 'c@example.com', 'billing_country' => 'IT', 'billing_name' => 'Mario', 'billing_surname' => 'Rossi',
        'billing_province' => 'MI', 'billing_city' => 'Milano', 'billing_cap' => '20100', 'billing_street' => 'Via Prova', 'billing_number' => '1'];
}

function manuale(): int
{
    return (int) (PaymentMethod::create([
        'code' => 'tst_'.uniqid(), 'name' => 'Bonifico di prova', 'provider' => 'bank_transfer', 'timing' => 'deferred',
        'fee_type' => 'none', 'fee_value' => '0.00', 'fee_percent' => '0.00', 'available_for' => 'all',
        'active' => 'true', 'position' => 1, 'instructions' => 'Istruzioni di prova',
    ])->insert_id ?? 0);
}

function coupon(): string
{
    $codice = 'T'.strtoupper(substr(uniqid(), -8));
    Coupon::create(['code' => $codice, 'name' => 'Prova '.$codice, 'discount_type' => 'percent', 'discount_value' => '10.00',
        'applies_to_all' => 'true', 'applies_online' => 'true', 'active' => 'true']);

    return $codice;
}

/** Come fa il controller: dal modulo ai dati di `place`, senza spedire posta. */
function invia(int $cart, array $post, int $pagamento): array
{
    Mailer::useTransport(static fn (): bool => true);

    try {
        return Checkout::place($cart, CheckoutForm::data($post + ['payment_method_id' => $pagamento]) + [
            'customer_id' => 0, 'source' => 'ecommerce', 'user_id' => 0,
        ]);
    } finally {
        Mailer::useTransport(null);
    }
}

check('l\'anteprima dà importi formattati e un solo metodo di spedizione già scelto', fn () => prova(static function (): bool {
    $metodo = standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $p = CheckoutSummary::payload($cart, modulo());

    return $p['shipping_methods']['selected'] === $metodo
        && $p['shipping_methods']['options'][0]['price_display'] === '8,00 €'
        && $p['display']['total'] === '18,00 €'
        && $p['items'][0]['line_total_display'] === '10,00 €';
}));

check('le righe dell\'anteprima sono gli articoli: spedizione e commissione stanno solo nei totali', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $p = CheckoutSummary::payload($cart, modulo());
    $tipi = array_map(static fn (array $riga): string => (string) $riga['type'], $p['items']);

    return $tipi === ['product'] && (float) $p['order']['shipping_total'] === 8.0;
}));

check('la prima anteprima lascia le scelte già sul carrello', fn () => prova(static function (): bool {
    $italia = zona('Italia', [['IT', '']]);
    listino(metodo('Standard'), $italia, [[5, 8.0]]);
    $veloce = metodo('Express');
    listino($veloce, $italia, [[5, 12.0]]);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    CheckoutSummary::payload($cart, modulo(['shipping_method_id' => $veloce]));
    $p = CheckoutSummary::payload($cart, modulo(), null, true);

    return $p['shipping_methods']['selected'] === $veloce && $p['fulfillment']['type'] === 'shipping';
}));

check('un metodo gratuito dice «Gratis»', fn () => prova(static function (): bool {
    $metodo = metodo('Gratis');
    listino($metodo, zona('Italia Gratis', [['IT', '']]), [[5, 0.0]]);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $p = CheckoutSummary::payload($cart, modulo());

    return $p['shipping_methods']['options'][0]['price_display'] === (string) __t('ecommerce.checkout.free');
}));

check('il coupon si applica e si toglie; uno sconosciuto dà il messaggio del gestionale', fn () => prova(static function (): bool {
    standard();
    $codice = coupon();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    $su = CheckoutSummary::coupon($cart, 'apply', $codice, modulo());
    $giu = CheckoutSummary::coupon($cart, 'remove', '', modulo());
    $no = CheckoutSummary::coupon($cart, 'apply', 'NONESISTE', modulo());

    return $su['error'] === '' && $su['coupon']['code'] === $codice && (float) $su['order']['discount_total'] > 0
        && $giu['error'] === '' && $giu['coupon']['code'] === ''
        && $no['error'] !== '' && $no['coupon']['code'] === '';
}));

check('lo sconto mostrato ha il meno, come nel riepilogo della pagina', fn () => prova(static function (): bool {
    standard();
    $codice = coupon();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    $su = CheckoutSummary::coupon($cart, 'apply', $codice, modulo());

    return $su['display']['discount_total'] === CartPresenter::money(-abs((float) $su['order']['discount_total']), (string) ($su['order']['currency'] ?? 'EUR'));
}));

check('con i coupon spenti l\'applicazione dà un errore e il carrello non cambia', fn () => prova(static function (): bool {
    standard();
    spegniFunzionalita(['coupons']);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $p = CheckoutSummary::coupon($cart, 'apply', 'QUALSIASI', modulo());

    return $p['error'] !== '' && $p['coupon']['code'] === '';
}));

check('un ordine con spedizione nasce con la sua riga e il bonifico è accettato', fn () => prova(static function (): bool {
    $metodo = standard();
    $pagamento = manuale();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $esito = invia($cart, modulo(fatturazione() + ['fulfillment_type' => 'shipping', 'shipping_method_id' => (string) $metodo]), $pagamento);

    return $esito['status'] === 'pending'
        && (float) Order::findById($cart)['shipping_total'] === 8.0
        && CheckoutForm::isManual(PaymentMethod::findById($pagamento));
}));

check('senza metodo di spedizione l\'ordine del negozio si ferma', fn () => prova(static function (): bool {
    standard();
    $pagamento = manuale();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    try {
        invia($cart, modulo(fatturazione()), $pagamento);
    } catch (UserError $e) {
        return $e->key() === 'order.shipping_method_required';
    }

    return false;
}));

check('un ordine col ritiro nasce senza riga di spedizione, nella sede scelta', fn () => prova(static function (): bool {
    standard();
    $pagamento = manuale();
    $sede = sede();
    $prodotto = articolo(2.0, 10.0);
    giacenzaIn($prodotto, $sede, 5);
    $cart = carrello([[$prodotto, 1]]);
    $esito = invia($cart, fatturazione() + ['fulfillment_type' => 'pickup', 'location_id' => (string) $sede], $pagamento);

    return $esito['status'] === 'pending'
        && (float) Order::findById($cart)['shipping_total'] === 0.0
        && (int) Order::findById($cart)['location_id'] === $sede;
}));

check('un\'anteprima senza scelte tiene quelle del carrello', fn () => prova(static function (): bool {
    $italia = zona('Italia', [['IT', '']]);
    listino(metodo('Standard'), $italia, [[5, 8.0]]);
    $veloce = metodo('Express');
    listino($veloce, $italia, [[5, 12.0]]);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    CheckoutSummary::payload($cart, modulo(['shipping_method_id' => $veloce]));
    $p = CheckoutSummary::payload($cart, ['customer_note' => 'x']);

    return $p['shipping_methods']['selected'] === $veloce && (float) $p['order']['shipping_total'] === 12.0;
}));

check('l\'email dell\'utente non sostituisce quella scritta al passo Spedizione', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    CheckoutSummary::payload($cart, modulo(['email' => 'c@example.com']));
    CheckoutSummary::payload($cart, modulo(), (object) ['email' => 'altro@example.com']);

    return Order::findById($cart)['email'] === 'c@example.com';
}));

summary();
