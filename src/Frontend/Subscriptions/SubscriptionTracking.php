<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Subscriptions;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;

/**
 * Gli eventi GA4 degli abbonamenti (G10 §5): `begin_checkout` prima di Stripe
 * e `purchase` alla conferma, con gli stessi item della pagina dei piani
 * (`item_id` = codice del prezzo).
 *
 * `beginCheckout()` e `purchaseFrom()` sono pure; `purchase()` legge il
 * prezzo e il piano dell'abbonamento.
 */
final class SubscriptionTracking
{
    /**
     * @param array<string, mixed> $price un prezzo di `PlanCatalog::present()`
     * @return array<string, mixed>
     */
    public static function beginCheckout(array $price, string $planName, string $currency = PlanCatalog::CURRENCY): array
    {
        return [
            'event' => 'begin_checkout',
            'ecommerce' => [
                'currency' => $currency,
                'value' => (float) $price['amount'],
                'items' => [PlanCatalog::trackingItem($price, $planName)],
            ],
        ];
    }

    /**
     * `purchase` di un abbonamento confermato: il codice dell'abbonamento
     * come `transaction_id`, il totale del primo periodo come `value` (zero
     * in prova).
     *
     * @param array<string, mixed> $subscription riga di `gst_subscriptions`
     * @param array<string, mixed> $price riga di `gst_plan_prices`
     * @param array<string, mixed> $plan riga di `gst_plans`
     * @return array<string, mixed>
     */
    public static function purchaseFrom(array $subscription, array $price, array $plan): array
    {
        $trial = (string) ($subscription['status'] ?? '') === 'trialing';
        $value = $trial ? 0.0 : round((float) ($subscription['total'] ?? 0), 2);
        $planName = trim(html_entity_decode((string) ($plan['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $priceName = trim(html_entity_decode((string) ($price['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $ecommerce = [
            'transaction_id' => (string) ($subscription['code'] ?? ''),
            'currency' => strtoupper((string) (($subscription['currency'] ?? '') ?: PlanCatalog::CURRENCY)),
            'value' => $value,
            'tax' => $trial ? 0.0 : round((float) ($subscription['tax_total'] ?? 0), 2),
            'items' => [PlanCatalog::trackingItem([
                'code' => (string) ($price['code'] ?? ''),
                'name' => $priceName,
                'full_name' => $planName.' — '.$priceName,
                'amount' => $value,
            ], $planName)],
        ];

        $coupon = trim((string) ($subscription['coupon_code'] ?? ''));

        if ($coupon !== '') {
            $ecommerce['coupon'] = $coupon;
        }

        return ['event' => 'purchase', 'ecommerce' => $ecommerce];
    }

    /** @param array<string, mixed> $subscription @return array<string, mixed> */
    public static function purchase(array $subscription): array
    {
        try {
            $price = PlanPrice::findById((int) ($subscription['plan_price_id'] ?? 0));
            $plan = is_array($price) ? Plan::findById((int) ($price['plan_id'] ?? 0)) : null;
        } catch (Throwable) {
            $price = $plan = null;
        }

        return self::purchaseFrom($subscription, is_array($price) ? $price : [], is_array($plan) ? $plan : []);
    }
}
