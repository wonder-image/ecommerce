<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Wonder\Consent\ConsentDictionary;
use Wonder\Plugin\Gestionale\Models\Sales\Order;

/** Le regole dei passi del checkout: cosa serve per andare avanti e cosa si scrive sull'ordine. */
final class CheckoutSteps
{
    public const CONSENTS = ['privacy_policy', 'terms_conditions'];

    private const CART_KEYS = ['email', 'phone', 'fulfillment_type', 'shipping_method_id', 'location_id', 'payment_method_id', 'customer_note'];
    private const ADDRESS = ['country', 'city', 'cap', 'street'];
    private const FISCAL = ['business_name', 'cf', 'pi', 'sdi', 'pec'];
    private const SAME = ['name', 'surname', 'country', 'province', 'city', 'cap', 'street', 'number', 'more', 'phone_prefix', 'phone'];

    /**
     * Cosa manca al passo Spedizione. `$order` è il carrello riletto dopo l'anteprima.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $preview
     * @return list<string> chiavi di ecommerce.checkout.errors.*
     */
    public static function shippingErrors(array $order, array $preview, bool $shipping): array
    {
        $errors = [];
        $text = static fn (string $key): string => trim((string) ($order[$key] ?? ''));

        // Un'email non valida non si scrive: quella sul carrello può essere la vecchia.
        if (in_array('email', (array) ($preview['invalid'] ?? []), true)
            || filter_var($text('email'), FILTER_VALIDATE_EMAIL) === false
            || $text('phone') === '' || $text('shipping_name') === '' || $text('shipping_surname') === '') {
            $errors[] = 'contact';
        }

        if ($text('fulfillment_type') === 'pickup') {
            $ids = array_map('intval', array_column((array) ($preview['pickup_locations']['options'] ?? []), 'id'));
            if (!in_array((int) ($order['location_id'] ?? 0), $ids, true)) {
                $errors[] = 'pickup_location';
            }

            return $errors;
        }

        foreach (self::ADDRESS as $field) {
            if ($text('shipping_'.$field) === '') {
                $errors[] = 'address';
                break;
            }
        }

        $methods = array_map('intval', array_column((array) ($preview['shipping_methods']['options'] ?? []), 'method_id'));
        if ($shipping && !in_array((int) ($order['shipping_method_id'] ?? 0), $methods, true)) {
            $errors[] = 'shipping_method';
        }

        return $errors;
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $preview */
    public static function shippingComplete(array $order, array $preview, bool $shipping): bool
    {
        return self::shippingErrors($order, $preview, $shipping) === [];
    }

    /**
     * I valori dei moduli presi dal carrello: contatto, scelte, nota e indirizzi.
     *
     * @param array<string, mixed> $order
     * @return array<string, string>
     */
    public static function fromCart(array $order): array
    {
        $values = [];
        foreach (array_merge(self::CART_KEYS, Order::shippingAddress()->keys(), Order::billingAddress()->keys()) as $key) {
            $values[$key] = trim((string) ($order[$key] ?? ''));
        }

        return $values;
    }

    /**
     * La fatturazione da scrivere sull'ordine: «Uguale alla spedizione» copia
     * l'indirizzo (non col ritiro), «Mi serve la fattura» decide il tipo.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $order
     * @return array<string, string>
     */
    public static function billing(array $post, array $order): array
    {
        $billing = [];
        foreach ($post as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'billing_') && is_scalar($value)) {
                $billing[$key] = trim((string) $value);
            }
        }

        if (!empty($post['same_as_shipping']) && (string) ($order['fulfillment_type'] ?? '') !== 'pickup') {
            foreach (self::SAME as $field) {
                $billing['billing_'.$field] = trim((string) ($order['shipping_'.$field] ?? ''));
            }
        }

        $invoice = !empty($post['invoice']);
        $type = $invoice && ($billing['billing_type'] ?? '') === 'business' ? 'business' : 'private';
        $billing['billing_type'] = $type;

        foreach (self::FISCAL as $field) {
            if (!$invoice || ($type === 'private' && $field !== 'cf')) {
                $billing['billing_'.$field] = '';
            }
        }

        return $billing;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, string> $billing
     * @param list<string> $asked
     * @return list<string> chiavi di ecommerce.checkout.errors.*
     */
    public static function paymentErrors(array $post, array $billing, array $asked): array
    {
        $errors = [];

        foreach (array_merge(['name', 'surname'], self::ADDRESS) as $field) {
            if (($billing['billing_'.$field] ?? '') === '') {
                $errors[] = 'billing';
                break;
            }
        }

        if (!empty($post['invoice'])) {
            $needed = ($billing['billing_type'] ?? 'private') === 'business' ? ['business_name', 'pi'] : ['cf'];
            foreach ($needed as $field) {
                if (($billing['billing_'.$field] ?? '') === '') {
                    $errors[] = 'invoice';
                    break;
                }
            }
        }

        foreach ($asked as $type) {
            if (empty($post['accept_'.$type])) {
                $errors[] = 'consents';
                break;
            }
        }

        return $errors;
    }

    /**
     * I documenti da far accettare: quelli con un testo attivo nella lingua
     * della pagina che l'utente non ha già accettato (l'ospite li vede tutti).
     *
     * @return list<string>
     */
    public static function askedConsents(int $userId): array
    {
        $accepted = [];
        if ($userId > 0) {
            foreach ((array) (consentService()->getUserConsents($userId)['current_state'] ?? []) as $row) {
                $row = (array) $row;
                if ((string) ($row['current_status'] ?? '') === ConsentDictionary::STATUS_ACCEPTED) {
                    $accepted[] = (string) ($row['consent_type'] ?? '');
                }
            }
        }

        return array_values(array_filter(
            self::CONSENTS,
            static fn (string $type): bool => !in_array(ConsentDictionary::consentTypeFromDocumentType($type), $accepted, true)
                && sqlSelect('legal_documents', ['doc_type' => $type, 'language_code' => __l(), 'active' => 'true'], 1, 'published_at DESC, id', 'DESC')->exists
        ));
    }
}
