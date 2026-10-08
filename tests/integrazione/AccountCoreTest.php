<?php
/** php tests/integrazione/AccountCoreTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$_SERVER['DOCUMENT_ROOT'] = SITE; // il layout del sito legge custom/config da qui
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/dns-fixture.php';

use Wonder\App\Models\Contacts\Contact;
use Wonder\App\Models\User\User;
use Wonder\Auth\Frontend\AccountAddresses;
use Wonder\Auth\Frontend\AccountBilling;
use Wonder\Auth\Frontend\AccountController;
use Wonder\Auth\Frontend\AccountPanel;
use Wonder\Auth\Frontend\AccountPersonal;
use Wonder\Auth\Frontend\AccountRoutes;
use Wonder\Auth\Frontend\AuthProfile;
use Wonder\Auth\Frontend\AuthRoutes;
use Wonder\Auth\Frontend\ContactAccount;
use Wonder\Http\Csrf;
use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Account\EcommerceAccountController;
use Wonder\Plugin\Ecommerce\Frontend\Account\EcommerceAccountExtension;
use Wonder\Sql\Transaction;

final class AnnullaAccountCore extends RuntimeException {}
final class UscitaDiProva extends RuntimeException {}

/** Uscite del controller vero senza `exit` e posta che non parte: la posta finisce in `$GLOBALS['POSTA']`. */
trait UsciteDiProva
{
    protected function redirect(string $url): never { throw new UscitaDiProva('redirect '.$url); }
    protected function notFound(): never { throw new UscitaDiProva('404'); }
    protected function invalidCsrf(): never { throw new UscitaDiProva('419'); }
    protected function mailer(): ?callable
    {
        return static function (string $to, string $subject, string $body): bool {
            $GLOBALS['POSTA'][] = ['to' => $to, 'subject' => $subject, 'body' => $body];
            return empty($GLOBALS['POSTA_FALLITA']);
        };
    }
}

final class ControllerDiProva extends AccountController
{
    use UsciteDiProva;
}

/** Il controller dell'ecommerce, con le stesse uscite di prova. */
final class ControllerEcommerceDiProva extends EcommerceAccountController
{
    use UsciteDiProva;
}

function pagina(string $action, array $parameters = [], string $method = 'GET', array $post = [], string $controller = ControllerDiProva::class): string
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = $post;
    // Il layout del sito chiede alle route nomi che qui non sono registrati (carrello, home):
    // `__r` allora ricarica le route del sito e rimette il pannello del modulo, con la sua
    // estensione. Prima di ogni pagina si rimette quello che il blocco prova.
    if (isset($GLOBALS['REGISTRA'])) {
        ($GLOBALS['REGISTRA'])();
    }
    ob_start();
    try {
        (new $controller(AccountRoutes::panel(), AccountRoutes::auth()))->handle($action, $parameters);
        // I <script> portano il dizionario delle traduzioni: con quelli dentro, un
        // controllo sul testo di un messaggio passerebbe anche se il messaggio non c'è.
        return (string) preg_replace('~<script\b[^>]*>.*?</script>~si', '', (string) ob_get_clean());
    } catch (UscitaDiProva $e) {
        ob_end_clean();
        return $e->getMessage();
    }
}

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

        $again = AccountPersonal::save($userId, ['name' => 'Grace', 'surname' => 'Hopper', 'birth_date' => '1990-05-17', 'phone_prefix' => '+39', 'phone' => $phone], $panel, false);
        check('salvare due volte gli stessi dati personali riesce', fn () => $again->success);

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
        $foreignEdit = AccountAddresses::save($otherContact, ['city' => 'Roma'] + $values, $addressId);
        check('un cliente non modifica l\'indirizzo di un altro', fn () =>
            !$foreignEdit->success && AccountAddresses::find($contactId, $addressId)['city'] === 'Torino');
        check('il cliente elimina il proprio indirizzo', fn () =>
            AccountAddresses::delete($contactId, $addressId) && AccountAddresses::find($contactId, $addressId) === null);

        // Posizioni: dopo un'eliminazione l'indirizzo nuovo non ripete una posizione.
        $first = AccountAddresses::save($contactId, ['street' => 'Via A'] + $values);
        AccountAddresses::save($contactId, ['street' => 'Via B'] + $values);
        AccountAddresses::save($contactId, ['street' => 'Via C'] + $values);
        AccountAddresses::delete($contactId, (int) $first->id);
        $fresh = AccountAddresses::save($contactId, ['street' => 'Via D'] + $values);
        $positions = array_map(static fn (array $row): int => (int) $row['position'], AccountAddresses::all($contactId));
        check('dopo un\'eliminazione le posizioni restano diverse e il nuovo indirizzo va in fondo', fn () =>
            $fresh->success && count($positions) === 3 && count(array_unique($positions)) === 3
            && (int) AccountAddresses::find($contactId, (int) $fresh->id)['position'] === max($positions));

        // Scheda dell'indirizzo e fatturazione.
        $card = AccountAddresses::card($values);
        check('la scheda dell\'indirizzo ha nome, telefono e due righe', fn () =>
            $card['name'] === 'Ada Lovelace' && $card['phone_href'] === 'tel:+393331234567'
            && $card['lines'] === ['Via Roma 1, 20100', 'Milano (MI)']);

        $noPhone = AccountAddresses::card(['name' => 'Ada', 'surname' => 'Lovelace', 'phone_prefix' => '+39', 'phone' => '']);
        check('senza numero la scheda non mostra il prefisso e non ha il link tel', fn () =>
            $noPhone['phone'] === '' && $noPhone['phone_href'] === '');

        $billing = AccountBilling::save($contactId, ['type' => 'private', 'name' => 'Ada', 'surname' => 'Lovelace', 'country' => 'IT', 'province' => 'MI', 'cap' => '20100', 'city' => 'Milano', 'street' => 'Via Roma', 'number' => '1']);
        $stored = Contact::find(['id' => $contactId], 1);
        check('la fatturazione salva sulla scheda del cliente', fn () =>
            $billing->success && $stored['city'] === 'Milano' && $stored['street'] === 'Via Roma');

        $badBilling = AccountBilling::save($contactId, ['type' => 'private', 'name' => 'Ada', 'surname' => 'Lovelace', 'country' => 'IT']);
        check('una fatturazione incompleta non si salva e dà messaggi', fn () => !$badBilling->success && $badBilling->messages !== []);

        // Una sezione spenta non risponde nemmeno se la route fosse raggiunta: 404.
        $senzaIndirizzi = new class extends AccountPanel {
            public function sections(): array { return ['overview', 'personal']; }
        };
        foreach (['addresses', 'addresses.create', 'addresses.edit', 'addresses.delete', 'billing'] as $azione) {
            check('sezione spenta: '.$azione.' dà 404', function () use ($azione, $senzaIndirizzi) {
                ob_start();
                try {
                    (new ControllerDiProva($senzaIndirizzi, AccountRoutes::auth()))->handle($azione, ['id' => 1]);
                } catch (UscitaDiProva $e) {
                    ob_end_clean();
                    return $e->getMessage() === '404';
                }
                ob_end_clean();
                return false;
            });
        }

        throw new AnnullaAccountCore();
    });
} catch (AnnullaAccountCore) {
}

// Pagine del pannello del core: controller, markup, modal con errori.
try {
    Transaction::run(static function (): void {
        $GLOBALS['REGISTRA'] = static function (): void {
            Route::reset();
            AccountRoutes::reset();
            AuthRoutes::register(new AuthProfile()); // così `route('logout')` si risolve senza ricaricare le route
            AccountRoutes::register(new AccountPanel(), new AuthProfile()); // senza moduli: nessuna estensione
        };
        ($GLOBALS['REGISTRA'])();

        $userId = clienteDiProva('account-http');
        $_SESSION['user_id'] = $userId;
        $html = pagina('personal');
        check('Dati personali: tre righe con Modifica che apre il proprio modal', fn () =>
            substr_count($html, 'data-wi-modal-target') >= 3 && str_contains($html, 'id="account-email"'));
        preg_match_all('/<(button|input)\b[^>]*type="submit"[^>]*>/', $html, $submits);
        // Salva di Dati personali, Email e Password, più Esci.
        check('ogni submit ha wi-input-submit, e sono almeno quattro', fn () =>
            count($submits[0]) >= 4 && array_filter($submits[0], static fn ($tag) => !str_contains($tag, 'wi-input-submit')) === []);
        check('Esci posta il csrf_token all\'auth, con l\'id logout di Google Tag Manager', fn () =>
            str_contains($html, 'name="csrf_token"') && str_contains($html, AccountRoutes::auth()->route('logout')) && str_contains($html, '<form id="logout"'));
        check('voce attiva e niente voci di moduli', fn () =>
            str_contains($html, 'aria-current="page"') && !str_contains($html, '/account/ordini/'));
        check('il titolo della pagina è nel layout del pannello', fn () =>
            str_contains($html, 'wi-side-layout__title') && str_contains($html, 'wi-data-row__action'));

        check('POST senza CSRF: 419', fn () => pagina('personal', [], 'POST', ['form' => 'personal', 'name' => 'X']) === '419');

        $csrf = Csrf::token();
        $errorHtml = pagina('personal', [], 'POST', ['_csrf' => $csrf, 'form' => 'personal', 'name' => '', 'surname' => 'Hopper', 'birth_date' => '1990-02-31']);
        check('con errori si riapre solo il modal giusto, con errori e valori', fn () =>
            preg_match('/<section[^>]*class="[^"]*\bwi-show\b[^"]*"[^>]*id="account-personal"/', $errorHtml) === 1
            && preg_match('/<section[^>]*class="[^"]*\bwi-show\b[^"]*"[^>]*id="account-email"/', $errorHtml) === 0
            && str_contains($errorHtml, 'value="Hopper"')
            && str_contains($errorHtml, (string) __t('account.personal.errors.birth_date')));

        check('con errori in Dati personali, Email e Password restano chiusi', fn () =>
            preg_match('/<section[^>]*class="[^"]*\bwi-show\b[^"]*"[^>]*id="account-password"/', $errorHtml) === 0
            && preg_match('/<section[^>]*class="[^"]*\bwi-show\b[^"]*"[^>]*id="account-email"/', $errorHtml) === 0
            && preg_match('/<section[^>]*class="[^"]*no-interaction[^"]*"[^>]*id="account-password"/', $errorHtml) === 1
            && preg_match('/<section[^>]*id="account-password"[^>]*aria-hidden="true"[^>]*\binert\b/', $errorHtml) === 1);

        check('il modal riaperto dal server si può cliccare, quelli chiusi restano non cliccabili', fn () =>
            preg_match('/<section[^>]*class="[^"]*no-interaction[^"]*"[^>]*id="account-personal"/', $errorHtml) === 0
            && preg_match('/<section[^>]*class="[^"]*no-interaction[^"]*"[^>]*id="account-email"/', $errorHtml) === 1);

        // La classe sola non basta: aria-hidden e inert lasciano il modal fuori dalla tastiera e dagli schermi.
        check('il modal riaperto dal server non è aria-hidden né inert', function () use ($errorHtml) {
            $tag = preg_match('/<section[^>]*id="account-personal"[^>]*>/', $errorHtml, $found) === 1 ? $found[0] : '';

            return $tag !== '' && !str_contains($tag, 'aria-hidden') && preg_match('/\binert\b/', $tag) === 0;
        });

        check('salvataggio riuscito: redirect alla pagina con avviso', fn () =>
            pagina('personal', [], 'POST', ['_csrf' => $csrf, 'form' => 'personal', 'name' => 'Grace', 'surname' => 'Hopper', 'birth_date' => '1990-05-17']) === 'redirect '.Route::url('account.personal')
            && ($_SESSION['wonder_account_notice'] ?? '') !== '');

        check('un form sconosciuto: 404', fn () => pagina('personal', [], 'POST', ['_csrf' => $csrf, 'form' => 'altro']) === '404');

        $overview = pagina('index');
        check('Panoramica: saluto e codice cliente', fn () =>
            str_contains($overview, '<strong>Grace</strong>')
            && str_contains($overview, (string) Contact::find(['user_id' => $userId], 1)['code']));
        // Il testo sta anche nel dizionario JSON della pagina: si guarda il corpo dell'avviso.
        $savedAlert = "<div class='wi-alert-body'>".__t('account.saved').'</div>';
        check('l\'avviso del salvataggio si vede una volta sola', fn () =>
            str_contains($overview, $savedAlert) && !str_contains(pagina('index'), $savedAlert));

        check('azione sconosciuta: 404', fn () => pagina('nessuna') === '404');

        // Cambio email: ogni errore arriva tradotto nel modal giusto.
        $wrong = pagina('personal', [], 'POST', ['_csrf' => $csrf, 'form' => 'email', 'email' => 'nuova-'.bin2hex(random_bytes(4)).'@example.com', 'current_password' => 'sbagliata']);
        check('email con password sbagliata: modal email aperto con il messaggio', fn () =>
            preg_match('/<section[^>]*class="[^"]*\bwi-show\b[^"]*"[^>]*id="account-email"/', $wrong) === 1
            && preg_match('/<section[^>]*class="[^"]*\bwi-show\b[^"]*"[^>]*id="account-personal"/', $wrong) === 0
            && str_contains($wrong, (string) __t('auth.validation.errors.current_password_wrong')));
        $invalid = pagina('personal', [], 'POST', ['_csrf' => $csrf, 'form' => 'email', 'email' => 'non-una-email', 'current_password' => 'password-di-prova-123']);
        check('email non valida: messaggio tradotto', fn () => str_contains($invalid, (string) __t('auth.validation.errors.email_invalid')));
        $current = (string) infoUser($userId, 'id')->email;
        $same = pagina('personal', [], 'POST', ['_csrf' => $csrf, 'form' => 'email', 'email' => strtoupper($current), 'current_password' => 'password-di-prova-123']);
        check('email uguale alla attuale: messaggio tradotto', fn () => str_contains($same, (string) __t('account.email.errors.same')));
        $takenEmail = (string) infoUser(clienteDiProva('account-taken'), 'id')->email;
        $taken = pagina('personal', [], 'POST', ['_csrf' => $csrf, 'form' => 'email', 'email' => $takenEmail, 'current_password' => 'password-di-prova-123']);
        check('email già registrata: messaggio tradotto', fn () => str_contains($taken, (string) __t('auth.validation.errors.email_exists')));
        check('gli errori sull\'email non lasciano il valore della password nel markup', fn () => !str_contains($taken, 'password-di-prova-123'));

        // Posta non partita: `mail:send` ha il suo messaggio, nel modal dell'email.
        $GLOBALS['POSTA_FALLITA'] = true;
        $notSent = pagina('personal', [], 'POST', ['_csrf' => $csrf, 'form' => 'email', 'email' => 'posta-'.bin2hex(random_bytes(4)).'@example.com', 'current_password' => 'password-di-prova-123']);
        unset($GLOBALS['POSTA_FALLITA']);
        check('posta non partita: modal email aperto con il messaggio d\'invio', fn () =>
            preg_match('/<section[^>]*class="[^"]*\bwi-show\b[^"]*"[^>]*id="account-email"/', $notSent) === 1
            && str_contains($notSent, htmlspecialchars((string) __t('account.email.errors.send'), ENT_QUOTES))
            && !str_contains($notSent, (string) __t('auth.validation.review')));

        // Posta partita: avviso con l'email nuova scritta una volta sola (l'Alert fa l'escape).
        $GLOBALS['POSTA'] = [];
        $newEmail = "o'neill-".bin2hex(random_bytes(4)).'@example.com';
        $sent = pagina('personal', [], 'POST', ['_csrf' => $csrf, 'form' => 'email', 'email' => $newEmail, 'current_password' => 'password-di-prova-123']);
        check('email nuova: redirect, un solo messaggio di posta al nuovo indirizzo', fn () =>
            $sent === 'redirect '.Route::url('account.personal') && count($GLOBALS['POSTA']) === 1 && $GLOBALS['POSTA'][0]['to'] === strtolower($newEmail));
        $afterSent = pagina('personal');
        check('l\'avviso nomina l\'email nuova, con l\'escape una volta sola', fn () =>
            str_contains($afterSent, 'neill-') && str_contains($afterSent, htmlspecialchars($newEmail, ENT_QUOTES))
            && !str_contains($afterSent, '&amp;#039;') && !str_contains($afterSent, '&amp;amp;'));

        // Cambio password.
        $weak = pagina('personal', [], 'POST', ['_csrf' => $csrf, 'form' => 'password', 'current_password' => 'password-di-prova-123', 'password' => 'corta']);
        check('password troppo corta: modal password aperto con il messaggio', fn () =>
            preg_match('/<section[^>]*class="[^"]*\bwi-show\b[^"]*"[^>]*id="account-password"/', $weak) === 1
            && str_contains($weak, (string) __t('auth.validation.errors.password_too_short')));
        $changed = pagina('personal', [], 'POST', ['_csrf' => $csrf, 'form' => 'password', 'current_password' => 'password-di-prova-123', 'password' => 'un-altra-password-456', 'is_admin' => '1']);
        check('password cambiata: redirect e avviso', fn () =>
            $changed === 'redirect '.Route::url('account.personal') && ($_SESSION['wonder_account_notice'] ?? '') === (string) __t('account.password.saved'));
        unset($_SESSION['wonder_account_notice']);

        // Conferma dell'email: solo il rendering dell'esito, la logica è nel test dell'email.
        $_GET['token'] = 'inventato';
        $confirm = pagina('email.confirm');
        check('conferma con un link inventato: testo di link non valido, senza pannello', fn () =>
            str_contains($confirm, htmlspecialchars((string) __t('account.email.invalid'), ENT_QUOTES)) && !str_contains($confirm, 'wi-side-layout'));
        unset($_GET['token']);

        // Account senza password (accesso social): la riga email non apre il modal e dice cosa fare.
        $socialId = clienteDiProva('account-social');
        sqlModify('user', ['password' => ''], 'id', $socialId);
        $_SESSION['user_id'] = $socialId;
        $social = pagina('personal');
        check('senza password: niente modal email, riga email con la nota', fn () =>
            !str_contains($social, 'id="account-email"') && !str_contains($social, '#account-email')
            && str_contains($social, htmlspecialchars((string) __t('account.email.needs_password'), ENT_QUOTES))
            && str_contains($social, 'id="account-password"') && str_contains($social, 'id="account-personal"'));
        check('senza password: un POST del form email è un 404', fn () =>
            pagina('personal', [], 'POST', ['_csrf' => $csrf, 'form' => 'email', 'email' => 'x@example.com', 'current_password' => 'x']) === '404');
        $_SESSION['user_id'] = $userId;

        // Indirizzi: schede, modal, ripiego senza JS, eliminazione.
        $contactId = (int) Contact::find(['user_id' => $userId], 1)['id'];
        $empty = pagina('addresses');
        check('senza indirizzi: stato vuoto e bottone per aggiungere', fn () =>
            str_contains($empty, 'wi-empty-state') && str_contains($empty, 'data-wi-modal-target="#account-address-new"'));

        $address = ['_csrf' => $csrf, 'name' => 'Ada', 'surname' => 'Lovelace', 'phone_prefix' => '+39', 'phone' => '3331234567', 'country' => 'IT', 'province' => 'MI', 'cap' => '20100', 'city' => 'Milano', 'street' => 'Via Roma', 'number' => '1'];
        check('aggiunta: redirect all\'elenco', fn () => pagina('addresses.create', [], 'POST', $address) === 'redirect '.Route::url('account.addresses'));
        $id = (int) (AccountAddresses::all($contactId)[0]['id'] ?? 0);
        $list = pagina('addresses');
        check('scheda con nome, tel: e righe dell\'indirizzo', fn () =>
            str_contains($list, 'wi-address-card__name') && str_contains($list, 'href="tel:+393331234567"')
            && str_contains($list, 'Via Roma 1, 20100') && str_contains($list, 'Milano (MI)'));

        $aperti = static fn (string $html): int => (int) preg_match_all('/<section\b[^>]*class="[^"]*\bwi-show\b/', $html);
        check('elenco: per ogni indirizzo il modal di modifica e quello di conferma, tutti chiusi', fn () =>
            str_contains($list, 'id="account-address-'.$id.'"') && str_contains($list, 'id="account-address-delete-'.$id.'"')
            && str_contains($list, 'id="account-address-new"') && str_contains($list, 'Via Roma 1, 20100, Milano (MI)')
            && $aperti($list) === 0);
        $fallback = pagina('addresses.edit', ['id' => $id]);
        check('ripiego senza JS: form con CSRF, valori, Salva e link per tornare', fn () =>
            str_contains($fallback, '<form method="post"') && str_contains($fallback, 'name="_csrf"')
            && str_contains($fallback, 'Lovelace') && str_contains($fallback, 'wi-input-submit')
            && str_contains($fallback, 'href="'.Route::url('account.addresses').'"'));

        $invalidHtml = pagina('addresses.edit', ['id' => $id], 'POST', ['cap' => ''] + $address);
        check('modifica con errori: si riapre il modal di quell\'indirizzo', fn () =>
            preg_match('/<section(?=[^>]*\bid="account-address-'.$id.'")(?=[^>]*class="[^"]*\bwi-show\b)[^>]*>/', $invalidHtml) === 1);
        check('modifica con errori: si apre solo quel modal, con gli errori', fn () =>
            $aperti($invalidHtml) === 1 && str_contains($invalidHtml, 'wi-alert'));
        $newInvalid = pagina('addresses.create', [], 'POST', ['cap' => ''] + $address);
        check('nuovo indirizzo con errori: si apre solo il modal del nuovo', fn () =>
            $aperti($newInvalid) === 1
            && preg_match('/<section(?=[^>]*\bid="account-address-new")(?=[^>]*class="[^"]*\bwi-show\b)[^>]*>/', $newInvalid) === 1
            && count(AccountAddresses::all($contactId)) === 1);

        $otherUser = clienteDiProva('account-http-other');
        $_SESSION['user_id'] = $otherUser;
        check('indirizzo altrui: 404 in modifica', fn () => pagina('addresses.edit', ['id' => $id]) === '404');
        check('indirizzo altrui: 404 in eliminazione', fn () => pagina('addresses.delete', ['id' => $id], 'POST', ['_csrf' => $csrf]) === '404');
        $_SESSION['user_id'] = $userId;
        check('eliminazione senza CSRF: 419', fn () => pagina('addresses.delete', ['id' => $id], 'POST', []) === '419');
        check('eliminazione propria', fn () =>
            pagina('addresses.delete', ['id' => $id], 'POST', ['_csrf' => $csrf]) === 'redirect '.Route::url('account.addresses')
            && AccountAddresses::find($contactId, $id) === null);

        $billingInvalid = pagina('billing', [], 'POST', ['_csrf' => $csrf, 'type' => 'private', 'name' => 'Ada', 'surname' => 'Lovelace', 'country' => 'IT']);
        check('Fatturazione con errori: si riapre il modal con gli errori', fn () =>
            $aperti($billingInvalid) === 1 && preg_match('/<section(?=[^>]*\bid="account-billing")(?=[^>]*class="[^"]*\bwi-show\b)[^>]*>/', $billingInvalid) === 1);
        check('Fatturazione valida: redirect alla pagina', fn () =>
            pagina('billing', [], 'POST', ['_csrf' => $csrf, 'type' => 'private', 'name' => 'Ada', 'surname' => 'Lovelace', 'country' => 'IT', 'province' => 'MI', 'cap' => '20100', 'city' => 'Milano', 'street' => 'Via Roma', 'number' => '1'])
            === 'redirect '.Route::url('account.billing'));
        $billing = pagina('billing');
        check('Fatturazione: righe e modal con Salva wi-input-submit', fn () =>
            str_contains($billing, 'wi-data-row') && str_contains($billing, 'id="account-billing"'));

        throw new AnnullaAccountCore();
    });
} catch (AnnullaAccountCore) {
}

// L'ecommerce si aggancia al pannello del core: estensione, route dei metodi di pagamento, pagina.
try {
    Transaction::run(static function (): void {
        $GLOBALS['REGISTRA'] = static function (): void {
            Route::reset();
            AccountRoutes::reset();
            AuthRoutes::register(new AuthProfile());
            AccountRoutes::register(new AccountPanel(), new AuthProfile());
            AccountRoutes::extend(new EcommerceAccountExtension());
        };
        ($GLOBALS['REGISTRA'])();
        $paymentUrl = Route::url('account.payment-methods');
        $personalUrl = Route::url('account.personal');

        $userId = clienteDiProva('account-shop');
        $_SESSION['user_id'] = $userId;

        $routeMetodi = array_values(array_filter(Route::all(), static fn ($r) => str_ends_with((string) ($r['path'] ?? ''), '/account/metodi-di-pagamento/')));
        check('l\'ecommerce aggiunge la route dei metodi di pagamento, protetta', fn () =>
            str_ends_with($paymentUrl, '/account/metodi-di-pagamento/')
            && count($routeMetodi) === 1
            && ($routeMetodi[0]['private'] ?? false) === true
            && ($routeMetodi[0]['permit'] ?? null) === ['client']);

        // Il sito accende o spegne i metodi di pagamento con `account.payment_methods.enabled`: qui si forza
        // sulla stessa configurazione che il codice legge.
        $forzaMetodi = static function (bool $acceso): void {
            Ecommerce::forgetConfig();
            Ecommerce::config();
            $proprieta = new ReflectionProperty(Ecommerce::class, 'config');
            $config = (array) $proprieta->getValue(null);
            $config['account']['payment_methods']['enabled'] = $acceso;
            $proprieta->setValue(null, $config);
        };
        $label = htmlspecialchars((string) __t('ecommerce.account.payment_methods.label'), ENT_QUOTES);
        $gestisci = htmlspecialchars((string) __t('account.actions.manage'), ENT_QUOTES);
        $presto = htmlspecialchars((string) __t('ecommerce.account.payment_methods.soon'), ENT_QUOTES);
        $rigaMetodi = static function (string $html) use ($label): string {
            foreach (array_slice(explode('<div class="wi-data-row">', $html), 1) as $riga) {
                // Solo la riga: l'ultima arriva fino a fine pagina, con i modal e i loro bottoni spenti.
                if (str_contains($riga, $label) && preg_match('~^.*?<div class="wi-data-row__action">.*?</div>~s', $riga, $trovata)) {
                    return $trovata[0];
                }
            }
            return '';
        };

        try {
            $forzaMetodi(false);
            $spento = pagina('personal');
            $rigaSpenta = $rigaMetodi($spento);
            check('riga Metodi di pagamento in Dati personali', fn () => $rigaSpenta !== '');
            check('Metodi di pagamento spenti: Gestisci è un bottone spento, con «presto disponibile»', fn () =>
                (bool) preg_match('~<button\b[^>]*\bdisabled\b[^>]*>~', $rigaSpenta)
                && str_contains($rigaSpenta, $gestisci)
                && str_contains($rigaSpenta, $presto)
                && !str_contains($rigaSpenta, 'href="'.$paymentUrl.'"'));
            $paginaSpenta = pagina('payment-methods', [], 'GET', [], ControllerEcommerceDiProva::class);
            check('Metodi di pagamento spenti: avviso di attesa', fn () =>
                str_contains($paginaSpenta, htmlspecialchars((string) __t('ecommerce.account.payment_methods.pending'), ENT_QUOTES))
                && str_contains($paginaSpenta, 'tx-warning'));

            $forzaMetodi(true);
            $acceso = pagina('personal');
            $rigaAccesa = $rigaMetodi($acceso);
            check('Metodi di pagamento accesi: Gestisci è un link attivo alla pagina dei metodi', fn () =>
                (bool) preg_match('~<a\b[^>]*href="'.preg_quote($paymentUrl, '~').'"[^>]*>~', $rigaAccesa)
                && str_contains($rigaAccesa, $gestisci)
                && !preg_match('~\bdisabled\b~', $rigaAccesa)
                && !str_contains($rigaAccesa, $presto));
            $paginaAccesa = pagina('payment-methods', [], 'GET', [], ControllerEcommerceDiProva::class);
            check('Metodi di pagamento accesi: avviso informativo Stripe', fn () =>
                str_contains($paginaAccesa, htmlspecialchars((string) __t('ecommerce.account.payment_methods.ready'), ENT_QUOTES))
                && str_contains($paginaAccesa, 'bi-info-circle') && !str_contains($paginaAccesa, 'tx-warning'));
        } finally {
            Ecommerce::forgetConfig();
        }

        $withShop = pagina('personal');
        check('Metodi di pagamento fuori dal menu', fn () => str_contains($withShop, 'wi-side-nav__link" href="'.$personalUrl)
            && !str_contains($withShop, 'wi-side-nav__link" href="'.$paymentUrl));

        $payment = pagina('payment-methods', [], 'GET', [], ControllerEcommerceDiProva::class);
        check('Metodi di pagamento: titolo, avviso Stripe e torna a Dati personali', fn () =>
            str_contains($payment, 'wi-side-layout__title') && str_contains($payment, htmlspecialchars((string) __t('ecommerce.account.payment_methods.title'), ENT_QUOTES))
            && str_contains($payment, 'Stripe')
            && str_contains($payment, 'href="'.$personalUrl.'"') && str_contains($payment, htmlspecialchars((string) __t('account.actions.back'), ENT_QUOTES)));
        check('Metodi di pagamento: la voce attiva del menu è Dati personali', fn () =>
            (bool) preg_match('~<a class="wi-side-nav__link" href="'.preg_quote($personalUrl, '~').'" aria-current="page"~', $payment));
        check('Metodi di pagamento: SEO privata', fn () => str_contains($payment, 'NOINDEX,NOFOLLOW'));
        check('Metodi di pagamento: l\'estensione mette font e foglio di stile del negozio nell\'head', fn () =>
            str_contains($payment, 'store.css'));
        $notFound = pagina('nessuna', [], 'GET', [], ControllerEcommerceDiProva::class);
        $billing = pagina('billing', [], 'GET', [], ControllerEcommerceDiProva::class);
        check('le azioni del core passano ancora dal controller dell\'ecommerce', fn () =>
            $notFound === '404' && str_contains($billing, 'id="account-billing"'));
        preg_match_all('/<(button|input)\b[^>]*type="submit"[^>]*>/', $payment, $submits);
        check('Metodi di pagamento: ogni submit ha wi-input-submit', fn () =>
            $submits[0] !== [] && array_filter($submits[0], static fn ($tag) => !str_contains($tag, 'wi-input-submit')) === []);

        // Il ricaricamento vero: `__r` con un nome che non c'è fa rileggere le route del sito (Route::load le
        // azzera), e il pannello del core con la sua estensione deve ritornare, la prima volta e le successive.
        unset($GLOBALS['REGISTRA']);
        Route::reset();
        AccountRoutes::reset();
        __r('nessuna-route-con-questo-nome');
        $dopoIlPrimo = [Route::url('account.index'), Route::url('account.payment-methods')];
        __r('nessuna-route-con-questo-nome');
        $dopoIlSecondo = [Route::url('account.index'), Route::url('account.payment-methods')];
        check('dopo il ricaricamento delle route restano account.index e account.payment-methods', fn () =>
            str_ends_with($dopoIlPrimo[0], '/account/') && str_ends_with($dopoIlPrimo[1], '/account/metodi-di-pagamento/'));
        check('dopo un secondo ricaricamento le route dell\'account ci sono ancora, una volta sola', fn () =>
            $dopoIlSecondo === $dopoIlPrimo
            && count(array_filter(Route::all(), static fn ($r) => str_ends_with((string) ($r['path'] ?? ''), '/account/metodi-di-pagamento/'))) === 1);

        throw new AnnullaAccountCore();
    });
} catch (AnnullaAccountCore) {
}
unset($GLOBALS['REGISTRA']);

summary();
