<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use RuntimeException;

/**
 * L'ospite ha scritto l'email di un account che non si può agganciare
 * (con password, con Google o non cliente del negozio): per ordinare con
 * quell'email deve accedere. L'ordine non nasce.
 *
 * Estende `RuntimeException` e non `UserError`, che è `final`.
 */
final class GuestAccountExists extends RuntimeException
{
    public function __construct()
    {
        parent::__construct((string) \__t('ecommerce.checkout.errors.account_exists'));
    }
}
