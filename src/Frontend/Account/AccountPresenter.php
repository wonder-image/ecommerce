<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

final class AccountPresenter
{
    public static function shippingLabel(array $address): string
    {
        $label = trim((string) ($address['label'] ?? ''));

        return $label !== '' ? $label : (string) __t('ecommerce.account.shipping.address');
    }

    public static function addressLines(array $address): array
    {
        return array_values(array_filter([
            trim((string) ($address['name'] ?? '').' '.(string) ($address['surname'] ?? '')),
            trim((string) ($address['street'] ?? '').' '.(string) ($address['number'] ?? '')),
            trim((string) ($address['cap'] ?? '').' '.(string) ($address['city'] ?? '').' '.(string) ($address['province'] ?? '')),
            trim((string) ($address['country'] ?? '')),
        ]));
    }
}
