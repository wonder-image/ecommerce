<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

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

/**
 * Gli abbonamenti del cliente per l'area cliente (G10.14): piano e prezzo,
 * stato, prossimo rinnovo, ultimi pagamenti, e le tre azioni (cambia piano,
 * disdici, aggiorna la carta) solo dove valgono.
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
        $choices = PlanCatalog::present(PlanCatalog::load(), static fn (string $code): string => '');

        return array_map(static fn (array $row): array => self::present($row, $choices), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $plans i piani in vendita, da `PlanCatalog::present()`
     * @return array<string, mixed>
     */
    public static function present(array $row, array $plans): array
    {
        $price = PlanPrice::findById((int) $row['plan_price_id']);
        $plan = is_array($price) ? Plan::findById((int) $price['plan_id']) : null;
        $status = (string) $row['status'];
        $live = in_array($status, Subscription::LIVE, true);
        $online = (string) ($row['provider'] ?? '') === 'stripe' && trim((string) ($row['provider_reference'] ?? '')) !== '';
        $renews = (string) ($row['auto_renew'] ?? 'true') === 'true';
        $currency = (string) (($row['currency'] ?? '') ?: 'EUR');
        $code = (string) $row['code'];
        $options = [];

        foreach ($plans as $choice) {
            foreach ($choice['prices'] as $candidate) {
                if (is_array($price) && $candidate['code'] === (string) ($price['code'] ?? '')) {
                    continue;
                }

                $options[$candidate['code']] = $candidate['full_name'].' · '.CartPresenter::money($candidate['amount'], $currency).' '.SubscriptionView::every($candidate['unit'], $candidate['count']);
            }
        }

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
            'can_change' => $live && $online && $options !== [],
            'change_options' => $options,
            'can_cancel' => $live && $online && $renews,
            'can_update_card' => $live && $online,
            'change_url' => self::route('account.subscriptions.change', ['code' => $code]),
            'cancel_url' => self::route('account.subscriptions.cancel', ['code' => $code]),
            'card_url' => self::route('account.subscriptions.card', ['code' => $code]),
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
