<?php
$auth_profile ??= new \Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceAuthProfile();
echo \Wonder\View\View::component('frontend.account.auth-errors', [
    'alert' => $alert ?? null, 'errors' => $errors ?? [],
    'federated_error' => $federated_error ?? null,
]);
