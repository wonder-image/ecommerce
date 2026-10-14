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

check('begin_checkout porta valore e item del prezzo scelto', function () use ($piani) {
    $evento = SubscriptionTracking::beginCheckout($piani[0]['prices'][0], 'Pro & Team');

    return $evento['event'] === 'begin_checkout' && $evento['ecommerce']['currency'] === 'EUR'
        && $evento['ecommerce']['value'] === 19.9 && $evento['ecommerce']['items'][0]['item_id'] === 'ppr_mese123';
});

check('purchase: transaction_id è il codice dell\'abbonamento, valore zero in prova, coupon se c\'è', function () {
    $prezzo = ['code' => 'ppr_mese123', 'name' => 'Mensile'];
    $piano = ['name' => 'Pro'];
    $attivo = SubscriptionTracking::purchaseFrom(['code' => 'sub_abc1234', 'status' => 'active', 'total' => '24.40', 'tax_total' => '4.40', 'currency' => 'EUR', 'coupon_code' => 'ABB20'], $prezzo, $piano);
    $prova = SubscriptionTracking::purchaseFrom(['code' => 'sub_def1234', 'status' => 'trialing', 'total' => '24.40', 'tax_total' => '4.40', 'currency' => 'EUR'], $prezzo, $piano);

    return $attivo['event'] === 'purchase' && $attivo['ecommerce']['transaction_id'] === 'sub_abc1234'
        && $attivo['ecommerce']['value'] === 24.4 && $attivo['ecommerce']['tax'] === 4.4 && $attivo['ecommerce']['coupon'] === 'ABB20'
        && $attivo['ecommerce']['items'][0]['item_id'] === 'ppr_mese123'
        && $prova['ecommerce']['value'] === 0.0 && !isset($prova['ecommerce']['coupon']);
});

check('le pagine: piani indicizzati con breadcrumb, sottoscrizione e conferma private', function () use ($root) {
    $controller = (string) file_get_contents($root.'/src/Frontend/Subscriptions/SubscriptionsController.php');

    return substr_count($controller, "'INDEX,FOLLOW'") >= 1
        && substr_count($controller, "'NOINDEX,NOFOLLOW'") === 2
        && str_contains($controller, "\$SEO->breadcrumb = [];")
        && str_contains($controller, 'self::TRACKED');
});

check('le viste: un solo h1, dataLayer dal punto condiviso, JSON-LD solo sui piani', function () use ($root) {
    foreach (['plans', 'subscribe', 'confirm'] as $vista) {
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

check('le rotte: la conferma prima del prezzo, il prezzo solo con il suo codice', function () use ($root) {
    $routes = (string) file_get_contents($root.'/config/routes/route.frontend.php');
    $conferma = strpos($routes, "Route::get('/conferma/'");
    $prezzo = strpos($routes, "Route::get('/{price}/'");

    return $conferma !== false && $prezzo !== false && $conferma < $prezzo
        && str_contains($routes, "->where('price', 'ppr_[a-z0-9-]+')");
});

check('le traduzioni degli abbonamenti ci sono in tutte le lingue, con la descrizione entro 140 caratteri', function () use ($root) {
    foreach (['it', 'en'] as $lingua) {
        $testi = json_decode((string) file_get_contents($root.'/lang/'.$lingua.'/ecommerce.json'), true);

        if (!isset($testi['subscriptions']['plans']['title'], $testi['account']['subscriptions']['title'])
            || mb_strlen((string) $testi['subscriptions']['plans']['seo']) > 140
            || mb_strlen((string) $testi['subscriptions']['subscribe']['seo']) > 140) {
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
