<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Subscriptions;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionItems;

/**
 * Gli eventi GA4 degli abbonamenti (G10 §5, G10b §7): `add_to_cart` e
 * `remove_from_cart` sulla selezione, `begin_checkout` sul riepilogo e
 * `purchase` alla conferma, con gli stessi item della pagina dei piani
 * (`item_id` = codice del prezzo). Un item per servizio, con la sua quantità
 * e il prezzo unitario comprese le personalizzazioni.
 *
 * Tutto è puro tranne `purchase()`, che legge i servizi dell'abbonamento.
 */
final class SubscriptionTracking
{
    /**
     * Un item GA4 per un servizio della selezione.
     *
     * @param array<string, mixed> $price un prezzo di `PlanCatalog::present()`
     * @param float $unitPrice il prezzo di un pezzo, personalizzazioni comprese
     * @return array<string, mixed>
     */
    public static function item(array $price, string $planName, float $unitPrice, int $quantity, ?int $index = null): array
    {
        return array_replace(PlanCatalog::trackingItem($price, $planName, $index), [
            'price' => round($unitPrice, 2),
            'quantity' => max(1, $quantity),
        ]);
    }

    /** @param array<string, mixed> $item di `item()` @return array<string, mixed> */
    public static function addToCart(array $item, string $currency = PlanCatalog::CURRENCY): array
    {
        return self::event('add_to_cart', [$item], $currency);
    }

    /** @param list<array<string, mixed>> $items di `item()` @return array<string, mixed> */
    public static function removeFromCart(array $items, string $currency = PlanCatalog::CURRENCY): array
    {
        return self::event('remove_from_cart', $items, $currency);
    }

    /**
     * Il riepilogo prima di Stripe: un item per servizio, `value` è il totale
     * del primo addebito (coupon compreso, come in pagina).
     *
     * @param list<array<string, mixed>> $items di `item()`
     * @return array<string, mixed>
     */
    public static function beginCheckout(array $items, float $value, string $currency = PlanCatalog::CURRENCY, string $coupon = ''): array
    {
        $event = self::event('begin_checkout', $items, $currency);
        $event['ecommerce']['value'] = round($value, 2);

        if (trim($coupon) !== '') {
            $event['ecommerce']['coupon'] = trim($coupon);
        }

        return $event;
    }

    /**
     * `purchase` di un abbonamento confermato: il codice dell'abbonamento
     * come `transaction_id`, il totale del primo periodo come `value` (zero
     * in prova), un item per servizio.
     *
     * @param array<string, mixed> $subscription riga di `gst_subscriptions`
     * @param list<array{item: array<string, mixed>, price: array<string, mixed>, plan: array<string, mixed>}> $lines i servizi con prezzo e piano
     * @return array<string, mixed>
     */
    public static function purchaseFrom(array $subscription, array $lines): array
    {
        $trial = (string) ($subscription['status'] ?? '') === 'trialing';
        $items = [];

        foreach ($lines as $line) {
            $planName = self::text($line['plan']['name'] ?? '');
            $priceName = self::text($line['price']['name'] ?? '');
            $regular = round((float) ($line['price']['price'] ?? 0), 2);
            $sale = round((float) ($line['price']['sale_price'] ?? 0), 2);
            $surcharge = array_sum(array_map(
                static fn (array $field): float => (float) ($field['surcharge'] ?? 0),
                Customizations::decode($line['item']['customization'] ?? '')
            ));
            $shown = $sale > 0.0 && $sale < $regular ? $sale : $regular;

            $items[] = self::item([
                'code' => (string) ($line['price']['code'] ?? ''),
                'name' => $priceName,
                'full_name' => $planName.' — '.$priceName,
                'amount' => $shown,
            ], $planName, $shown + $surcharge, (int) round((float) ($line['item']['quantity'] ?? 1)));
        }

        $ecommerce = [
            'transaction_id' => (string) ($subscription['code'] ?? ''),
            'currency' => strtoupper((string) (($subscription['currency'] ?? '') ?: PlanCatalog::CURRENCY)),
            'value' => $trial ? 0.0 : round((float) ($subscription['total'] ?? 0), 2),
            'tax' => $trial ? 0.0 : round((float) ($subscription['tax_total'] ?? 0), 2),
            'items' => $items,
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
        $lines = [];

        try {
            foreach (SubscriptionItems::forSubscription((int) ($subscription['id'] ?? 0)) as $item) {
                $price = PlanPrice::findById((int) ($item['plan_price_id'] ?? 0));
                $plan = is_array($price) ? Plan::findById((int) ($price['plan_id'] ?? 0)) : null;
                $lines[] = ['item' => $item, 'price' => is_array($price) ? $price : [], 'plan' => is_array($plan) ? $plan : []];
            }
        } catch (Throwable) {
            $lines = [];
        }

        return self::purchaseFrom($subscription, $lines);
    }

    /** @param list<array<string, mixed>> $items @return array<string, mixed> */
    private static function event(string $name, array $items, string $currency): array
    {
        $value = array_sum(array_map(static fn (array $item): float => (float) $item['price'] * (int) $item['quantity'], $items));

        return [
            'event' => $name,
            'ecommerce' => ['currency' => $currency, 'value' => round($value, 2), 'items' => array_values($items)],
        ];
    }

    private static function text(mixed $value): string
    {
        return trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
