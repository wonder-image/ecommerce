<?php
/** php tests/integrazione/ExpressAddressTest.php — dal wallet al post del checkout, senza Stripe. */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressAddress;

/** @return array<string, mixed> */
function walletProva(): array
{
    return [
        'email' => 'cliente@example.com',
        'phone' => '+39 333 1234567',
        'shipping' => ['name' => 'Maria Luisa Rossi', 'address' => [
            'line1' => 'Via Roma 1', 'line2' => 'Scala B', 'city' => 'Milano', 'state' => 'MI', 'postal_code' => '20100', 'country' => 'IT',
        ]],
        'billing' => ['name' => 'Maria Luisa Rossi', 'address' => [
            'line1' => 'Corso Francia 2', 'line2' => '', 'city' => 'Torino', 'state' => 'TO', 'postal_code' => '10100', 'country' => 'IT',
        ]],
        'rate' => '7',
    ];
}

check('province: sigla', fn () => ExpressAddress::province('IT', 'mi') === 'MI');
check('province: nome con accento', fn () => ExpressAddress::province('it', 'Forli-Cesena') === 'FC' && ExpressAddress::province('IT', 'Forlì–Cesena') === 'FC');
check('province: apostrofo', fn () => ExpressAddress::province('IT', 'Valle d’Aosta') === 'AO');
check('province: sconosciuta', fn () => ExpressAddress::province('IT', 'Atlantide') === '');
check('province: vuota o paese senza province', fn () => ExpressAddress::province('IT', '') === '' && ExpressAddress::province('ZZ', 'Milano') === '');
check('partial', fn () => ExpressAddress::partial(['country' => 'it', 'state' => 'Milano', 'city' => 'Milano', 'postal_code' => '20100'], '7') === [
    'fulfillment_type' => 'shipping', 'shipping_country' => 'IT', 'shipping_province' => 'MI',
    'shipping_city' => 'Milano', 'shipping_cap' => '20100', 'shipping_method_id' => '7',
]);
check('partial: tariffa provvisoria non è un metodo', fn () => !isset(ExpressAddress::partial(['country' => 'IT'], 'pending')['shipping_method_id']));
check('partial: valori non scalari diventano vuoti', fn () => ExpressAddress::partial(['country' => ['IT'], 'city' => null], '0')['shipping_city'] === ''
    && ExpressAddress::partial(['country' => ['IT']], '0')['shipping_country'] === '');
check('post: nome, via, pagamento e consensi', function (): bool {
    $p = ExpressAddress::post(walletProva(), null, 12, 'apple_pay', ['terms']);

    return $p['shipping_name'] === 'Maria Luisa' && $p['shipping_surname'] === 'Rossi'
        && $p['shipping_street'] === 'Via Roma 1 Scala B' && $p['payment_method_id'] === '12:apple_pay'
        && $p['accept_terms'] === '1' && $p['same_as_shipping'] === '0' && $p['billing_city'] === 'Torino'
        && $p['billing_name'] === 'Maria Luisa' && $p['billing_surname'] === 'Rossi' && $p['billing_province'] === 'TO'
        && $p['email'] === 'cliente@example.com' && $p['shipping_phone'] === '+39 333 1234567';
});
check('post: un nome solo vale anche da cognome', fn () => ExpressAddress::post(['shipping' => ['name' => 'Cher'] + walletProva()['shipping']] + walletProva(), null, 1, 'link', [])['shipping_surname'] === 'Cher');
check('post: la fatturazione salvata della scheda vince sul wallet', function (): bool {
    $c = ['name' => 'Ditta', 'surname' => 'Bianchi', 'country' => 'IT', 'province' => 'BO', 'city' => 'Bologna', 'cap' => '40100', 'street' => 'Via Emilia', 'number' => '3'];
    $p = ExpressAddress::post(walletProva(), $c, 1, 'link', []);

    return $p['billing_city'] === 'Bologna' && $p['billing_number'] === '3' && $p['billing_name'] === 'Ditta';
});
check('post: una fatturazione salvata a metà non vale', function (): bool {
    $c = ['name' => 'Ditta', 'surname' => 'Bianchi', 'country' => 'IT', 'city' => '', 'cap' => '40100', 'street' => 'Via Emilia'];

    return ExpressAddress::post(walletProva(), $c, 1, 'link', [])['billing_city'] === 'Torino';
});
check('post: senza indirizzo di fatturazione si usa la spedizione', function (): bool {
    $w = walletProva();
    $w['billing']['address'] = [];

    return ExpressAddress::post($w, null, 1, 'link', [])['same_as_shipping'] === '1';
});

summary();
