<?php
/** php tests/ProductUrlTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductUrl;

check('modello, variante sotto il modello, query in coda', fn () =>
    ProductUrl::make('maglietta-girocollo') === '/prodotto/maglietta-girocollo/'
    && ProductUrl::make('maglietta-girocollo', 'blu') === '/prodotto/maglietta-girocollo/blu/'
    && ProductUrl::make('maglietta-girocollo', 'blu', ['taglia' => 's', 'materiale' => 'cotone'])
        === '/prodotto/maglietta-girocollo/blu/?taglia=s&materiale=cotone'
);

check('la variante scheletro non lascia una barra doppia', fn () =>
    ProductUrl::make('maglietta', '') === '/prodotto/maglietta/'
    && ProductUrl::make('maglietta', '  ') === '/prodotto/maglietta/'
);

check('della query restano i valori semplici non vuoti, di una lista il primo', fn () =>
    ProductUrl::query(['taglia' => '', 'colore' => ['m', 's'], 'x' => ['a' => ['b']], 'q' => ' a b ', 3 => 'z'])
        === ['colore' => 'm', 'q' => 'a b']
    && ProductUrl::make('m', '', ['q' => 'a b']) === '/prodotto/m/?q=a%20b'
    && ProductUrl::make('m', '', ['taglia' => '']) === '/prodotto/m/'
);

check('lo slug della variante va nell\'indirizzo solo se le varianti visibili sono più di una', fn () =>
    ProductUrl::variantSlugFor(['slug' => 'blu'], 1) === ''
    && ProductUrl::variantSlugFor(['slug' => 'blu'], 2) === 'blu'
    && ProductUrl::variantSlugFor(['slug' => ''], 3) === ''
    && ProductUrl::variantSlugFor([], 3) === ''
);

summary();
