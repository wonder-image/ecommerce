<?php
/** php tests/CheckoutFormTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutForm;

check('il modulo diventa i dati del gestionale, con la consegna e gli indirizzi', function () {
    $dati = CheckoutForm::data([
        'email' => ' a@example.com ', 'phone' => '333', 'payment_method_id' => '7',
        'fulfillment_type' => 'pickup', 'shipping_method_id' => '3', 'location_id' => '5',
        'customer_note' => 'ciao', 'billing_city' => 'Milano', 'shipping_city' => 'Roma',
        'csrf_token' => 'x',
    ]);

    return $dati['email'] === 'a@example.com'
        && $dati['payment_method_id'] === 7
        && $dati['fulfillment_type'] === 'pickup'
        && $dati['shipping_method_id'] === 3
        && $dati['location_id'] === 5
        && $dati['billing'] === ['city' => 'Milano']
        && $dati['shipping'] === ['city' => 'Roma']; // niente `method_id` nell'indirizzo
});

check('una consegna sconosciuta o assente è spedizione', fn () =>
    CheckoutForm::data(['fulfillment_type' => 'none'])['fulfillment_type'] === 'shipping'
    && CheckoutForm::data([])['fulfillment_type'] === 'shipping');

check('i valori array o negativi non rompono: diventano zero o vuoto', function () {
    $dati = CheckoutForm::data(['shipping_method_id' => ['1'], 'location_id' => '-4', 'billing_city' => ['x'], 'email' => ['a']]);

    return $dati['shipping_method_id'] === 0 && $dati['location_id'] === 0
        && $dati['billing'] === [] && $dati['email'] === '';
});

check('l\'utente riempie email e cellulare mancanti', function () {
    $dati = CheckoutForm::data([], (object) ['email' => 'u@example.com', 'phone' => '999']);

    return $dati['email'] === 'u@example.com' && $dati['phone'] === '999';
});

check('un provider è manuale per bonifico, contanti e «manual»; Stripe no', fn () =>
    CheckoutForm::isManual(['provider' => 'bank_transfer'])
    && CheckoutForm::isManual(['provider' => 'cash'])
    && CheckoutForm::isManual(['provider' => 'manual'])
    && !CheckoutForm::isManual(['provider' => 'stripe']));

summary();
