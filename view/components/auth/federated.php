<?php
$auth_profile ??= new \Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceAuthProfile();
echo \Wonder\View\View::component('frontend.account.federated', [
    'auth_profile' => $auth_profile, 'csrf_token' => $csrf_token,
    'oidc_nonce' => $oidc_nonce, 'google_client_id' => $google_client_id ?? '',
    'auth_surface' => $auth_surface ?? 'login',
]);
