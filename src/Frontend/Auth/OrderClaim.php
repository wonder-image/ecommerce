<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Auth;

use Throwable;
use Wonder\Auth\Frontend\UserAccountGateway;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Orders\OrderOwner;

/**
 * Gli ordini fatti con l'email del cliente senza essere entrato (per esempio
 * dal check-out rapido) passano al suo account quando apre l'area cliente o il
 * checkout, se l'email è verificata. Mai per lo staff né per un utente
 * cancellato. Gira a ogni ingresso, senza segno in sessione: così si aggancia
 * anche un ordine fatto nel frattempo da un altro dispositivo. Senza niente da
 * prendere costa una SELECT sugli ordini.
 */
final class OrderClaim
{
    /** @var array<string, callable>|null */
    private static ?array $fake = null;

    /**
     * Solo per i test: `authenticated`, `user`, `verified`, `customer`, `claim`.
     *
     * @param array<string, callable>|null $fake
     */
    public static function useCollaborators(?array $fake): void
    {
        self::$fake = $fake;
    }

    /** Quanti ordini ha preso. Un errore va nel registro e non ferma la pagina. */
    public static function run(): int
    {
        try {
            if (!self::call('authenticated')) {
                return 0;
            }

            $user = self::call('user');
            $userId = (int) ($user['id'] ?? 0);
            $client = is_array($user) && $userId > 0 && !$user['deleted']
                && in_array('frontend', (array) $user['area'], true)
                && in_array('client', (array) $user['authority'], true);

            // Prima la verifica: customerId() può collegare la scheda.
            if (!$client || !self::call('verified', $userId)) {
                return 0;
            }

            $customerId = (int) self::call('customer');
            if ($customerId <= 0) {
                return 0;
            }

            return (int) self::call('claim', $userId, $customerId, (string) $user['email']);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.account.order_claim');

            return 0;
        }
    }

    private static function call(string $name, mixed ...$args): mixed
    {
        if (isset(self::$fake[$name])) {
            return (self::$fake[$name])(...$args);
        }

        return match ($name) {
            'authenticated' => CartSession::authenticated(),
            'user' => (new UserAccountGateway())->findUserById((int) (CartSession::user()->id ?? 0)),
            'verified' => \isUserEmailVerified((int) $args[0]),
            'customer' => CartSession::customerId(),
            'claim' => OrderOwner::claim((int) $args[0], (int) $args[1], (string) $args[2]),
        };
    }
}
