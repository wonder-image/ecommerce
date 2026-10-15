<?php
/** php tests/SubscriptionsPageTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\PlanCatalog;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionBilling;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionTracking;

$root = dirname(__DIR__);
$piani = PlanCatalog::present([
    [
        'plan' => ['code' => 'pln_pro1234', 'name' => 'Pro &amp; Team', 'description' => '<p>Per chi lavora tutti i giorni</p>'],
        'prices' => [
            ['code' => 'ppr_mese123', 'name' => 'Mensile', 'price' => '24.90', 'sale_price' => '19.90', 'billing_interval' => 'month', 'term_months' => 1, 'trial_days' => 14, 'commitment_months' => 0],
            ['code' => 'ppr_anno123', 'name' => 'Annuale', 'price' => '249.00', 'sale_price' => '0.00', 'billing_interval' => 'year', 'term_months' => 12, 'trial_days' => 0, 'commitment_months' => 12],
        ],
        'features' => [['feature_key' => 'max_progetti', 'feature_value' => '20']],
    ],
    [
        'plan' => ['code' => 'pln_base123', 'name' => 'Base', 'description' => ''],
        'prices' => [
            ['code' => 'ppr_tri1234', 'name' => 'Trimestrale', 'price' => '27.00', 'billing_interval' => 'month', 'term_months' => 3],
        ],
        'features' => [],
    ],
], static fn (string $code): string => '/abbonamenti/'.$code.'/');

check('i piani pronti per la vista: prezzo mostrato, barrato, frequenza e indirizzo', function () use ($piani) {
    $mese = $piani[0]['prices'][0];
    $anno = $piani[0]['prices'][1];
    $tri = $piani[1]['prices'][0];

    return $piani[0]['name'] === 'Pro & Team' && $piani[0]['description'] === 'Per chi lavora tutti i giorni'
        && $mese['amount'] === 19.9 && $mese['regular'] === 24.9 && $mese['unit'] === 'month' && $mese['count'] === 1
        && $mese['trial_days'] === 14 && $mese['url'] === '/abbonamenti/ppr_mese123/'
        && $anno['amount'] === 249.0 && $anno['regular'] === null && $anno['unit'] === 'year' && $anno['count'] === 1
        && $anno['commitment_months'] === 12
        && $tri['unit'] === 'month' && $tri['count'] === 3
        && $piani[0]['features'] === [['key' => 'max_progetti', 'value' => '20']];
});

check('il JSON-LD è un ItemList di Product con gli stessi importi visibili e valido', function () use ($piani) {
    $schema = PlanCatalog::schemaOrg($piani, 'https://shop.test/abbonamenti/', 'Abbonamenti');
    $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    $decoded = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    $pro = $decoded['itemListElement'][0]['item'];
    $mese = $pro['offers'][0];
    $anno = $pro['offers'][1];

    return $decoded['@type'] === 'ItemList' && $decoded['numberOfItems'] === 2
        && $decoded['url'] === 'https://shop.test/abbonamenti/'
        && $pro['@type'] === 'Product' && $pro['name'] === 'Pro & Team' && $pro['productID'] === 'pln_pro1234'
        && $mese['price'] === 19.9 && $mese['priceCurrency'] === 'EUR' && $mese['sku'] === 'ppr_mese123'
        && $mese['priceSpecification'][0]['@type'] === 'UnitPriceSpecification'
        && $mese['priceSpecification'][0]['unitCode'] === 'MON' && $mese['priceSpecification'][0]['billingDuration'] === 1
        && $mese['priceSpecification'][1]['priceType'] === 'https://schema.org/StrikethroughPrice'
        && $mese['priceSpecification'][1]['price'] === 24.9
        && $anno['priceSpecification'][0]['unitCode'] === 'ANN' && count($anno['priceSpecification']) === 1
        && !isset($decoded['itemListElement'][1]['item']['description']);
});

check('view_item_list: un item per prezzo, con il codice del prezzo come item_id e il prezzo numerico', function () use ($piani) {
    $items = PlanCatalog::trackingItems($piani);

    return count($items) === 3
        && $items[0]['item_id'] === 'ppr_mese123' && $items[0]['item_name'] === 'Pro & Team — Mensile'
        && $items[0]['price'] === 19.9 && is_float($items[0]['price'])
        && $items[0]['item_category'] === 'abbonamento' && $items[0]['index'] === 0
        && $items[2]['item_id'] === 'ppr_tri1234' && $items[2]['index'] === 2;
});

check('item: prezzo unitario con le personalizzazioni e quantità almeno 1', function () use ($piani) {
    $item = SubscriptionTracking::item($piani[0]['prices'][0], 'Pro & Team', 24.904, 0, 1);

    return $item['item_id'] === 'ppr_mese123' && $item['price'] === 24.9 && $item['quantity'] === 1 && $item['index'] === 1;
});

check('add_to_cart e remove_from_cart: valore = prezzo × quantità degli item', function () use ($piani) {
    $uno = SubscriptionTracking::item($piani[0]['prices'][0], 'Pro & Team', 10.0, 3);
    $due = SubscriptionTracking::item($piani[0]['prices'][1], 'Pro & Team', 5.5, 2);
    $aggiunto = SubscriptionTracking::addToCart($uno);
    $tolti = SubscriptionTracking::removeFromCart([$uno, $due]);

    return $aggiunto['event'] === 'add_to_cart' && $aggiunto['ecommerce']['value'] === 30.0 && count($aggiunto['ecommerce']['items']) === 1
        && $tolti['event'] === 'remove_from_cart' && $tolti['ecommerce']['value'] === 41.0 && count($tolti['ecommerce']['items']) === 2;
});

check('begin_checkout: un item per servizio, valore = totale del primo addebito, coupon se c\'è', function () use ($piani) {
    $items = [
        SubscriptionTracking::item($piani[0]['prices'][0], 'Pro & Team', 19.9, 1, 0),
        SubscriptionTracking::item($piani[0]['prices'][1], 'Pro & Team', 199.0, 2, 1),
        SubscriptionTracking::item($piani[0]['prices'][0], 'Pro & Team', 24.9, 1, 2),
    ];
    $evento = SubscriptionTracking::beginCheckout($items, 401.234, 'EUR', ' ABB20 ');
    $senza = SubscriptionTracking::beginCheckout($items, 10.0);

    return $evento['event'] === 'begin_checkout' && $evento['ecommerce']['currency'] === 'EUR'
        && $evento['ecommerce']['value'] === 401.23 && count($evento['ecommerce']['items']) === 3
        && $evento['ecommerce']['items'][1]['quantity'] === 2 && $evento['ecommerce']['coupon'] === 'ABB20'
        && !isset($senza['ecommerce']['coupon']);
});

check('purchase: transaction_id è il codice dell\'abbonamento, valore zero in prova, un item per servizio', function () {
    $righe = [
        ['item' => ['quantity' => '2', 'customization' => ''], 'price' => ['code' => 'ppr_mese123', 'name' => 'Mensile', 'price' => '10.00', 'sale_price' => '0'], 'plan' => ['name' => 'Pro']],
        ['item' => ['quantity' => '1', 'customization' => ''], 'price' => ['code' => 'ppr_extra12', 'name' => 'Extra', 'price' => '5.00', 'sale_price' => '4.00'], 'plan' => ['name' => 'Spazio']],
    ];
    $attivo = SubscriptionTracking::purchaseFrom(['code' => 'sub_abc1234', 'status' => 'active', 'total' => '24.40', 'tax_total' => '4.40', 'currency' => 'EUR', 'coupon_code' => 'ABB20'], $righe);
    $prova = SubscriptionTracking::purchaseFrom(['code' => 'sub_def1234', 'status' => 'trialing', 'total' => '24.40', 'tax_total' => '4.40', 'currency' => 'EUR'], $righe);
    $items = $attivo['ecommerce']['items'];

    return $attivo['event'] === 'purchase' && $attivo['ecommerce']['transaction_id'] === 'sub_abc1234'
        && $attivo['ecommerce']['value'] === 24.4 && $attivo['ecommerce']['tax'] === 4.4 && $attivo['ecommerce']['coupon'] === 'ABB20'
        && count($items) === 2 && $items[0]['item_id'] === 'ppr_mese123' && $items[0]['quantity'] === 2 && $items[0]['price'] === 10.0
        && $items[1]['item_id'] === 'ppr_extra12' && $items[1]['price'] === 4.0
        && $prova['ecommerce']['value'] === 0.0 && !isset($prova['ecommerce']['coupon']);
});

check('le pagine: piani indicizzati con breadcrumb, servizio, riepilogo e conferma private', function () use ($root) {
    $controller = (string) file_get_contents($root.'/src/Frontend/Subscriptions/SubscriptionsController.php');

    return substr_count($controller, "'INDEX,FOLLOW'") >= 1
        && substr_count($controller, "'NOINDEX,NOFOLLOW'") === 3
        && str_contains($controller, "\$SEO->breadcrumb = [];")
        && str_contains($controller, 'self::TRACKED');
});

check('le viste: un solo h1, dataLayer dal punto condiviso, JSON-LD solo sui piani', function () use ($root) {
    foreach (['plans', 'configure', 'summary', 'confirm'] as $vista) {
        $source = (string) file_get_contents($root.'/view/pages/subscriptions/'.$vista.'.php');

        if (substr_count($source, '<h1') !== 1 || !str_contains($source, 'DataLayer::script(')) {
            return false;
        }

        if (($vista === 'plans') !== str_contains($source, '$SEO->schemaOrg')) {
            return false;
        }
    }

    return true;
});

check('le rotte: conferma e riepilogo prima del prezzo, il prezzo solo con il suo codice', function () use ($root) {
    $routes = (string) file_get_contents($root.'/config/routes/route.frontend.php');
    $conferma = strpos($routes, "Route::get('/conferma/'");
    $riepilogo = strpos($routes, "Route::get('/riepilogo/'");
    $togli = strpos($routes, "Route::post('/riepilogo/togli/'");
    $prezzo = strpos($routes, "Route::get('/{price}/'");

    return $conferma !== false && $riepilogo !== false && $togli !== false && $prezzo !== false
        && max($conferma, $riepilogo, $togli) < $prezzo
        && substr_count($routes, "->where('price', 'ppr_[a-z0-9-]+')") === 2;
});

check('le traduzioni degli abbonamenti ci sono in tutte le lingue, con la descrizione entro 140 caratteri', function () use ($root) {
    foreach (['it', 'en'] as $lingua) {
        $testi = json_decode((string) file_get_contents($root.'/lang/'.$lingua.'/ecommerce.json'), true);

        if (!isset($testi['subscriptions']['plans']['title'], $testi['account']['subscriptions']['title'])
            || mb_strlen((string) $testi['subscriptions']['plans']['seo']) > 140
            || !isset($testi['subscriptions']['add'], $testi['subscriptions']['summary']['submit'], $testi['subscriptions']['configure']['parent'])
            || mb_strlen((string) $testi['subscriptions']['configure']['seo']) > 140
            || mb_strlen((string) $testi['subscriptions']['summary']['seo']) > 140) {
            return false;
        }
    }

    return true;
});

check('la fatturazione vuole l\'indirizzo e chi paga: persona o ragione sociale', function () {
    $indirizzo = ['billing_country' => 'IT', 'billing_city' => 'Torino', 'billing_cap' => '10100', 'billing_street' => 'Via Po'];

    return SubscriptionBilling::complete($indirizzo + ['billing_name' => 'Lucia', 'billing_surname' => 'Rossi'])
        && SubscriptionBilling::complete($indirizzo + ['billing_business_name' => 'Rossi Srl'])
        && !SubscriptionBilling::complete($indirizzo + ['billing_name' => 'Lucia'])
        && !SubscriptionBilling::complete(['billing_city' => ''] + $indirizzo + ['billing_business_name' => 'Rossi Srl'])
        && !SubscriptionBilling::complete([]);
});

check('la conferma smette di ricaricarsi dopo qualche minuto e lo dice', function () use ($root) {
    $vista = (string) file_get_contents($root.'/view/pages/subscriptions/confirm.php');

    return str_contains($vista, "if (\$state === 'waiting')") && str_contains($vista, 'http-equiv="refresh"')
        && \Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionsController::WAIT_SECONDS <= 300;
});

summary();
