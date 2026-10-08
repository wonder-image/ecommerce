<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use RuntimeException;
use Throwable;
use Wonder\Auth\Federated\FederatedIdentityRepository;
use Wonder\Auth\PasswordReset;
use Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceUserAccountGateway;
use Wonder\Plugin\Ecommerce\Frontend\Client\CustomerAccount;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;

/**
 * L'ospite che ordina: l'ordine va a un account. Con un'email nuova
 * l'account nasce senza password; con un'email nota si riusa così com'è.
 * La sessione resta da ospite: chi ordina non entra nell'account.
 */
final class GuestCheckout
{
    /** Il link per scegliere la password vale una settimana. */
    private const LINK_TTL = 7 * 86400;

    /** Il commerciante lo accende nelle impostazioni del negozio; spento per default. */
    public static function enabled(): bool
    {
        return (string) (MerchantSetting::current()['checkout_guest'] ?? 'false') === 'true';
    }

    /**
     * Trova o crea utente e contatto per l'email dell'ordine e salva i consensi.
     *
     * @param array<string, mixed> $order il carrello riletto dopo l'anteprima
     * @param array<string, mixed> $post
     * @param list<string> $asked i documenti chiesti dalla pagina
     * @return array{user_id: int, customer_id: int, created: bool}
     */
    public static function account(array $order, array $post, array $asked): array
    {
        $users = new EcommerceUserAccountGateway();
        $email = strtolower(trim((string) ($order['email'] ?? '')));
        $found = $users->findUserByEmail($email);
        $context = ['required_document_types' => $asked, 'ui_surface' => 'checkout'];

        if ($found !== null) {
            $userId = (int) $found['id'];
            $contact = Contact::find(['user_id' => $userId], 1);
            $customerId = is_array($contact) && !empty($contact['id']) ? (int) $contact['id'] : self::link($userId, []);

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

        $customerId = self::linkNew($userId, $email, [
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
        $users = new EcommerceUserAccountGateway();
        if ($userId <= 0
            || $users->hasLocalPassword($userId)
            || !$users->canAccessArea($userId, 'frontend', ['client'])
            || (new FederatedIdentityRepository())->findByUserId($userId) !== []) {
            return '';
        }

        $issued = (new PasswordReset(self::LINK_TTL))->issueForUser($userId, null, [], false);

        return $restorePath.'?token='.rawurlencode((string) $issued->token);
    }

    /**
     * Il contatto dell'account; `0` se la scheda con quella email è già di un
     * altro account: l'ordine nasce lo stesso, con l'email e l'utente.
     *
     * @param array<string, string> $input
     */
    private static function link(int $userId, array $input): int
    {
        $linked = CustomerAccount::linkContact($userId, $input);

        return ($linked->success ?? false) ? (int) ($linked->contact_id ?? 0) : 0;
    }

    /**
     * Il contatto di un account appena nato. Una scheda del commerciante con la
     * stessa email e senza account si collega così com'è: chi ordina non ne
     * cambia nome e telefono, finché non prova l'email scegliendo la password.
     *
     * @param array<string, string> $input
     */
    private static function linkNew(int $userId, string $email, array $input): int
    {
        $contact = Contact::find(['email' => $email], 1);
        if (!is_array($contact) || empty($contact['id']) || (int) ($contact['user_id'] ?? 0) > 0) {
            return self::link($userId, $input);
        }

        $linked = Contact::update(['user_id' => $userId, 'is_customer' => 'true', 'active' => 'true'], (int) $contact['id']);

        return ($linked->success ?? false) ? (int) $contact['id'] : 0;
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
