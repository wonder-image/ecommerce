<?php

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

$active ??= 'shipping';
Ecommerce::layout('account', compact('title', 'errors', 'notice', 'active'));
?>
<?php if (!empty($intro)): ?><p class="text mb-5"><?=e($intro)?></p><?php endif; ?>
<?=View::component('frontend.account.address-form', [
    'form_id' => $active === 'billing' ? 'update_billing_address' : 'save_shipping_address',
    'fields' => $fields, 'csrf_token' => $csrf_token,
    'cancel_url' => __r($active === 'billing' ? 'ecommerce.account.index' : 'ecommerce.account.shipping'),
])?>
<?php View::end(); ?>
