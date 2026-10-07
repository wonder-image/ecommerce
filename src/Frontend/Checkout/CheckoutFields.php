<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

/** Come stanno i campi di indirizzi e fattura nella griglia a 4 colonne del checkout. */
final class CheckoutFields
{
    /** I campi della fattura in fila: ragione sociale e SDI riempiono una riga. */
    public const INVOICE = ['billing_business_name', 'billing_sdi', 'billing_pi', 'billing_cf', 'billing_pec'];

    private const SPANS = [
        'name' => 2, 'surname' => 2, 'country' => 2, 'province' => 2,
        'cap' => 1, 'number' => 1, 'sdi' => 1, 'phone_prefix' => 1,
        'city' => 3, 'street' => 3, 'phone' => 3, 'business_name' => 3,
    ];

    /** Le colonne occupate dal campo; quelli non elencati prendono la riga intera. */
    public static function span(string $key): int
    {
        return self::SPANS[preg_replace('/^(shipping|billing)_/', '', $key)] ?? 4;
    }

    /**
     * I campi dello schema nell'ordine delle chiavi date.
     *
     * @param array<string, mixed> $schema
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public static function pick(array $schema, array $keys): array
    {
        $fields = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $schema)) {
                $fields[$key] = $schema[$key];
            }
        }

        return $fields;
    }
}
