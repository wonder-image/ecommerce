<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Wonder\App\Support\GoogleMaps;
use Wonder\Consent\ConsentDictionary;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Providers\Payments\StripeMethods;

/** Le regole della pagina di checkout: cosa serve per ordinare e cosa si scrive sull'ordine. */
final class CheckoutRules
{
    public const CONSENTS = ['privacy_policy', 'terms_conditions'];

    private const KEEP = ['shipping_name', 'shipping_surname', 'shipping_phone', 'shipping_phone_prefix'];
    public const ADDRESS = ['country', 'city', 'cap', 'street'];
    /** Il modulo del checkout vuole anche il civico, e la provincia se il paese ne ha. La barra rapida no: i wallet scrivono il civico nella via. */
    public const FORM_ADDRESS = [...self::ADDRESS, 'number'];
    private const FISCAL = ['business_name', 'cf', 'pi', 'sdi', 'pec'];
    private const SAME = ['name', 'surname', 'country', 'province', 'city', 'cap', 'street', 'number', 'more', 'phone_prefix', 'phone'];

    /**
     * Cosa manca a contatti e consegna. `$order` è il carrello riletto dopo l'anteprima.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $preview
     * @return list<string> chiavi di ecommerce.checkout.errors.*
     */
    public static function deliveryErrors(array $order, array $preview, bool $shipping, bool $form = false): array
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

        if (!self::addressComplete($order, $form)) {
            $errors[] = 'address';
        }

        $methods = array_map('intval', array_column((array) ($preview['shipping_methods']['options'] ?? []), 'method_id'));
        if ($shipping && !in_array((int) ($order['shipping_method_id'] ?? 0), $methods, true)) {
            $errors[] = 'shipping_method';
        }

        return $errors;
    }

    /**
     * `$form`: l'indirizzo viene dal modulo del checkout, che chiede anche
     * civico e provincia.
     *
     * @param array<string, mixed> $order
     */
    public static function addressComplete(array $order, bool $form = false): bool
    {
        return self::filled($order, 'shipping_', $form ? self::FORM_ADDRESS : self::ADDRESS, $form);
    }

    /**
     * I campi pieni; con `$province` anche la provincia, quando il paese ne ha
     * (lo stesso criterio di `required_when_states_available` del core).
     *
     * @param array<string, mixed> $values
     * @param list<string> $fields
     */
    private static function filled(array $values, string $prefix, array $fields, bool $province): bool
    {
        $text = static fn (string $field): string => trim((string) ($values[$prefix.$field] ?? ''));

        foreach ($fields as $field) {
            if ($text($field) === '') {
                return false;
            }
        }

        return !$province || $text('province') !== '' || !self::hasStates($text('country'));
    }

    private static function hasStates(string $country): bool
    {
        return $country !== '' && function_exists('states') && states($country) !== [];
    }

    /**
     * Il POST della pagina come va sul carrello: il cellulare del contatto
     * (prefisso e numero) è anche quello del corriere, col ritiro non resta
     * un indirizzo vecchio (né il suo place ID), con l'accesso fatto l'email è quella dell'utente.
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

        $pickup = (string) ($post['fulfillment_type'] ?? '') === 'pickup';
        if ($pickup) {
            foreach (Order::shippingAddress()->keys() as $key) {
                if (!in_array($key, self::KEEP, true)) {
                    $post[$key] = '';
                }
            }
        }

        // Place ID e coordinate dell'aiuto di Google: se mancano o non valgono
        // si scrivono vuoti, così non resta quello di un indirizzo vecchio.
        foreach (GoogleMaps::cleanGeo($pickup ? [] : $post, 'shipping_') as $key => $value) {
            $post[$key] = $value === null ? '' : (string) $value;
        }

        $email = trim((string) ($user->email ?? ''));
        if ($email !== '') {
            $post['email'] = $email;
        }

        // Il radio vale «id» oppure «id:tipo»: da qui in poi l'id è nudo e il tipo sta a parte.
        if (array_key_exists('payment_method_id', $post)) {
            [$post['payment_method_id'], $post['stripe_method_type']] = self::splitPayment($post['payment_method_id']);
        }

        return array_merge($post, self::billing($post, $post));
    }

    /**
     * Il valore del radio del pagamento: «891» è il metodo, «891:klarna» è la
     * scelta Stripe «klarna» di quel metodo. Un id che non è un intero
     * positivo vale 0 (nessun metodo); un tipo che non è `[a-z0-9_]{1,40}`
     * vale `''`.
     *
     * @return array{0: int, 1: string} id e tipo Stripe
     */
    public static function splitPayment(mixed $value): array
    {
        if (is_int($value)) {
            return [$value > 0 ? $value : 0, ''];
        }

        if (!is_string($value)) {
            return [0, ''];
        }

        [$id, $type] = array_pad(explode(':', trim($value), 2), 2, '');

        $id = ctype_digit($id) && (int) $id > 0 ? (int) $id : 0;

        return $id > 0 && preg_match('/^[a-z0-9_]{1,40}$/', $type) === 1 ? [$id, $type] : [$id, ''];
    }

    /**
     * Il POST come va nello stato del modulo in sessione: del radio del
     * pagamento resta il valore grezzo che il browser ha mandato («891:klarna»),
     * non l'id nudo, così dopo un redirect con errori torna spuntata la stessa
     * scelta. `post()` lo spezza di nuovo per riepilogo e carrello.
     *
     * @param array<string, mixed> $post il POST già passato da `post()`
     * @param array<string, mixed> $raw il POST come è arrivato
     * @return array<string, mixed>
     */
    public static function rememberedPost(array $post, array $raw): array
    {
        $value = $raw['payment_method_id'] ?? null;

        if (is_string($value) && $value !== '') {
            $post['payment_method_id'] = $value;
            unset($post['stripe_method_type']);
        }

        return $post;
    }

    /** Apple Pay, Google Pay e Link fra le scelte: restano nascosti finché il browser non li conferma. */
    public static function wallet(array $option): bool
    {
        return StripeMethods::expressChoices([(string) ($option['stripe_method_type'] ?? '')]) !== [];
    }

    /**
     * La chiave della scelta di pagamento da spuntare: quella salvata, se la
     * pagina la offre e non è un wallet (resta nascosto finché il browser non
     * lo conferma); altrimenti la voce con l'id scelto e, per Stripe, la
     * carta. Vuota se nessuna.
     *
     * @param list<array<string, mixed>> $options
     */
    public static function checkedPayment(array $options, string $saved, int $selected): string
    {
        foreach ($options as $option) {
            if ($saved !== '' && !self::wallet($option) && (string) ($option['key'] ?? '') === $saved) {
                return $saved;
            }
        }

        foreach ($options as $option) {
            if ((int) ($option['id'] ?? 0) === $selected && in_array((string) ($option['stripe_method_type'] ?? ''), ['', 'card'], true)) {
                return (string) ($option['key'] ?? $option['id'] ?? '');
            }
        }

        return '';
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
     * Le scelte della fatturazione rilette dal carrello, quando la pagina
     * riparte senza il POST (ricarica, ritorno dal pagamento): una
     * fatturazione scritta e diversa dalla spedizione è «indirizzo diverso»,
     * il tipo azienda o il codice fiscale sono «Mi serve la fattura».
     *
     * @param array<string, mixed> $order
     * @return array<string, string>
     */
    public static function choices(array $order): array
    {
        $text = static fn (string $key): string => trim((string) ($order[$key] ?? ''));
        $choices = [];

        // Il cellulare si copia sempre dai contatti: non dice niente della scelta.
        if ($text('fulfillment_type') !== 'pickup' && $text('billing_street') !== '') {
            foreach (array_diff(self::SAME, ['phone_prefix', 'phone']) as $field) {
                if ($text('billing_'.$field) !== $text('shipping_'.$field)) {
                    $choices['same_as_shipping'] = '0';
                    break;
                }
            }
        }

        if ($text('billing_type') === 'business' || $text('billing_cf') !== '') {
            $choices['invoice'] = '1';
        }

        return $choices;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, string> $billing
     * @param list<string> $asked
     * @param bool $form la fatturazione viene dal modulo del checkout: servono anche civico e provincia
     * @return list<string> chiavi di ecommerce.checkout.errors.*
     */
    public static function paymentErrors(array $post, array $billing, array $asked, bool $form = false): array
    {
        $errors = [];

        if (!self::filled($billing, 'billing_', ['name', 'surname', ...($form ? self::FORM_ADDRESS : self::ADDRESS)], $form)) {
            $errors[] = 'billing';
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
