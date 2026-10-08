<?php
/** php tests/OptionQueryTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Catalog\OptionQuery;

$gruppi = [
    ['id' => 3, 'slug' => 'taglia', 'values' => [['id' => '31', 'label' => 'S'], ['id' => '32', 'label' => 'M']]],
    ['id' => 4, 'slug' => 'materiale', 'values' => [['id' => '41', 'label' => 'Cotone'], ['id' => '42', 'label' => 'Lino']]],
];
$offerte = [
    ['product_id' => 101, 'available' => true, 'attributes' => ['3' => '31', '4' => '41']],
    ['product_id' => 102, 'available' => false, 'attributes' => ['3' => '31', '4' => '42']],
    ['product_id' => 103, 'available' => false, 'attributes' => ['3' => '32', '4' => '41']],
    ['product_id' => 104, 'available' => true, 'attributes' => ['3' => '32', '4' => '42']],
];

check('la query completa sceglie quell\'opzione, anche se non è disponibile', fn () =>
    OptionQuery::match($gruppi, $offerte, ['taglia' => 's', 'materiale' => 'lino']) === 102
);

check('la query parziale sceglie la prima disponibile che combacia', fn () =>
    OptionQuery::match($gruppi, $offerte, ['taglia' => 'm']) === 104
);

check('chiavi e valori sconosciuti si ignorano', fn () =>
    OptionQuery::match($gruppi, $offerte, ['colore' => 'blu']) === null
    && OptionQuery::match($gruppi, $offerte, ['taglia' => 's', 'colore' => 'blu']) === 101
    && OptionQuery::match($gruppi, $offerte, ['taglia' => 'xl']) === null
    && OptionQuery::match($gruppi, $offerte, []) === null
);

check('una combinazione che non esiste non sceglie niente', fn () =>
    OptionQuery::match($gruppi, [$offerte[0]], ['taglia' => 'm']) === null
);

check('si accetta l\'id del valore al posto dello slug', fn () =>
    OptionQuery::match($gruppi, $offerte, ['taglia' => '32', 'materiale' => '41']) === 103
);

check('maiuscole, liste e valori vuoti come li scrive una persona', fn () =>
    OptionQuery::match($gruppi, $offerte, ['Taglia' => 'S']) === 101
    && OptionQuery::match($gruppi, $offerte, ['taglia' => ['m', 's']]) === 104
    && OptionQuery::match($gruppi, $offerte, ['taglia' => '']) === null
);

check('lo slug dell\'etichetta è quello degli altri slug, entità comprese', fn () =>
    OptionQuery::slug('Blu Notte') === 'blu-notte'
    && OptionQuery::slug('Novit&agrave;') === 'novita'
    && OptionQuery::slug('XL_2') === 'xl-2'
);

summary();
