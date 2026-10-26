<?php
/**
 * php tests/integrazione/CheckoutSnapshotTest.php
 *
 * La GET del checkout riusa l'anteprima se il carrello non è cambiato; il POST
 * che crea l'ordine ricalcola sempre. Testimone del ricalcolo: last_activity_at,
 * che Cart::recalculate riscrive. Le classi dell'ecommerce sono quelle di questo
 * pacchetto, non quelle installate nel sito.
 */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');
const PACCHETTO = __DIR__.'/../..';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'Wonder\\Plugin\\Ecommerce\\';
    if (str_starts_with($class, $prefix)) {
        $file = PACCHETTO.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
}, true, true);
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/dns-fixture.php';

// Gli aiuti del gestionale che il sito carica davvero, ovunque stia.
$supporto = dirname((string) (new ReflectionClass(Wonder\Plugin\Gestionale\Support\Orders\Checkout::class))->getFileName(), 4).'/tests/integrazione/supporto';
require $supporto.'/compra.php';
require $supporto.'/spedizioni.php';
require $supporto.'/FakeStripeHttp.php';

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutPlacement;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutRejected;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutRules;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutSnapshot;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Providers\Payments\StripeProvider;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

const VECCHIO = '2000-01-01 00:00:00';

function prova(callable $corpo): mixed
{
    $esito = null;
    $_SESSION = [];

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
        $_SESSION = [];
        unset($_COOKIE[CartSession::COOKIE]);
        PaymentProviders::reset();
        StripeProvider::forget();
    }

    return $esito;
}

/** Il carrello di un ospite con il cookie del browser, senza spedizione. */
function carrelloOspite(float $prezzo = 10.0): array
{
    $prodotto = articolo(2.0, $prezzo);
    $cart = carrello([[$prodotto, 1]]);
    spegniFunzionalita(['shipping']);
    $token = bin2hex(random_bytes(32));
    Order::update(['cart_token' => $token], $cart);
    $_COOKIE[CartSession::COOKIE] = $token;

    return [$cart, $prodotto];
}

/** Il post della pagina come lo costruisce il controller. */
function postPagina(int $metodo, string $tipo = ''): array
{
    $grezzo = [
        'email' => 'snapshot@example.com',
        'fulfillment_type' => 'shipping',
        'shipping_name' => 'Maria', 'shipping_surname' => 'Rossi',
        'shipping_country' => 'IT', 'shipping_province' => 'MI', 'shipping_city' => 'Milano',
        'shipping_cap' => '20100', 'shipping_street' => 'Via Roma', 'shipping_number' => '1',
        'shipping_phone_prefix' => '+39', 'shipping_phone' => '3330001111',
        'same_as_shipping' => '1',
        'payment_method_id' => $tipo === '' ? (string) $metodo : $metodo.':'.$tipo,
    ];
    foreach (CheckoutRules::askedConsents(0) as $documento) {
        $grezzo['accept_'.$documento] = '1';
    }

    return CheckoutRules::post($grezzo, CartSession::user());
}

/** Una GET della pagina: il controller fa così con peek() e lo stesso post. */
function pagina(array $post): array
{
    return CheckoutSnapshot::page(CartSession::peek(), $post, CartSession::user(), true, static fn (array $cart): array => $post);
}

function invecchia(int $cart): void
{
    Order::update(['last_activity_at' => VECCHIO], $cart);
}

function attivita(int $cart): string
{
    return (string) (Order::findById($cart)['last_activity_at'] ?? '');
}

function bonifico(): int
{
    return (int) (PaymentMethod::create([
        'code' => 'tst_'.uniqid(), 'name' => 'Bonifico di prova', 'provider' => 'bank_transfer', 'timing' => 'deferred',
        'fee_type' => 'none', 'fee_value' => '0.00', 'fee_percent' => '0.00', 'available_for' => 'all',
        'active' => 'true', 'position' => 1, 'instructions' => 'Istruzioni di prova',
    ])->insert_id ?? 0);
}

function stripeApplePay(): int
{
    FakeStripeHttp::install();
    StripeProvider::forget();
    PaymentProviders::reset();
    PaymentProviders::register(new StripeProvider((object) [
        'stripe_test' => true,
        'stripe_test_key' => 'sk_test_prova', 'stripe_test_account_id' => 'acct_prova_test',
        'stripe_test_public_key' => 'pk_test_prova', 'stripe_test_webhook_secret' => 'whsec_prova_test',
        'stripe_private_key' => 'sk_live_prova', 'stripe_account_id' => 'acct_prova_live',
        'stripe_public_key' => 'pk_live_prova', 'stripe_webhook_secret' => 'whsec_prova_live',
    ]));
    Setting::update(['stripe_methods_cache' => json_encode([
        'environment' => 'test', 'account' => 'acct_prova_test', 'types' => ['card', 'apple_pay'], 'fetched_at' => time(),
    ])], (int) Setting::current()['id']);
    sqlModify(PaymentMethod::$table, ['active' => 'false'], 'active', 'true');

    return (int) (PaymentMethod::create([
        'code' => 'tst_'.uniqid(), 'name' => 'Carta di credito', 'provider' => 'stripe', 'timing' => 'immediate',
        'applies_online' => 'true', 'icons' => 'visa,master', 'fee_type' => 'none', 'fee_value' => '0.00',
        'fee_percent' => '0.00', 'available_for' => 'all', 'active' => 'true', 'position' => 1, 'instructions' => '',
    ])->insert_id ?? 0);
}

check('GET con righe invariate: riusa l\'anteprima, nessun ricalcolo', fn () => prova(static function (): bool {
    [$cart] = carrelloOspite();
    $post = postPagina(bonifico());
    $prima = pagina($post);
    invecchia($cart);
    $seconda = pagina($post);

    return $prima['reused'] === false && is_array($prima['summary'])
        && $seconda['reused'] === true
        && $seconda['summary']['order']['total'] === $prima['summary']['order']['total']
        && attivita($cart) === VECCHIO;
}));

check('GET dopo una modifica delle righe: ricalcola', fn () => prova(static function (): bool {
    [$cart] = carrelloOspite();
    $post = postPagina(bonifico());
    $prima = pagina($post);
    $riga = (int) Cart::contents($cart)['items'][0]['id'];
    Cart::setQuantity($cart, $riga, 2);
    invecchia($cart);
    $seconda = pagina($post);

    return $prima['reused'] === false && $seconda['reused'] === false
        && attivita($cart) !== VECCHIO
        && (float) $seconda['summary']['order']['total'] === 2 * (float) $prima['summary']['order']['total'];
}));

check('GET dopo un cambio di prezzo in anagrafica: ricalcola', fn () => prova(static function (): bool {
    [$cart, $prodotto] = carrelloOspite();
    $post = postPagina(bonifico());
    pagina($post);
    Product::update(['price' => '20.00'], $prodotto);
    invecchia($cart);
    $seconda = pagina($post);

    return $seconda['reused'] === false && attivita($cart) !== VECCHIO
        && (float) $seconda['summary']['order']['total'] === 20.0;
}));

check('GET con un input diverso o anteprima scaduta: ricalcola', fn () => prova(static function (): bool {
    [$cart] = carrelloOspite();
    $post = postPagina(bonifico());
    pagina($post);
    $diverso = pagina(['customer_note' => 'altro'] + $post)['reused'] === false;
    pagina($post);
    $_SESSION['ecommerce_checkout_snapshot']['at'] = time() - CheckoutSnapshot::TTL;
    invecchia($cart);
    $scaduta = pagina($post);

    return $diverso && $scaduta['reused'] === false && attivita($cart) !== VECCHIO;
}));

check('con un coupon l\'impronta è vuota: non si riusa', fn () => prova(static function (): bool {
    [$cart] = carrelloOspite();
    $contenuto = Cart::contents($cart);
    $contenuto['order']['coupon_code'] = 'QUALSIASI';

    return CheckoutSnapshot::fingerprint(Cart::contents($cart), []) !== ''
        && CheckoutSnapshot::fingerprint($contenuto, []) === '';
}));

check('POST che crea l\'ordine: ricalcola anche con l\'anteprima in sessione', fn () => prova(static function (): bool {
    [$cart, $prodotto] = carrelloOspite();
    $post = postPagina(stripeApplePay(), 'apple_pay');
    $vecchio = pagina($post)['summary']['order']['total'];
    // Il prezzo cambia dopo la pagina (una campagna finita, un listino nuovo).
    Product::update(['price' => '20.00'], $prodotto);

    try {
        CheckoutPlacement::place($cart, $post, CartSession::user(), ['json' => true, 'expected_total' => $vecchio, 'express' => true]);
    } catch (CheckoutRejected $e) {
        $nuovo = $e->summary()['order']['total'] ?? null;
        if (!$e->totalChanged() || (float) $nuovo !== 20.0) {
            return false;
        }
        $esito = CheckoutPlacement::place($cart, $post, CartSession::user(), ['json' => true, 'expected_total' => $nuovo, 'express' => true]);

        return (float) $vecchio === 10.0
            && (float) (Order::findById((int) $esito['receipt']['order_id'])['total'] ?? 0) === 20.0;
    }

    return false;
}));

summary();
