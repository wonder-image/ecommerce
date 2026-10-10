<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Normalizer;

/**
 * Dal wallet al post del checkout: provincia, nome, via, fatturazione e
 * scelta di pagamento. Il post che ne esce passa poi da CheckoutRules::post,
 * come quello della pagina.
 */
final class ExpressAddress
{
    /** La fatturazione del wallet dell'ultimo totale cambiato: l'anteprima del foglio dopo ne conta l'imposta. */
    public const SESSION = 'ecommerce_express_billing';

    private const PLACE = ['country', 'province', 'city', 'cap'];

    /** La sigla della provincia da sigla o nome; maiuscole, accenti e trattini indifferenti. */
    public static function province(string $country, string $state): string
    {
        $state = trim($state);
        $states = $state === '' ? [] : (array) states(strtoupper(trim($country)));
        if (isset($states[strtoupper($state)])) {
            return strtoupper($state);
        }

        $wanted = self::fold($state);
        foreach ($states as $code => $name) {
            if (self::fold((string) $name) === $wanted) {
                return (string) $code;
            }
        }

        return '';
    }

    /**
     * Il post dell'anteprima mentre il cliente sceglie nel foglio del wallet:
     * Stripe dà solo paese, provincia, città e CAP. L'imposta si conta sulla
     * fatturazione: quella nota se c'è, se no il luogo della spedizione.
     *
     * @param array<string, mixed> $address
     * @param array<string, string>|null $billing Campi `billing_*`, di cui conta solo il luogo.
     * @return array<string, string>
     */
    public static function partial(array $address, string $rateId, ?array $billing = null): array
    {
        $country = strtoupper(self::text($address['country'] ?? ''));
        $post = [
            'fulfillment_type' => 'shipping',
            'shipping_country' => $country,
            'shipping_province' => self::province($country, self::text($address['state'] ?? '')),
            'shipping_city' => self::text($address['city'] ?? ''),
            'shipping_cap' => self::text($address['postal_code'] ?? ''),
        ];
        foreach (self::PLACE as $field) {
            $post['billing_'.$field] = $billing === null ? $post['shipping_'.$field] : self::text($billing['billing_'.$field] ?? '');
        }
        // Prima della scelta Stripe usa una tariffa provvisoria, che non è un metodo.
        if (ctype_digit($rateId) && (int) $rateId > 0) {
            $post['shipping_method_id'] = (string) (int) $rateId;
        }

        return $post;
    }

    /**
     * @param array<string, mixed> $wallet {email, phone, shipping: {name, address}, billing: {name, address}, rate}
     * @param array<string, mixed>|null $contact La scheda del cliente entrato.
     * @param list<string> $asked
     * @return array<string, string>
     */
    public static function post(array $wallet, ?array $contact, int $paymentMethodId, string $type, array $asked): array
    {
        $shipping = (array) ($wallet['shipping'] ?? []);
        $post = self::partial((array) ($shipping['address'] ?? []), self::text($wallet['rate'] ?? ''))
            + self::person('shipping', $shipping)
            + [
                'email' => self::text($wallet['email'] ?? ''),
                'shipping_phone_prefix' => '',
                'shipping_phone' => self::text($wallet['phone'] ?? ''),
                'payment_method_id' => $paymentMethodId.':'.$type,
                'invoice' => '',
            ];

        // La fatturazione salvata nella scheda vince su quella del wallet.
        $billing = self::savedBilling($contact) ?? self::walletBilling((array) ($wallet['billing'] ?? []));
        $post = array_merge($post, $billing === null ? ['same_as_shipping' => '1'] : ['same_as_shipping' => '0'] + $billing);

        // Il foglio del wallet non mostra caselle: chi paga accetta le condizioni scritte sopra il bottone.
        foreach ($asked as $document) {
            $post['accept_'.$document] = '1';
        }

        return $post;
    }

    /**
     * La fatturazione già nota prima del pagamento: quella salvata nella
     * scheda, se no quella del wallet ricordata dopo un totale cambiato.
     *
     * @param array<string, mixed>|null $contact
     * @return array<string, string>|null
     */
    public static function knownBilling(?array $contact): ?array
    {
        $remembered = $_SESSION[self::SESSION] ?? null;

        return self::savedBilling($contact) ?? (is_array($remembered) ? $remembered : null);
    }

    /** Ricorda il luogo di fatturazione del post rapido, per l'anteprima del prossimo foglio. */
    public static function remember(array $post): void
    {
        $billing = [];
        foreach (self::PLACE as $field) {
            $billing['billing_'.$field] = self::text($post['billing_'.$field] ?? '');
        }
        $_SESSION[self::SESSION] = $billing;
    }

    /**
     * Nome e via di un gruppo: il nome si divide sull'ultimo spazio, e una
     * parola sola vale per nome e cognome.
     *
     * @param array<string, mixed> $person {name, address}
     * @return array<string, string>
     */
    private static function person(string $group, array $person): array
    {
        $full = preg_replace('/\s+/u', ' ', self::text($person['name'] ?? '')) ?? '';
        $cut = strrpos($full, ' ');
        $address = (array) ($person['address'] ?? []);

        return [
            $group.'_name' => $cut === false ? $full : substr($full, 0, $cut),
            $group.'_surname' => $cut === false ? $full : substr($full, $cut + 1),
            $group.'_street' => trim(self::text($address['line1'] ?? '').' '.self::text($address['line2'] ?? '')),
            $group.'_number' => '',
            $group.'_more' => '',
        ];
    }

    /**
     * @param array<string, mixed>|null $contact
     * @return array<string, string>|null
     */
    private static function savedBilling(?array $contact): ?array
    {
        if ($contact === null) {
            return null;
        }

        foreach (array_merge(['name', 'surname'], CheckoutRules::ADDRESS) as $field) {
            if (self::text($contact[$field] ?? '') === '') {
                return null;
            }
        }

        $billing = [];
        foreach (['name', 'surname', 'country', 'province', 'city', 'cap', 'street', 'number', 'more'] as $field) {
            $billing['billing_'.$field] = self::text($contact[$field] ?? '');
        }

        return $billing;
    }

    /**
     * @param array<string, mixed> $billing {name, address}
     * @return array<string, string>|null
     */
    private static function walletBilling(array $billing): ?array
    {
        $address = (array) ($billing['address'] ?? []);
        if (self::text($address['city'] ?? '') === '' || self::text($address['line1'] ?? '') === '') {
            return null;
        }

        $country = strtoupper(self::text($address['country'] ?? ''));

        return self::person('billing', $billing) + [
            'billing_country' => $country,
            'billing_province' => self::province($country, self::text($address['state'] ?? '')),
            'billing_city' => self::text($address['city'] ?? ''),
            'billing_cap' => self::text($address['postal_code'] ?? ''),
        ];
    }

    /** Minuscole senza accenti né segni: «Forlì–Cesena» e «forli cesena» sono uguali. */
    private static function fold(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $decomposed = class_exists(Normalizer::class) ? Normalizer::normalize($text, Normalizer::FORM_D) : false;
        $text = is_string($decomposed)
            ? (preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text)
            : strtr($text, ['à' => 'a', 'á' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'í' => 'i', 'ò' => 'o', 'ó' => 'o', 'ù' => 'u', 'ú' => 'u']);

        return preg_replace('/[^a-z0-9]+/', '', $text) ?? '';
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
