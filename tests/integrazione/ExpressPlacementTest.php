<?php
/** php tests/integrazione/ExpressPlacementTest.php — l'ordine dalla barra rapida passa da CheckoutPlacement, senza rete. */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/dns-fixture.php';

$supporto = dirname(__DIR__, 3).'/gestionale/tests/integrazione/supporto';
require $supporto.'/compra.php';
require $supporto.'/spedizioni.php';
require $supporto.'/FakeStripeHttp.php';

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutPlacement;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutRejected;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutRules;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutSummary;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressCart;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressController;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\OnlinePayment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Providers\Payments\StripeProvider;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            accendiFunzionalita(['orders', 'coupons']);
            spegniFunzionalita(['shipping']);
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    } finally {
        PaymentProviders::reset();
        StripeProvider::forget();
    }

    return $esito;
}

/** Stripe collegato con chiavi finte e questi tipi già in cache: nessuna richiesta. */
function stripeConTipi(array $tipi): FakeStripeHttp
{
    $http = FakeStripeHttp::install();
    StripeProvider::forget();
    PaymentProviders::reset();
    PaymentProviders::register(new StripeProvider((object) [
        'stripe_test' => true,
        'stripe_test_key' => 'sk_test_prova',
        'stripe_test_account_id' => 'acct_prova_test',
        'stripe_test_public_key' => 'pk_test_prova',
        'stripe_test_webhook_secret' => 'whsec_prova_test',
        'stripe_private_key' => 'sk_live_prova',
        'stripe_account_id' => 'acct_prova_live',
        'stripe_public_key' => 'pk_live_prova',
        'stripe_webhook_secret' => 'whsec_prova_live',
    ]));
    Setting::update(['stripe_methods_cache' => json_encode([
        'environment' => 'test', 'account' => 'acct_prova_test', 'types' => $tipi, 'fetched_at' => time(),
    ])], (int) Setting::current()['id']);

    return $http;
}

function metodoStripe(): int
{
    sqlModify(PaymentMethod::$table, ['active' => 'false'], 'active', 'true');

    return (int) (PaymentMethod::create([
        'code' => 'tst_'.uniqid(), 'name' => 'Carta di credito', 'provider' => 'stripe', 'timing' => 'immediate',
        'applies_online' => 'true', 'icons' => 'visa,master', 'fee_type' => 'none', 'fee_value' => '0.00',
        'fee_percent' => '0.00', 'available_for' => 'all', 'active' => 'true', 'position' => 1, 'instructions' => '',
    ])->insert_id ?? 0);
}

/** L'ospite del CLI: nessuna sessione, quindi CartSession lo vede senza accesso. */
function utenteProva(): object
{
    return CartSession::user();
}

/**
 * Un carrello senza spedizione e il post completo di un ordine con questo tipo Stripe.
 *
 * @return array{int, array<string, mixed>}
 */
function postExpress(int $metodo, string $tipo): array
{
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    spegniFunzionalita(['shipping']);
    $grezzo = [
        'email' => 'express-'.bin2hex(random_bytes(6)).'@example.com',
        'fulfillment_type' => 'shipping',
        'shipping_name' => 'Maria', 'shipping_surname' => 'Rossi',
        'shipping_country' => 'IT', 'shipping_province' => 'MI', 'shipping_city' => 'Milano',
        'shipping_cap' => '20100', 'shipping_street' => 'Via Roma', 'shipping_number' => '1',
        'shipping_phone_prefix' => '+39', 'shipping_phone' => '3330001111',
        'same_as_shipping' => '1',
        'payment_method_id' => $metodo.':'.$tipo,
    ];
    foreach (CheckoutRules::askedConsents(0) as $documento) {
        $grezzo['accept_'.$documento] = '1';
    }

    return [$cart, CheckoutRules::post($grezzo, utenteProva())];
}

function providerMethodDi(int $orderId): string
{
    return (string) (Payment::find(['order_id' => $orderId], 1)['provider_method'] ?? '');
}

check('express: totale diverso → CheckoutRejected con il riepilogo nuovo', fn () => prova(static function (): bool {
    stripeConTipi(['card', 'apple_pay']);
    [$cartId, $post] = postExpress(metodoStripe(), 'apple_pay');

    try {
        CheckoutPlacement::place($cartId, $post, utenteProva(), ['json' => true, 'expected_total' => '0.01', 'express' => true]);
    } catch (CheckoutRejected $e) {
        return $e->totalChanged() && isset($e->summary()['order']['total']);
    }

    return false;
}));

check('dati mancanti → CheckoutRejected senza riepilogo', fn () => prova(static function (): bool {
    stripeConTipi(['card', 'apple_pay']);
    [$cartId, $post] = postExpress(metodoStripe(), 'apple_pay');
    unset($post['billing_name'], $post['shipping_name']);
    $post['same_as_shipping'] = '0';

    try {
        CheckoutPlacement::place($cartId, $post, utenteProva(), ['json' => true, 'expected_total' => '0.01', 'express' => true]);
    } catch (CheckoutRejected $e) {
        return !$e->totalChanged() && $e->errors() !== [];
    }

    return false;
}));

check('express: apple_pay crea l\'ordine online con provider_method, senza chiamare Stripe', fn () => prova(static function (): bool {
    $http = stripeConTipi(['card', 'apple_pay']);
    [$cartId, $post] = postExpress(metodoStripe(), 'apple_pay');
    $totale = CheckoutSummary::payload($cartId, $post, utenteProva())['order']['total'];
    $esito = CheckoutPlacement::place($cartId, $post, utenteProva(), ['json' => true, 'expected_total' => $totale, 'express' => true]);

    return $esito['online'] === true
        && $esito['receipt']['order_id'] > 0
        && providerMethodDi($esito['receipt']['order_id']) === 'apple_pay'
        && $http->requests === [];
}));

check('express: l\'ordine in sospeso del checkout normale torna nel carrello vero', fn () => prova(static function (): bool {
    $_SESSION = [];
    $_COOKIE[CartSession::COOKIE] = bin2hex(random_bytes(32));
    stripeConTipi(['card', 'apple_pay']);
    $vecchio = articolo(1.0, 20.0);
    $normale = carrello([[$vecchio, 1]]);
    Order::update(['stage' => 'order', 'status' => 'pending', 'email' => 'cliente@example.com', 'total' => '20.00'], $normale);
    Ledger::open(['order_id' => $normale, 'amount' => 20.0, 'provider' => 'stripe']);
    // Il pagamento non è mai partito: niente da chiedere a Stripe, l'ordine si annulla.
    Payment::update(['provider_reference' => ''], (int) Payment::find(['order_id' => $normale], 1)['id']);
    OnlinePayment::remember($normale);
    [$cartId, $post] = postExpress(metodoStripe(), 'apple_pay');
    $_SESSION[ExpressCart::SESSION] = ['id' => $cartId, 'source_cart' => 0, 'source_items' => []];
    $totale = CheckoutSummary::payload($cartId, $post, utenteProva())['order']['total'];
    CheckoutPlacement::place($cartId, $post, utenteProva(), ['json' => true, 'expected_total' => $totale, 'express' => true]);
    $vero = (int) (CartSession::current(false)['order']['id'] ?? 0);
    $righe = $vero > 0 ? array_map(static fn (array $riga): array => [(int) $riga['product_id'], (float) $riga['quantity']], Cart::contents($vero)['items']) : [];
    $_SESSION = [];

    return (string) Order::findById($normale)['status'] === 'cancelled' && $righe === [[$vecchio, 1.0]];
}));

check('senza express apple_pay si rifiuta', fn () => prova(static function (): bool {
    stripeConTipi(['card', 'apple_pay']);
    [$cartId, $post] = postExpress(metodoStripe(), 'apple_pay');
    $totale = CheckoutSummary::payload($cartId, $post, utenteProva())['order']['total'];

    try {
        CheckoutPlacement::place($cartId, $post, utenteProva(), ['json' => true, 'expected_total' => $totale, 'express' => false]);
    } catch (UserError $e) {
        return $e->key() === 'order.payment_method_unavailable';
    }

    return false;
}));

check('online senza JavaScript si rifiuta prima di creare l\'ordine', fn () => prova(static function (): bool {
    stripeConTipi(['card']);
    [$cartId, $post] = postExpress(metodoStripe(), 'card');

    try {
        CheckoutPlacement::place($cartId, $post, utenteProva(), ['json' => false]);
    } catch (CheckoutRejected) {
        return false;
    } catch (RuntimeException $e) {
        return $e->getMessage() === (string) __t('ecommerce.checkout.errors.online_javascript');
    }

    return false;
}));

check('start: senza metodi rapidi non nasce nessun carrello', fn () => prova(static function (): bool {
    stripeConTipi([]);
    metodoStripe();
    $_SESSION = [];
    $prodotto = articolo(1.0, 10.0);
    $ultimo = static fn (): int => (int) (Order::find(null, 1, 'id', 'DESC')['id'] ?? 0);
    $prima = $ultimo();

    try {
        ExpressController::begin(['source' => 'product', 'product_id' => (string) $prodotto, 'quantity' => '1']);
    } catch (RuntimeException) {
        return ExpressCart::id() === 0 && $ultimo() === $prima;
    }

    return false;
}));

summary();
