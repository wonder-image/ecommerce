<?php
use Wonder\Plugin\Ecommerce\Frontend\Slots;
use Wonder\View\View;
// Lo apre EcommerceAuthProfile::layout(): `auth_layout` sono i dati per il layout del core.
$auth_layout = (array) ($auth_layout ?? []);
$auth_layout['auth_profile'] ??= new \Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceAuthProfile();
View::layout('frontend.account.auth', $auth_layout);
View::head(\Wonder\Plugin\Ecommerce\Frontend\StoreFont::style('auth'));
View::head(\Wonder\Plugin\Ecommerce\Frontend\StoreStyle::sheet());
$slot = ['surface' => (string) ($surface ?? '')];
echo Slots::render('auth.before', $slot);
echo $PAGE_CONTENT;
echo Slots::render('auth.after', $slot);
View::end();
