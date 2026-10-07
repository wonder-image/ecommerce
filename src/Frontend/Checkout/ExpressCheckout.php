<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

/**
 * I bottoni del check-out rapido (Google Pay, Apple Pay, PayPal) in cima
 * alla pagina. Vuoto finché i pagamenti online non sono collegati (D6):
 * la vista allora non stampa la sezione.
 */
final class ExpressCheckout
{
    /**
     * @param array<string, mixed> $order
     * @return list<string> HTML già pronto, uno per bottone
     */
    public static function buttons(array $order): array
    {
        return [];
    }
}
