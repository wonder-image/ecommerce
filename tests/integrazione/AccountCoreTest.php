<?php
/** php tests/integrazione/AccountCoreTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/dns-fixture.php';

use Wonder\App\Models\Contacts\Contact;
use Wonder\App\Models\User\User;
use Wonder\Auth\Frontend\AccountAddresses;
use Wonder\Auth\Frontend\AccountBilling;
use Wonder\Auth\Frontend\AccountPanel;
use Wonder\Auth\Frontend\AccountPersonal;
use Wonder\Auth\Frontend\ContactAccount;
use Wonder\Sql\Transaction;

final class AnnullaAccountCore extends RuntimeException {}

/** Come la fixture di AuthAccountTest.php:140-163: User::create, email verificata, password con user(). */
function clienteDiProva(string $prefix): int
{
    $email = $prefix.'-'.bin2hex(random_bytes(6)).'@example.com';
    $created = User::create([
        'name' => 'Ada', 'surname' => 'Lovelace', 'email' => $email,
        'username' => create_link(explode('@', $email)[0], 'user', 'username'),
        'authority' => json_encode(['client'], JSON_THROW_ON_ERROR),
        'area' => json_encode(['frontend'], JSON_THROW_ON_ERROR),
        'active' => 'true',
    ]);
    $id = (int) ($created->insert_id ?? 0);
    markUserEmailVerified($id, date('Y-m-d H:i:s'));
    $GLOBALS['ALERT'] = null;
    user([
        'password' => 'password-di-prova-123', 'password_confirmation' => 'password-di-prova-123',
        'area' => 'frontend', 'authority' => 'client',
    ], $id);
    $GLOBALS['ALERT'] = null;
    return $id;
}

try {
    Transaction::run(static function (): void {
        $panel = new AccountPanel();

        // Dati personali con data di nascita.
        $userId = clienteDiProva('account-core');
        $phone = '33'.random_int(10000000, 99999999);
        $saved = AccountPersonal::save($userId, ['name' => 'Grace', 'surname' => 'Hopper', 'birth_date' => '1990-05-17', 'phone_prefix' => '+39', 'phone' => $phone], $panel, false);
        $contact = Contact::find(['user_id' => $userId], 1);
        check('i dati personali salvano nome, cellulare canonico e data di nascita', fn () =>
            $saved->success && infoUser($userId, 'id')->name === 'Grace'
            && infoUser($userId, 'id')->phone === '+39'.$phone
            && ($contact['birth_date'] ?? null) === '1990-05-17');

        $bad = AccountPersonal::save($userId, ['name' => 'Grace', 'surname' => 'Hopper', 'birth_date' => '1990-02-31'], $panel, false);
        check('una data di nascita impossibile è un errore', fn () => !$bad->success && ($bad->errors['birth_date'] ?? '') === 'invalid');

        // Scheda in conflitto: nulla viene scritto.
        $otherId = clienteDiProva('account-other');
        $victimId = clienteDiProva('account-victim');
        $victimEmail = infoUser($victimId, 'id')->email;
        $conflictId = (int) ContactAccount::link($otherId)->contact_id;
        Contact::update(['email' => $victimEmail], $conflictId);
        $before = infoUser($victimId, 'id')->name;
        $conflict = AccountPersonal::save($victimId, ['name' => 'Cambiato', 'surname' => 'X', 'birth_date' => '2000-01-01'], $panel, false);
        check('con la scheda in conflitto non si scrive nulla', fn () =>
            !$conflict->success && ($conflict->errors['contact'] ?? '') === 'conflict'
            && infoUser($victimId, 'id')->name === $before
            && (Contact::find(['id' => $conflictId], 1)['birth_date'] ?? null) === null);

        // Indirizzi: aggiunta, modifica, eliminazione propria e altrui.
        $contactId = (int) $contact['id'];
        $values = ['name' => 'Ada', 'surname' => 'Lovelace', 'phone_prefix' => '+39', 'phone' => '3331234567', 'country' => 'IT', 'province' => 'MI', 'cap' => '20100', 'city' => 'Milano', 'street' => 'Via Roma', 'number' => '1'];
        $created = AccountAddresses::save($contactId, $values);
        $addressId = (int) $created->id;
        check('un indirizzo nuovo nasce sulla scheda del cliente', fn () => $created->success && AccountAddresses::find($contactId, $addressId) !== null);

        $edited = AccountAddresses::save($contactId, ['city' => 'Torino'] + $values, $addressId);
        check('la modifica tocca l\'indirizzo giusto', fn () => $edited->success && AccountAddresses::find($contactId, $addressId)['city'] === 'Torino');

        $invalid = AccountAddresses::save($contactId, ['cap' => ''] + $values);
        check('un indirizzo senza cap non si salva e dà messaggi', fn () => !$invalid->success && $invalid->messages !== []);

        $otherContact = (int) Contact::find(['user_id' => $otherId], 1)['id'];
        check('l\'indirizzo di un altro non si trova e non si elimina', fn () =>
            AccountAddresses::find($otherContact, $addressId) === null
            && AccountAddresses::delete($otherContact, $addressId) === false
            && AccountAddresses::find($contactId, $addressId) !== null);
        check('il cliente elimina il proprio indirizzo', fn () =>
            AccountAddresses::delete($contactId, $addressId) && AccountAddresses::find($contactId, $addressId) === null);

        // Scheda dell'indirizzo e fatturazione.
        $card = AccountAddresses::card($values);
        check('la scheda dell\'indirizzo ha nome, telefono e due righe', fn () =>
            $card['name'] === 'Ada Lovelace' && $card['phone_href'] === 'tel:+393331234567'
            && $card['lines'] === ['Via Roma 1, 20100', 'Milano (MI)']);

        $billing = AccountBilling::save($contactId, ['type' => 'private', 'name' => 'Ada', 'surname' => 'Lovelace', 'country' => 'IT', 'province' => 'MI', 'cap' => '20100', 'city' => 'Milano', 'street' => 'Via Roma', 'number' => '1']);
        $stored = Contact::find(['id' => $contactId], 1);
        check('la fatturazione salva sulla scheda del cliente', fn () =>
            $billing->success && $stored['city'] === 'Milano' && $stored['street'] === 'Via Roma');

        $badBilling = AccountBilling::save($contactId, ['type' => 'private', 'name' => 'Ada', 'surname' => 'Lovelace', 'country' => 'IT']);
        check('una fatturazione incompleta non si salva e dà messaggi', fn () => !$badBilling->success && $badBilling->messages !== []);

        throw new AnnullaAccountCore();
    });
} catch (AnnullaAccountCore) {
}

summary();
