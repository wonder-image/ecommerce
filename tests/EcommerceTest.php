<?php
/** php tests/EcommerceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Ecommerce\Ecommerce;

$root = dirname(__DIR__);

check('i percorsi del modulo puntano dentro il pacchetto', fn () =>
    Ecommerce::root() === $root
    && Ecommerce::manifestPath() === $root.'/module.json'
    && Ecommerce::langPath() === $root.'/lang'
    && Ecommerce::handlerPath('frontend/cart.php') === $root.'/http/frontend/cart.php'
    && Ecommerce::assetPath('js/cart.js') === $root.'/resources/assets/js/cart.js'
);

// Senza $ROOT (comandi forge, test) l'override del sito non esiste: si prende
// sempre la view del pacchetto.
check('viewPath cade sul modulo quando il sito non ha override', function () use ($root) {
    unset($GLOBALS['ROOT']);

    return Ecommerce::viewPath('pages/frontend/stato.php') === $root.'/view/pages/frontend/stato.php';
});

check('la configurazione del pacchetto elenca estensioni e slot', function () use ($root) {
    $config = require $root.'/config/module.php';

    return $config['extensions'] === [] && $config['slots'] === [];
});

summary();
