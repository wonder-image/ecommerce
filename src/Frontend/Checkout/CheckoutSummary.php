<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;

/** L'anteprima del checkout per la pagina: il gestionale calcola, qui si formatta. */
final class CheckoutSummary
{
    /**
     * @param array<string, mixed> $post
     * @param bool $keepCart se il modulo non porta le scelte di consegna e pagamento (prima visita, coupon senza JavaScript), restano quelle del carrello
     * @return array<string, mixed>
     */
    public static function payload(int $cartId, array $post, ?object $user = null, bool $keepCart = false): array
    {
        $data = CheckoutForm::data($post, $user);

        if ($keepCart) {
            unset($data['fulfillment_type'], $data['shipping_method_id'], $data['location_id'], $data['payment_method_id']);
        }

        $preview = Checkout::preview($cartId, $data);
        $valuta = (string) $preview['order']['currency'];

        $preview['display'] = [];
        foreach (['products_total', 'discount_total', 'shipping_total', 'fees_total', 'total'] as $chiave) {
            $preview['display'][$chiave] = CartPresenter::money($preview['order'][$chiave], $valuta);
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

        return $preview;
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
