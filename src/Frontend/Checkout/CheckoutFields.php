<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

/** Come stanno i campi di indirizzi e fattura nella griglia a 4 colonne del checkout. */
final class CheckoutFields
{
    /** I campi della fattura in fila: ragione sociale e SDI riempiono una riga. */
    public const INVOICE = ['billing_business_name', 'billing_sdi', 'billing_pi', 'billing_cf', 'billing_pec'];

    private const SPANS = [
        'name' => 2, 'surname' => 2, 'city' => 2,
        'province' => 1, 'cap' => 1, 'number' => 1, 'sdi' => 1, 'phone_prefix' => 1,
        'street' => 3, 'phone' => 3, 'business_name' => 3,
    ];

    /** Da telefono la griglia ha 5 colonne: l'indirizzo da solo, poi civico e provincia, città e CAP, prefisso e telefono. */
    private const PHONE_SPANS = [
        'number' => 2, 'cap' => 2, 'phone_prefix' => 2, 'sdi' => 2,
        'province' => 3, 'city' => 3, 'phone' => 3, 'business_name' => 3,
    ];

    /** Le colonne occupate dal campo; quelli non elencati prendono la riga intera. */
    public static function span(string $key): int
    {
        return self::SPANS[preg_replace('/^(shipping|billing)_/', '', $key)] ?? 4;
    }

    /** Le colonne da telefono, sulla griglia `col-p-5`. */
    public static function phoneSpan(string $key): int
    {
        return self::PHONE_SPANS[preg_replace('/^(shipping|billing)_/', '', $key)] ?? 5;
    }

    /** Le classi della cella: colonne della griglia a 4 e di quella a 5 da telefono. */
    public static function cols(string $key): string
    {
        return 'col-'.self::span($key).' col-p-'.self::phoneSpan($key);
    }

    /** Place ID e coordinate dell'aiuto di Google: campi nascosti, fuori dalla griglia. */
    public static function hidden(string $key): bool
    {
        return in_array($key, ['shipping_place_id', 'shipping_lat', 'shipping_lng'], true);
    }

    /**
     * I campi che le regole del checkout vogliono pieni: la label prende
     * l'asterisco. Il codice fiscale dipende dal tipo e lo decide la pagina;
     * i campi nascosti il JS non li controlla.
     */
    public static function required(string $key): bool
    {
        $field = preg_replace('/^(shipping|billing)_/', '', $key);

        return in_array($field, [...['name', 'surname'], ...CheckoutRules::ADDRESS, 'business_name', 'pi'], true);
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
