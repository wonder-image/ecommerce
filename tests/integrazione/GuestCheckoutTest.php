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
use Wonder\Auth\Federated\FederatedIdentityPayload;
use Wonder\Auth\Federated\FederatedIdentityRepository;
use Wonder\Auth\OneTimeToken;
use Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceUserAccountGateway;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\GuestCheckout;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceAuthProfile;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Sql\Transaction;

final class AnnullaGuestCheckout extends RuntimeException {}

/** Il token di un link per la password: valido se si può ancora usare. */
function tokenValido(string $link): bool
{
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    return (new OneTimeToken('password_reset'))->inspect((string) ($query['token'] ?? '')) !== null;
}

/** Un cliente del negozio senza password. */
function clienteSenzaPassword(string $email, string $attivo = 'true'): int
{
    return (int) (User::create([
        'name' => 'Ida',
        'surname' => 'Cliente',
        'email' => $email,
        'username' => create_link(explode('@', $email)[0], 'user', 'username'),
        'authority' => json_encode(['client'], JSON_THROW_ON_ERROR),
        'area' => json_encode(['frontend'], JSON_THROW_ON_ERROR),
        'active' => $attivo,
    ])->insert_id ?? 0);
}

/** Il carrello come lo rilegge il controller dopo l'anteprima. */
function carrelloOspite(string $email, string $nome = 'Lina', string $telefono = '3330001111'): array
{
    return [
        'email' => $email,
        'shipping_name' => $nome,
        'shipping_surname' => 'Ospite',
        'shipping_phone_prefix' => '+39',
        'shipping_phone' => $telefono,
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

        // Come al secondo «Ordina» dopo un errore vero dell'ordine: l'account c'è già.
        try {
            Checkout::place(0, []);
            $ordineFallito = false;
        } catch (Throwable) {
            $ordineFallito = true;
        }
        $ancora = GuestCheckout::account(carrelloOspite($email, 'Altro', '3399999999'), [], []);

        check('dopo un ordine fallito la stessa email riusa account e contatto senza cambiarne nome e telefono', function () use ($ancora, $nuovo, $ordineFallito) {
            $contatto = (array) Contact::findById($nuovo['customer_id']);

            return $ordineFallito
                && $ancora === ['user_id' => $nuovo['user_id'], 'customer_id' => $nuovo['customer_id'], 'created' => false]
                && ($contatto['name'] ?? '') === 'Lina'
                && ($contatto['phone'] ?? '') === '3330001111';
        });

        $link = GuestCheckout::passwordLink($nuovo['user_id'], '/account/password-restore/');
        $secondo = GuestCheckout::passwordLink($nuovo['user_id'], '/account/password-restore/');

        check('un secondo link non annulla quello già mandato', fn () =>
            $secondo !== $link && tokenValido($link) && tokenValido($secondo)
        );

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
            && GuestCheckout::passwordLink($locale, '/account/password-restore/') === ''
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
            && GuestCheckout::passwordLink($backend, '/account/password-restore/') === ''
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

        $disattivo = clienteSenzaPassword('ecommerce-ospite-'.bin2hex(random_bytes(6)).'@example.com', 'false');

        check('a un cliente disattivato non parte il link', fn () =>
            $disattivo > 0 && GuestCheckout::passwordLink($disattivo, '/account/password-restore/') === ''
        );

        $delGoogle = 'ecommerce-ospite-'.bin2hex(random_bytes(6)).'@example.com';
        $google = clienteSenzaPassword($delGoogle);
        (new FederatedIdentityRepository())->linkIdentity($google, new FederatedIdentityPayload('google', 'sub-'.bin2hex(random_bytes(6)), $delGoogle, true));

        check('a chi entra con Google non parte il link per la password', fn () =>
            $google > 0 && GuestCheckout::passwordLink($google, '/account/password-restore/') === ''
        );

        $delNegozio = 'ecommerce-ospite-'.bin2hex(random_bytes(6)).'@example.com';
        $scheda = (int) (Contact::create([
            'name' => 'Mario',
            'surname' => 'Negozio',
            'email' => $delNegozio,
            'phone' => '0200000000',
            'is_customer' => 'true',
            'active' => 'true',
        ])->insert_id ?? 0);
        $suScheda = GuestCheckout::account(carrelloOspite($delNegozio, 'Intruso', '3391111111'), [], []);

        check('l\'ospite collega la scheda del commerciante con la sua email senza cambiarne i dati', function () use ($scheda, $suScheda) {
            $contatto = (array) Contact::findById($scheda);

            return $scheda > 0
                && $suScheda['created'] === true
                && $suScheda['customer_id'] === $scheda
                && (int) ($contatto['user_id'] ?? 0) === $suScheda['user_id']
                && ($contatto['name'] ?? '') === 'Mario'
                && ($contatto['surname'] ?? '') === 'Negozio'
                && ($contatto['phone'] ?? '') === '0200000000';
        });

        $impostazioni = MerchantSetting::current();
        $accendi = static function (string $valore) use ($impostazioni): bool {
            return $impostazioni === []
                ? (bool) (MerchantSetting::create(['id' => 1, 'checkout_guest' => $valore])->success ?? false)
                : (bool) (MerchantSetting::update(['checkout_guest' => $valore], 1)->success ?? false);
        };
        $acceso = $accendi('true') && GuestCheckout::enabled();
        $spento = $accendi('false') && !GuestCheckout::enabled();

        check('il checkout dell\'ospite si accende e si spegne dalle impostazioni del negozio', fn () => $acceso && $spento);

        $registrato = 'ecommerce-registrato-'.bin2hex(random_bytes(6)).'@example.com';
        $vecchio = (int) (Order::create([
            'code' => Code::make(Order::class, Codes::ORDER),
            'stage' => 'order',
            'email' => $registrato,
            'total' => '10.00',
        ])->insert_id ?? 0);
        $nuovoUtente = clienteSenzaPassword($registrato);
        $profilo = new EcommerceAuthProfile();
        $profilo->afterUserSaved($nuovoUtente, 'signup-request', []);
        $primaDellaConferma = (int) (Order::findById($vecchio)['user_id'] ?? -1);
        $profilo->afterUserSaved($nuovoUtente, 'email.verify', []);
        $ordine = (array) Order::findById($vecchio);
        $scheda = Contact::find(['user_id' => $nuovoUtente], 1);

        check('chi conferma l\'email della registrazione ritrova nel suo account gli ordini fatti con quell\'email', fn () =>
            $vecchio > 0
            && $primaDellaConferma === 0
            && (int) ($ordine['user_id'] ?? 0) === $nuovoUtente
            && is_array($scheda) && (int) ($ordine['customer_id'] ?? 0) === (int) $scheda['id']
        );

        throw new AnnullaGuestCheckout();
    });
} catch (AnnullaGuestCheckout) {
}

check('il test non lascia account nel database', fn () => !(infoUser($email, 'email')->exists ?? false));

summary();
