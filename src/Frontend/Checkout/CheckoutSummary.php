<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;

/** L'anteprima del checkout per la pagina: il gestionale calcola, qui si formatta. */
final class CheckoutSummary
{
    /**
     * @param array<string, mixed> $post
     * @param bool $keepCart se il modulo non porta le scelte di consegna e pagamento (prima visita, coupon senza JavaScript), restano quelle del carrello; anche senza, le scelte che il modulo non porta restano quelle del carrello
     * @return array<string, mixed>
     */
    public static function payload(int $cartId, array $post, ?object $user = null, bool $keepCart = false): array
    {
        $data = CheckoutForm::data($post, $user);

        // Arriva al gestionale solo ciò che il modulo porta: il Pagamento non
        // ripete i campi della Spedizione e non deve azzerarli.
        foreach (['fulfillment_type', 'shipping_method_id', 'location_id', 'payment_method_id'] as $chiave) {
            if ($keepCart || !array_key_exists($chiave, $post)) {
                unset($data[$chiave]);
            }
        }

        foreach (['email', 'phone'] as $chiave) {
            if (!array_key_exists($chiave, $post) || $data[$chiave] === '') {
                unset($data[$chiave]);
            }
        }

        $preview = Checkout::preview($cartId, $data);
        $valuta = (string) $preview['order']['currency'];

        $preview['display'] = [];
        foreach (['products_total', 'discount_total', 'shipping_total', 'fees_total', 'total'] as $chiave) {
            // Lo sconto si legge col meno, come nel riepilogo disegnato dal server.
            $importo = $chiave === 'discount_total' ? -abs((float) $preview['order'][$chiave]) : $preview['order'][$chiave];
            $preview['display'][$chiave] = CartPresenter::money($importo, $valuta);
        }

        // Spedizione e commissione stanno nei totali, non tra gli articoli.
        $preview['items'] = CartPresenter::lines($preview['items']);

        foreach ($preview['items'] as $i => $riga) {
            $preview['items'][$i]['line_total_display'] = CartPresenter::money($riga['line_total'] ?? 0, $valuta);
            $preview['items'][$i]['quantity_display'] = CartPresenter::quantity($riga['quantity'] ?? 1);
        }

        foreach ($preview['shipping_methods']['options'] as $i => $opzione) {
            $preview['shipping_methods']['options'][$i]['price_display'] = !empty($opzione['free']) || (float) ($opzione['price'] ?? 0) <= 0
                ? (string) __t('ecommerce.checkout.free')
                : CartPresenter::money($opzione['price'] ?? 0, $valuta);
        }

        foreach ((array) ($preview['payment_methods']['options'] ?? []) as $i => $opzione) {
            $preview['payment_methods']['options'][$i] = $opzione + self::paymentDisplay((array) $opzione, $valuta);
        }

        // Il Payment Element si disegna prima che l'ordine nasca: gli servono
        // le chiavi pubbliche, l'importo in centesimi e la valuta, come all'intento.
        foreach ((array) ($preview['payment_methods']['options'] ?? []) as $opzione) {
            if (($opzione['provider'] ?? '') === 'stripe') {
                $preview['stripe'] = OnlinePayment::browserKeys() + [
                    'amount' => (int) round((float) $preview['order']['total'] * 100),
                    'currency' => strtolower($valuta),
                ];
                break;
            }
        }

        $preview['shipping_methods']['address_complete'] = CheckoutRules::addressComplete((array) Order::findById($cartId));
        $preview['display']['shipping_pending'] = Gestionale::feature('shipping')
            && (string) ($preview['fulfillment']['type'] ?? 'shipping') === 'shipping'
            && (int) ($preview['shipping_methods']['selected'] ?? 0) === 0;

        return $preview;
    }

    /**
     * I loghi di un metodo di pagamento: solo le chiavi note, con il loro svg.
     *
     * @param list<string> $keys
     * @return list<array{src: string, alt: string}>
     */
    public static function paymentIcons(array $keys): array
    {
        $icons = [];
        foreach ($keys as $key) {
            $src = isset(PaymentMethod::ICONS[$key]) ? (string) module_asset('ecommerce', 'payment-icons/'.$key.'.svg') : '';
            if ($src !== '') {
                $icons[] = ['src' => $src, 'alt' => PaymentMethod::ICONS[$key]];
            }
        }

        return $icons;
    }

    /**
     * Loghi, commissione e pannello di un'opzione di pagamento.
     *
     * @param array<string, mixed> $option
     * @return array{icon_urls: list<array{src: string, alt: string}>, fee_display: string, panel: string, key: string, stripe_method_type: string, payment_method_types: list<string>}
     */
    private static function paymentDisplay(array $option, string $currency): array
    {
        // La commissione la calcola il gestionale come quando la applica
        // (contrassegno dal listino, percentuale fino al 100%).
        $fee = (float) ($option['fee'] ?? 0);

        $stripe = ($option['provider'] ?? '') === 'stripe';

        return [
            'icon_urls' => self::paymentIcons((array) ($option['icons'] ?? [])),
            'fee_display' => $fee > 0 ? '+ '.CartPresenter::money($fee, $currency) : '',
            'panel' => match (true) {
                !empty($option['manual']) => (string) ($option['instructions'] ?? ''),
                // La carta si scrive nel Payment Element, sotto le scelte: niente pannello.
                $stripe => '',
                default => (string) __t('ecommerce.checkout.redirect_panel', ['name' => (string) ($option['name'] ?? '')]),
            },
            // Chiave e tipo li dà il gestionale (`+` tiene i suoi); questi sono solo il ripiego.
            'key' => (string) ($option['id'] ?? ''),
            'stripe_method_type' => $stripe ? 'card' : '',
            // Gli stessi metodi che avrà l'intento: li decide il gestionale, Stripe rifiuta la conferma se non coincidono.
            'payment_method_types' => $stripe ? array_values((array) ($option['payment_method_types'] ?? [])) : [],
        ];
    }

    /**
     * Applica o toglie il coupon, poi ridà l'anteprima. Un codice rifiutato
     * non è un errore della pagina: il messaggio del gestionale va in `error`.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function coupon(int $cartId, string $action, string $code, array $post, ?object $user = null, bool $keepCart = false): array
    {
        $errore = '';

        try {
            $action === 'remove' ? Coupons::remove($cartId) : Coupons::apply($cartId, trim($code));
        } catch (UserError $e) {
            $errore = $e->getMessage();
        }

        return self::payload($cartId, $post, $user, $keepCart) + ['error' => $errore];
    }
}
