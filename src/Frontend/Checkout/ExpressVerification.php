<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

/**
 * Il segno che l'ospite ha passato il reCAPTCHA all'avvio del check-out
 * rapido. Vale solo per il carrello rapido nato in quell'avvio: `place` lo
 * vuole; l'ordine pagato, il foglio chiuso e l'avvio successivo lo tolgono.
 */
final class ExpressVerification
{
    public const SESSION = 'ecommerce_express_verified';

    public static function mark(int $cartId): void
    {
        $_SESSION[self::SESSION] = $cartId;
    }

    public static function verified(int $cartId): bool
    {
        return $cartId > 0 && (int) ($_SESSION[self::SESSION] ?? 0) === $cartId;
    }

    public static function clear(): void
    {
        unset($_SESSION[self::SESSION]);
    }
}
