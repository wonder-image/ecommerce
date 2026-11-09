<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Wonder\App\Support\GoogleMaps;
use Wonder\Plugin\CustomerAccount\Account\AccountAddresses;
use Wonder\Plugin\CustomerAccount\Account\AccountBilling;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\Gestionale\Models\Sales\Order;

/**
 * L'indirizzo di consegna di un ordine resta nell'account del cliente: al
 * prossimo checkout lo ritrova già scritto. Uno uguale non si ripete; posizione,
 * predefinito e validazione sono quelli di customer-account.
 * Anche la fatturazione dell'ordine resta, nella scheda del cliente.
 */
final class AccountAddress
{
    /** @return int|null l'indirizzo nuovo, o null se non ne serviva uno */
    public static function remember(int $contactId, array $order): ?int
    {
        if ($contactId <= 0 || ($order['fulfillment_type'] ?? '') !== 'shipping') {
            return null;
        }

        $address = self::address($order);
        if ($address['street'] === '') {
            return null;
        }

        foreach (AccountAddresses::all($contactId) as $row) {
            if (self::same($address, $row)) {
                return null;
            }
        }

        // Scrive customer-account, con la sua validazione: un indirizzo incompleto
        // (dalla barra rapida, senza civico o provincia) non entra in rubrica e
        // l'ordine va avanti lo stesso.
        $saved = AccountAddresses::save($contactId, $address);
        if (!($saved->success ?? false)) {
            // Solo l'id della scheda e i messaggi della validazione: niente dati del cliente.
            error_log('Ecommerce checkout address not remembered for contact '.$contactId.': '.implode(' | ', array_map('strval', (array) ($saved->messages ?? []))));

            return null;
        }

        return (int) ($saved->id ?? 0) > 0 ? (int) $saved->id : null;
    }

    /**
     * La fatturazione dell'ordine nella scheda del cliente, con `AccountBilling` di customer-account.
     * Un ordine per un'azienda non scrive niente: la fatturazione era la sua, letta dalla sua scheda.
     * Senza «Mi serve la fattura» il codice fiscale salvato resta; nome, cognome e cellulare
     * della scheda cambiano solo se il cliente ha scritto una fatturazione diversa dalla spedizione
     * (con «uguale» sarebbero quelli di chi riceve il pacco).
     *
     * @param array<string, string> $billing i campi `billing_*` di `CheckoutRules::billing()`
     * @param array<string, mixed> $post
     */
    public static function rememberBilling(int $contactId, array $billing, array $post): bool
    {
        if ($contactId <= 0 || trim((string) ($billing['billing_street'] ?? '')) === '' || (int) ($post['company_id'] ?? 0) > 0) {
            return false;
        }

        $contact = Contact::findById($contactId);
        $contact = is_array($contact) ? $contact : [];
        // Sulla persona resta solo il codice fiscale: i dati da società stanno sulle aziende.
        $own = ['cf'];
        $person = ['name', 'surname', 'phone_prefix', 'phone'];
        $written = (string) ($post['same_as_shipping'] ?? '1') === '0';
        $values = [];
        foreach (array_keys(Contact::billing()->labels()) as $key) {
            $saved = trim((string) ($contact[$key] ?? ''));
            $value = trim((string) ($billing['billing_'.$key] ?? ''));
            // Con la fattura a una società il codice fiscale è della società, non della persona.
            $keep = (in_array($key, $own, true) && (empty($post['invoice']) || ($billing['billing_type'] ?? '') === 'business'))
                || (in_array($key, $person, true) && !$written && $saved !== '');
            $values[$key] = $keep ? $saved : $value;
        }

        $saved = AccountBilling::save($contactId, $values);
        if (!($saved->success ?? false)) {
            // Solo l'id della scheda e i messaggi della validazione: niente dati del cliente.
            error_log('Ecommerce checkout billing not remembered for contact '.$contactId.': '.implode(' | ', array_map('strval', (array) ($saved->messages ?? []))));

            return false;
        }

        return true;
    }

    /**
     * L'indirizzo di consegna dell'ordine con le chiavi della rubrica: place
     * ID e coordinate valide vengono con lui, quelle non valide valgono null.
     *
     * @param array<string, mixed> $order
     * @return array<string, string|float|null>
     */
    public static function address(array $order): array
    {
        $address = [];
        foreach (Order::shippingAddress()->keys() as $key) {
            $address[substr($key, strlen('shipping_'))] = trim((string) ($order[$key] ?? ''));
        }

        foreach (GoogleMaps::cleanGeo($order, 'shipping_') as $key => $value) {
            $address[substr($key, strlen('shipping_'))] = $value;
        }

        return $address;
    }

    /** Uguali a meno di maiuscole e spazi; place ID e coordinate non contano. */
    private static function same(array $address, array $row): bool
    {
        $normal = static fn (mixed $value): string => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $value)));
        unset($address['place_id'], $address['lat'], $address['lng']);

        foreach ($address as $key => $value) {
            if ($normal($value) !== $normal($row[$key] ?? '')) {
                return false;
            }
        }

        return true;
    }
}
