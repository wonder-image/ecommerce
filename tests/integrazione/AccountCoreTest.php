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
use Wonder\Auth\Frontend\ContactAccount;
use Wonder\Http\Csrf;
use Wonder\Http\Route;
use Wonder\Sql\Transaction;

final class AnnullaAccountCore extends RuntimeException {}
final class UscitaDiProva extends RuntimeException {}

/** Come il controller vero, ma senza `exit` e senza spedire posta: la posta finisce in `$GLOBALS['POSTA']`. */
final class ControllerDiProva extends AccountController
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

function pagina(string $action, array $parameters = [], string $method = 'GET', array $post = []): string
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = $post;
    ob_start();
    try {
        (new ControllerDiProva(AccountRoutes::panel(), AccountRoutes::auth()))->handle($action, $parameters);
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

        throw new AnnullaAccountCore();
    });
} catch (AnnullaAccountCore) {
}

// Pagine del pannello del core: controller, markup, modal con errori.
try {
    Transaction::run(static function (): void {
        Route::reset();
        AccountRoutes::reset();
        AccountRoutes::register(new AccountPanel()); // senza moduli: nessuna estensione

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

        throw new AnnullaAccountCore();
    });
} catch (AnnullaAccountCore) {
}

summary();
