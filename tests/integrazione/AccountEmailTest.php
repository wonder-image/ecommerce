<?php
/** php tests/integrazione/AccountEmailTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/dns-fixture.php';

use Wonder\App\Models\User\User;
use Wonder\Auth\Frontend\AccountEmail;
use Wonder\Sql\Transaction;

final class AnnullaAccountEmail extends RuntimeException {}

/** Mailer finto: tiene i messaggi e ne estrae il token dall'ultimo. */
final class PostaDiProva
{
    public array $sent = [];

    public function __invoke(string $to, string $subject, string $body): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }

    public function token(): string
    {
        $last = end($this->sent);
        return preg_match('/[?&]token=([^\s"&<]+)/', (string) ($last['body'] ?? ''), $m) ? rawurldecode($m[1]) : '';
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
        $url = 'https://example.test/account/email/conferma/';
        $mail = new PostaDiProva();
        $userId = clienteDiProva('email-change');
        $user = infoUser($userId, 'id');
        $new = 'nuova-'.bin2hex(random_bytes(6)).'@example.com';

        $wrong = AccountEmail::request($user, $new, 'sbagliata', $url, $mail);
        check('password sbagliata: niente richiesta', fn () => !$wrong->success && ($wrong->errors['current_password'] ?? '') === 'wrong');
        $same = AccountEmail::request($user, $user->email, 'password-di-prova-123', $url, $mail);
        check('email uguale: errore email.same', fn () => !$same->success && ($same->errors['email'] ?? '') === 'same');
        $takenId = clienteDiProva('email-taken');
        $busy = AccountEmail::request($user, infoUser($takenId, 'id')->email, 'password-di-prova-123', $url, $mail);
        check('email di un altro: errore email.exists', fn () => !$busy->success && ($busy->errors['email'] ?? '') === 'exists');
        check('nessun errore: nessuna email spedita', fn () => $mail->sent === []);

        // L'apostrofo e i commenti SQL passano FILTER_VALIDATE_EMAIL: il controllo di unicità non li interpreta.
        $quote = "o'brien-".bin2hex(random_bytes(4)).'@example.com';
        $apos = AccountEmail::request($user, $quote, 'password-di-prova-123', $url, $mail);
        check('email con apostrofo: richiesta valida', fn () => $apos->success && end($mail->sent)['to'] === $quote);
        $inject = AccountEmail::request($user, "x'/**/or/**/1=1#".bin2hex(random_bytes(4)).'@example.com', 'password-di-prova-123', $url, $mail);
        check('email con SQL dentro: non vale come «già usata»', fn () => $inject->success);

        AccountEmail::request($user, $new, 'password-di-prova-123', $url, $mail);
        $first = $mail->token();
        AccountEmail::request($user, $new, 'password-di-prova-123', $url, $mail);
        $second = $mail->token();
        check('il link va alla nuova casella', fn () => end($mail->sent)['to'] === $new && $second !== '' && $second !== $first);
        check('la vecchia email resta valida fino al clic', fn () => infoUser($userId, 'id')->email === $user->email);
        check('un link vecchio dopo una richiesta nuova non vale', fn () => AccountEmail::confirm($first) === 'invalid');

        $_SESSION['user_id'] = $takenId;
        check('la conferma cambia email e verifica', fn () =>
            AccountEmail::confirm($second) === 'confirmed'
            && infoUser($userId, 'id')->email === $new
            && (string) infoUser($userId, 'id')->email_verified === '1');
        check('la conferma non tocca la sessione di chi è loggato', fn () => $_SESSION['user_id'] === $takenId);
        unset($_SESSION['user_id']);
        check('un link già usato non vale', fn () => AccountEmail::confirm($second) === 'invalid');

        $raceId = clienteDiProva('email-race');
        $raceUser = infoUser($raceId, 'id');
        $wanted = 'contesa-'.bin2hex(random_bytes(6)).'@example.com';
        AccountEmail::request($raceUser, $wanted, 'password-di-prova-123', $url, $mail);
        $pending = $mail->token();
        $GLOBALS['ALERT'] = null;
        user(['email' => $wanted, 'area' => 'frontend', 'authority' => 'client'], clienteDiProva('email-thief'));
        $GLOBALS['ALERT'] = null;
        check('email presa nel frattempo: esito taken, nulla cambia', fn () =>
            AccountEmail::confirm($pending) === 'taken' && infoUser($raceId, 'id')->email === $raceUser->email);

        check('un token inventato non vale', fn () => AccountEmail::confirm('inventato') === 'invalid');

        throw new AnnullaAccountEmail();
    });
} catch (AnnullaAccountEmail) {
}

summary();
