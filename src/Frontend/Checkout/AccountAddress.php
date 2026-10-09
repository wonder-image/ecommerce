<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;
use Wonder\Plugin\Gestionale\Models\Sales\Order;

/**
 * L'indirizzo di consegna di un ordine resta nell'account del cliente: al
 * prossimo checkout lo ritrova già scritto. Uno uguale non si ripete, e il
 * primo diventa il predefinito.
 */
final class AccountAddress
{
    /** @return int|null l'indirizzo nuovo, o null se non ne serviva uno */
    public static function remember(int $contactId, array $order): ?int
    {
        if ($contactId <= 0 || ($order['fulfillment_type'] ?? '') !== 'shipping') {
            return null;
        }

        $address = [];
        foreach (Order::shippingAddress()->keys() as $key) {
            $address[substr($key, strlen('shipping_'))] = trim((string) ($order[$key] ?? ''));
        }
        if ($address['street'] === '') {
            return null;
        }

        $saved = ContactAddress::find(['contact_id' => $contactId, 'deleted' => 'false']);
        $saved = isset($saved['id']) ? [$saved] : array_values(array_filter((array) $saved, 'is_array'));
        foreach ($saved as $row) {
            if (self::same($address, $row)) {
                return null;
            }
        }

        $positions = array_map(static fn (array $row): int => (int) ($row['position'] ?? 0), $saved);
        $created = ContactAddress::create($address + [
            'contact_id' => $contactId,
            'is_default' => $saved === [] ? 'true' : 'false',
            'position' => ($positions === [] ? 0 : max($positions)) + 1,
        ]);

        return (int) ($created->insert_id ?? 0) ?: null;
    }

    /** Uguali a meno di maiuscole e spazi. */
    private static function same(array $address, array $row): bool
    {
        $normal = static fn (mixed $value): string => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $value)));

        foreach ($address as $key => $value) {
            if ($normal($value) !== $normal($row[$key] ?? '')) {
                return false;
            }
        }

        return true;
    }
}
