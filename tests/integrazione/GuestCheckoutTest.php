<?php
/** php tests/integrazione/GuestCheckoutTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/dns-fixture.php';

use Wonder\App\Models\User\User;
use Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceUserAccountGateway;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\GuestCheckout;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Sql\Transaction;

final class AnnullaGuestCheckout extends RuntimeException {}

/** Il carrello come lo rilegge il controller dopo l'anteprima. */
function carrelloOspite(string $email, string $nome = 'Lina'): array
{
    return [
        'email' => $email,
        'shipping_name' => $nome,
        'shipping_surname' => 'Ospite',
        'shipping_phone_prefix' => '+39',
        'shipping_phone' => '3330001111',
    ];
}

$email = 'ecommerce-ospite-'.bin2hex(random_bytes(6)).'@example.com';

try {
    Transaction::run(static function () use ($email): void {
        $gateway = new EcommerceUserAccountGateway();

        $nuovo = GuestCheckout::account(carrelloOspite('  '.strtoupper($email).' '), [], []);
        $utente = $gateway->findUserById($nuovo['user_id']);
        $contatto = Contact::find(['user_id' => $nuovo['user_id']], 1);

        check('un\'email nuova crea cliente e contatto, senza password', fn () =>
            $nuovo['created'] === true
            && ($utente['email'] ?? '') === $email
            && ($utente['password'] ?? 'x') === ''
            && is_array($contatto)
            && (int) $contatto['id'] === $nuovo['customer_id']
            && ($contatto['name'] ?? '') === 'Lina'
            && ($contatto['phone'] ?? '') === '3330001111'
        );

        // Come al secondo «Ordina» dopo un errore: l'account c'è già.
        $ancora = GuestCheckout::account(carrelloOspite($email, 'Altro'), [], []);

        check('la stessa email riusa account e contatto senza cambiarne i dati', function () use ($ancora, $nuovo) {
            $contatto = (array) Contact::findById($nuovo['customer_id']);

            return $ancora === ['user_id' => $nuovo['user_id'], 'customer_id' => $nuovo['customer_id'], 'created' => false]
                && ($contatto['name'] ?? '') === 'Lina';
        });

        $link = GuestCheckout::passwordLink($nuovo['user_id'], '/account/password-restore/', '/account/');

        check('senza password parte il link con il token', fn () =>
            str_starts_with($link, '/account/password-restore/?token=')
            && strlen($link) > strlen('/account/password-restore/?token=') + 10
        );

        $conPassword = 'ecommerce-ospite-'.bin2hex(random_bytes(6)).'@example.com';
        $creato = User::create([
            'name' => 'Ada',
            'surname' => 'Locale',
            'email' => $conPassword,
            'username' => create_link(explode('@', $conPassword)[0], 'user', 'username'),
            'authority' => json_encode(['client'], JSON_THROW_ON_ERROR),
            'area' => json_encode(['frontend'], JSON_THROW_ON_ERROR),
            'active' => 'true',
        ]);
        $locale = (int) ($creato->insert_id ?? 0);
        sqlModify('user', ['password' => hashPassword('password-locale-123')], 'id', $locale);
        $collegato = GuestCheckout::account(carrelloOspite($conPassword, 'Intruso'), [], []);

        check('l\'email di un account con password collega l\'ordine e non manda link', fn () =>
            $locale > 0
            && $collegato['user_id'] === $locale
            && $collegato['created'] === false
            && $collegato['customer_id'] > 0
            && GuestCheckout::passwordLink($locale, '/account/password-restore/', '/account/') === ''
            && (infoUser($locale, 'id')->name ?? '') === 'Ada'
        );

        $conflitto = 'ecommerce-ospite-'.bin2hex(random_bytes(6)).'@example.com';
        Contact::create([
            'name' => 'Altra',
            'surname' => 'Scheda',
            'email' => $conflitto,
            'user_id' => $locale,
            'is_customer' => 'true',
            'active' => 'true',
        ]);
        $senzaScheda = GuestCheckout::account(carrelloOspite($conflitto), [], []);

        check('una scheda con quella email già di un altro account lascia l\'ordine senza contatto', fn () =>
            $senzaScheda['user_id'] > 0
            && $senzaScheda['user_id'] !== $locale
            && $senzaScheda['customer_id'] === 0
        );

        $delBackend = 'ecommerce-ospite-'.bin2hex(random_bytes(6)).'@example.com';
        $backend = (int) (User::create([
            'name' => 'Bea',
            'surname' => 'Backend',
            'email' => $delBackend,
            'username' => create_link(explode('@', $delBackend)[0], 'user', 'username'),
            'authority' => json_encode(['admin'], JSON_THROW_ON_ERROR),
            'area' => json_encode(['backend'], JSON_THROW_ON_ERROR),
            'active' => 'true',
        ])->insert_id ?? 0);
        $collegatoBackend = GuestCheckout::account(carrelloOspite($delBackend), [], []);

        check('l\'email di un utente senza l\'area del negozio collega l\'ordine ma non manda link', fn () =>
            $backend > 0
            && $collegatoBackend['user_id'] === $backend
            && GuestCheckout::passwordLink($backend, '/account/password-restore/', '/account/') === ''
        );

        $cancellato = 'ecommerce-ospite-'.bin2hex(random_bytes(6)).'@example.com';
        $vecchio = (int) (User::create([
            'name' => 'Ugo',
            'surname' => 'Cancellato',
            'email' => $cancellato,
            'username' => create_link(explode('@', $cancellato)[0], 'user', 'username'),
            'authority' => json_encode(['client'], JSON_THROW_ON_ERROR),
            'area' => json_encode(['frontend'], JSON_THROW_ON_ERROR),
            'active' => 'true',
        ])->insert_id ?? 0);
        sqlModify('user', ['deleted' => 'true'], 'id', $vecchio);
        try {
            $senzaAccount = GuestCheckout::account(carrelloOspite($cancellato), [], []);
        } catch (Throwable $errore) {
            $senzaAccount = ['errore' => $errore->getMessage()];
        }

        check('un account che non si può creare (email di un utente cancellato) non blocca l\'ordine', fn () =>
            $vecchio > 0
            && $senzaAccount === ['user_id' => 0, 'customer_id' => 0, 'created' => false]
        );

        throw new AnnullaGuestCheckout();
    });
} catch (AnnullaGuestCheckout) {
}

check('il test non lascia account nel database', fn () => !(infoUser($email, 'email')->exists ?? false));

summary();
