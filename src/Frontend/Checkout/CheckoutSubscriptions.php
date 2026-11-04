<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Throwable;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\PlanCatalog;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionSelection;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionsController;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionTracking;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionView;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OnlineSubscriptions;

/**
 * Gli abbonamenti dentro il checkout dei prodotti: la selezione in sessione
 * (`SubscriptionSelection`), le sue righe col prezzo del gestionale, il
 * tracciamento GA4 e i campi di fatturazione. Il checkout resta uno solo:
 * questa classe gli dice cosa c'è oltre all'ordine.
 */
final class CheckoutSubscriptions
{
    /** Il coupon degli abbonamenti: si salva solo quando il gestionale lo accetta. */
    public const COUPON = 'ecommerce.checkout_subscription_coupon';

    /** I metodi dell'abbonamento in pagina (`OnlineSubscriptions::startInPage`): il Payment Element deve averli uguali. */
    public const METHODS = ['card', 'link'];

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
                // Il nome del piano: il periodo lo dice già il prezzo sotto («9,90 €/Mese»).
                'name' => $entry !== null ? (string) $entry['plan']['name'] : $item['price_code'],
                'price' => $entry['price'] ?? null,
                'quantity' => $item['quantity'],
                'customization' => is_array($line) ? Customizations::lines($line) : [],
                'unit_price' => $unit,
                'line_total' => $unit !== null ? round($unit * $item['quantity'], 2) : null,
                'parent' => $parentEntry !== null ? (string) $parentEntry['plan']['name'] : '',
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

    /**
     * Aggiunge all'anteprima del checkout i servizi della selezione: le righe
     * (tipo `subscription`, come gli articoli per `lines.php` e il JS), il
     * primo addebito, il totale di oggi e Stripe in modo `subscription`.
     * Pagano solo le scelte Stripe che non sono wallet, con carta e Link come
     * l'abbonamento. Un errore del riepilogo va in `subscriptions.error` e
     * spegne il pagamento.
     *
     * @param array<string, mixed> $preview
     * @param array<string, mixed> $post
     */
    public static function extend(array &$preview, array $post, string $currency): void
    {
        $items = self::items();
        $preview['subscriptions'] = null;

        if ($items === []) {
            return;
        }

        $contact = self::contact();
        $billing = self::billing($post, $contact);
        $coupon = self::coupon();
        $error = '';
        $result = null;

        try {
            try {
                $result = OnlineSubscriptions::preview($contact, $items, $billing, $coupon);
            } catch (UserError $e) {
                if ($coupon === '' || !str_starts_with($e->key(), 'coupon.')) {
                    throw $e;
                }

                // Il codice non vale più (scaduto, esaurito): si toglie e si ricalcola.
                self::forgetCoupon();
                $coupon = '';
                $result = OnlineSubscriptions::preview($contact, $items, $billing, '');
            }
        } catch (UserError $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            Errors::internal($e, 'ecommerce.checkout.subscriptions');
            $error = (string) __t('ecommerce.subscriptions.error');
        }

        $amounts = (array) ($result['amounts'] ?? []);
        $trial = (int) ($result['trial'] ?? 0);
        $charge = $result !== null ? SubscriptionView::firstCharge($amounts, $trial) : null;
        $oneTime = $result !== null ? SubscriptionView::oneTimeLines($amounts) : [];

        foreach (self::summaryRows($items, self::catalog(), (array) ($result['lines'] ?? [])) as $row) {
            $total = (float) ($row['line_total'] ?? 0);
            $preview['items'][] = [
                'type' => 'subscription',
                'index' => $row['index'],
                'name' => $row['name'],
                'quantity' => $row['quantity'],
                // Un servizio si compra una volta sola: il badge resta solo per le quantità vere.
                'quantity_display' => $row['quantity'] > 1 ? CartPresenter::quantity($row['quantity']) : '',
                'line_total' => $total,
                'line_total_display' => $row['line_total'] !== null ? CartPresenter::money($total, $currency) : '',
                'parts' => self::parts($row, (float) ($oneTime[$row['index']] ?? 0), $currency),
                'children' => $row['children'],
                'image' => '',
                'image_html' => '',
                'tracking' => $row['tracking'],
            ];
        }

        $first = $charge !== null ? (float) $charge['total'] : 0.0;
        $today = round((float) ($preview['order']['total'] ?? 0) + $first, 2);
        $discount = $charge !== null ? (float) $charge['discount'] : 0.0;

        $preview['subscriptions'] = [
            'first_charge' => $first,
            'today_total' => $today,
            'discount' => $discount,
            'trial' => $trial,
            'coupon' => $coupon,
            'error' => $error,
        ];
        $preview['display']['subscriptions_first_charge'] = CartPresenter::money($first, $currency);
        $preview['display']['today_total'] = CartPresenter::money($today, $currency);
        $preview['display']['subscriptions_then'] = $result !== null
            ? self::then((float) ($amounts['total'] ?? 0), self::catalog()[$items[0]['price_code']]['price'] ?? null, $trial, $currency)
            : '';
        $preview['display']['discount_saved'] = CartPresenter::money(abs((float) ($preview['order']['discount_total'] ?? 0)) + $discount, $currency);

        // Il codice dell'abbonamento si toglie dal riquadro come gli altri.
        $codes = (array) ($preview['coupon']['codes'] ?? []);
        if ($coupon !== '' && !in_array(strtolower($coupon), array_map('strtolower', $codes), true)) {
            $preview['coupon']['codes'] = [...$codes, $coupon];
        }

        // L'abbonamento si paga solo con la carta dentro la pagina: niente wallet, niente manuali.
        $options = self::paymentOptions((array) ($preview['payment_methods']['options'] ?? []));

        $preview['payment_methods']['options'] = $options;

        if ($options !== [] && !in_array((string) ($preview['payment_methods']['selected'] ?? ''), array_map(static fn (array $o): string => (string) ($o['id'] ?? ''), $options), true)) {
            $preview['payment_methods']['selected'] = $options[0]['id'] ?? 0;
        }
        $preview['express'] = [];
        $preview['stripe'] = $options !== []
            ? ['mode' => 'subscription', 'amount' => (int) round($today * 100), 'currency' => strtolower($currency)] + OnlinePayment::browserKeys()
            : null;

        if ($options === [] && $error === '') {
            $preview['subscriptions']['error'] = (string) __t('ecommerce.subscriptions.error');
        }
    }

    /**
     * I servizi per carrello e mini-carrello: le righe e i totali come nel
     * checkout (`extend()` su un'anteprima con il solo ordine). `today_total`
     * parte da `$total`, il totale del carrello con la sua spedizione. Null
     * senza servizi. Pagamento ed errori restano al checkout.
     *
     * @param array<string, mixed> $order
     * @return array{items: list<array<string, mixed>>, first_charge: float, today_total: float, then: string, products: bool}|null
     */
    public static function cart(array $order, float $total, string $currency): ?array
    {
        if (self::items() === []) {
            return null;
        }

        $preview = ['order' => $order, 'items' => [], 'payment_methods' => ['options' => []], 'coupon' => ['codes' => []], 'display' => []];
        self::extend($preview, [], $currency);
        $first = (float) ($preview['subscriptions']['first_charge'] ?? 0);

        return [
            'items' => $preview['items'],
            'first_charge' => $first,
            'today_total' => round($total + $first, 2),
            'then' => (string) ($preview['display']['subscriptions_then'] ?? ''),
            'products' => self::hasProducts((int) ($order['id'] ?? 0)),
        ];
    }

    /**
     * Metodi di pagamento con un servizio nel carrello: solo la carta Stripe dentro la pagina,
     * perché gli altri (Klarna, PayPal, wallet, manuali) non fanno gli addebiti ricorrenti.
     *
     * @param list<array<string, mixed>> $options
     * @return list<array<string, mixed>>
     */
    public static function paymentOptions(array $options): array
    {
        $carte = [];

        foreach ($options as $option) {
            $option = (array) $option;
            $carta = in_array('card', (array) ($option['payment_method_types'] ?? []), true);
            if (($option['provider'] ?? '') === 'stripe' && !CheckoutRules::wallet($option) && $carta) {
                $carte[] = ['payment_method_types' => self::METHODS] + $option;
            }
        }

        return $carte;
    }

    /**
     * Le righe sotto il nome di un servizio: prezzo e ricorrenza, una
     * tantum, personalizzazioni, servizio a cui è legato, prova e vincolo.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public static function parts(array $row, float $oneTime, string $currency): array
    {
        $price = is_array($row['price'] ?? null) ? $row['price'] : null;
        $parts = [];

        if ($row['unit_price'] !== null && $price !== null) {
            $money = CartPresenter::money($row['unit_price'], $currency);
            $parts[] = $row['quantity'] > 1
                ? __t('ecommerce.subscriptions.summary.unit', ['price' => $money]).' '.SubscriptionView::every($price['unit'], $price['count'])
                : SubscriptionView::per($money, $price['unit'], $price['count']);
        }

        if ($oneTime > 0) {
            $parts[] = (string) __t('ecommerce.subscriptions.summary.one_time_line', ['price' => CartPresenter::money($oneTime, $currency)]);
        }

        foreach ((array) $row['customization'] as $line) {
            $parts[] = (string) $line;
        }

        if ($row['parent'] !== '') {
            $parts[] = (string) __t('ecommerce.subscriptions.summary.parent', ['name' => $row['parent']]);
        }

        if ($price !== null && (int) $price['trial_days'] > 0) {
            $parts[] = (string) __t('ecommerce.subscriptions.trial_once', ['days' => $price['trial_days']]);
        }

        if ($price !== null && (int) $price['commitment_months'] > 0) {
            $parts[] = (string) __t('ecommerce.subscriptions.commitment', ['months' => $price['commitment_months']]);
        }

        return array_values(array_map('strval', $parts));
    }

    /**
     * La riga sotto il totale, come quella di Stripe Checkout: «Poi 9,90 €/Mese»,
     * con la prova «Poi 9,90 €/Mese dal 18/11/2026». Vuota senza prezzo.
     *
     * @param array<string, mixed>|null $price il prezzo del primo servizio, per il periodo
     */
    public static function then(float $recurring, ?array $price, int $trial, string $currency): string
    {
        if ($price === null || $recurring <= 0) {
            return '';
        }

        $per = SubscriptionView::per(CartPresenter::money($recurring, $currency), (string) $price['unit'], (int) $price['count']);

        return $trial > 0
            ? (string) __t('ecommerce.subscriptions.summary.then_trial', ['price' => $per, 'date' => date('d/m/Y', strtotime('+'.$trial.' days'))])
            : (string) __t('ecommerce.subscriptions.summary.then', ['price' => $per]);
    }

    /**
     * La fatturazione dell'abbonamento: quella del modulo, se c'è, sopra la
     * scheda del cliente.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $contact
     * @return array<string, string>
     */
    public static function billing(array $post, array $contact): array
    {
        $billing = self::contactValues($contact);

        foreach ($billing as $key => $value) {
            if (is_scalar($post[$key] ?? null) && trim((string) $post[$key]) !== '') {
                $billing[$key] = trim((string) $post[$key]);
            }
        }

        return $billing;
    }

    /**
     * Prova il codice sull'abbonamento: se vale si ricorda, altrimenti si
     * toglie. Restituisce l'errore del gestionale, vuoto se il codice vale.
     */
    public static function applyCoupon(string $code): string
    {
        self::forgetCoupon();
        $code = trim($code);

        if ($code === '' || self::items() === []) {
            return '';
        }

        try {
            OnlineSubscriptions::couponFor($code, CartSession::customerId());
        } catch (UserError $e) {
            return $e->getMessage();
        }

        if (isset($_SESSION)) {
            $_SESSION[self::COUPON] = $code;
        }

        return '';
    }

    /** Il carrello ha articoli: senza, l'ordine non nasce e paga solo l'abbonamento. */
    public static function hasProducts(int $cartId): bool
    {
        return $cartId > 0 && Cart::contents($cartId)['items'] !== [];
    }

    public static function coupon(): string
    {
        return isset($_SESSION) && is_string($_SESSION[self::COUPON] ?? null) ? $_SESSION[self::COUPON] : '';
    }

    public static function forgetCoupon(): void
    {
        if (isset($_SESSION)) {
            unset($_SESSION[self::COUPON]);
        }
    }
}
