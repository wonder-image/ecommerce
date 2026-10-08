<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Promotions\Campaigns;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;

/** I coupon riservati al cliente che può usare adesso, con valore e usi pronti da stampare. */
final class AccountCoupons
{
    /** @return list<array{code: string, value: string, uses: string}> */
    public static function forCustomer(int $contactId, string $now): array
    {
        $rows = [];
        foreach (Coupons::reserved($contactId) as $reserved) {
            $coupon = $reserved['coupon'];
            if (Campaigns::status($coupon, $now) !== 'running') {
                continue;
            }
            $limit = (int) ($coupon['usage_limit_per_customer'] ?? 0);
            $rows[] = [
                'code' => (string) $coupon['code'],
                'value' => self::value($coupon),
                'uses' => $reserved['used'].' / '.($limit > 0 ? (string) $limit : (string) __t('ecommerce.account.coupons.unlimited')),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $rows;
    }

    private static function value(array $coupon): string
    {
        return match ((string) ($coupon['discount_type'] ?? '')) {
            'percent' => OrderSheet::number($coupon['discount_value'] ?? 0).'%',
            'amount' => CartPresenter::money($coupon['discount_value'] ?? 0, 'EUR'),
            'free_shipping' => (string) __t('ecommerce.account.coupons.free_shipping'),
            'store_credit' => (string) __t('ecommerce.account.coupons.store_credit'),
            default => '—',
        };
    }
}
