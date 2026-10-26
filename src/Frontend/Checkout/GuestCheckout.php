<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use RuntimeException;
use Throwable;
use Wonder\Auth\AuthLog;
use Wonder\Auth\Federated\FederatedIdentityRepository;
use Wonder\Auth\PasswordReset;
use Wonder\Auth\Frontend\UserAccountGateway;
use Wonder\Plugin\CustomerAccount\Account\ContactAccount;
use Wonder\Plugin\CustomerAccount\Account\CustomerContact;
use Wonder\Plugin\Ecommerce\Support\Throttle;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;

/**
 * L'ospite che ordina: l'ordine va a un account. Con un'email nuova
 * l'account nasce senza password; con l'email di un cliente del negozio senza
 * password, anche disattivato, si riusa così com'è. Ogni altro account ferma
 * l'ordine con `GuestAccountExists`; dal check-out rapido l'ordine nasce invece
 * senza account e si aggancia dopo, da `OrderClaim`.
 * La sessione resta da ospite: chi ordina non entra nell'account.
 */
final class GuestCheckout
{
    /** Il link per scegliere la password vale una settimana. */
    private const LINK_TTL = 7 * 86400;

    // Quante volte il blocco può dire che un'email ha un account: i limiti del signup del core.
    private const EXISTS_EMAIL = [3, 900];
    private const EXISTS_IP = [10, 3600];

    /** Si accende nelle Impostazioni di Set Up, riquadro «Negozio online»; spento per default. */
    public static function enabled(): bool
    {
        return (string) (Setting::current()['checkout_guest'] ?? 'false') === 'true';
    }

    /**
     * Trova o crea utente e contatto per l'email dell'ordine e salva i consensi.
     *
     * @param array<string, mixed> $order il carrello riletto dopo l'anteprima
     * @param array<string, mixed> $post
     * @param list<string> $asked i documenti chiesti dalla pagina
     * @param bool $express dal check-out rapido: l'email viene dal wallet
     * @return array{user_id: int, customer_id: int, created: bool}
     */
    public static function account(array $order, array $post, array $asked, bool $express = false): array
    {
        $users = new UserAccountGateway();
        $email = strtolower(trim((string) ($order['email'] ?? '')));
        $found = $users->findUserByEmail($email);
        $context = ['required_document_types' => $asked, 'ui_surface' => 'checkout'];

        if ($found !== null) {
            $userId = (int) $found['id'];
            // Un account con un modo per entrare è di chi lo possiede: l'ospite deve accedere.
            if (self::claimed($users, $userId)) {
                if (!$express) {
                    self::refuse($email, $userId);
                }
                // Il wallet ha già pagato: l'ordine nasce slegato, senza dire nulla dell'account.
                self::unlinked($email, $userId);
                self::consents(static fn () => consentService()->registerLeadConsents($email, $post, $context), $asked);

                return ['user_id' => 0, 'customer_id' => 0, 'created' => false];
            }
            // La sua scheda, o collegata ora; 0 se quella con la sua email è di un altro account.
            $customerId = (int) (CustomerContact::forUser($userId)['id'] ?? 0);

            // I consensi dell'account restano i suoi: l'accettazione si traccia sull'email.
            self::consents(static fn () => consentService()->registerLeadConsents($email, $post, $context), $asked);

            return ['user_id' => $userId, 'customer_id' => $customerId, 'created' => false];
        }

        $error = null;
        try {
            $userId = $users->createUserWithoutPassword(
                (string) ($order['shipping_name'] ?? ''),
                (string) ($order['shipping_surname'] ?? ''),
                $email,
                'frontend'
            );
        } catch (Throwable $error) {
            $userId = 0;
        }
        if ($userId <= 0) {
            // Per esempio un utente cancellato con la stessa email: l'account è un
            // servizio in più, l'ordine nasce lo stesso, senza account né link.
            Errors::internal($error ?? new RuntimeException('guest_account_not_created'), 'ecommerce.checkout.guest_account');

            return ['user_id' => 0, 'customer_id' => 0, 'created' => false];
        }

        $customerId = self::linkNew($userId, [
            'phone_prefix' => (string) ($order['shipping_phone_prefix'] ?? ''),
            'phone' => (string) ($order['shipping_phone'] ?? ''),
        ]);
        self::consents(static fn () => consentService()->registerBaseConsents($userId, $post, $context), $asked);

        return ['user_id' => $userId, 'customer_id' => $customerId, 'created' => true];
    }

    /**
     * Il link per scegliere la password, solo per un cliente del negozio attivo
     * che non ne ha una e non entra con Google (anche l'ospite che torna): a un
     * utente del backend la pagina del ripristino rifiuterebbe il token. I link
     * già mandati restano validi. Relativo: l'email lo fa assoluto.
     */
    public static function passwordLink(int $userId, string $restorePath): string
    {
        $users = new UserAccountGateway();
        if ($userId <= 0 || self::claimed($users, $userId) || !$users->canAccessArea($userId, 'frontend', ['client'])) {
            return '';
        }

        $issued = (new PasswordReset(self::LINK_TTL))->issueForUser($userId, null, [], false);

        return $restorePath.'?token='.rawurlencode((string) $issued->token);
    }

    /**
     * Il blocco dice che l'email ha un account: oltre i limiti la risposta
     * torna quella generica dei troppi ordini. Il registro ha l'hash, non l'email.
     */
    private static function refuse(string $email, int $userId): never
    {
        $hash = hash('sha256', $email);
        $ip = Throttle::ip();
        $allowed = Throttle::attempt('guest_exists', 'email:'.$hash, ...self::EXISTS_EMAIL)
            && Throttle::attempt('guest_exists', $ip === '' ? '' : 'ip:'.$ip, ...self::EXISTS_IP);

        try {
            AuthLog::write('guest_checkout_existing', $userId, 'frontend', false, ['email_hash' => $hash, 'limited' => !$allowed]);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.guest_exists_log');
        }

        if (!$allowed) {
            throw new RuntimeException((string) \__t('ecommerce.checkout.errors.too_many_orders'));
        }

        throw new GuestAccountExists();
    }

    /** L'ordine rapido con l'email di un account resta senza account: il registro ha l'hash, non l'email. */
    private static function unlinked(string $email, int $userId): void
    {
        try {
            AuthLog::write('guest_checkout_existing_unlinked', $userId, 'frontend', null, ['email_hash' => hash('sha256', $email)]);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.guest_unlinked_log');
        }
    }

    /**
     * `true` se l'account ha una password, entra con un'identità federata o
     * non è un cliente del negozio (area frontend, ruolo client). Il cliente
     * senza password resta agganciabile anche se disattivato (Q-GUEST-NOPWD).
     */
    private static function claimed(UserAccountGateway $users, int $userId): bool
    {
        $user = $users->findUserById($userId);
        $client = $user !== null && !$user['deleted']
            && in_array('frontend', $user['area'], true)
            && in_array('client', $user['authority'], true);

        return !$client
            || $users->hasLocalPassword($userId)
            || (new FederatedIdentityRepository())->findByUserId($userId) !== [];
    }

    /**
     * Il contatto di un account appena nato; `0` se la scheda con quella email è
     * già di un altro account: l'ordine nasce lo stesso, con l'email e l'utente.
     * Una scheda del commerciante con la stessa email e senza account si collega
     * così com'è: chi ordina non ne cambia nome e telefono, finché non prova
     * l'email scegliendo la password.
     *
     * @param array<string, string> $input
     */
    private static function linkNew(int $userId, array $input): int
    {
        $linked = ContactAccount::linkKeepingIdentity($userId, $input);
        CustomerContact::forget($userId);

        return ($linked->success ?? false) ? (int) ($linked->contact_id ?? 0) : 0;
    }

    /** @param list<string> $asked */
    private static function consents(callable $register, array $asked): void
    {
        if ($asked === []) {
            return;
        }

        try {
            $register();
        } catch (Throwable $error) {
            // Un consenso non registrato non ferma l'ordine.
            Errors::internal($error, 'ecommerce.checkout.guest_consents');
        }
    }
}
