<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Subscriptions;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanFeature;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\System\Setting;

/**
 * I piani della pagina pubblica (G10 §5): letti dal gestionale, preparati per
 * la vista, per il JSON-LD e per il dataLayer, con gli **stessi** importi.
 *
 * `load()` legge il database; `present()`, `schemaOrg()` e `trackingItems()`
 * sono pure e le provano i test.
 *
 * Solo i piani attivi e visibili sul sito, con i prezzi attivi; un piano senza
 * prezzi attivi non si mostra.
 */
final class PlanCatalog
{
    public const CURRENCY = 'EUR';

    /**
     * @return list<array{plan: array<string, mixed>, prices: list<array<string, mixed>>, features: list<array<string, mixed>>}>
     */
    public static function load(): array
    {
        try {
            $plans = self::rows(Plan::find(['active' => 'true', 'visible_online' => 'true', 'deleted' => 'false'], null, 'position', 'ASC'));
            $result = [];

            foreach ($plans as $plan) {
                $prices = self::rows(PlanPrice::find(['plan_id' => (int) $plan['id'], 'active' => 'true', 'deleted' => 'false'], null, 'position', 'ASC'));

                if ($prices === []) {
                    continue;
                }

                $result[] = [
                    'plan' => $plan,
                    'prices' => $prices,
                    'features' => self::rows(PlanFeature::find(['plan_id' => (int) $plan['id'], 'deleted' => 'false'], null, 'position', 'ASC')),
                ];
            }

            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    /** Il listino è IVA inclusa? Come il catalogo dei prodotti (G10.4). */
    public static function pricesIncludeTax(): bool
    {
        try {
            return (string) (Setting::current()['catalog_prices_include_tax'] ?? 'true') === 'true';
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * I piani pronti per la vista: un prezzo mostrato (lo scontato se più
     * basso), quello pieno barrato, la frequenza a parole e l'indirizzo per
     * abbonarsi.
     *
     * @param list<array{plan: array<string, mixed>, prices: list<array<string, mixed>>, features: list<array<string, mixed>>}> $plans
     * @param callable(string): string $subscribeUrl il codice del prezzo → l'indirizzo
     * @return list<array{code: string, name: string, description: string, features: list<array{key: string, value: string}>, prices: list<array<string, mixed>>}>
     */
    public static function present(array $plans, callable $subscribeUrl): array
    {
        $out = [];

        foreach ($plans as $entry) {
            $plan = $entry['plan'];
            $name = trim(html_entity_decode((string) ($plan['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $prices = [];

            foreach ($entry['prices'] as $price) {
                $regular = round((float) ($price['price'] ?? 0), 2);
                $sale = round((float) ($price['sale_price'] ?? 0), 2);
                $shown = $sale > 0.0 && $sale < $regular ? $sale : $regular;
                [$unit, $count] = self::billing($price);
                $priceName = trim(html_entity_decode((string) ($price['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                $prices[] = [
                    'code' => (string) ($price['code'] ?? ''),
                    'name' => $priceName,
                    'full_name' => $name.' — '.$priceName,
                    'amount' => $shown,
                    'regular' => $shown < $regular ? $regular : null,
                    'unit' => $unit,
                    'count' => $count,
                    'trial_days' => max(0, (int) ($price['trial_days'] ?? 0)),
                    'commitment_months' => max(0, (int) ($price['commitment_months'] ?? 0)),
                    'url' => $subscribeUrl((string) ($price['code'] ?? '')),
                ];
            }

            $out[] = [
                'code' => (string) ($plan['code'] ?? ''),
                'name' => $name,
                'description' => trim(html_entity_decode(strip_tags((string) ($plan['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'features' => array_map(static fn (array $feature): array => [
                    'key' => (string) ($feature['feature_key'] ?? ''),
                    'value' => (string) ($feature['feature_value'] ?? ''),
                ], $entry['features']),
                'prices' => $prices,
            ];
        }

        return $out;
    }

    /**
     * L'`ItemList` della pagina: un `Product` per piano, un `Offer` per
     * prezzo con la sua `UnitPriceSpecification` (e il prezzo pieno barrato,
     * se in pagina si vede). Gli importi sono quelli di `present()`.
     *
     * @param list<array<string, mixed>> $presented
     * @return array<string, mixed>
     */
    public static function schemaOrg(array $presented, string $pageUrl, string $title, string $currency = self::CURRENCY): array
    {
        $items = [];

        foreach ($presented as $index => $plan) {
            $offers = [];

            foreach ($plan['prices'] as $price) {
                $specification = [[
                    '@type' => 'UnitPriceSpecification',
                    'price' => $price['amount'],
                    'priceCurrency' => $currency,
                    'unitCode' => $price['unit'] === 'year' ? 'ANN' : 'MON',
                    'billingDuration' => $price['count'],
                    'billingIncrement' => $price['count'],
                ]];

                if ($price['regular'] !== null) {
                    $specification[] = [
                        '@type' => 'UnitPriceSpecification',
                        'priceType' => 'https://schema.org/StrikethroughPrice',
                        'price' => $price['regular'],
                        'priceCurrency' => $currency,
                    ];
                }

                $offers[] = [
                    '@type' => 'Offer',
                    'name' => $price['name'],
                    'sku' => $price['code'],
                    'price' => $price['amount'],
                    'priceCurrency' => $currency,
                    'priceSpecification' => $specification,
                    'url' => $pageUrl,
                    'availability' => 'https://schema.org/InStock',
                ];
            }

            $product = [
                '@type' => 'Product',
                'name' => $plan['name'],
                'productID' => $plan['code'],
                'offers' => $offers,
            ];

            if ($plan['description'] !== '') {
                $product['description'] = $plan['description'];
            }

            $items[] = ['@type' => 'ListItem', 'position' => $index + 1, 'item' => $product];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $title,
            'url' => $pageUrl,
            'numberOfItems' => count($items),
            'itemListElement' => $items,
        ];
    }

    /**
     * Gli item GA4 della lista: uno per prezzo, con il codice del prezzo come
     * `item_id` (lo stesso del `sku` nel JSON-LD).
     *
     * @param list<array<string, mixed>> $presented
     * @return list<array<string, mixed>>
     */
    public static function trackingItems(array $presented): array
    {
        $items = [];

        foreach ($presented as $plan) {
            foreach ($plan['prices'] as $price) {
                $items[] = self::trackingItem($price, $plan['name'], count($items));
            }
        }

        return $items;
    }

    /**
     * Un item GA4 per un prezzo.
     *
     * @param array<string, mixed> $price
     * @return array<string, mixed>
     */
    public static function trackingItem(array $price, string $planName, ?int $index = null): array
    {
        $item = [
            'item_id' => (string) $price['code'],
            'item_name' => (string) $price['full_name'],
            'item_brand' => $planName,
            'item_category' => 'abbonamento',
            'item_variant' => (string) $price['name'],
            'price' => (float) $price['amount'],
            'quantity' => 1,
        ];

        if ($index !== null) {
            $item['index'] = $index;
        }

        return $item;
    }

    /** Il prezzo dal suo codice, già pronto come in `present()`; `null` se non è in vendita. @return array{plan: array<string, mixed>, price: array<string, mixed>}|null */
    public static function find(string $code, callable $subscribeUrl): ?array
    {
        foreach (self::present(self::load(), $subscribeUrl) as $plan) {
            foreach ($plan['prices'] as $price) {
                if ($price['code'] === $code && $code !== '') {
                    return ['plan' => $plan, 'price' => $price];
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $price @return array{0: string, 1: int} `month`/`year` e quanti */
    private static function billing(array $price): array
    {
        $term = max(1, (int) ($price['term_months'] ?? 1));

        return (string) ($price['billing_interval'] ?? 'month') === 'year'
            ? ['year', max(1, intdiv($term, 12))]
            : ['month', $term];
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return array_is_list($found) ? array_values(array_filter($found, 'is_array')) : [$found];
    }
}
