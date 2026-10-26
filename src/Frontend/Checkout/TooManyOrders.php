<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use RuntimeException;

/**
 * Oltre il limite di ordini (per cliente, per indirizzo, per carrello o, solo
 * nel checkout normale, per email nota): l'ordine non nasce. La barra rapida
 * risponde `rejected` con `reason: too_many_orders`.
 *
 * Estende `RuntimeException` come prima: chi la prende così non cambia.
 */
final class TooManyOrders extends RuntimeException
{
    public function __construct()
    {
        parent::__construct((string) \__t('ecommerce.checkout.errors.too_many_orders'));
    }
}
