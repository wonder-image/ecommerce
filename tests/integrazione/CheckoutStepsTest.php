<?php
/** php tests/integrazione/CheckoutStepsTest.php */
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

use Wonder\Auth\Federated\FederatedIdentityPayload;
use Wonder\Auth\Federated\FederatedIdentityRepository;
use Wonder\Auth\Federated\FederatedLoginService;
use Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceUserAccountGateway;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutSteps;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutSummary;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Seeding\ShippingDemo;
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

function contatto(array $extra = []): array
{
    return $extra + ['email' => 'c@example.com', 'phone' => '333 1234567', 'shipping_name' => 'Ada', 'shipping_surname' => 'Lovelace'];
}

/** Anteprima e carrello riletto, come fa il controller. */
function passo(int $cart, array $post): array
{
    $p = CheckoutSummary::payload($cart, $post);

    return [(array) Order::findById($cart), $p];
}

check('la Spedizione completa non ha errori', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    [$o, $p] = passo($cart, modulo(contatto()));

    return CheckoutSteps::shippingErrors($o, $p, true) === [] && CheckoutSteps::shippingComplete($o, $p, true);
}));

check('senza contatto manca contact', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    [$o, $p] = passo($cart, modulo());

    return CheckoutSteps::shippingErrors($o, $p, true) === ['contact'];
}));

check('un\'email non valida ferma il passo anche con una buona già sul carrello', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    passo($cart, modulo(contatto()));
    [$o, $p] = passo($cart, modulo(contatto(['email' => 'non-una-email'])));

    return CheckoutSteps::shippingErrors($o, $p, true) === ['contact'];
}));

check('senza indirizzo completo manca l\'indirizzo; il metodo che copre già il paese resta', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    [$o, $p] = passo($cart, contatto(['shipping_country' => 'IT']));

    return CheckoutSteps::shippingErrors($o, $p, true) === ['address'];
}));

check('con le spedizioni spente basta l\'indirizzo', fn () => prova(static function (): bool {
    spegniFunzionalita(['shipping']);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    [$o, $p] = passo($cart, modulo(contatto()));

    return CheckoutSteps::shippingErrors($o, $p, false) === [];
}));

check('il ritiro vuole una sede valida e non l\'indirizzo', fn () => prova(static function (): bool {
    standard();
    $sede = sede();
    $prodotto = articolo(2.0, 10.0);
    giacenzaIn($prodotto, $sede, 5);
    $cart = carrello([[$prodotto, 1]]);
    [$o, $p] = passo($cart, contatto(['fulfillment_type' => 'pickup', 'location_id' => (string) $sede]));
    $buona = CheckoutSteps::shippingErrors($o, $p, true);
    $o['location_id'] = $sede + 999;

    return $buona === [] && CheckoutSteps::shippingErrors($o, $p, true) === ['pickup_location'];
}));

check('un metodo che non copre più l\'indirizzo rimanda alla Spedizione', fn () => prova(static function (): bool {
    $milano = metodo('Solo Italia');
    listino($milano, zona('Italia', [['IT', '']]), [[5, 8.0]]);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    passo($cart, modulo(contatto(['shipping_method_id' => (string) $milano])));
    [$o, $p] = passo($cart, contatto(['shipping_country' => 'FR', 'shipping_city' => 'Paris', 'shipping_cap' => '75001', 'shipping_street' => 'Rue', 'shipping_province' => '']));

    return CheckoutSteps::shippingErrors($o, $p, true) === ['shipping_method'];
}));

check('fromCart legge contatto e indirizzi ma non i totali', function (): bool {
    $v = CheckoutSteps::fromCart(['email' => 'c@example.com', 'shipping_city' => 'Milano', 'shipping_total' => '8.00', 'id' => 5]);

    return $v['email'] === 'c@example.com' && $v['shipping_city'] === 'Milano'
        && !array_key_exists('shipping_total', $v) && !array_key_exists('id', $v) && $v['billing_city'] === '';
});

check('«Uguale alla spedizione» copia nome e indirizzo', function (): bool {
    $b = CheckoutSteps::billing(['same_as_shipping' => '1', 'billing_name' => 'Altro'], ['fulfillment_type' => 'shipping', 'shipping_name' => 'Ada', 'shipping_city' => 'Milano']);

    return $b['billing_name'] === 'Ada' && $b['billing_city'] === 'Milano' && $b['billing_type'] === 'private';
});

check('«Uguale» è ignorato col ritiro', function (): bool {
    $b = CheckoutSteps::billing(['same_as_shipping' => '1', 'billing_name' => 'Bea'], ['fulfillment_type' => 'pickup', 'shipping_name' => 'Ada']);

    return $b['billing_name'] === 'Bea';
});

check('senza fattura i campi fiscali si svuotano e il tipo è privato', function (): bool {
    $b = CheckoutSteps::billing(['billing_type' => 'business', 'billing_pi' => '123', 'billing_cf' => 'X'], []);

    return $b['billing_type'] === 'private' && $b['billing_pi'] === '' && $b['billing_cf'] === '';
});

check('il privato con fattura tiene solo il codice fiscale', function (): bool {
    $b = CheckoutSteps::billing(['invoice' => '1', 'billing_type' => 'private', 'billing_cf' => 'X', 'billing_pi' => '123'], []);

    return $b['billing_type'] === 'private' && $b['billing_cf'] === 'X' && $b['billing_pi'] === '';
});

check('fatturazione: incompleta, azienda senza partita IVA, consensi mancanti', function (): bool {
    $piena = ['billing_name' => 'Ada', 'billing_surname' => 'L', 'billing_country' => 'IT', 'billing_city' => 'Milano', 'billing_cap' => '20100', 'billing_street' => 'Via'];

    return CheckoutSteps::paymentErrors([], ['billing_name' => 'Ada'], []) === ['billing']
        && CheckoutSteps::paymentErrors(['invoice' => '1'], $piena + ['billing_type' => 'business', 'billing_business_name' => 'Srl', 'billing_pi' => ''], []) === ['invoice']
        && CheckoutSteps::paymentErrors([], $piena, ['privacy_policy']) === ['consents']
        && CheckoutSteps::paymentErrors(['accept_privacy_policy' => '1'], $piena, ['privacy_policy']) === [];
});

check('l\'ospite deve accettare tutti e due i documenti', fn () =>
    CheckoutSteps::askedConsents(0) === ['privacy_policy', 'terms_conditions']
);

check('chi li ha già accettati non li rivede', fn () => prova(static function (): bool {
    $pid = 'tst-'.uniqid();
    $service = new FederatedLoginService(new EcommerceUserAccountGateway(), new FederatedIdentityRepository());
    $userId = (int) $service->authenticate(new FederatedIdentityPayload('google', $pid, $pid.'@example.com', true, 'Ada', 'Lovelace', ['sub' => $pid, 'email_verified' => true]), 'frontend', ['client'])->userId;
    $input = [];
    foreach (CheckoutSteps::CONSENTS as $tipo) {
        $input['accept_'.$tipo] = '1';
        $input[$tipo.'_id'] = (string) sqlSelect('legal_documents', ['doc_type' => $tipo, 'language_code' => __l(), 'active' => 'true'], 1, 'published_at DESC, id', 'DESC')->id;
    }
    consentService()->registerBaseConsents($userId, $input, ['required_document_types' => CheckoutSteps::CONSENTS, 'ip_address' => '127.0.0.1', 'user_agent' => 'prova', 'ui_surface' => 'checkout']);

    return CheckoutSteps::askedConsents($userId) === [];
}));

check('un documento spento non si chiede', fn () => prova(static function (): bool {
    sqlModify('legal_documents', ['active' => 'false'], 'doc_type', 'privacy_policy');

    return CheckoutSteps::askedConsents(0) === ['terms_conditions'];
}));

summary();
