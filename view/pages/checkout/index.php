<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Tracking\DataLayer;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\View\View;

$order = (array) ($cart['order'] ?? []);
$items = array_values((array) ($cart['items'] ?? []));
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

$labels = [];
foreach (['free', 'no_shipping', 'shipping_total', 'fees_total', 'coupon_remove', 'submit_manual', 'submit_online', 'updating', 'summary_error'] as $key) {
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
<h1 class="title mb-8"><?=e(__t('ecommerce.checkout.title'))?></h1>
<div class="d-grid col-3 col-p-1 gap-6">
    <form id="checkout" class="col-2 col-p-1 d-grid col-1 gap-6" method="post" action="<?=e(__r('ecommerce.checkout.place'))?>" novalidate
        data-shipping="<?=$shipping ? 'on' : 'off'?>"
        data-coupons="<?=$coupons ? 'on' : 'off'?>"
        data-summary-url="<?=e(__r('ecommerce.checkout.summary'))?>"
        data-coupon-url="<?=e(__r('ecommerce.checkout.coupon'))?>"
        data-labels="<?=e(json_encode($labels, $flags))?>"
        data-initial="<?=e(json_encode($summary, $flags))?>">
        <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
        <section class="wi-box p-5">
            <h2 class="subtitle mb-4"><?=e(__t('ecommerce.checkout.contact'))?></h2>
            <div class="d-grid col-2 col-p-1 gap-4">
                <?=FormField::key('email')->email()->label((string) __t('ecommerce.auth.fields.email'))->required()->value($values['email'] ?? '')?>
                <?=FormField::key('phone')->tel()->label((string) __t('ecommerce.auth.fields.mobile'))->required()->value($values['phone'] ?? '')?>
            </div>
        </section>
        <section class="wi-box p-5">
            <h2 class="subtitle mb-4"><?=e(__t('ecommerce.checkout.billing'))?></h2>
            <div class="d-grid col-2 col-p-1 gap-4">
                <?php foreach ($billing_fields as $field): ?><?=$field?><?php endforeach; ?>
            </div>
        </section>
        <?php if ($shipping): ?>
            <section class="wi-box p-5" data-checkout-fulfillment>
                <h2 class="subtitle mb-4"><?=e(__t('ecommerce.checkout.delivery'))?></h2>
                <div class="d-flex gap-5">
                    <label class="d-flex gap-3">
                        <input type="radio" name="fulfillment_type" value="shipping"<?=$fulfillment === 'shipping' ? ' checked' : ''?>>
                        <span><?=e(__t('ecommerce.checkout.fulfillment_shipping'))?></span>
                    </label>
                    <label class="d-flex gap-3"<?=$canPickup ? '' : ' hidden'?>>
                        <input type="radio" name="fulfillment_type" value="pickup"<?=$fulfillment === 'pickup' ? ' checked' : ''?>>
                        <span><?=e(__t('ecommerce.checkout.fulfillment_pickup'))?></span>
                    </label>
                </div>
            </section>
        <?php endif; ?>
        <section class="wi-box p-5" data-checkout-shipping<?=$shipping && $fulfillment === 'pickup' ? ' hidden' : ''?>>
            <h2 class="subtitle mb-4"><?=e(__t('ecommerce.checkout.shipping'))?></h2>
            <div class="d-grid col-2 col-p-1 gap-4">
                <?php foreach ($shipping_fields as $field): ?><?=$field?><?php endforeach; ?>
            </div>
            <?php if ($shipping): ?>
                <h3 class="subtitle mt-5 mb-3"><?=e(__t('ecommerce.checkout.shipping_methods'))?></h3>
                <div class="d-grid col-1 gap-3" data-checkout-shipping-methods>
                    <?php foreach ($shippingOptions as $option): ?>
                        <label class="d-flex gap-3">
                            <input type="radio" name="shipping_method_id" value="<?=(int) $option['method_id']?>"<?=(int) $option['method_id'] === $shippingSelected ? ' checked' : ''?>>
                            <span><?=e(implode(' — ', array_filter([(string) $option['name'], (string) $option['description'], (string) ($option['price_display'] ?? '')])))?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="text-small mt-3" data-checkout-notice role="status"<?=$shippingOptions === [] ? '' : ' hidden'?>><?=$shippingOptions === [] ? e(__t('ecommerce.checkout.no_shipping')) : ''?></p>
            <?php endif; ?>
        </section>
        <?php if ($shipping): ?>
            <section class="wi-box p-5" data-checkout-pickup<?=$fulfillment === 'pickup' ? '' : ' hidden'?>>
                <h2 class="subtitle mb-4"><?=e(__t('ecommerce.checkout.pickup_locations'))?></h2>
                <div class="d-grid col-1 gap-3" data-checkout-pickup-locations>
                    <?php foreach ($pickupOptions as $option): ?>
                        <label class="d-flex gap-3">
                            <input type="radio" name="location_id" value="<?=(int) $option['id']?>"<?=(int) $option['id'] === $pickupSelected ? ' checked' : ''?>>
                            <span><?=e((string) $option['name'].' — '.(string) ($option['address'] ?? ''))?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
        <section class="wi-box p-5">
            <h2 class="subtitle mb-4"><?=e(__t('ecommerce.checkout.payment'))?></h2>
            <div data-checkout-payments>
                <?php if ($payment_methods === []): ?>
                    <p class="text"><?=e(__t('ecommerce.checkout.no_payment_methods'))?></p>
                <?php else: ?>
                    <?=$payment_field?>
                <?php endif; ?>
            </div>
            <div class="mt-4"><?=FormField::key('customer_note')->textarea()->label((string) __t('ecommerce.checkout.note'))->value($values['customer_note'] ?? '')?></div>
        </section>
        <?php if ($guest): ?>
            <?=FormField::key('recaptcha')->recaptcha('ecommerce_checkout')?>
        <?php endif; ?>
    </form>
    <aside class="wi-box p-5">
        <h2 class="subtitle mb-4"><?=e(__t('ecommerce.cart.summary'))?></h2>
        <div class="d-grid col-1 gap-3" data-checkout-lines>
            <?php foreach ($items as $item): ?>
                <div class="d-grid col-2 gap-3">
                    <span><?=e(CartPresenter::quantity($item['quantity'] ?? 1))?> × <?=e($item['name'] ?? '')?></span>
                    <strong class="a-r"><?=e(CartPresenter::money($item['line_total'] ?? 0, $currency))?></strong>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="d-grid col-1 gap-3 mt-5 pt-4" data-checkout-totals>
            <?php if ($display !== []): ?>
                <div class="d-grid col-2 gap-3"><span><?=e(__t('ecommerce.cart.products_total'))?></span><span class="a-r"><?=e($display['products_total'])?></span></div>
                <?php if ((float) $summary['order']['discount_total'] !== 0.0): ?>
                    <div class="d-grid col-2 gap-3"><span><?=e(__t('ecommerce.cart.discount'))?></span><span class="a-r"><?=e($display['discount_total'])?></span></div>
                <?php endif; ?>
                <?php if ($shipping && $fulfillment === 'shipping'): ?>
                    <div class="d-grid col-2 gap-3"><span><?=e(__t('ecommerce.checkout.shipping_total'))?></span><span class="a-r"><?=e($display['shipping_total'])?></span></div>
                <?php endif; ?>
                <?php if ((float) $summary['order']['fees_total'] !== 0.0): ?>
                    <div class="d-grid col-2 gap-3"><span><?=e(__t('ecommerce.checkout.fees_total'))?></span><span class="a-r"><?=e($display['fees_total'])?></span></div>
                <?php endif; ?>
                <div class="d-grid col-2 gap-3"><span class="fw-700"><?=e(__t('ecommerce.cart.total'))?></span><strong class="a-r"><?=e($display['total'])?></strong></div>
            <?php else: ?>
                <div class="d-grid col-2 gap-3">
                    <span class="fw-700"><?=e(__t('ecommerce.cart.total'))?></span>
                    <strong class="a-r"><?=e(CartPresenter::money($order['total'] ?? 0, $currency))?></strong>
                </div>
            <?php endif; ?>
        </div>
        <div class="mt-4" data-checkout-notices>
            <?php foreach ((array) ($summary['notices'] ?? []) as $message): ?><p class="text-small" role="status"><?=e($message)?></p><?php endforeach; ?>
        </div>
        <?php if ($coupons): ?>
            <form id="checkout-coupon" class="mt-5" method="post" action="<?=e(__r('ecommerce.checkout.coupon'))?>" data-checkout-coupon>
                <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
                <div class="d-grid col-2 gap-3">
                    <?=FormField::key('code')->text()->label((string) __t('ecommerce.checkout.coupon_label'))->value($couponCode)?>
                    <div class="d-flex gap-3 a-end">
                        <?=Button::make((string) __t('ecommerce.checkout.coupon_apply'))->type('submit')->variant('secondary')->attr('name', 'action')->attr('value', 'apply')?>
                        <?php $remove = Button::make((string) __t('ecommerce.checkout.coupon_remove'))->type('submit')->variant('secondary')->attr('name', 'action')->attr('value', 'remove')->attr('data-checkout-coupon-remove', ''); ?>
                        <?=$couponCode === '' ? $remove->attr('hidden', 'hidden') : $remove?>
                    </div>
                </div>
            </form>
        <?php endif; ?>
        <?=Button::make((string) __t('ecommerce.checkout.place_order'))->type('submit')->attr('form', 'checkout')->attr('data-checkout-submit', '')->variant('primary')->class('w-100 mt-5 wi-input-submit wi-submit')->disabled($payment_methods === [])?>
        <a class="d-block a-c text-small mt-4" href="<?=e(__r('ecommerce.cart.index'))?>"><?=e(__t('ecommerce.checkout.back_to_cart'))?></a>
    </aside>
</div>
<?php if (($checkoutJs = module_asset('ecommerce', 'js/checkout.js')) !== ''): ?>
    <script src="<?=e($checkoutJs)?>"></script>
<?php endif; ?>
<?php View::end(); ?>
