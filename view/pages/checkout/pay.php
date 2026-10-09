<?php

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\View\View;

Ecommerce::layout('checkout');

$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
$labels = [
    'failed' => (string) __t('ecommerce.checkout.pay.failed'),
    'error' => (string) __t('ecommerce.checkout.pay.error'),
];
?>
<div class="w-100 wi-box p-6">
    <h1 class="title"><?=e(__t('ecommerce.checkout.pay.title'))?></h1>
    <p class="text mt-4"><?=e(__t('ecommerce.checkout.pay.text', [
        'number' => (string) ($order['order_number'] ?? ''),
        'total' => CartPresenter::money($order['total'] ?? 0, (string) ($order['currency'] ?? 'EUR')),
    ]))?></p>
    <?php if ($client_secret === '' || $stripe['publishable_key'] === ''): ?>
        <p class="text mt-4"><?=e(__t('ecommerce.checkout.pay.error'))?></p>
    <?php else: ?>
        <div class="w-100 mt-6" data-checkout-pay
            data-client-secret="<?=e($client_secret)?>"
            data-publishable-key="<?=e($stripe['publishable_key'])?>"
            data-account="<?=e($stripe['account'])?>"
            data-return-url="<?=e($return_url)?>"
            data-labels="<?=e(json_encode($labels, $flags))?>">
            <div class="w-100" data-checkout-pay-element></div>
            <p class="text-small mt-3" data-checkout-pay-notice role="status"></p>
            <button type="button" class="btn btn-primary w-100 mt-4" data-checkout-pay-submit disabled><?=e(__t('ecommerce.checkout.pay.submit'))?></button>
        </div>
    <?php endif; ?>
    <form class="mt-4 a-c" method="post" action="<?=e(__r('ecommerce.checkout.abandon'))?>">
        <input type="hidden" name="csrf_token" value="<?=e($csrf_token)?>">
        <button type="submit" class="btn btn-link"><?=e(__t('ecommerce.checkout.pay.abandon'))?></button>
    </form>
</div>
<?php if (($checkoutJs = module_asset('ecommerce', 'js/checkout.js')) !== '') {
    View::head('<script src="'.e($checkoutJs).'" defer></script>');
} ?>
<?php View::end(); ?>
