<?php
$auth_profile ??= new \Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceAuthProfile();
$auth_profile->layout([
    'title' => $title ?? '', 'text' => $text ?? '',
    'alert' => $alert ?? null, 'errors' => $errors ?? [],
    'federated_error' => $federated_error ?? null,
]);
echo \Wonder\Plugin\Ecommerce\Frontend\StoreFont::style('auth');
echo \Wonder\Plugin\Ecommerce\Frontend\StoreStyle::sheet();
echo $PAGE_CONTENT;
\Wonder\View\View::end();
