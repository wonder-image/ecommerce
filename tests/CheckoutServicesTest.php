<?php
/** php tests/CheckoutServicesTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutSubscriptionStart;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionTracking;

$root = dirname(__DIR__);

// La vista dei totali si prova da sola: etichette come chiavi, escape semplice.
if (!function_exists('__t')) {
    function __t(string $key): string
    {
        return $key;
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES);
    }
}

/** @param array<string, mixed>|null $services */
function totali(?array $services, array $order = []): string
{
    $order += ['products_total' => 20, 'discount_total' => 0, 'shipping_total' => 5, 'fees_total' => 0, 'total' => 25];
    $currency = 'EUR';
    $shippingRow = true;
    ob_start();
    include dirname(__DIR__).'/view/components/checkout/totals.php';

    return (string) ob_get_clean();
}

check('totali senza servizi: prodotti, spedizione e totale del carrello', function (): bool {
    $html = totali(null);

    return str_contains($html, 'ecommerce.cart.products_total') && str_contains($html, 'ecommerce.checkout.shipping_total')
        && str_contains($html, 'ecommerce.cart.total') && !str_contains($html, 'ecommerce.checkout.today_total');
});

check('totali con servizi e prodotti: primo addebito, totale di oggi e il «poi»', function (): bool {
    $html = totali(['products' => true, 'first_charge' => 61, 'today_total' => 86, 'then' => 'Poi 24,00 € al mese']);

    return str_contains($html, 'ecommerce.cart.products_total') && str_contains($html, 'ecommerce.checkout.shipping_total')
        && str_contains($html, 'ecommerce.checkout.subscriptions_first_charge')
        && str_contains($html, '<span class="fw-700">ecommerce.checkout.today_total</span>')
        && str_contains($html, 'Poi 24,00 € al mese')
        && !str_contains($html, 'ecommerce.cart.total<');
});

check('totali con soli servizi: niente prodotti né spedizione', function (): bool {
    $html = totali(['products' => false, 'first_charge' => 61, 'today_total' => 61, 'then' => ''], ['products_total' => 0, 'shipping_total' => 0, 'total' => 0]);

    return !str_contains($html, 'ecommerce.cart.products_total') && !str_contains($html, 'ecommerce.checkout.shipping_total')
        && str_contains($html, 'ecommerce.checkout.today_total');
});

check('soli servizi: la spedizione sparisce, la fatturazione si scrive a mano', function () use ($root): bool {
    $view = (string) file_get_contents($root.'/view/pages/checkout/index.php');
    $start = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutSubscriptionStart.php');

    return str_contains($view, "\$shipping = Gestionale::feature('shipping') && \$products;")
        && str_contains($view, "__t(\$products ? 'ecommerce.checkout.delivery' : 'ecommerce.checkout.details')")
        && str_contains($view, "<?php if (\$products): ?>\n                <div class=\"w-100 mt-4 mt-p-3\" data-checkout-shipping")
        && str_contains($view, "<?php if (!\$products): ?><?=FormField::key('same_as_shipping')->hidden()->value('0')?>")
        && str_contains($start, "\$post['same_as_shipping'] = '0';");
});

check('dataLayer dal JS: value è il totale di oggi, i servizi con il loro item GA4', function () use ($root): bool {
    $js = (string) file_get_contents($root.'/resources/assets/js/checkout.js');

    return str_contains($js, 'value: parseFloat(services ? services.today_total : order.total)')
        && str_contains($js, "item.type === 'subscription' ? item.tracking :")
        && str_contains($js, '.filter(Boolean)');
});

check('purchase dell\'ordine misto: solo i prodotti, prezzo unitario dalla riga', function (): bool {
    $items = (new ReflectionMethod(CheckoutSubscriptionStart::class, 'trackingItems'))->invoke(null, [
        ['type' => 'product', 'sku' => 'SKU-1', 'name' => 'Tazza', 'line_total' => 30, 'quantity' => 3],
        ['type' => 'subscription', 'name' => 'Pro', 'line_total' => 10, 'quantity' => 1],
        ['product_id' => 9, 'name' => 'Piatto', 'line_total' => 7.5, 'quantity' => 1],
    ]);

    return $items === [
        ['item_id' => 'SKU-1', 'item_name' => 'Tazza', 'price' => 10.0, 'quantity' => 3.0],
        ['item_id' => '9', 'item_name' => 'Piatto', 'price' => 7.5, 'quantity' => 1.0],
    ];
});

check('purchase misto: value e item sommano ordine e servizi, senza ordine resta com\'è', function (): bool {
    $servizi = ['event' => 'purchase', 'ecommerce' => ['transaction_id' => 'sub_abc1234', 'currency' => 'EUR', 'value' => 61.0, 'items' => [['item_id' => 'ppr_mese123']]]];
    $misto = SubscriptionTracking::withOrder($servizi, ['value' => 25.5, 'items' => [['item_id' => 'SKU-1']]]);

    return $misto['ecommerce']['value'] === 86.5 && $misto['ecommerce']['transaction_id'] === 'sub_abc1234'
        && array_column($misto['ecommerce']['items'], 'item_id') === ['SKU-1', 'ppr_mese123']
        && SubscriptionTracking::withOrder($servizi, []) === $servizi;
});

check('le righe dei servizi nel checkout non hanno il «Togli»', function () use ($root): bool {
    $sorgenti = file_get_contents($root.'/view/components/checkout/lines.php').file_get_contents($root.'/resources/assets/js/checkout.js')
        .file_get_contents($root.'/src/Frontend/Checkout/CheckoutSubscriptions.php');

    return !str_contains($sorgenti, 'data-line-remove') && !str_contains($sorgenti, 'remove_html');
});

summary();
