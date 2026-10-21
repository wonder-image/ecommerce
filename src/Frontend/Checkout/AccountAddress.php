<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Wonder\App\Support\GoogleMaps;
use Wonder\Plugin\CustomerAccount\Account\AccountAddresses;
use Wonder\Plugin\Gestionale\Models\Sales\Order;

/**
 * L'indirizzo di consegna di un ordine resta nell'account del cliente: al
 * prossimo checkout lo ritrova già scritto. Uno uguale non si ripete; posizione,
 * predefinito e validazione sono quelli di customer-account.
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
