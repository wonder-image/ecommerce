<?php
/** php tests/integrazione/ExpressCartTest.php — la barra rapida compra da un carrello a parte. */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

$supporto = dirname(__DIR__, 3).'/gestionale/tests/integrazione/supporto';
require $supporto.'/compra.php';
require $supporto.'/spedizioni.php';
require $supporto.'/FakePaymentProvider.php';
// Il sito di prova ha indirizzi veri: la posta di fondo si butta via.
require $supporto.'/posta.php';

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressAddress;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressCart;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\OnlinePayment;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Fa girare il corpo con la sessione pulita, dentro una transazione che poi annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;
    $_SESSION = [];

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            accendiFunzionalita(['orders', 'coupons']);
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    }

    Gestionale::reset();

    return $esito;
}

/** [product_id, quantity] delle righe del carrello, in ordine. */
function righe(int $carrello): array
{
    return array_map(
        static fn (array $riga): array => [(int) $riga['product_id'], (float) $riga['quantity']],
        Cart::contents($carrello)['items']
    );
}

$finto = new FakePaymentProvider();
PaymentProviders::register($finto);

check('start: carrello a parte, quello vero intatto', fn () => prova(static function (): bool {
    $primo = articolo(1.0);
    $altro = articolo(1.0);
    $vero = carrello([[$primo, 1]]);
    $express = ExpressCart::start($altro, 2);

    return $express > 0
        && $express !== $vero
        && ExpressCart::id() === $express
        && righe($vero) === [[$primo, 1.0]]
        && righe($express) === [[$altro, 2.0]]
        && (string) Order::findById($express)['stage'] === 'cart';
}));

check('start: prodotto non acquistabile → UserError e niente sessione', fn () => prova(static function (): bool {
    $spento = articolo(1.0);
    Product::update(['active' => 'false'], $spento);

    try {
        ExpressCart::start($spento, 1);
    } catch (UserError) {
        return ExpressCart::id() === 0 && !isset($_SESSION[ExpressCart::SESSION]);
    }

    return false;
}));

check('fromCart: copia righe e coupon, ricorda le righe', fn () => prova(static function (): bool {
    $a = articolo(1.0);
    $b = articolo(1.0);
    $vero = carrello([[$a, 1], [$b, 3]]);
    $codice = 'K'.strtoupper(substr(uniqid(), -8));
    Coupon::create([
        'code' => $codice, 'name' => 'Prova '.$codice, 'discount_type' => 'percent', 'discount_value' => '10.00',
        'applies_to_all' => 'true', 'applies_online' => 'true', 'active' => 'true',
    ]);
    Coupons::apply($vero, $codice);
    $express = ExpressCart::fromCart($vero);
    $ricordate = array_column(array_map(static fn (array $riga): array => [(int) $riga['id'], (float) $riga['quantity']], Cart::contents($vero)['items']), 1, 0);

    return $express !== $vero
        && righe($express) === [[$a, 1.0], [$b, 3.0]]
        && (string) Order::findById($express)['coupon_code'] === $codice
        && $_SESSION[ExpressCart::SESSION]['source_cart'] === $vero
        && $_SESSION[ExpressCart::SESSION]['source_items'] === $ricordate
        && righe($vero) === [[$a, 1.0], [$b, 3.0]];
}));

check('settled: toglie dal vero solo le righe ricordate', fn () => prova(static function (): bool {
    $a = articolo(1.0);
    $nuovo = articolo(1.0);
    $vero = carrello([[$a, 2]]);
    $express = ExpressCart::fromCart($vero);
    Cart::add($vero, ['product_id' => $nuovo, 'quantity' => 1]);
    ExpressCart::settled($express);

    return righe($vero) === [[$nuovo, 1.0]] && ExpressCart::id() === 0;
}));

check('fromCart: un articolo non più acquistabile → errore e niente carrello rapido', fn () => prova(static function (): bool {
    $a = articolo(1.0);
    $b = articolo(1.0);
    $vero = carrello([[$a, 1], [$b, 2]]);
    Product::update(['active' => 'false'], $b);

    try {
        ExpressCart::fromCart($vero);

        return false;
    } catch (RuntimeException $errore) {
        return $errore->getMessage() !== '' && ExpressCart::id() === 0 && righe($vero) === [[$a, 1.0], [$b, 2.0]];
    }
}));

check('fromCart: la riga di spedizione del carrello vero non conta come articolo mancante', fn () => prova(static function (): bool {
    $a = articolo(1.0);
    $vero = carrello([[$a, 2]]);
    OrderItem::create([
        'order_id' => $vero, 'type' => 'shipping', 'position' => 800, 'name' => 'Spedizione',
        'quantity' => '1.000', 'price_source' => 'base', 'tax_category_id' => 0,
    ]);
    $express = ExpressCart::fromCart($vero);

    $prodotto = (int) Cart::contents($vero)['items'][0]['id'];

    return $express !== $vero && righe($express) === [[$a, 2.0]]
        && $_SESSION[ExpressCart::SESSION]['source_items'] === [$prodotto => 2.0];
}));

check('settled: se nel vero la quantità è cresciuta, toglie solo quella copiata', fn () => prova(static function (): bool {
    $a = articolo(1.0);
    $vero = carrello([[$a, 2]]);
    $express = ExpressCart::fromCart($vero);
    Cart::setQuantity($vero, (int) Cart::contents($vero)['items'][0]['id'], 5);
    ExpressCart::settled($express);

    return righe($vero) === [[$a, 3.0]];
}));

check('settled: ordine estraneo non tocca niente', fn () => prova(static function (): bool {
    $a = articolo(1.0);
    $vero = carrello([[$a, 2]]);
    $express = ExpressCart::fromCart($vero);
    ExpressCart::settled($express + 100000);

    return righe($vero) === [[$a, 2.0]] && ExpressCart::id() === $express;
}));

check('reopen: ordine express annullato non torna nel carrello vero', fn () => prova(static function (): bool {
    $_COOKIE[CartSession::COOKIE] = bin2hex(random_bytes(32));
    $a = articolo(1.0, 20.0);
    $vero = (int) CartSession::current(true)['order']['id'];
    Cart::add($vero, ['product_id' => $a, 'quantity' => 1]);
    $express = ExpressCart::start($a, 2);
    Order::update(['stage' => 'order', 'status' => 'pending', 'email' => 'cliente@example.com', 'total' => '40.00'], $express);
    Ledger::open(['order_id' => $express, 'amount' => 40.0, 'provider' => 'stripe']);
    OnlinePayment::start($express);
    $esito = OnlinePayment::reopen();

    return $esito['outcome'] === 'cancelled'
        && $esito['removed'] === []
        && (string) Order::findById($express)['status'] === 'cancelled'
        && righe($vero) === [[$a, 1.0]]
        && ExpressCart::id() === 0
        && OnlinePayment::sessionOrder() === 0;
}));

check('current: il carrello rapido finché è un carrello, poi 0', fn () => prova(static function (): bool {
    $express = ExpressCart::start(articolo(1.0), 1);
    $prima = ExpressCart::current();
    Order::update(['stage' => 'order', 'status' => 'pending'], $express);

    return $prima === $express && ExpressCart::current() === 0 && ExpressCart::id() === $express;
}));

check('start: il carrello rapido di prima si butta, non resta abbandonato', fn () => prova(static function (): bool {
    $a = articolo(1.0);
    $primo = ExpressCart::start($a, 1);
    $secondo = ExpressCart::fromCart(carrello([[$a, 1]]));
    $buttato = Order::find(['id' => $primo, 'deleted' => 'true'], 1);

    return $secondo !== $primo && ExpressCart::id() === $secondo
        && is_array($buttato) && (int) ($buttato['id'] ?? 0) === $primo && (string) ($buttato['deleted'] ?? '') === 'true';
}));

check('discard: un ordine già piazzato non si butta', fn () => prova(static function (): bool {
    $express = ExpressCart::start(articolo(1.0), 1);
    Order::update(['stage' => 'order', 'status' => 'pending'], $express);
    ExpressCart::discard();

    return ExpressCart::id() === 0 && (string) (Order::findById($express)['stage'] ?? '') === 'order';
}));

check('settled: si dimentica anche la fatturazione del wallet', fn () => prova(static function (): bool {
    $express = ExpressCart::start(articolo(1.0), 1);
    $_SESSION[ExpressAddress::SESSION] = ['billing_name' => 'Maria', 'billing_country' => 'IT'];
    ExpressCart::settled($express);

    return !isset($_SESSION[ExpressAddress::SESSION]) && ExpressAddress::knownBilling(null) === null;
}));

PaymentProviders::reset();
summary();
