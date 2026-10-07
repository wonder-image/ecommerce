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
$pickup = $shipping && $fulfillment === 'pickup';
$canPickup = in_array('pickup', (array) ($summary['fulfillment']['choices'] ?? []), true);
$shippingOptions = (array) ($summary['shipping_methods']['options'] ?? []);
$shippingSelected = (int) ($summary['shipping_methods']['selected'] ?? 0);
$addressComplete = (bool) ($summary['shipping_methods']['address_complete'] ?? false);
$pickupOptions = (array) ($summary['pickup_locations']['options'] ?? []);
$pickupSelected = (int) ($summary['pickup_locations']['selected'] ?? 0);
$payments = (array) ($summary['payment_methods']['options'] ?? []);
$paymentSelected = (int) ($values['payment_method_id'] ?? $summary['payment_methods']['selected'] ?? 0);
$manual = true;
foreach ($payments as $p) {
    if ((int) $p['id'] === $paymentSelected) {
        $manual = (bool) $p['manual'];
    }
}
$couponCode = (string) ($summary['coupon']['code'] ?? ($order['coupon_code'] ?? ''));
$same = (string) ($values['same_as_shipping'] ?? '1') !== '0';
$invoice = !empty($values['invoice']);
$business = ($values['billing_type'] ?? 'private') === 'business';
$shippingNotice = $shippingOptions !== [] ? '' : ($addressComplete ? 'no_shipping' : 'shipping_methods_pending');

$order = ($summary['order'] ?? null) !== null ? $summary['order'] + ['fulfillment_type' => $fulfillment] : $order;
$aside = [
    'step' => 'checkout', 'items' => (array) ($summary['items'] ?? $items), 'order' => $order, 'currency' => $currency,
    'csrf_token' => $csrf_token, 'coupons' => $coupons, 'couponCode' => $couponCode, 'notices' => (array) ($summary['notices'] ?? []),
];

$labels = [];
foreach (['free', 'no_shipping', 'shipping_pending', 'shipping_methods_pending', 'shipping_total', 'fees_total', 'coupon_remove', 'submit_manual', 'submit_online', 'updating', 'summary_error', 'field_required'] as $key) {
    $labels[$key] = (string) __t('ecommerce.checkout.'.$key);
}
foreach (['products_total', 'discount', 'total'] as $key) {
    $labels[$key] = (string) __t('ecommerce.cart.'.$key);
}

$gaItems = array_map(static fn (array $item): array => [
    'item_id' => (string) ($item['sku'] ?? $item['product_id'] ?? ''),
    'item_name' => (string) ($item['name'] ?? ''),
    'price' => round((float) ($item['line_total'] ?? 0) / max((float) ($item['quantity'] ?? 1), 1), 2),
    'quantity' => (float) ($item['quantity'] ?? 1),
], array_values(array_filter($items, static fn (array $item): bool => (string) ($item['type'] ?? 'product') === 'product')));

$payment = static fn (array $p): Choice => Choice::make('payment_method_id', (int) $p['id'])
    ->type('radio')->title((string) $p['name'])->aside((string) ($p['fee_display'] ?? ''))
    ->icons((array) ($p['icon_urls'] ?? []))->panel((string) ($p['panel'] ?? ''))
    ->checked((int) $p['id'] === $paymentSelected);

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
<h1 class="wi-checkout__sr"><?=e(__t('ecommerce.checkout.title'))?></h1>
<?=View::component(Ecommerce::viewPath('components/checkout/mobile.php'), $aside)?>
<?php /* Senza JS niente si apre o si chiude: si vede tutto il modulo. */ ?>
<noscript><style>[data-checkout-toggle][hidden],[data-checkout-shipping][hidden],[data-checkout-pickup][hidden]{display:block!important}.d-grid[data-checkout-toggle][hidden]{display:grid!important}</style></noscript>
<div class="w-100 d-grid col-2 col-t-1 gap-6 wi-checkout">
    <form id="checkout" class="w-100 d-flex d-column gap-6 wi-checkout__form" method="post" action="<?=e(__r('ecommerce.checkout.place'))?>" novalidate
        data-checkout
        data-shipping="<?=$shipping ? 'on' : 'off'?>"
        data-coupons="<?=$coupons ? 'on' : 'off'?>"
        data-summary-url="<?=e(__r('ecommerce.checkout.summary'))?>"
        data-coupon-url="<?=e(__r('ecommerce.checkout.coupon'))?>"
        data-labels="<?=e(json_encode($labels, $flags))?>"
        data-initial="<?=e(json_encode($summary, $flags))?>">
        <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
        <?php if ($express !== []): ?>
            <section id="rapido" class="w-100">
                <h2 class="text-small a-c mb-3"><?=e(__t('ecommerce.checkout.express'))?></h2>
                <div class="w-100 d-grid col-3 gap-3"><?php foreach ($express as $button): ?><?=$button?><?php endforeach; ?></div>
                <p class="wi-checkout__or mt-5"><?=e(__t('ecommerce.checkout.or'))?></p>
            </section>
        <?php endif; ?>
        <section id="contatti" class="w-100">
            <div class="w-100 d-flex gap-3 mb-3">
                <h2 class="subtitle"><?=e(__t('ecommerce.checkout.contact'))?></h2>
                <?php if ($guest): ?>
                    <a class="text-small ml-auto" href="<?=e(__r('ecommerce.auth.login').'?continue='.rawurlencode((string) __r('ecommerce.checkout.index')))?>"><?=e(__t('ecommerce.checkout.login'))?></a>
                <?php endif; ?>
            </div>
            <?php if ($guest || $user_email === ''): ?>
                <?=FormField::key('email')->email()->label((string) __t('ecommerce.auth.fields.email'))->required()->value($values['email'] ?? '')?>
            <?php else: ?>
                <div class="w-100 d-flex gap-3">
                    <p class="text"><?=e($user_email)?></p>
                    <button type="submit" form="checkout-logout" class="ml-auto wi-checkout__link"><?=e(__t('ecommerce.checkout.logout'))?></button>
                </div>
            <?php endif; ?>
        </section>
        <section id="consegna" class="w-100">
            <h2 class="subtitle mb-3"><?=e(__t('ecommerce.checkout.delivery'))?></h2>
            <?php if ($shipping): ?>
                <div class="w-100 mb-4" data-checkout-fulfillment<?=$canPickup ? '' : ' hidden'?>>
                    <?=ChoiceGroup::make()->variant('segmented')->choices(
                        Choice::make('fulfillment_type', 'shipping')->type('radio')->icon('truck')->title((string) __t('ecommerce.checkout.fulfillment_shipping'))->checked(!$pickup),
                        Choice::make('fulfillment_type', 'pickup')->type('radio')->icon('shop')->title((string) __t('ecommerce.checkout.fulfillment_pickup'))->checked($pickup)->disabled(!$canPickup)
                    )?>
                </div>
            <?php endif; ?>
            <div class="w-100 d-grid col-2 col-p-1 gap-4">
                <?=FormField::key('shipping_name')->text()->label((string) __t('ecommerce.auth.fields.name'))->required()->value($values['shipping_name'] ?? '')?>
                <?=FormField::key('shipping_surname')->text()->label((string) __t('ecommerce.auth.fields.surname'))->required()->value($values['shipping_surname'] ?? '')?>
            </div>
            <div class="w-100 mt-4" data-checkout-shipping<?=$pickup ? ' hidden' : ''?>>
                <div class="w-100 d-grid col-2 col-p-1 gap-4">
                    <?php foreach ($shipping_fields as $field): ?><?=$field?><?php endforeach; ?>
                </div>
            </div>
            <?php if ($shipping): ?>
                <div class="w-100 mt-4" data-checkout-pickup<?=$pickup ? '' : ' hidden'?>>
                    <h3 class="text fw-600 mb-3"><?=e(__t('ecommerce.checkout.pickup_locations'))?></h3>
                    <div class="w-100" data-checkout-pickup-locations>
                        <?=ChoiceGroup::make()->variant('list')->choices(...array_map(static fn (array $o): Choice => Choice::make('location_id', (int) $o['id'])
                            ->type('radio')->title((string) $o['name'])->text((string) ($o['address'] ?? ''))
                            ->checked((int) $o['id'] === $pickupSelected), $pickupOptions))?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="w-100 d-grid col-4 gap-4 mt-4">
                <div class="w-100 col-1"><?=FormField::key('shipping_phone_prefix')->phonePrefix()->label((string) __t('auth.fields.prefix'))->required()->value($values['shipping_phone_prefix'] ?? '+39')?></div>
                <div class="w-100 col-3"><?=FormField::key('shipping_phone')->phone()->label((string) __t('ecommerce.auth.fields.mobile'))->required()->value($values['shipping_phone'] ?? '')?></div>
            </div>
            <?php if ($shipping): ?>
                <div class="w-100 mt-5" data-checkout-shipping<?=$pickup ? ' hidden' : ''?>>
                    <h3 class="text fw-600 mb-3"><?=e(__t('ecommerce.checkout.shipping_methods'))?></h3>
                    <div class="w-100" data-checkout-shipping-methods>
                        <?=ChoiceGroup::make()->variant('list')->choices(...array_map(static fn (array $o): Choice => Choice::make('shipping_method_id', (int) $o['method_id'])
                            ->type('radio')->title((string) $o['name'])->text((string) ($o['description'] ?? ''))->aside((string) ($o['price_display'] ?? ''))
                            ->checked((int) $o['method_id'] === $shippingSelected), $shippingOptions))?>
                    </div>
                    <p class="w-100 wi-box p-4 text-small" data-checkout-notice role="status"<?=$shippingNotice === '' ? ' hidden' : ''?>><?=$shippingNotice === '' ? '' : e(__t('ecommerce.checkout.'.$shippingNotice))?></p>
                </div>
            <?php endif; ?>
        </section>
        <section id="pagamento" class="w-100">
            <h2 class="subtitle"><?=e(__t('ecommerce.checkout.payment'))?></h2>
            <p class="text-small tx-secondary mt-1 mb-3"><?=e(__t('ecommerce.checkout.secure'))?></p>
            <div class="w-100" data-checkout-payments>
                <?php if ($payments === []): ?>
                    <p class="text"><?=e(__t('ecommerce.checkout.no_payment_methods'))?></p>
                <?php else: ?>
                    <?=ChoiceGroup::make()->variant('list')->choices(...array_map($payment, $payments))?>
                <?php endif; ?>
            </div>
        </section>
        <section id="fatturazione" class="w-100">
            <h2 class="subtitle mb-3"><?=e(__t('ecommerce.checkout.billing'))?></h2>
            <?php if ($shipping): ?>
                <div class="w-100 mb-4" data-checkout-toggle="fulfillment_type:shipping"<?=$pickup ? ' hidden' : ''?>>
                    <?=ChoiceGroup::make()->variant('list')->choices(
                        Choice::make('same_as_shipping', '1')->type('radio')->title((string) __t('ecommerce.checkout.billing_same'))->checked($same),
                        Choice::make('same_as_shipping', '0')->type('radio')->title((string) __t('ecommerce.checkout.billing_different'))->checked(!$same)
                    )?>
                </div>
            <?php endif; ?>
            <div class="w-100 d-grid col-2 col-p-1 gap-4" data-checkout-toggle="<?=$shipping ? 'same_as_shipping:0|fulfillment_type:pickup' : ''?>"<?=$shipping && $same && !$pickup ? ' hidden' : ''?>>
                <?php foreach ($billing_fields as $field): ?><?=$field?><?php endforeach; ?>
            </div>
            <div class="w-100 mt-5">
                <?=Choice::make('invoice', '1')->type('checkbox')->title((string) __t('ecommerce.checkout.invoice'))->checked($invoice)?>
            </div>
            <div class="w-100 mt-4" data-checkout-toggle="invoice:on"<?=$invoice ? '' : ' hidden'?>>
                <?=ChoiceGroup::make()->variant('segmented')->choices(
                    Choice::make('billing_type', 'private')->type('radio')->title((string) __t('ecommerce.checkout.billing_private'))->checked(!$business),
                    Choice::make('billing_type', 'business')->type('radio')->title((string) __t('ecommerce.checkout.billing_business'))->checked($business)
                )?>
                <?php /* Il codice fiscale serve a tutti e due: l'azienda può averne uno diverso dalla partita IVA. */ ?>
                <div class="w-100 d-grid col-2 col-p-1 gap-4 mt-4">
                    <?php foreach ($invoice_fields as $key => $field): ?>
                        <?php if ($key === 'billing_cf'): ?>
                            <div class="w-100"><?=$field?></div>
                        <?php else: ?>
                            <div class="w-100" data-checkout-toggle="billing_type:business"<?=$business ? '' : ' hidden'?>><?=$field?></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <section id="conferma" class="w-100 d-flex d-column gap-4">
            <?=FormField::key('customer_note')->textarea()->label((string) __t('ecommerce.checkout.note'))->value($values['customer_note'] ?? '')?>
            <?php foreach ($consents as $type): ?>
                <?=FormField::key('accept_'.$type)->acceptDocument($type)->required()->value($values['accept_'.$type] ?? '')?>
            <?php endforeach; ?>
            <?php if ($guest): ?>
                <?=FormField::key('recaptcha')->recaptcha('ecommerce_checkout')?>
            <?php endif; ?>
            <?php $submit = Button::make((string) __t('ecommerce.checkout.'.($manual ? 'submit_manual' : 'submit_online')))->type('submit')->attr('data-checkout-submit', '')->variant('black')->size('lg')->class('w-100 wi-input-submit wi-submit'); ?>
            <?=$payments === [] ? $submit->disabled(true)->attr('data-checkout-locked', '') : $submit?>
        </section>
        <template data-checkout-choice><?=Choice::make('', '')->type('radio')?></template>
    </form>
    <?=View::component(Ecommerce::viewPath('components/checkout/aside.php'), $aside + ['button' => ''])?>
</div>
<?php if (!$guest && $user_email !== ''): ?>
    <form id="checkout-logout" method="post" action="<?=e(__r('ecommerce.auth.logout'))?>" hidden>
        <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
    </form>
<?php endif; ?>
<?php if (($checkoutJs = module_asset('ecommerce', 'js/checkout.js')) !== ''): ?>
    <script src="<?=e($checkoutJs)?>"></script>
<?php endif; ?>
<?php View::end(); ?>
