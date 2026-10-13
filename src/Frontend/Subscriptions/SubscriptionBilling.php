<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Subscriptions;

/**
 * I dati di fatturazione che un abbonamento chiede, come il checkout:
 * l'indirizzo (paese, città, CAP, via) e chi paga, nome e cognome oppure
 * ragione sociale. Pura.
 */
final class SubscriptionBilling
{
    public const ADDRESS = ['billing_country', 'billing_city', 'billing_cap', 'billing_street'];

    /** @param array<string, string> $billing */
    public static function complete(array $billing): bool
    {
        foreach (self::ADDRESS as $field) {
            if (trim((string) ($billing[$field] ?? '')) === '') {
                return false;
            }
        }

        $person = trim((string) ($billing['billing_name'] ?? '')) !== '' && trim((string) ($billing['billing_surname'] ?? '')) !== '';
        $business = trim((string) ($billing['billing_business_name'] ?? '')) !== '';

        return $person || $business;
    }
}
