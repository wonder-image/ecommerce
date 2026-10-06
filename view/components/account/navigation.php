<?php
$account_panel ??= new \Wonder\Plugin\Ecommerce\Frontend\Account\EcommerceAccountPanel();
echo \Wonder\View\View::component('frontend.account.navigation', [
    'items' => $items ?? $account_panel->navigation($user), 'active' => $active ?? '',
    'csrf_token' => $csrf_token, 'logout_url' => $logout_url ?? __r('ecommerce.auth.logout'),
]);
