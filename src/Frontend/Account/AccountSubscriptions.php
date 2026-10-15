<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

use Closure;
use Throwable;
use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\PlanCatalog;
use Wonder\Plugin\Ecommerce\Frontend\Subscriptions\SubscriptionView;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Subscriptions\PlanLinks;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionItems;

/**
 * Gli abbonamenti del cliente per l'area cliente (G10.14, G10b §7): piano,
 * stato, prossimo rinnovo, ultimi pagamenti, i servizi di ogni abbonamento e
 * le azioni (aggiungi, togli e cambia un servizio, disdici, aggiorna la
 * carta) solo dove valgono.
 *
 * Le righe `pending` non si mostrano: sono pagamenti mai completati su Stripe.
 */
final class AccountSubscriptions
{
    /** @return list<array<string, mixed>> */
    public static function forCustomer(int $contactId): array
    {
        if ($contactId <= 0) {
            return [];
        }

        $rows = EcommerceAccountController::rows(Subscription::find(
            '`customer_id` = '.$contactId." AND `status` <> 'pending' AND `deleted` = 'false'",
            null,
            'id',
            'DESC'
        ));
        $candidates = self::candidates();

        return array_map(static fn (array $row): array => self::present($row, $candidates), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array{code: string, price_id: int, plan_id: int, label: string}> $candidates i prezzi in vendita, da `candidates()`
     * @return array<string, mixed>
     */
    public static function present(array $row, array $candidates): array
    {
        $price = PlanPrice::findById((int) $row['plan_price_id']);
        $plan = is_array($price) ? Plan::findById((int) $price['plan_id']) : null;
        $status = (string) $row['status'];
        $live = in_array($status, Subscription::LIVE, true);
        $online = (string) ($row['provider'] ?? '') === 'stripe' && trim((string) ($row['provider_reference'] ?? '')) !== '';
        $renews = (string) ($row['auto_renew'] ?? 'true') === 'true';
        $editable = self::editable($row);
        $currency = (string) (($row['currency'] ?? '') ?: 'EUR');
        $code = (string) $row['code'];
        $items = self::items((int) $row['id']);
        $planOf = self::planOf($items, $candidates);
        $parentsOf = self::parentsOf();
        $addOptions = $editable ? AccountSubscriptionItems::addable($candidates, $items, $planOf, $parentsOf) : [];

        return [
            'code' => $code,
            'plan' => trim((is_array($plan) ? (string) $plan['name'].' — ' : '').(is_array($price) ? (string) $price['name'] : '')),
            'status' => $status,
            'status_label' => SubscriptionView::status($status),
            'total' => CartPresenter::money($row['total'] ?? 0, $currency),
            'renewal' => $live && $renews ? OrderSheet::date((string) ($row['next_renewal_at'] ?? '')) : '',
            'trial_end' => $status === 'trialing' ? OrderSheet::date((string) ($row['trial_ends_at'] ?? '')) : '',
            'ends' => $live && !$renews ? OrderSheet::date(self::endOf($row)) : '',
            'payments' => self::payments((int) $row['id'], $currency),
            'items' => array_map(
                static fn (array $item): array => self::presentItem($item, $items, $candidates, $planOf, $parentsOf, $editable, $code, $currency),
                $items
            ),
            'can_add' => $addOptions !== [],
            'add_options' => $addOptions,
            'add_url' => self::route('account.subscriptions.add', ['code' => $code]),
            'can_cancel' => $live && $online && $renews,
            'can_update_card' => $live && $online,
            'cancel_url' => self::route('account.subscriptions.cancel', ['code' => $code]),
            'card_url' => self::route('account.subscriptions.card', ['code' => $code]),
        ];
    }

    /**
     * I servizi di un abbonamento si toccano dall'area cliente solo se è in
     * corso, su Stripe e si rinnova: disdetto, si lascia finire com'è.
     *
     * @param array<string, mixed> $row
     */
    public static function editable(array $row): bool
    {
        return in_array((string) ($row['status'] ?? ''), Subscription::LIVE, true)
            && (string) ($row['provider'] ?? '') === 'stripe'
            && trim((string) ($row['provider_reference'] ?? '')) !== ''
            && (string) ($row['auto_renew'] ?? 'true') === 'true';
    }

    /** @return list<array<string, mixed>> le righe in corso, in arrivo o che terminano */
    public static function items(int $subscriptionId): array
    {
        try {
            return SubscriptionItems::forSubscription($subscriptionId, AccountSubscriptionItems::CURRENT);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * I prezzi in vendita, con gli id che servono alle regole dei servizi.
     *
     * @return list<array{code: string, price_id: int, plan_id: int, label: string}>
     */
    public static function candidates(): array
    {
        $loaded = PlanCatalog::load();
        $ids = [];

        foreach ($loaded as $entry) {
            foreach ($entry['prices'] as $row) {
                $ids[(string) $row['code']] = (int) $row['id'];
            }
        }

        $candidates = [];

        foreach (PlanCatalog::present($loaded, static fn (string $code): string => '') as $plan) {
            foreach ($plan['prices'] as $price) {
                if (($ids[$price['code']] ?? 0) <= 0) {
                    continue;
                }

                $candidates[] = [
                    'code' => (string) $price['code'],
                    'price_id' => $ids[$price['code']],
                    'plan_id' => (int) $plan['id'],
                    'label' => $price['full_name'].' · '.CartPresenter::money($price['amount'], PlanCatalog::CURRENCY).' '.SubscriptionView::every($price['unit'], $price['count']),
                ];
            }
        }

        return $candidates;
    }

    /**
     * Id del prezzo ⇒ id del piano, per i prezzi in vendita e per quelli delle
     * righe (anche se non più in vendita).
     *
     * @param list<array<string, mixed>> $items
     * @param list<array{code: string, price_id: int, plan_id: int, label: string}> $candidates
     * @return array<int, int>
     */
    public static function planOf(array $items, array $candidates): array
    {
        $map = [];

        foreach ($candidates as $candidate) {
            $map[$candidate['price_id']] = $candidate['plan_id'];
        }

        foreach ($items as $item) {
            $priceId = (int) ($item['plan_price_id'] ?? 0);

            if ($priceId > 0 && !isset($map[$priceId])) {
                $price = PlanPrice::findById($priceId);
                $map[$priceId] = is_array($price) ? (int) ($price['plan_id'] ?? 0) : 0;
            }
        }

        return $map;
    }

    /** @return Closure(int): list<int> i piani sotto cui deve stare un piano */
    public static function parentsOf(): Closure
    {
        return static function (int $planId): array {
            try {
                return array_values(array_map('intval', PlanLinks::parentsOf($planId)));
            } catch (Throwable) {
                return [];
            }
        };
    }

    /**
     * Una riga per la vista: nome, quantità, importo, date e le azioni.
     *
     * @param array<string, mixed> $item
     * @param list<array<string, mixed>> $items
     * @param list<array{code: string, price_id: int, plan_id: int, label: string}> $candidates
     * @param array<int, int> $planOf
     * @return array<string, mixed>
     */
    private static function presentItem(array $item, array $items, array $candidates, array $planOf, Closure $parentsOf, bool $editable, string $code, string $currency): array
    {
        $id = (int) $item['id'];
        $status = (string) $item['status'];
        $label = SubscriptionItems::label($item);
        $children = array_map(static fn (array $child): string => SubscriptionItems::label($child), AccountSubscriptionItems::children($items, $id));
        $changeOptions = $editable && $status === 'active' ? AccountSubscriptionItems::changeTargets($item, $items, $candidates, $planOf, $parentsOf) : [];
        $scheduled = '';

        try {
            $change = SubscriptionItems::scheduledChange($item);
        } catch (Throwable) {
            $change = null;
        }

        if ($change !== null) {
            $next = PlanPrice::findById((int) $change['new_plan_price_id']);
            $scheduled = (string) __t('ecommerce.account.subscriptions.item_scheduled', [
                'name' => is_array($next) ? trim(html_entity_decode((string) $next['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '',
                'date' => OrderSheet::date((string) ($change['effective_at'] ?? '')),
            ]);
        }

        return [
            'id' => $id,
            'label' => $label,
            'child' => (int) ($item['parent_item_id'] ?? 0) > 0,
            'quantity' => max(1, (int) round((float) ($item['quantity'] ?? 1))),
            'total' => CartPresenter::money($item['total'] ?? 0, $currency),
            'starts' => $status === 'pending' ? OrderSheet::date((string) ($item['starts_at'] ?? '')) : '',
            'ends' => $status === 'ending' ? OrderSheet::date((string) ($item['ends_at'] ?? '')) : '',
            'scheduled' => $scheduled,
            'can_remove' => $editable && AccountSubscriptionItems::removable($items, $id),
            'remove_confirm' => AccountSubscriptionItems::removeConfirm($label, $children),
            'remove_url' => self::route('account.subscriptions.item.remove', ['code' => $code, 'item' => $id]),
            'can_change' => $changeOptions !== [],
            'change_options' => $changeOptions,
            'change_url' => self::route('account.subscriptions.item.change', ['code' => $code, 'item' => $id]),
        ];
    }

    /** L'abbonamento del cliente con quel codice; `null` se non è suo. @return array<string, mixed>|null */
    public static function owned(string $code, int $contactId): ?array
    {
        if ($code === '' || $contactId <= 0) {
            return null;
        }

        $row = Subscription::find(['code' => $code, 'deleted' => 'false'], 1);

        return is_array($row) && (int) ($row['id'] ?? 0) > 0 && (int) $row['customer_id'] === $contactId ? $row : null;
    }

    /** Fine del vincolo se dura ancora, altrimenti fine periodo. @param array<string, mixed> $row */
    private static function endOf(array $row): string
    {
        $commitment = trim((string) ($row['commitment_ends_at'] ?? ''));

        if ($commitment !== '' && !str_starts_with($commitment, '0000') && strtotime($commitment) > time()) {
            return $commitment;
        }

        return (string) ($row['current_period_end'] ?? '');
    }

    /** Gli ultimi cinque pagamenti riusciti. @return list<array{date: string, amount: string}> */
    private static function payments(int $subscriptionId, string $currency): array
    {
        $rows = EcommerceAccountController::rows(Payment::find(
            ['subscription_id' => $subscriptionId, 'type' => 'payment', 'status' => 'paid', 'deleted' => 'false'],
            5,
            'paid_at',
            'DESC'
        ));

        return array_map(static fn (array $payment): array => [
            'date' => OrderSheet::date((string) ($payment['paid_at'] ?? '')),
            'amount' => CartPresenter::money($payment['amount'] ?? 0, $currency),
        ], $rows);
    }

    private static function route(string $name, array $parameters): string
    {
        try {
            return Route::url($name, $parameters);
        } catch (Throwable) {
            return '';
        }
    }
}
