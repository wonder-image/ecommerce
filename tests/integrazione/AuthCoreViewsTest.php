<?php
declare(strict_types=1);
define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');
chdir(SITE);
$GLOBALS['ROOT'] = SITE;
$_SERVER['DOCUMENT_ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/dns-fixture.php';

use Wonder\Auth\Frontend\AuthController;
use Wonder\Auth\Frontend\AuthSession;
use Wonder\Auth\Frontend\AccountAddressForm;
use Wonder\App\Models\Contacts\ContactAddress;
use Wonder\Auth\OneTimeToken;
use Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceAuthProfile;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Sql\Transaction;

final class RollbackCoreViews extends RuntimeException {}
$snapshot = $_SESSION ?? [];
$email = 'core-auth-'.bin2hex(random_bytes(8)).'@example.com';
try {
    Transaction::run(static function () use ($email): void {
        $profile = new EcommerceAuthProfile();
        $GLOBALS['ALERT'] = null;
        $created = user(['name' => 'Ada', 'surname' => 'Lovelace', 'email' => $email, 'area' => 'frontend', 'authority' => 'client', 'active' => 'true']);
        $userId = (int) ($created->user->id ?? 0);
        if ($userId <= 0) { throw new RuntimeException('Test user not created'); }
        markUserEmailVerified($userId, date('Y-m-d H:i:s'));
        $token = (new OneTimeToken($profile->completionPurpose()))->issue($userId);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        $_GET = ['token' => $token->token];
        ob_start();
        (new AuthController($profile))->handle('signup.completion');
        $html = (string) ob_get_clean();
        check('il completamento usa le view del core e conserva il captcha ecommerce', fn () =>
            str_contains($html, 'id="sign_up_completion"') && str_contains($html, 'ecommerce_signup_completion')
            && str_contains($html, 'name="phone"') && str_contains($html, 'name="phone_prefix"')
        );
        check('la registrazione non mostra fatturazione o regolamento di gioco', fn () =>
            !str_contains($html, 'name="business_name"') && !str_contains($html, 'name="accept_game_rules"')
        );
        check('auth ha un solo h1 e SEO privata senza JSON-LD', fn () =>
            substr_count($html, '<h1') === 1 && str_contains($html, 'NOINDEX,FOLLOW') && !str_contains($html, 'application/ld+json')
        );
        $input = ['phone_prefix' => '+39', 'phone' => '333'.random_int(1000000, 9999999), 'password' => 'test-password-123', 'password_confirmation' => 'test-password-123'];
        $GLOBALS['ALERT'] = null;
        $completed = user($profile->userValues('signup-completion', $input) + ['area' => 'frontend', 'authority' => 'client'], $userId);
        $profile->afterUserSaved($userId, 'signup-completion', $input);
        $contact = Contact::find(['user_id' => $userId], 1);
        check('il nuovo profilo salva cellulare e contatto senza i flag ecommerce legacy', fn () =>
            empty($GLOBALS['ALERT']) && ($completed->user->exists ?? false) && !empty($contact['id'])
            && empty($contact['business_name']) && empty($contact['pi']) && empty($contact['cf'])
        );
        $failureProfile = new class extends EcommerceAuthProfile {
            public function afterUserSaved(int $userId, string $surface, array $input): void
            {
                throw new RuntimeException('test_hook_failure');
            }
        };
        $tokens = new OneTimeToken($profile->completionPurpose());
        $pending = $tokens->inspect($token->token);
        $method = new ReflectionMethod(AuthController::class, 'completeSignup');
        $newInput = $input + [];
        $newInput['password'] = $newInput['password_confirmation'] = 'test-password-456';
        try {
            $method->invoke(new AuthController($failureProfile), $tokens, $token->token, $pending, $newInput);
            check('hook fallito interrompe il completamento', fn () => false);
        } catch (RuntimeException $exception) {
            check('hook fallito annulla password e consumo token', fn () =>
                $exception->getMessage() === 'test_hook_failure' && $tokens->inspect($token->token) !== null
                && checkPassword($input['password'], (string) infoUser($userId, 'id')->password)
            );
        }
        $method->invoke(new AuthController($profile), $tokens, $token->token, $pending, $input);
        try {
            $method->invoke(new AuthController($profile), $tokens, $token->token, $pending, $newInput);
            check('replay token viene rifiutato prima delle scritture', fn () => false);
        } catch (RuntimeException $exception) {
            check('replay token non riscrive la password', fn () =>
                $exception->getMessage() === 'auth_completion_token_invalid'
                && checkPassword($input['password'], (string) infoUser($userId, 'id')->password)
            );
        }
        $_GET = [];
        check('CSRF account e auth condividono la stessa verifica', fn () => AuthSession::verify(AuthSession::csrfToken()));
        $defaults = AccountAddressForm::fields(ContactAddress::address());
        check('una provincia vuota non seleziona automaticamente la prima della lista', fn () =>
            str_contains($defaults['province']->render(), '<option value="" selected>')
        );
        $billingLayout = AccountAddressForm::layout(AccountAddressForm::fields(Contact::billing()))->render();
        check('i campi aziendali nascondono la cella intera senza wrapper flottanti', fn () =>
            substr_count($billingLayout, 'data-wi-conditional-container="true"') === 6
            && str_contains($billingLayout, 'data-visible-when-values="business"')
            && !str_contains($billingLayout, '<div class="col-')
        );
        $validAddress = ['name' => 'Ada', 'surname' => 'Lovelace', 'country' => 'IT', 'province' => 'BG', 'city' => 'Bergamo', 'cap' => '24100', 'street' => 'Via Test', 'number' => '1', 'label' => 'Casa'];
        $validation = \Wonder\Auth\Frontend\AccountAddressValidation::class;
        check('un indirizzo completo senza etichetta supera la validazione e si salva', function () use ($validation, $validAddress, $contact): bool {
            $values = array_diff_key($validAddress, ['label' => true]);
            $result = ContactAddress::create($values + ['contact_id' => (int) $contact['id']]);
            return $validation::validate(ContactAddress::address(), $values) === [] && !empty($result->success);
        });
        check('indirizzo completo valido e provincia di un altro paese rifiutata', fn () =>
            $validation::validate(ContactAddress::address(), $validAddress) === []
            && $validation::validate(ContactAddress::address(), array_replace($validAddress, ['province' => 'BE'])) !== []
        );
        check('azienda richiede ragione sociale e tipo cliente manomesso è rifiutato', fn () =>
            $validation::validate(Contact::billing(), $validAddress + ['type' => 'business']) !== []
            && $validation::validate(Contact::billing(), $validAddress + ['type' => 'unexpected']) !== []
        );
        check('i dati fiscali opzionali vuoti restano validi e possono essere cancellati', fn () =>
            Contact::validate($validAddress + ['type' => 'private', 'cf' => '', 'pi' => '', 'pec' => ''])->valid
        );
        check('i vincoli aggiuntivi del progetto non vengono rimossi', fn () =>
            $validation::validate(Contact::billing()->requiredFields(['cf']), $validAddress + ['type' => 'private']) !== []
        );
        $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
        check('un nuovo indirizzo senza dati preseleziona Italia e +39', fn () =>
            $defaults['country']->get('value') === 'IT' && $defaults['phone_prefix']->get('value') === '+39'
        );
        $foreign = AccountAddressForm::fields(ContactAddress::address(), ['country' => 'DE', 'phone_prefix' => '+44']);
        check('un prefisso esplicito non viene sovrascritto dal paese', fn () => $foreign['phone_prefix']->get('value') === '+44');
        $german = AccountAddressForm::fields(ContactAddress::address(), ['country' => 'DE']);
        check('il default segue il paese e normalizza un prefisso storico senza +', fn () =>
            $german['phone_prefix']->get('value') === '+49'
            && AccountAddressForm::fields(ContactAddress::address(), ['phone_prefix' => '39'])['phone_prefix']->get('value') === '+39'
        );
        $posted = AccountAddressForm::fields(ContactAddress::address(), ['country' => '', 'phone_prefix' => '', 'name' => ''], true);
        check('un POST vuoto non viene sostituito dai default', fn () =>
            $posted['country']->get('value') === '' && $posted['phone_prefix']->get('value') === ''
            && $posted['name']->get('value') === ''
        );
        $shipping = ContactAddress::create(['contact_id' => (int) $contact['id'], 'label' => 'Test delivery', 'country' => 'DE', 'phone_prefix' => '+49', 'phone' => '15112345678']);
        check('gli indirizzi core e gestionale condividono la stessa FK e riga', fn () =>
            ($shipping->success ?? false) && !empty(\Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress::findById((int) $shipping->insert_id)['id'])
        );
        throw new RollbackCoreViews();
    });
} catch (RollbackCoreViews) {
} finally {
    $_SESSION = $snapshot;
}
check('il test non lascia utenti nel database', fn () => !(infoUser($email, 'email')->exists ?? false));
summary();
