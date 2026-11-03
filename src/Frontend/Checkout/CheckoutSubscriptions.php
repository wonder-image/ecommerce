<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Throwable;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\PlanCatalog;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionSelection;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionsController;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionTracking;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;

/**
 * Gli abbonamenti dentro il checkout dei prodotti: la selezione in sessione
 * (`SubscriptionSelection`), le sue righe col prezzo del gestionale, il
 * tracciamento GA4 e i campi di fatturazione. Il checkout resta uno solo:
 * questa classe gli dice cosa c'è oltre all'ordine.
 */
final class CheckoutSubscriptions
{
    /**
     * I servizi da pagare nel checkout: solo con `online_subscriptions` e un
     * cliente col suo account (l'abbonamento non va all'ospite).
     *
     * @return list<array{price_code: string, quantity: int, customization: array<int, string>, parent: int}>
     */
    public static function items(): array
    {
        if (!Gestionale::feature('online_subscriptions') || !CartSession::authenticated()) {
            return [];
        }

        return SubscriptionSelection::items();
    }

    /** Il cliente ha servizi nella selezione, anche se non può ancora pagarli (ospite). */
    public static function selected(): bool
    {
        return Gestionale::feature('online_subscriptions') && SubscriptionSelection::items() !== [];
    }

    /**
     * Le righe del riepilogo: dalla selezione, coi prezzi del gestionale
     * quando il riepilogo è riuscito.
     *
     * @param list<array{price_code: string, quantity: int, customization: array<int, string>, parent: int}> $items
     * @param array<string, array{plan: array<string, mixed>, price: array<string, mixed>}> $catalog
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    public static function summaryRows(array $items, array $catalog, array $lines): array
    {
        $rows = [];

        foreach ($items as $index => $item) {
            $entry = $catalog[$item['price_code']] ?? null;
            $line = $lines[$index] ?? null;
            $unit = is_array($line) ? round((float) $line['unit_price'], 2) : null;
            $parent = $item['parent'] >= 0 ? ($items[$item['parent']] ?? null) : null;
            $parentEntry = $parent !== null ? ($catalog[$parent['price_code']] ?? null) : null;

            $rows[] = [
                'index' => $index,
                'name' => $entry !== null ? (string) $entry['price']['full_name'] : $item['price_code'],
                'price' => $entry['price'] ?? null,
                'quantity' => $item['quantity'],
                'customization' => is_array($line) ? Customizations::lines($line) : [],
                'unit_price' => $unit,
                'line_total' => $unit !== null ? round($unit * $item['quantity'], 2) : null,
                'parent' => $parentEntry !== null ? (string) $parentEntry['price']['full_name'] : '',
                'children' => count(array_filter($items, static fn (array $other): bool => $other['parent'] === $index)),
                'tracking' => $entry !== null && $unit !== null
                    ? SubscriptionTracking::item($entry['price'], (string) $entry['plan']['name'], $unit, $item['quantity'], $index)
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * L'item GA4 di una riga: prezzo mostrato più i sovrapprezzi delle
     * personalizzazioni scelte.
     *
     * @param array{plan: array<string, mixed>, price: array<string, mixed>} $found
     * @param array{price_code: string, quantity: int, customization: array<int, string>, parent: int} $item
     * @return array<string, mixed>
     */
    public static function trackingItem(array $found, array $item): array
    {
        $surcharge = 0.0;

        try {
            $surcharge = (float) Customizations::resolvePlan((int) $found['plan']['id'], $item['customization'])['surcharge'];
        } catch (Throwable) {
            $surcharge = 0.0;
        }

        return SubscriptionTracking::item($found['price'], (string) $found['plan']['name'], (float) $found['price']['amount'] + $surcharge, $item['quantity']);
    }

    /** @return array<string, array{plan: array<string, mixed>, price: array<string, mixed>}> codice del prezzo ⇒ piano e prezzo */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (PlanCatalog::present(PlanCatalog::load(), SubscriptionsController::configureUrl(...)) as $plan) {
            foreach ($plan['prices'] as $price) {
                $catalog[(string) $price['code']] = ['plan' => $plan, 'price' => $price];
            }
        }

        return $catalog;
    }

    /**
     * I campi di fatturazione del modulo, come nel checkout: lo schema del
     * gestionale con i valori della scheda del cliente.
     *
     * @param array<string, string> $values
     * @return array<string, mixed>
     */
    public static function billingFields(array $values): array
    {
        $schema = Subscription::billingAddress()->formSchema($values['billing_country'] ?? null);
        $labels = Subscription::billingAddress()->labels();
        $fields = [];

        foreach ($schema as $key => $field) {
            if (!is_object($field) || in_array($key, ['billing_phone_prefix', 'billing_phone'], true)) {
                continue;
            }

            if (isset($labels[$key]) && method_exists($field, 'label')) {
                $field->label((string) $labels[$key]);
            }

            if (method_exists($field, 'value') && array_key_exists($key, $values)) {
                $field->value((string) $values[$key]);
            }

            $fields[$key] = $field;
        }

        return $fields;
    }

    /** @param array<string, mixed> $contact @return array<string, string> */
    public static function contactValues(array $contact): array
    {
        $values = [];

        foreach (Subscription::billingAddress()->tableSchema() as $column) {
            $name = (string) $column->name;
            $values[$name] = trim((string) ($contact[substr($name, strlen('billing_'))] ?? ''));
        }

        $values['billing_country'] = $values['billing_country'] !== '' ? $values['billing_country'] : 'IT';

        return $values;
    }

    /** @return array<string, mixed> la scheda del cliente collegato, vuota se manca */
    public static function contact(): array
    {
        $contact = Contact::findById(CartSession::customerId());

        return is_array($contact) ? $contact : [];
    }
}
