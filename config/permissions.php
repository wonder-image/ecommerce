<?php

use Wonder\App\Permission\Permission;
use Wonder\App\Permission\Permissions;

$route = static fn (string $name): string => function_exists('__r') ? __r($name) : $name;

Permissions::addPermission(
    Permission::make('client', 'frontend')
        ->name('Cliente')
        ->icon("<i class='bi bi-person'></i>")
        ->bg('bg-info')
        ->tx('text-white')
        ->color('info')
        ->creator(['admin', 'administrator'])
        ->route('login', 'ecommerce.auth.login')
        ->route('sign-in', 'ecommerce.auth.signup.request')
        ->route('password-restore', 'ecommerce.auth.password.restore')
        ->route('password-recovery', 'ecommerce.auth.password.recovery')
        ->route('password-set', 'ecommerce.auth.password.restore')
        ->function('creation', 'ecommerceClient')
        ->function('modify', 'ecommerceClient')
        ->function('info', 'infoEcommerceClient')
        ->function('validate', 'validateEcommerceClient')
        ->verification('email', [
            'required' => true,
            'token_link' => $route('ecommerce.auth.email.verify'),
            'sent_link' => $route('ecommerce.auth.email.sent'),
            'ttl_hours' => 24,
        ])
);

return Permissions::instance();
