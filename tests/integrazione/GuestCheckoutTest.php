<?php
/** php tests/integrazione/GuestCheckoutTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

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
use Wonder\Auth\Frontend\UserAccountGateway;
use Wonder\Plugin\CustomerAccount\Account\CustomerContact;
use Wonder\Plugin\Ecommerce\Frontend\Account\AccountOrder;
use Wonder\Plugin\Ecommerce\Frontend\Auth\OrderClaim;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\GuestAccountExists;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\GuestCheckout;
use Wonder\Plugin\Ecommerce\Support\Throttle;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\System\Setting;
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

/** `true` se l'ospite con quell'email viene fermato prima di account, contatto e ordine. */
function fermato(string $email): bool
{
    try {
        GuestCheckout::account(carrelloOspite($email, 'Intruso'), [], []);
    } catch (GuestAccountExists) {
        return true;
    }

    return false;
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
        $gateway = new UserAccountGateway();

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
        $fermatoLocale = fermato($conPassword);

        check('l\'email di un account con password ferma l\'ospite: nessun contatto, nessun link', fn () =>
            $locale > 0
            && $fermatoLocale
            && empty(Contact::find(['user_id' => $locale], 1))
            && GuestCheckout::passwordLink($locale, '/account/password-restore/') === ''
            && (infoUser($locale, 'id')->name ?? '') === 'Ada'
        );

        // Dal rapido niente blocco e niente limite guest_exists: anche col limite pieno l'ordine passa slegato.
        Throttle::useStore(static fn (): int => 99);
        try {
            $daRapido = GuestCheckout::account(carrelloOspite($conPassword), [], [], true);
        } catch (Throwable $errore) {
            $daRapido = ['errore' => $errore->getMessage()];
        } finally {
            Throttle::useStore(null);
        }
        $registro = sqlSelect('auth_log', ['event' => 'guest_checkout_existing_unlinked', 'user_id' => $locale], 1);
        $meta = json_decode((string) ($registro->row['meta'] ?? ''), true);

        check('dal check-out rapido l\'email di un account con password non ferma: ordine senza account né contatto', fn () =>
            $daRapido === ['user_id' => 0, 'customer_id' => 0, 'created' => false]
            && empty(Contact::find(['user_id' => $locale], 1))
        );

        check('l\'ordine rapido slegato va nel registro con l\'hash dell\'email, mai l\'email', fn () =>
            ($registro->exists ?? false)
            && ($meta['email_hash'] ?? '') === hash('sha256', $conPassword)
            && !str_contains((string) ($registro->row['meta'] ?? ''), $conPassword)
        );

        // Oltre il limite il blocco non conferma più l'account: risponde come ai troppi ordini.
        Throttle::useStore(static fn (): int => 99);
        try {
            GuestCheckout::account(carrelloOspite($conPassword), [], []);
            $oltre = 'nessun errore';
        } catch (GuestAccountExists) {
            $oltre = 'account_exists';
        } catch (RuntimeException $errore) {
            $oltre = $errore->getMessage();
        } finally {
            Throttle::useStore(null);
        }

        check('oltre il limite l\'email nota riceve l\'errore generico dei troppi ordini', fn () =>
            $oltre === (string) __t('ecommerce.checkout.errors.too_many_orders')
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
        $fermatoBackend = fermato($delBackend);

        check('l\'email di un utente senza l\'area del negozio ferma l\'ospite e non manda link', fn () =>
            $backend > 0
            && $fermatoBackend
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

        $delDisattivo = 'ecommerce-ospite-'.bin2hex(random_bytes(6)).'@example.com';
        $disattivo = clienteSenzaPassword($delDisattivo, 'false');

        check('a un cliente disattivato non parte il link', fn () =>
            $disattivo > 0 && GuestCheckout::passwordLink($disattivo, '/account/password-restore/') === ''
        );

        check('il cliente disattivato senza password non ferma l\'ospite: si aggancia come prima', fn () =>
            $disattivo > 0 && !fermato($delDisattivo)
        );

        $delGoogle = 'ecommerce-ospite-'.bin2hex(random_bytes(6)).'@example.com';
        $google = clienteSenzaPassword($delGoogle);
        (new FederatedIdentityRepository())->linkIdentity($google, new FederatedIdentityPayload('google', 'sub-'.bin2hex(random_bytes(6)), $delGoogle, true));

        check('a chi entra con Google non parte il link per la password', fn () =>
            $google > 0 && GuestCheckout::passwordLink($google, '/account/password-restore/') === ''
        );

        $fermatoGoogle = fermato($delGoogle);

        check('l\'email di chi entra con Google ferma l\'ospite senza toccare il contatto', fn () =>
            $fermatoGoogle && empty(Contact::find(['user_id' => $google], 1))
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

        $impostazioni = Setting::current();
        $accendi = static function (string $valore) use ($impostazioni): bool {
            return $impostazioni === []
                ? (bool) (Setting::create(['id' => 1, 'checkout_guest' => $valore])->success ?? false)
                : (bool) (Setting::update(['checkout_guest' => $valore], 1)->success ?? false);
        };
        $acceso = $accendi('true') && GuestCheckout::enabled();
        $spento = $accendi('false') && !GuestCheckout::enabled();
        $accendi((string) ($impostazioni['checkout_guest'] ?? 'false'));

        check('il checkout dell\'ospite si accende e si spegne dalle Impostazioni di Set Up', fn () => $acceso && $spento);

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

        // L'ordine rapido slegato entra nell'account quando il cliente, entrato e verificato, apre l'area cliente.
        $rapido = (int) (Order::create([
            'code' => Code::make(Order::class, Codes::ORDER),
            'stage' => 'order',
            'email' => $conPassword,
            'total' => '10.00',
        ])->insert_id ?? 0);
        $sessione = $_SESSION ?? null;
        $_SESSION = ['user_id' => $locale];
        $nonVerificato = OrderClaim::run();
        $dopoNonVerificato = (int) (Order::findById($rapido)['user_id'] ?? -1);
        markUserEmailVerified($locale, date('Y-m-d H:i:s'));
        $presi = OrderClaim::run();
        $agganciato = (array) Order::findById($rapido);
        $schedaLocale = (int) (CustomerContact::forUser($locale)['id'] ?? 0);
        $visibile = array_column(array_filter((array) Order::find(AccountOrder::condition($schedaLocale)), 'is_array'), 'id');

        check('il cliente non verificato non prende l\'ordine rapido; verificato sì, e lo vede negli ordini', fn () =>
            $rapido > 0
            && $nonVerificato === 0 && $dopoNonVerificato === 0
            && $presi >= 1
            && (int) ($agganciato['user_id'] ?? 0) === $locale
            && $schedaLocale > 0 && (int) ($agganciato['customer_id'] ?? 0) === $schedaLocale
            && in_array((string) $rapido, array_map('strval', $visibile), true)
        );

        $delloStaff = (int) (Order::create([
            'code' => Code::make(Order::class, Codes::ORDER),
            'stage' => 'order',
            'email' => $delBackend,
            'total' => '10.00',
        ])->insert_id ?? 0);
        markUserEmailVerified($backend, date('Y-m-d H:i:s'));
        $_SESSION = ['user_id' => $backend];
        $presiStaff = OrderClaim::run();

        check('lo staff, anche verificato, non prende mai gli ordini con la sua email', fn () =>
            $delloStaff > 0 && $presiStaff === 0 && (int) (Order::findById($delloStaff)['user_id'] ?? -1) === 0
        );
        $sessione === null ? $_SESSION = [] : $_SESSION = $sessione;

        throw new AnnullaGuestCheckout();
    });
} catch (AnnullaGuestCheckout) {
}

check('il test non lascia account nel database', fn () => !(infoUser($email, 'email')->exists ?? false));

summary();
