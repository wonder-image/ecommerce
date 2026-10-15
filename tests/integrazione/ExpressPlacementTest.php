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
use Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressCheckout;
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

check('dal modulo (senza express) apple_pay si rifiuta solo se è spento nel conto', fn () => prova(static function (): bool {
    stripeConTipi(['card']);
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

check('riquadro: la scritta dice che si accettano condizioni e privacy', fn () => prova(static function (): bool {
    stripeConTipi(['card', 'apple_pay']);
    metodoStripe();
    Setting::update(['checkout_guest' => 'true'], (int) Setting::current()['id']);
    $html = ExpressCheckout::render('product', ['total' => '10.00', 'currency' => 'EUR'], articolo(1.0, 10.0));
    $testo = static fn (string $chiave): string => htmlspecialchars((string) __t('ecommerce.checkout.'.$chiave), ENT_QUOTES);

    return $html !== ''
        && str_contains($html, $testo('express_terms_link'))
        && str_contains($html, $testo('express_privacy_join'))
        && str_contains($html, $testo('express_privacy_link'))
        && !str_contains($html, 'ecommerce.checkout.express_privacy');
}));

/** Il riquadro di una fonte, con Stripe e un metodo rapido accesi. */
function riquadro(string $fonte): string
{
    stripeConTipi(['card', 'apple_pay']);
    metodoStripe();
    Setting::update(['checkout_guest' => 'true'], (int) Setting::current()['id']);

    return ExpressCheckout::render($fonte, ['total' => '10.00', 'currency' => 'EUR'], $fonte === 'product' ? articolo(1.0, 10.0) : 0);
}

$tradotto = static fn (string $chiave): string => htmlspecialchars((string) __t('ecommerce.checkout.'.$chiave), ENT_QUOTES);

check('riquadro: resta invisibile finché Stripe non mostra i bottoni', fn () => prova(static function (): bool {
    $html = riquadro('checkout');

    return preg_match('~<div[^>]*data-express=[^>]*data-express-loading~', $html) === 1;
}));

check('riquadro: titolo (non nel carrello), bottoni, condizioni piccole e staccate, poi «OPPURE» nel checkout e nel carrello', fn () => prova(static function () use ($tradotto): bool {
    foreach (['checkout', 'cart'] as $fonte) {
        $html = riquadro($fonte);
        $titolo = strpos($html, '>'.$tradotto('express').'<');
        $bottoni = strpos($html, 'data-express-element');
        $condizioni = strpos($html, '<p class="text-xsmall a-c mt-3">'.$tradotto('express_terms'));
        $oppure = strpos($html, '<p class="wi-express__or mt-5">'.$tradotto('or').'</p>');
        // Nel carrello i bottoni stanno nel riepilogo senza titolo.
        if ($fonte === 'cart' ? $titolo !== false : ($titolo === false || $titolo > $bottoni)) {
            return false;
        }
        if ($bottoni === false || $condizioni === false || $oppure === false
            || !($bottoni < $condizioni && $condizioni < $oppure)) {
            return false;
        }
    }

    return true;
}));

check('riquadro del prodotto: «Oppure acquista ora con», niente «OPPURE»', fn () => prova(static function () use ($tradotto): bool {
    $html = riquadro('product');

    return str_contains($html, '>'.$tradotto('express_product').'<')
        && !str_contains($html, 'wi-express__or');
}));

check('riquadro: nel carrello e sul prodotto i bottoni stanno in fila (nel carrello due per riga) e Google Pay mostra solo il logo', fn () => prova(static function (): bool {
    foreach (['cart' => '"layout":{"maxColumns":2,"overflow":"never"}', 'product' => '"layout":{"overflow":"never"}'] as $fonte => $layout) {
        $html = html_entity_decode(riquadro($fonte), ENT_QUOTES);
        if (!str_contains($html, $layout) || !str_contains($html, '"buttonType":{"googlePay":"plain"}')) {
            return false;
        }
    }
    $checkout = html_entity_decode(riquadro('checkout'), ENT_QUOTES);

    return str_contains($checkout, '"layout":null') && str_contains($checkout, '"buttonType":null');
}));

check('riquadro: carrello e checkout dicono cosa preparano mentre aspettano i bottoni, il prodotto no', fn () => prova(static function (): bool {
    $attesa = static fn (string $chiave): string => 'data-express-wait="'.htmlspecialchars((string) __t('ecommerce.checkout.'.$chiave), ENT_QUOTES).'"';

    return str_contains(riquadro('cart'), $attesa('express_wait_cart'))
        && str_contains(riquadro('checkout'), $attesa('express_wait_checkout'))
        && !str_contains(riquadro('product'), 'data-express-wait');
}));

check('prodotto: il foglio si apre col totale di quantità e variante scelte, non di un pezzo', fn () => prova(static function (): bool {
    $js = (string) file_get_contents(dirname(__DIR__, 2).'/resources/assets/js/checkout.js');
    $scheda = (string) file_get_contents(dirname(__DIR__, 2).'/view/pages/frontend/product.php');

    // L'importo segue la form «Aggiungi» e il prezzo della variante che la scheda scrive sul riquadro.
    return str_contains($js, 'productAmount()')
        && str_contains($js, "amount: this.productForm ? this.productAmount() : this.config.amount")
        && str_contains($js, "this.elements?.update({ amount: this.productAmount() })")
        && str_contains($js, "'express:amount'")
        && str_contains($scheda, 'dataset.expressUnit = Math.round(offer.price * 100)')
        && str_contains($scheda, "new Event('express:amount')");
}));

summary();
