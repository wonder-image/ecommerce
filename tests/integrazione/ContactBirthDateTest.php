<?php
/** php tests/integrazione/ContactBirthDateTest.php */
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
use Wonder\Auth\Frontend\ContactAccount;
use Wonder\Sql\Transaction;

final class AnnullaBirthDate extends RuntimeException {}

try {
    Transaction::run(static function (): void {
        $email = 'birth-'.bin2hex(random_bytes(6)).'@example.com';
        $created = User::create([
            'name' => 'Ada', 'surname' => 'Lovelace', 'email' => $email,
            'username' => create_link(explode('@', $email)[0], 'user', 'username'),
            'authority' => json_encode(['client'], JSON_THROW_ON_ERROR),
            'area' => json_encode(['frontend'], JSON_THROW_ON_ERROR),
            'active' => 'true',
        ]);
        $userId = (int) ($created->insert_id ?? 0);
        $contactId = (int) (ContactAccount::link($userId)->contact_id ?? 0);

        Contact::update(['birth_date' => '1990-05-17'], $contactId);
        check('la scheda salva la data di nascita', fn () => (Contact::find(['id' => $contactId], 1)['birth_date'] ?? null) === '1990-05-17');

        Contact::update(['birth_date' => null], $contactId);
        check('la data di nascita si può togliere', function () use ($contactId) {
            $contact = Contact::find(['id' => $contactId], 1);

            return array_key_exists('birth_date', $contact) && $contact['birth_date'] === null;
        });

        throw new AnnullaBirthDate();
    });
} catch (AnnullaBirthDate) {
}

summary();
