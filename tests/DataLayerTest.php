<?php
/** php tests/DataLayerTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Tracking\DataLayer;

$page = ['type' => 'product', 'language' => 'it', 'currency' => 'EUR'];
$event = ['event' => 'view_item', 'ecommerce' => ['currency' => 'EUR', 'items' => [['item_id' => 'abc']]]];

check('con un cliente loggato lo script non manda user: lo manda il core, con l\'id cifrato', function () use ($page, $event) {
    $script = DataLayer::script($page, $event, 7);

    return !str_contains($script, '"user"')
        && !str_contains($script, '"id":7')
        && !str_contains($script, '7');
});

check('page e gli eventi ecommerce restano, con il reset prima di ogni evento', function () use ($page, $event) {
    $script = DataLayer::script($page, [$event, ['event' => 'view_item_list']], 7);

    return str_contains($script, 'window.dataLayer = window.dataLayer || [];')
        && str_contains($script, 'window.dataLayer.push({"page":{"type":"product","language":"it","currency":"EUR"}});')
        && substr_count($script, 'window.dataLayer.push({ ecommerce: null });') === 2
        && str_contains($script, '"event":"view_item"')
        && str_contains($script, '"event":"view_item_list"');
});

check('lo script è lo stesso per l\'ospite e per il cliente loggato', fn () =>
    DataLayer::script($page, $event, 7) === DataLayer::script($page, $event)
    && DataLayer::script($page, $event, 7) === DataLayer::script($page, $event, 0)
);

summary();
