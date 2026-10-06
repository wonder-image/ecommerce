<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Choice;
use Wonder\Elements\Components\ChoiceGroup;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\View\View;

$items = (array) ($summary['items'] ?? []);
$currency = (string) ($summary['order']['currency'] ?? 'EUR');
$coupons = Gestionale::feature('coupons');
$pickup = (string) ($order['fulfillment_type'] ?? '') === 'pickup';
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$payments = (array) ($summary['payment_methods']['options'] ?? []);
$selected = (int) ($values['payment_method_id'] ?? $summary['payment_methods']['selected'] ?? 0);
$manual = true;
foreach ($payments as $p) {
    if ((int) $p['id'] === $selected) {
        $manual = (bool) $p['manual'];
    }
}
$submit = (string) __t('ecommerce.checkout.'.($manual ? 'submit_manual' : 'submit_online'));
$labels = [];
foreach (['coupon_remove', 'submit_manual', 'submit_online', 'updating', 'summary_error', 'fees_total', 'shipping_total'] as $key) {
    $labels[$key] = (string) __t('ecommerce.checkout.'.$key);
}
foreach (['products_total', 'discount', 'total'] as $key) {
    $labels[$key] = (string) __t('ecommerce.cart.'.$key);
}
$labels['sku'] = (string) __t('ecommerce.cart.sku', ['sku' => ':sku']);
// Senza pagamenti il bottone resta spento anche quando il browser riapre la pagina dalla cache.
$lock = static fn (Button $button): Button => $payments === [] ? $button->disabled(true)->attr('data-checkout-locked', '') : $button;
$field = static fn (string $key): string => trim((string) ($order[$key] ?? ''));
$delivery = '';
if ($pickup) {
    foreach ((array) ($summary['pickup_locations']['options'] ?? []) as $o) {
        if ((int) $o['id'] === (int) ($order['location_id'] ?? 0)) {
            $delivery = trim($o['name'].' — '.($o['address'] ?? ''), ' —');
        }
    }
} else {
    $delivery = implode(', ', array_filter([trim($field('shipping_street').' '.$field('shipping_number')), trim($field('shipping_cap').' '.$field('shipping_city')), $field('shipping_country')]));
    foreach ((array) ($summary['shipping_methods']['options'] ?? []) as $o) {
        if ((int) $o['method_id'] === (int) ($order['shipping_method_id'] ?? 0)) {
            $delivery .= ' · '.$o['name'];
        }
    }
}
$contact = implode(' · ', array_filter([trim($field('shipping_name').' '.$field('shipping_surname')), $field('email'), $field('phone')]));
$aside = [
    'step' => 'payment', 'items' => $items, 'order' => (array) ($summary['order'] ?? []) + ['fulfillment_type' => $field('fulfillment_type')], 'currency' => $currency,
    'csrf_token' => $csrf_token, 'coupons' => $coupons, 'couponCode' => (string) ($summary['coupon']['code'] ?? ''), 'notices' => (array) ($summary['notices'] ?? []),
];

Ecommerce::layout('checkout', compact('errors', 'notice'));
?>
<?=View::component(Ecommerce::viewPath('components/checkout/steps.php'), ['step' => 'payment'])?>
<h1 class="title mb-6"><?=e(__t('ecommerce.checkout.payment'))?></h1>
<?=View::component(Ecommerce::viewPath('components/checkout/mobile.php'), $aside)?>
<div class="d-grid col-3 col-t-1 gap-6">
    <form id="checkout" class="col-2 col-t-1 d-grid col-1 gap-6" method="post" action="<?=e(__r('ecommerce.checkout.place'))?>" novalidate
        data-checkout data-step="payment"
        data-shipping="<?=Gestionale::feature('shipping') ? 'on' : 'off'?>"
        data-coupons="<?=$coupons ? 'on' : 'off'?>"
        data-summary-url="<?=e(__r('ecommerce.checkout.summary'))?>"
        data-coupon-url="<?=e(__r('ecommerce.checkout.coupon'))?>"
        data-labels="<?=e(json_encode($labels, $flags))?>"
        data-initial="<?=e(json_encode($summary, $flags))?>">
        <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
        <section class="wi-box p-5 d-grid col-1 gap-3">
            <div class="d-flex gap-3"><span class="w-100"><span class="d-block text-small tx-secondary"><?=e(__t('ecommerce.checkout.contact'))?></span><?=e($contact)?></span><a class="text-small" href="<?=e(__r('ecommerce.checkout.index'))?>#contatto"><?=e(__t('ecommerce.checkout.edit'))?></a></div>
            <div class="d-flex gap-3"><span class="w-100"><span class="d-block text-small tx-secondary"><?=e(__t('ecommerce.checkout.delivery'))?></span><?=e($delivery)?></span><a class="text-small" href="<?=e(__r('ecommerce.checkout.index'))?>#consegna"><?=e(__t('ecommerce.checkout.edit'))?></a></div>
        </section>
        <section class="wi-box p-5">
            <h2 class="subtitle mb-4"><?=e(__t('ecommerce.checkout.payment_method'))?></h2>
            <div data-checkout-payments>
                <?php if ($payments === []): ?>
                    <p class="text"><?=e(__t('ecommerce.checkout.no_payment_methods'))?></p>
                <?php else: ?>
                    <?=ChoiceGroup::make()->choices(...array_map(static fn (array $p): Choice => Choice::make('payment_method_id', (int) $p['id'])
                        ->type('radio')->title((string) $p['name'])->text((string) ($p['instructions'] ?? ''))
                        ->checked((int) $p['id'] === $selected), $payments))?>
                <?php endif; ?>
            </div>
            <template data-checkout-choice><?=Choice::make('', '')->type('radio')?></template>
        </section>
        <section class="wi-box p-5">
            <h2 class="subtitle mb-4"><?=e(__t('ecommerce.checkout.billing'))?></h2>
            <?php if (!$pickup): ?>
                <?=Choice::make('same_as_shipping', '1')->type('checkbox')->title((string) __t('ecommerce.checkout.billing_same'))->checked(!empty($values['same_as_shipping']))?>
            <?php endif; ?>
            <div class="d-grid col-2 col-p-1 gap-4 mt-4" data-checkout-toggle="<?=$pickup ? '' : 'same_as_shipping:off'?>">
                <?php foreach ($billing_fields as $billingField): ?><?=$billingField?><?php endforeach; ?>
            </div>
            <div class="mt-5">
                <?=Choice::make('invoice', '1')->type('checkbox')->title((string) __t('ecommerce.checkout.invoice'))->checked(!empty($values['invoice']))?>
            </div>
            <div class="mt-4" data-checkout-toggle="invoice:on">
                <?=ChoiceGroup::make()->choices(
                    Choice::make('billing_type', 'private')->type('radio')->title((string) __t('ecommerce.checkout.billing_private'))->checked(($values['billing_type'] ?? 'private') !== 'business'),
                    Choice::make('billing_type', 'business')->type('radio')->title((string) __t('ecommerce.checkout.billing_business'))->checked(($values['billing_type'] ?? '') === 'business')
                )?>
                <div class="d-grid col-2 col-p-1 gap-4 mt-4">
                    <?php foreach ($cf_field as $billingField): ?><?=$billingField?><?php endforeach; ?>
                </div>
                <div class="d-grid col-2 col-p-1 gap-4 mt-4" data-checkout-toggle="billing_type:business">
                    <?php foreach ($business_fields as $billingField): ?><?=$billingField?><?php endforeach; ?>
                </div>
            </div>
        </section>
        <section class="wi-box p-5">
            <?=FormField::key('customer_note')->textarea()->label((string) __t('ecommerce.checkout.note'))->value($values['customer_note'] ?? '')?>
            <?php if ($consents !== []): ?>
                <h2 class="subtitle mt-5 mb-3"><?=e(__t('ecommerce.checkout.consents'))?></h2>
                <?php foreach ($consents as $type): ?>
                    <?=FormField::key('accept_'.$type)->acceptDocument($type)->required()->value($values['accept_'.$type] ?? '')?>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        <?php if ($guest): ?>
            <?=FormField::key('recaptcha')->recaptcha('ecommerce_checkout')?>
        <?php endif; ?>
        <?=$lock(Button::make($submit)->type('submit')->attr('data-checkout-submit', '')->attr('data-checkout-inline-submit', '')->variant('primary')->class('w-100 pc-none wi-input-submit wi-submit'))?>
    </form>
    <?=View::component(Ecommerce::viewPath('components/checkout/aside.php'), $aside + [
        'button' => (string) $lock(Button::make($submit)->type('submit')->attr('form', 'checkout')->attr('data-checkout-submit', '')->variant('primary')->class('w-100 mt-5 wi-input-submit wi-submit')),
    ])?>
</div>
<?php if (($checkoutJs = module_asset('ecommerce', 'js/checkout.js')) !== ''): ?>
    <script src="<?=e($checkoutJs)?>"></script>
<?php endif; ?>
<?php View::end(); ?>
