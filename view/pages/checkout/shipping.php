<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Choice;
use Wonder\Elements\Components\ChoiceGroup;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Tracking\DataLayer;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\View\View;

$order = (array) ($cart['order'] ?? []);
$items = CartPresenter::lines(array_values((array) ($cart['items'] ?? [])));
$currency = (string) ($order['currency'] ?? 'EUR');
$shipping = Gestionale::feature('shipping');
$coupons = Gestionale::feature('coupons');
$summary = is_array($summary ?? null) ? $summary : null;
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

$fulfillment = (string) ($summary['fulfillment']['type'] ?? 'shipping');
$canPickup = in_array('pickup', (array) ($summary['fulfillment']['choices'] ?? []), true);
$shippingOptions = (array) ($summary['shipping_methods']['options'] ?? []);
$shippingSelected = (int) ($summary['shipping_methods']['selected'] ?? 0);
$pickupOptions = (array) ($summary['pickup_locations']['options'] ?? []);
$pickupSelected = (int) ($summary['pickup_locations']['selected'] ?? 0);
$display = (array) ($summary['display'] ?? []);
$couponCode = (string) ($summary['coupon']['code'] ?? ($order['coupon_code'] ?? ''));

$order = ($summary['order'] ?? null) !== null ? $summary['order'] + ['fulfillment_type' => $fulfillment] : $order;
$aside = [
    'step' => 'shipping', 'items' => (array) ($summary['items'] ?? $items), 'order' => $order, 'currency' => $currency,
    'csrf_token' => $csrf_token, 'coupons' => $coupons, 'couponCode' => $couponCode, 'notices' => (array) ($summary['notices'] ?? []),
];

$labels = [];
foreach (['free', 'no_shipping', 'shipping_total', 'fees_total', 'coupon_remove', 'submit_manual', 'submit_online', 'updating', 'summary_error'] as $key) {
    $labels[$key] = (string) __t('ecommerce.checkout.'.$key);
}
foreach (['products_total', 'discount', 'total'] as $key) {
    $labels[$key] = (string) __t('ecommerce.cart.'.$key);
}
$labels['sku'] = (string) __t('ecommerce.cart.sku', ['sku' => ':sku']);

$gaItems = array_map(static fn (array $item): array => [
    'item_id' => (string) ($item['sku'] ?? $item['product_id'] ?? ''),
    'item_name' => (string) ($item['name'] ?? ''),
    'price' => round((float) ($item['line_total'] ?? 0) / max((float) ($item['quantity'] ?? 1), 1), 2),
    'quantity' => (float) ($item['quantity'] ?? 1),
], array_values(array_filter($items, static fn (array $item): bool => (string) ($item['type'] ?? 'product') === 'product')));

Ecommerce::layout('checkout', compact('errors', 'notice'));
?>
<?=DataLayer::script([
    'type' => 'checkout',
    'language' => __l(),
    'currency' => $currency,
], [
    'event' => 'begin_checkout',
    'ecommerce' => ['currency' => $currency, 'value' => (float) ($order['total'] ?? 0), 'items' => $gaItems],
], (int) ($_SESSION['user_id'] ?? 0))?>
<?=View::component(Ecommerce::viewPath('components/checkout/steps.php'), ['step' => 'shipping'])?>
<h1 class="title mb-6"><?=e(__t('ecommerce.checkout.title'))?></h1>
<?=View::component(Ecommerce::viewPath('components/checkout/mobile.php'), $aside)?>
<div class="w-100 d-grid col-3 col-t-1 gap-6">
    <form id="checkout" class="col-2 col-t-1 d-flex d-column gap-6" method="post" action="<?=e(__r('ecommerce.checkout.shipping'))?>" novalidate
        data-checkout data-step="shipping"
        data-shipping="<?=$shipping ? 'on' : 'off'?>"
        data-coupons="<?=$coupons ? 'on' : 'off'?>"
        data-summary-url="<?=e(__r('ecommerce.checkout.summary'))?>"
        data-coupon-url="<?=e(__r('ecommerce.checkout.coupon'))?>"
        data-labels="<?=e(json_encode($labels, $flags))?>"
        data-initial="<?=e(json_encode($summary, $flags))?>">
        <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
        <section id="contatto" class="wi-box p-5">
            <h2 class="subtitle mb-4"><?=e(__t('ecommerce.checkout.contact'))?></h2>
            <?php if ($guest): ?>
                <p class="text-small mb-4"><?=e(__t('ecommerce.checkout.have_account'))?> <a href="<?=e(__r('ecommerce.auth.login').'?continue='.rawurlencode((string) __r('ecommerce.checkout.index')))?>"><?=e(__t('ecommerce.checkout.login'))?></a></p>
            <?php endif; ?>
            <div class="w-100 d-grid col-2 col-p-1 gap-4">
                <?=FormField::key('shipping_name')->text()->label((string) __t('ecommerce.auth.fields.name'))->required()->value($values['shipping_name'] ?? '')?>
                <?=FormField::key('shipping_surname')->text()->label((string) __t('ecommerce.auth.fields.surname'))->required()->value($values['shipping_surname'] ?? '')?>
                <?=FormField::key('email')->email()->label((string) __t('ecommerce.auth.fields.email'))->required()->value($values['email'] ?? '')?>
                <?=FormField::key('phone')->tel()->label((string) __t('ecommerce.auth.fields.mobile'))->required()->value($values['phone'] ?? '')?>
            </div>
        </section>
        <section id="consegna" class="wi-box p-5">
            <h2 class="subtitle mb-4"><?=e(__t('ecommerce.checkout.delivery'))?></h2>
            <?php if ($shipping): ?>
                <div class="w-100 mb-5" data-checkout-fulfillment>
                    <?=ChoiceGroup::make()->choices(
                        Choice::make('fulfillment_type', 'shipping')->type('radio')->title((string) __t('ecommerce.checkout.fulfillment_shipping'))->checked($fulfillment === 'shipping'),
                        Choice::make('fulfillment_type', 'pickup')->type('radio')->title((string) __t('ecommerce.checkout.fulfillment_pickup'))->checked($fulfillment === 'pickup')->disabled(!$canPickup)
                    )?>
                </div>
            <?php endif; ?>
            <div class="w-100" data-checkout-shipping<?=$shipping && $fulfillment === 'pickup' ? ' hidden' : ''?>>
                <div class="w-100 d-grid col-2 col-p-1 gap-4">
                    <?php foreach ($shipping_fields as $field): ?><?=$field?><?php endforeach; ?>
                </div>
                <?php if ($shipping): ?>
                    <h3 class="subtitle mt-5 mb-3"><?=e(__t('ecommerce.checkout.shipping_methods'))?></h3>
                    <div class="w-100" data-checkout-shipping-methods>
                        <?=ChoiceGroup::make()->choices(...array_map(static fn (array $o): Choice => Choice::make('shipping_method_id', (int) $o['method_id'])
                            ->type('radio')->title((string) $o['name'])->text((string) ($o['description'] ?? ''))->aside((string) ($o['price_display'] ?? ''))
                            ->checked((int) $o['method_id'] === $shippingSelected), $shippingOptions))?>
                    </div>
                    <p class="text-small mt-3" data-checkout-notice role="status"<?=$shippingOptions === [] ? '' : ' hidden'?>><?=$shippingOptions === [] ? e(__t('ecommerce.checkout.no_shipping')) : ''?></p>
                <?php endif; ?>
            </div>
            <?php if ($shipping): ?>
                <div class="w-100" data-checkout-pickup<?=$fulfillment === 'pickup' ? '' : ' hidden'?>>
                    <h3 class="subtitle mb-3"><?=e(__t('ecommerce.checkout.pickup_locations'))?></h3>
                    <div class="w-100" data-checkout-pickup-locations>
                        <?=ChoiceGroup::make()->choices(...array_map(static fn (array $o): Choice => Choice::make('location_id', (int) $o['id'])
                            ->type('radio')->title((string) $o['name'])->text((string) ($o['address'] ?? ''))
                            ->checked((int) $o['id'] === $pickupSelected), $pickupOptions))?>
                    </div>
                </div>
            <?php endif; ?>
            <template data-checkout-choice><?=Choice::make('', '')->type('radio')?></template>
        </section>
        <?=Button::make((string) __t('ecommerce.checkout.continue_payment'))->type('submit')->attr('data-checkout-submit', '')->attr('data-checkout-inline-submit', '')->variant('primary')->class('w-100 pc-none')?>
    </form>
    <?=View::component(Ecommerce::viewPath('components/checkout/aside.php'), $aside + [
        'button' => (string) Button::make((string) __t('ecommerce.checkout.continue_payment'))->type('submit')->attr('form', 'checkout')->attr('data-checkout-submit', '')->variant('primary')->class('w-100 mt-5'),
    ])?>
</div>
<?php if (($checkoutJs = module_asset('ecommerce', 'js/checkout.js')) !== ''): ?>
    <script src="<?=e($checkoutJs)?>"></script>
<?php endif; ?>
<?php View::end(); ?>
