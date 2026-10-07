<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Wonder\Consent\ConsentDictionary;
use Wonder\Plugin\Gestionale\Models\Sales\Order;

/** Le regole della pagina di checkout: cosa serve per ordinare e cosa si scrive sull'ordine. */
final class CheckoutRules
{
    public const CONSENTS = ['privacy_policy', 'terms_conditions'];

    private const KEEP = ['shipping_name', 'shipping_surname', 'shipping_phone', 'shipping_phone_prefix'];
    public const ADDRESS = ['country', 'city', 'cap', 'street'];
    private const FISCAL = ['business_name', 'cf', 'pi', 'sdi', 'pec'];
    private const SAME = ['name', 'surname', 'country', 'province', 'city', 'cap', 'street', 'number', 'more', 'phone_prefix', 'phone'];

    /**
     * Cosa manca a contatti e consegna. `$order` è il carrello riletto dopo l'anteprima.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $preview
     * @return list<string> chiavi di ecommerce.checkout.errors.*
     */
    public static function deliveryErrors(array $order, array $preview, bool $shipping): array
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

        if (!self::addressComplete($order)) {
            $errors[] = 'address';
        }

        $methods = array_map('intval', array_column((array) ($preview['shipping_methods']['options'] ?? []), 'method_id'));
        if ($shipping && !in_array((int) ($order['shipping_method_id'] ?? 0), $methods, true)) {
            $errors[] = 'shipping_method';
        }

        return $errors;
    }

    /** @param array<string, mixed> $order */
    public static function addressComplete(array $order): bool
    {
        foreach (self::ADDRESS as $field) {
            if (trim((string) ($order['shipping_'.$field] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Il POST della pagina come va sul carrello: il cellulare del contatto
     * (prefisso e numero) è anche quello del corriere, col ritiro non resta
     * un indirizzo vecchio, con l'accesso fatto l'email è quella dell'utente.
     * La fatturazione arriva già decisa: le tasse si calcolano su quella.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function post(array $post, ?object $user = null): array
    {
        $prefix = is_scalar($post['shipping_phone_prefix'] ?? null) ? trim((string) $post['shipping_phone_prefix']) : '';
        $number = is_scalar($post['shipping_phone'] ?? null) ? trim((string) $post['shipping_phone']) : '';
        $post['shipping_phone_prefix'] = $prefix;
        $post['shipping_phone'] = $number;
        $post['phone'] = $number === '' ? '' : trim($prefix.' '.$number);

        if ((string) ($post['fulfillment_type'] ?? '') === 'pickup') {
            foreach (Order::shippingAddress()->keys() as $key) {
                if (!in_array($key, self::KEEP, true)) {
                    $post[$key] = '';
                }
            }
        }

        $email = trim((string) ($user->email ?? ''));
        if ($email !== '') {
            $post['email'] = $email;
        }

        return array_merge($post, self::billing($post, $post));
    }

    /**
     * L'opzione di pagamento dell'anteprima con quell'id: un id che la pagina
     * non ha offerto (manipolato o di un provider non collegato) non vale.
     *
     * @param array<string, mixed> $preview
     * @return array<string, mixed>|null
     */
    public static function method(array $preview, int $id): ?array
    {
        foreach ((array) ($preview['payment_methods']['options'] ?? []) as $option) {
            if (is_array($option) && (int) ($option['id'] ?? 0) === $id && $id > 0) {
                return $option;
            }
        }

        return null;
    }

    /**
     * La fatturazione da scrivere sull'ordine: «Uguale all'indirizzo di
     * spedizione» (di partenza, `same_as_shipping` diverso da `0`) copia
     * l'indirizzo, non col ritiro; col ritiro nome e cognome vuoti vengono
     * dalla consegna; il cellulare è sempre quello dei contatti; «Mi serve la
     * fattura» decide il tipo.
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

        $pickup = (string) ($order['fulfillment_type'] ?? '') === 'pickup';
        // «Uguale all'indirizzo di spedizione» è la scelta di partenza.
        if (!$pickup && (string) ($post['same_as_shipping'] ?? '1') !== '0') {
            foreach (self::SAME as $field) {
                $billing['billing_'.$field] = trim((string) ($order['shipping_'.$field] ?? ''));
            }
        }

        // Col ritiro nome e cognome si scrivono una volta sola, nella consegna.
        if ($pickup) {
            foreach (['name', 'surname'] as $field) {
                if (($billing['billing_'.$field] ?? '') === '') {
                    $billing['billing_'.$field] = trim((string) ($order['shipping_'.$field] ?? ''));
                }
            }
        }

        // Il cellulare si chiede una volta, nei contatti.
        foreach (['phone_prefix', 'phone'] as $field) {
            $billing['billing_'.$field] = trim((string) ($order['shipping_'.$field] ?? ''));
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
