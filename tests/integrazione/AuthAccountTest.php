<?php
/** php tests/integrazione/AuthAccountTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/dns-fixture.php';

use Wonder\Auth\Federated\FederatedIdentityPayload;
use Wonder\Auth\Federated\FederatedIdentityRepository;
use Wonder\Auth\Federated\FederatedLoginService;
use Wonder\Auth\PasswordReset;
use Wonder\App\Models\User\User;
use Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceUserAccountGateway;
use Wonder\Plugin\Ecommerce\Frontend\Client\CustomerAccount;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Sql\Transaction;

final class AnnullaAuthAccount extends RuntimeException {}

$email = 'ecommerce-auth-'.bin2hex(random_bytes(6)).'@example.com';
$providerUserId = 'google-'.bin2hex(random_bytes(12));

try {
    Transaction::run(static function () use ($email, $providerUserId): void {
        $identity = new FederatedIdentityPayload(
            'google',
            $providerUserId,
            $email,
            true,
            'Ada',
            'Lovelace',
            ['sub' => $providerUserId, 'email_verified' => true]
        );
        $gateway = new EcommerceUserAccountGateway();
        $identities = new FederatedIdentityRepository();
        $service = new FederatedLoginService($gateway, $identities);
        $created = $service->authenticate($identity, 'frontend', ['client']);

        check('il federato crea un cliente verificato senza password locale', function () use ($created, $gateway, $email) {
            $user = $gateway->findUserById((int) $created->userId);

            return $created->success
                && $created->status === 'login_success_created_user'
                && ($user['email'] ?? '') === $email
                && ($user['password'] ?? '') === ''
                && in_array('client', $user['authority'] ?? [], true)
                && in_array('frontend', $user['area'] ?? [], true);
        });

        $linked = $identities->findByProviderIdentity('google', $providerUserId);
        check('l\'identità federata salva email verificata nel tipo previsto dal database', fn () =>
            (int) ($linked['user_id'] ?? 0) === (int) $created->userId
            && filter_var($linked['provider_email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
        );

        $again = $service->authenticate($identity, 'frontend', ['client']);
        check('un secondo accesso riusa il collegamento federato', fn () =>
            $again->success
            && $again->status === 'login_success_existing_link'
            && $again->userId === $created->userId
        );

        $contact = CustomerAccount::linkContact((int) $created->userId, [
            'phone_prefix' => '+39',
            'phone' => '3331234567',
        ]);
        $row = Contact::find(['user_id' => (int) $created->userId], 1);

        check('il cliente si collega al contatto senza dati di fatturazione', fn () =>
            ($contact->success ?? false)
            && is_array($row)
            && ($row['email'] ?? '') === $email
            && ($row['phone_prefix'] ?? '') === '+39'
            && ($row['phone'] ?? '') === '3331234567'
            && trim((string) ($row['business_name'] ?? '')) === ''
            && trim((string) ($row['pi'] ?? '')) === ''
            && trim((string) ($row['cf'] ?? '')) === ''
        );

        $secondEmail = 'ecommerce-auth-'.bin2hex(random_bytes(6)).'@example.com';
        $secondIdentity = new FederatedIdentityPayload(
            'google',
            'google-'.bin2hex(random_bytes(12)),
            $secondEmail,
            true,
            'Grace',
            'Hopper'
        );
        $second = $service->authenticate($secondIdentity, 'frontend', ['client']);
        $unlinked = Contact::create([
            'name' => 'Grace',
            'surname' => 'Hopper',
            'email' => $secondEmail,
            'is_customer' => 'true',
            'active' => 'true',
        ]);
        $reused = CustomerAccount::linkContact((int) $second->userId);

        check('una scheda con la stessa email e senza account viene riutilizzata', function () use ($unlinked, $reused, $second) {
            $row = Contact::findById((int) ($unlinked->insert_id ?? 0));

            return $second->success
                && ($reused->success ?? false)
                && (int) ($reused->contact_id ?? 0) === (int) ($unlinked->insert_id ?? 0)
                && (int) ($row['user_id'] ?? 0) === (int) $second->userId;
        });

        $thirdEmail = 'ecommerce-auth-'.bin2hex(random_bytes(6)).'@example.com';
        $thirdIdentity = new FederatedIdentityPayload(
            'google',
            'google-'.bin2hex(random_bytes(12)),
            $thirdEmail,
            true,
            'Katherine',
            'Johnson'
        );
        $third = $service->authenticate($thirdIdentity, 'frontend', ['client']);
        Contact::create([
            'name' => 'Katherine',
            'surname' => 'Johnson',
            'email' => $thirdEmail,
            'user_id' => (int) $created->userId,
            'is_customer' => 'true',
            'active' => 'true',
        ]);
        $conflict = CustomerAccount::linkContact((int) $third->userId);

        check('una scheda già collegata a un altro account viene rifiutata', fn () =>
            $third->success
            && !($conflict->success ?? true)
            && ($conflict->reason ?? '') === 'contact_link_conflict'
        );

        $localEmail = 'ecommerce-auth-'.bin2hex(random_bytes(6)).'@example.com';
        $localCreated = User::create([
            'name' => 'Margaret',
            'surname' => 'Hamilton',
            'email' => $localEmail,
            'username' => create_link(explode('@', $localEmail)[0], 'user', 'username'),
            'authority' => json_encode(['client'], JSON_THROW_ON_ERROR),
            'area' => json_encode(['frontend'], JSON_THROW_ON_ERROR),
            'active' => 'true',
        ]);
        $localUserId = (int) ($localCreated->insert_id ?? 0);
        markUserEmailVerified($localUserId, date('Y-m-d H:i:s'));
        $GLOBALS['ALERT'] = null;
        $localInput = [
            'phone_prefix' => '+39',
            'phone' => '+393337654321',
            '_ecommerce_contact_phone' => '3337654321',
            'password' => 'password-di-prova-123',
            'password_confirmation' => 'password-di-prova-123',
            'area' => 'frontend',
            'authority' => 'client',
            '_ecommerce_signup_completion' => true,
        ];
        $localCompleted = user($localInput, $localUserId);
        // Come fa EcommerceAuthProfile::afterUserSaved() dopo il salvataggio dell'utente.
        CustomerAccount::linkContact($localUserId, $localInput);
        $localUser = infoUser($localUserId, 'id');
        $localContact = Contact::find(['user_id' => $localUserId], 1);

        check('il secondo passaggio locale salva cellulare e password senza fatturazione', fn () =>
            empty($GLOBALS['ALERT'])
            && ($localCompleted->user->exists ?? false)
            && ($localUser->phone ?? '') === '+393337654321'
            && checkPassword('password-di-prova-123', (string) ($localUser->password ?? ''))
            && is_array($localContact)
            && ($localContact['phone_prefix'] ?? '') === '+39'
            && ($localContact['phone'] ?? '') === '3337654321'
            && trim((string) ($localContact['business_name'] ?? '')) === ''
            && trim((string) ($localContact['pi'] ?? '')) === ''
            && trim((string) ($localContact['cf'] ?? '')) === ''
        );

        $passwordReset = new PasswordReset(300);
        $resetToken = $passwordReset->issueForUser($localUserId, '/account/');
        $reset = $passwordReset->reset($resetToken->token, 'password-nuova-456');
        $replay = $passwordReset->reset($resetToken->token, 'password-nuova-789');

        if (!($reset->success ?? false) || ($replay->success ?? true)) {
            echo '  diagnostica reset: '.json_encode(['reset' => $reset, 'replay' => $replay])."\n";
        }

        check('il reset password consuma il token una sola volta nella transazione', fn () =>
            ($reset->success ?? false)
            && ($reset->continue_url ?? '') === '/account/'
            && checkPassword('password-nuova-456', (string) (infoUser($localUserId, 'id')->password ?? ''))
            && !($replay->success ?? true)
            && ($replay->reason ?? '') === 'password_reset_token_invalid'
        );

        $guestEmail = 'ecommerce-guest-'.bin2hex(random_bytes(6)).'@example.com';
        $guestId = $gateway->createUserWithoutPassword('Ada', 'Ospite', '  '.strtoupper($guestEmail).' ', 'frontend');
        $guest = $gateway->findUserById($guestId);

        check('l\'ospite diventa un cliente attivo senza password e con l\'email da verificare', fn () =>
            $guestId > 0
            && ($guest['email'] ?? '') === $guestEmail
            && ($guest['password'] ?? 'x') === ''
            && ($guest['active'] ?? false) === true
            && in_array('client', $guest['authority'] ?? [], true)
            && in_array('frontend', $guest['area'] ?? [], true)
            && !$gateway->hasLocalPassword($guestId)
            && $gateway->canAccessArea($guestId, 'frontend', ['client'])
            && (infoUser($guestId, 'id')->email_verified ?? true) === false
        );

        check('senza email valida non nasce nessun account', fn () =>
            $gateway->createUserWithoutPassword('Ada', 'Ospite', '', 'frontend') === 0
            && $gateway->createUserWithoutPassword('Ada', 'Ospite', 'non-una-email', 'frontend') === 0
        );

        $guestReset = new PasswordReset(7 * 86400);
        $guestToken = $guestReset->issueForUser($guestId, '/account/');
        $guestDone = $guestReset->reset($guestToken->token, 'password-ospite-123');

        check('scegliere la password dal link verifica l\'email', fn () =>
            ($guestDone->success ?? false)
            && $gateway->hasLocalPassword($guestId)
            && (infoUser($guestId, 'id')->email_verified ?? false) === true
        );

        throw new AnnullaAuthAccount();
    });
} catch (AnnullaAuthAccount) {
}

check('il test non lascia account nel database', fn () => !(infoUser($email, 'email')->exists ?? false));

summary();
