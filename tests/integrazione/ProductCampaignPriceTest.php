<?php
/** php tests/integrazione/ProductCampaignPriceTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductCatalog;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaign;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Sql\Transaction;

final class AnnullaProductCampaignPrice extends RuntimeException {}

// La maglietta dei dati di prova: più varianti, più opzioni.
const MODELLO = 'maglietta-girocollo';

$token = bin2hex(random_bytes(32));
$nome = 'Prova scheda '.bin2hex(random_bytes(4));
$model = ProductModel::find(['slug' => MODELLO, 'deleted' => 'false'], 1);

check('il modello di prova '.MODELLO.' esiste', fn () => is_array($model) && (int) ($model['id'] ?? 0) > 0);

try {
    Transaction::run(static function () use ($model, $token, $nome): void {
        // Uno sconto che batte qualsiasi campagna di prova sul modello.
        $campaignId = (int) (DiscountCampaign::create([
            'name' => $nome,
            'active' => 'true',
            'applies_to_all' => 'false',
            'exclude_sale_products' => 'false',
            'applies_online' => 'true',
            'applies_office' => 'false',
            'applies_pos' => 'false',
            'discount_type' => 'percent',
            'discount_value' => '50.00',
            'starts_at' => date('Y-m-d H:i:s', time() - 3600),
            'ends_at' => '',
        ])->insert_id ?? 0);
        DiscountCampaignProductModel::create(['discount_campaign_id' => $campaignId, 'product_model_id' => (int) $model['id']]);

        $detail = ProductCatalog::find(MODELLO)?->data() ?? [];
        $offers = (array) ($detail['offers'] ?? []);

        check('la scheda ha più offerte da confrontare', fn () => count($offers) > 1);

        check('ogni opzione mostra il prezzo di campagna, col listino da barrare', fn () => $offers !== [] && array_filter(
            $offers,
            static fn (array $offer): bool => $offer['regular_price'] <= 0
                || abs($offer['price'] - round($offer['regular_price'] * 0.5, 2)) > 0.001
        ) === []);

        check('il prezzo della scheda è quello dell\'opzione scelta', fn () =>
            abs((float) $detail['price'] - round((float) $detail['regular_price'] * 0.5, 2)) < 0.001
        );

        // Lo stesso prodotto nel carrello costa quanto dice la scheda.
        $offer = current(array_filter($offers, static fn (array $offer): bool => $offer['available'])) ?: ($offers[0] ?? []);
        $cart = Cart::open(['cart_token' => $token, 'channel' => 'online']);
        Cart::add((int) $cart['id'], ['product_id' => (int) $offer['product_id'], 'quantity' => 1]);
        $line = Cart::contents((int) $cart['id'])['items'][0] ?? [];

        check('carrello e scheda danno lo stesso prezzo', fn () =>
            (int) ($line['product_id'] ?? 0) === (int) $offer['product_id']
            && abs((float) ($line['unit_price'] ?? -1) - (float) $offer['price']) < 0.001
            && (int) ($line['discount_campaign_id'] ?? 0) === $campaignId
        );

        DiscountCampaign::update(['active' => 'false'], $campaignId);
        $without = ProductCatalog::find(MODELLO)?->data() ?? [];

        check('con la campagna spenta la scheda torna come prima', fn () =>
            abs((float) ($without['price'] ?? 0) - round((float) ($without['regular_price'] ?? 0) * 0.5, 2)) > 0.001
        );

        throw new AnnullaProductCampaignPrice();
    });
} catch (AnnullaProductCampaignPrice) {
}

check('il test non lascia campagne o carrelli nel database', fn () =>
    DiscountCampaign::find(['name' => $nome], 1) === []
    && Order::find(['cart_token' => $token], 1) === []
);

summary();
