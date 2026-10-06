<?php
// Compatibility entrypoint; authentication presentation belongs to app.
$auth_profile ??= new \Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceAuthProfile();
$surface ??= 'message';
$fields ??= $auth_profile->fields($surface, $values ?? [], $password_required ?? true);
include $auth_profile->viewPath('message');
