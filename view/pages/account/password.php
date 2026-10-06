<?php

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

Ecommerce::layout('account', compact('title', 'errors', 'notice') + ['active' => 'password']);
?>
<?=View::component('frontend.account.password-form', [
    'has_password' => $has_password,
    'csrf_token' => $csrf_token,
    'cancel_url' => __r('ecommerce.account.index'),
])?>
<?php View::end(); ?>
