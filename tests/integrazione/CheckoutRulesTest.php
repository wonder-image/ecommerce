<?php
/** php tests/integrazione/CheckoutRulesTest.php */
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
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutRules;
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

    return CheckoutRules::deliveryErrors($o, $p, true) === [];
}));

check('senza contatto manca contact', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    [$o, $p] = passo($cart, modulo());

    return CheckoutRules::deliveryErrors($o, $p, true) === ['contact'];
}));

check('un\'email non valida ferma il passo anche con una buona già sul carrello', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    passo($cart, modulo(contatto()));
    [$o, $p] = passo($cart, modulo(contatto(['email' => 'non-una-email'])));

    return CheckoutRules::deliveryErrors($o, $p, true) === ['contact'];
}));

check('senza indirizzo completo manca l\'indirizzo; il metodo che copre già il paese resta', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    [$o, $p] = passo($cart, contatto(['shipping_country' => 'IT']));

    return CheckoutRules::deliveryErrors($o, $p, true) === ['address'];
}));

check('con le spedizioni spente basta l\'indirizzo', fn () => prova(static function (): bool {
    spegniFunzionalita(['shipping']);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    [$o, $p] = passo($cart, modulo(contatto()));

    return CheckoutRules::deliveryErrors($o, $p, false) === [];
}));

check('il ritiro vuole una sede valida e non l\'indirizzo', fn () => prova(static function (): bool {
    standard();
    $sede = sede();
    $prodotto = articolo(2.0, 10.0);
    giacenzaIn($prodotto, $sede, 5);
    $cart = carrello([[$prodotto, 1]]);
    [$o, $p] = passo($cart, contatto(['fulfillment_type' => 'pickup', 'location_id' => (string) $sede]));
    $buona = CheckoutRules::deliveryErrors($o, $p, true);
    $o['location_id'] = $sede + 999;

    return $buona === [] && CheckoutRules::deliveryErrors($o, $p, true) === ['pickup_location'];
}));

check('un metodo che non copre più l\'indirizzo rimanda alla Spedizione', fn () => prova(static function (): bool {
    $milano = metodo('Solo Italia');
    listino($milano, zona('Italia', [['IT', '']]), [[5, 8.0]]);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    passo($cart, modulo(contatto(['shipping_method_id' => (string) $milano])));
    [$o, $p] = passo($cart, contatto(['shipping_country' => 'FR', 'shipping_city' => 'Paris', 'shipping_cap' => '75001', 'shipping_street' => 'Rue', 'shipping_province' => '']));

    return CheckoutRules::deliveryErrors($o, $p, true) === ['shipping_method'];
}));

check('«Uguale alla spedizione» copia nome e indirizzo', function (): bool {
    $b = CheckoutRules::billing(['same_as_shipping' => '1', 'billing_name' => 'Altro'], ['fulfillment_type' => 'shipping', 'shipping_name' => 'Ada', 'shipping_city' => 'Milano']);

    return $b['billing_name'] === 'Ada' && $b['billing_city'] === 'Milano' && $b['billing_type'] === 'private';
});

check('«Uguale» è ignorato col ritiro', function (): bool {
    $b = CheckoutRules::billing(['same_as_shipping' => '1', 'billing_name' => 'Bea'], ['fulfillment_type' => 'pickup', 'shipping_name' => 'Ada']);

    return $b['billing_name'] === 'Bea';
});

check('senza fattura i campi fiscali si svuotano e il tipo è privato', function (): bool {
    $b = CheckoutRules::billing(['billing_type' => 'business', 'billing_pi' => '123', 'billing_cf' => 'X'], []);

    return $b['billing_type'] === 'private' && $b['billing_pi'] === '' && $b['billing_cf'] === '';
});

check('il privato con fattura tiene solo il codice fiscale', function (): bool {
    $b = CheckoutRules::billing(['invoice' => '1', 'billing_type' => 'private', 'billing_cf' => 'X', 'billing_pi' => '123'], []);

    return $b['billing_type'] === 'private' && $b['billing_cf'] === 'X' && $b['billing_pi'] === '';
});

check('la fatturazione è uguale alla spedizione finché non si sceglie «diverso»', function (): bool {
    $order = ['fulfillment_type' => 'shipping', 'shipping_name' => 'Ada', 'shipping_city' => 'Milano'];
    $uguale = CheckoutRules::billing(['billing_name' => 'Altro', 'billing_city' => 'Roma'], $order);
    $diverso = CheckoutRules::billing(['same_as_shipping' => '0', 'billing_name' => 'Bea', 'billing_city' => 'Roma'], $order);

    return $uguale['billing_name'] === 'Ada' && $uguale['billing_city'] === 'Milano'
        && $diverso['billing_name'] === 'Bea' && $diverso['billing_city'] === 'Roma';
});

check('col ritiro la fatturazione prende nome e telefono della consegna se vuoti', function (): bool {
    $b = CheckoutRules::billing(['billing_city' => 'Roma', 'billing_surname' => 'Mia'], ['fulfillment_type' => 'pickup', 'shipping_name' => 'Ada', 'shipping_surname' => 'L', 'shipping_phone' => '333']);

    return $b['billing_name'] === 'Ada' && $b['billing_surname'] === 'Mia' && $b['billing_phone'] === '333' && $b['billing_city'] === 'Roma';
});

check('POST senza JavaScript col ritiro: l\'indirizzo di spedizione si svuota, il nome resta', function (): bool {
    $p = CheckoutRules::post(['fulfillment_type' => 'pickup', 'shipping_phone_prefix' => '+39', 'shipping_phone' => '333', 'shipping_name' => 'Ada', 'shipping_city' => 'Milano', 'shipping_street' => 'Via', 'email' => 'c@example.com']);

    return $p['shipping_city'] === '' && $p['shipping_street'] === '' && $p['shipping_name'] === 'Ada'
        && $p['shipping_phone'] === '333' && $p['shipping_phone_prefix'] === '+39' && $p['email'] === 'c@example.com';
});

check('il cellulare del contatto è prefisso più numero', function (): bool {
    $p = CheckoutRules::post(['shipping_phone_prefix' => '+41', 'shipping_phone' => ' 333 1234567 ']);
    $vuoto = CheckoutRules::post(['shipping_phone_prefix' => '+39', 'shipping_phone' => '']);

    return $p['phone'] === '+41 333 1234567' && $vuoto['phone'] === '';
});

check('il telefono della fatturazione è sempre quello del contatto', function (): bool {
    $b = CheckoutRules::billing(['same_as_shipping' => '0', 'billing_phone' => '999', 'billing_phone_prefix' => '+1'],
        ['fulfillment_type' => 'shipping', 'shipping_phone_prefix' => '+39', 'shipping_phone' => '333']);

    return $b['billing_phone'] === '333' && $b['billing_phone_prefix'] === '+39';
});

check('l\'anteprima riceve la fatturazione vera: le tasse seguono il suo paese', function (): bool {
    $uguale = CheckoutRules::post(modulo(['billing_country' => 'DE', 'billing_city' => 'Berlin']));
    $diverso = CheckoutRules::post(modulo(['same_as_shipping' => '0', 'billing_country' => 'DE', 'billing_city' => 'Berlin']));

    return $uguale['billing_country'] === 'IT' && $uguale['billing_city'] === 'Milano'
        && $diverso['billing_country'] === 'DE' && $diverso['billing_city'] === 'Berlin';
});

check('senza fattura l\'anteprima non riceve la partita IVA (niente tasse da azienda)', function (): bool {
    $senza = CheckoutRules::post(modulo(['billing_type' => 'business', 'billing_pi' => '12345678901']));
    $con = CheckoutRules::post(modulo(['invoice' => '1', 'billing_type' => 'business', 'billing_pi' => '12345678901', 'billing_cf' => 'X']));

    return $senza['billing_pi'] === '' && $con['billing_pi'] === '12345678901' && $con['billing_cf'] === 'X';
});

check('con l\'accesso fatto vince l\'email dell\'utente', function (): bool {
    $p = CheckoutRules::post(['email' => 'altra@example.com', 'phone' => '1'], (object) ['email' => 'ada@example.com']);
    $senza = CheckoutRules::post(['email' => 'altra@example.com'], (object) ['email' => '']);

    return $p['email'] === 'ada@example.com' && $senza['email'] === 'altra@example.com';
});

check('un metodo di pagamento che non è nell\'anteprima non vale', function (): bool {
    $preview = ['payment_methods' => ['options' => [['id' => 4, 'name' => 'Bonifico', 'manual' => true]]]];

    return (CheckoutRules::method($preview, 4)['name'] ?? '') === 'Bonifico'
        && CheckoutRules::method($preview, 5) === null
        && CheckoutRules::method([], 4) === null;
});

check('l\'indirizzo è completo con paese, città, CAP e via', fn () =>
    CheckoutRules::addressComplete(['shipping_country' => 'IT', 'shipping_city' => 'Milano', 'shipping_cap' => '20100', 'shipping_street' => 'Via'])
    && !CheckoutRules::addressComplete(['shipping_country' => 'IT', 'shipping_city' => 'Milano', 'shipping_cap' => '', 'shipping_street' => 'Via'])
);

check('fatturazione: incompleta, azienda senza partita IVA, consensi mancanti', function (): bool {
    $piena = ['billing_name' => 'Ada', 'billing_surname' => 'L', 'billing_country' => 'IT', 'billing_city' => 'Milano', 'billing_cap' => '20100', 'billing_street' => 'Via'];

    return CheckoutRules::paymentErrors([], ['billing_name' => 'Ada'], []) === ['billing']
        && CheckoutRules::paymentErrors(['invoice' => '1'], $piena + ['billing_type' => 'business', 'billing_business_name' => 'Srl', 'billing_pi' => ''], []) === ['invoice']
        && CheckoutRules::paymentErrors([], $piena, ['privacy_policy']) === ['consents']
        && CheckoutRules::paymentErrors(['accept_privacy_policy' => '1'], $piena, ['privacy_policy']) === [];
});

check('l\'ospite deve accettare tutti e due i documenti', fn () =>
    CheckoutRules::askedConsents(0) === ['privacy_policy', 'terms_conditions']
);

check('chi li ha già accettati non li rivede', fn () => prova(static function (): bool {
    $pid = 'tst-'.uniqid();
    $service = new FederatedLoginService(new EcommerceUserAccountGateway(), new FederatedIdentityRepository());
    $userId = (int) $service->authenticate(new FederatedIdentityPayload('google', $pid, $pid.'@example.com', true, 'Ada', 'Lovelace', ['sub' => $pid, 'email_verified' => true]), 'frontend', ['client'])->userId;
    $input = [];
    foreach (CheckoutRules::CONSENTS as $tipo) {
        $input['accept_'.$tipo] = '1';
        $input[$tipo.'_id'] = (string) sqlSelect('legal_documents', ['doc_type' => $tipo, 'language_code' => __l(), 'active' => 'true'], 1, 'published_at DESC, id', 'DESC')->id;
    }
    consentService()->registerBaseConsents($userId, $input, ['required_document_types' => CheckoutRules::CONSENTS, 'ip_address' => '127.0.0.1', 'user_agent' => 'prova', 'ui_surface' => 'checkout']);

    return CheckoutRules::askedConsents($userId) === [];
}));

check('un documento spento non si chiede', fn () => prova(static function (): bool {
    sqlModify('legal_documents', ['active' => 'false'], 'doc_type', 'privacy_policy');

    return CheckoutRules::askedConsents(0) === ['terms_conditions'];
}));

summary();
