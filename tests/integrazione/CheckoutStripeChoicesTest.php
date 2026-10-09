<?php
/** php tests/integrazione/CheckoutStripeChoicesTest.php — le scelte Stripe dal gestionale alla pagina, senza rete. */
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

use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutRules;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutSummary;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Providers\Payments\StripeProvider;
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

/**
 * Stripe collegato con chiavi finte e questi tipi già in cache: nessuna
 * lettura, quindi nessuna richiesta. Il finto torna per i controlli.
 */
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

check('le scelte Stripe del gestionale arrivano alla pagina con chiave, tipo e tipi dell\'intento', fn () => prova(static function (): bool {
    $http = stripeConTipi(['card', 'klarna', 'link']);
    $id = metodoStripe();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    spegniFunzionalita(['shipping']);

    $post = CheckoutRules::post(['payment_method_id' => $id.':klarna']);
    $payload = CheckoutSummary::payload($cart, $post);
    $voci = array_values(array_filter((array) $payload['payment_methods']['options'], static fn (array $v): bool => (int) $v['id'] === $id));
    $per = static fn (string $key): ?array => current(array_filter($voci, static fn (array $v): bool => $v['key'] === $key)) ?: null;

    return $http->requests === []
        && array_column($voci, 'key') === [(string) $id, $id.':klarna', $id.':link']
        && $per((string) $id)['stripe_method_type'] === 'card'
        && $per((string) $id)['payment_method_types'] === ['card']
        && $per($id.':klarna')['stripe_method_type'] === 'klarna'
        && $per($id.':klarna')['payment_method_types'] === ['klarna']
        && $per($id.':link')['payment_method_types'] === ['link']
        && $per($id.':klarna')['panel'] === ''
        && (int) $payload['payment_methods']['selected'] === $id;
}));

check('un metodo non Stripe resta con la chiave nuda e senza tipi', fn () => prova(static function (): bool {
    stripeConTipi(['card']);
    $bonifico = (int) (PaymentMethod::create([
        'code' => 'tst_'.uniqid(), 'name' => 'Bonifico di prova', 'provider' => 'bank_transfer', 'timing' => 'deferred',
        'fee_type' => 'none', 'fee_value' => '0.00', 'fee_percent' => '0.00', 'available_for' => 'all',
        'active' => 'true', 'position' => 1, 'instructions' => 'Istruzioni di prova',
    ])->insert_id ?? 0);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    spegniFunzionalita(['shipping']);

    $payload = CheckoutSummary::payload($cart, CheckoutRules::post(['payment_method_id' => (string) $bonifico]));
    $voce = current(array_filter((array) $payload['payment_methods']['options'], static fn (array $v): bool => (int) $v['id'] === $bonifico)) ?: [];

    return ($voce['key'] ?? null) === (string) $bonifico
        && ($voce['stripe_method_type'] ?? null) === ''
        && ($voce['payment_method_types'] ?? null) === [];
}));

summary();
