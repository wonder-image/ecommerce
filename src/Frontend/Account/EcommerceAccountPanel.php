<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

use Wonder\Auth\Frontend\AccountPanel;
use Wonder\Plugin\Ecommerce\Ecommerce;

class EcommerceAccountPanel extends AccountPanel
{
    public function navigation(object $user, string $active = ''): array
    {
        $defaults = [
            'overview' => ['route' => 'ecommerce.account.index', 'icon' => 'bi bi-person-fill', 'label' => __t('ecommerce.account.navigation.overview')],
            'profile' => ['route' => 'ecommerce.account.profile', 'icon' => 'bi bi-person-fill-gear', 'label' => __t('ecommerce.account.navigation.profile')],
            'shipping' => ['route' => 'ecommerce.account.shipping', 'icon' => 'bi bi-geo-alt-fill', 'label' => __t('ecommerce.account.navigation.shipping')],
            'billing' => ['route' => 'ecommerce.account.billing', 'icon' => 'bi bi-person-vcard-fill', 'label' => __t('ecommerce.account.navigation.billing')],
            'payment-methods' => ['route' => 'ecommerce.account.payment-methods', 'icon' => 'bi bi-credit-card', 'label' => __t('ecommerce.account.navigation.payment_methods')],
            'password' => ['route' => 'ecommerce.account.password', 'icon' => 'bi bi-key-fill', 'label' => __t('account.navigation.password')],
        ];
        $overrides = (array) Ecommerce::config('account.navigation', []);
        foreach ($overrides as $key => $item) {
            if ($item === false) { unset($defaults[$key]); }
            elseif (is_array($item)) { $defaults[$key] = array_replace($defaults[$key] ?? [], $item); }
        }
        $items = [];
        foreach ($defaults as $key => $item) {
            $href = (string) ($item['href'] ?? __r((string) ($item['route'] ?? '')));
            if ($href !== '') { $items[] = ['key' => $key, 'href' => $href] + $item; }
        }
        return $items;
    }
}
