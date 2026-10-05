<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\View\View;

$order = (array) ($cart['order'] ?? []);
$items = array_values((array) ($cart['items'] ?? []));
$currency = (string) ($order['currency'] ?? 'EUR');

Ecommerce::layout('checkout', compact('errors', 'notice'));
?>
<h1 class="title mb-8"><?=e(__t('ecommerce.checkout.title'))?></h1>
<form id="checkout" method="post" action="<?=e(__r('ecommerce.checkout.place'))?>" novalidate>
    <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
    <div class="d-grid col-3 col-p-1 gap-6">
        <div class="col-2 col-p-1 d-grid col-1 gap-6">
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
            <section class="wi-box p-5">
                <h2 class="subtitle mb-4"><?=e(__t('ecommerce.checkout.shipping'))?></h2>
                <div class="d-grid col-2 col-p-1 gap-4">
                    <?php foreach ($shipping_fields as $field): ?><?=$field?><?php endforeach; ?>
                </div>
            </section>
            <section class="wi-box p-5">
                <h2 class="subtitle mb-4"><?=e(__t('ecommerce.checkout.payment'))?></h2>
                <?php if ($payment_methods === []): ?>
                    <p class="text"><?=e(__t('ecommerce.checkout.no_payment_methods'))?></p>
                <?php else: ?>
                    <?=$payment_field?>
                <?php endif; ?>
                <div class="mt-4"><?=FormField::key('customer_note')->textarea()->label((string) __t('ecommerce.checkout.note'))->value($values['customer_note'] ?? '')?></div>
            </section>
            <?php if ($guest): ?>
                <?=FormField::key('recaptcha')->recaptcha('ecommerce_checkout')?>
            <?php endif; ?>
        </div>
        <aside class="wi-box p-5">
            <h2 class="subtitle mb-4"><?=e(__t('ecommerce.cart.summary'))?></h2>
            <div class="d-grid col-1 gap-3">
                <?php foreach ($items as $item): ?>
                    <div class="d-grid col-2 gap-3">
                        <span><?=e(CartPresenter::quantity($item['quantity'] ?? 1))?> × <?=e($item['name'] ?? '')?></span>
                        <strong class="a-r"><?=e(CartPresenter::money($item['line_total'] ?? 0, $currency))?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="d-grid col-2 gap-3 mt-5 pt-4">
                <span class="fw-700"><?=e(__t('ecommerce.cart.total'))?></span>
                <strong class="a-r"><?=e(CartPresenter::money($order['total'] ?? 0, $currency))?></strong>
            </div>
            <?=Button::make((string) __t('ecommerce.checkout.place_order'))->type('submit')->variant('primary')->class('w-100 mt-5 wi-input-submit wi-submit')->disabled($payment_methods === [])?>
            <a class="d-block a-c text-small mt-4" href="<?=e(__r('ecommerce.cart.index'))?>"><?=e(__t('ecommerce.checkout.back_to_cart'))?></a>
        </aside>
    </div>
</form>
<?php View::end(); ?>
