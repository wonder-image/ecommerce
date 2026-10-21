<?php
/** php tests/GuestAccountExistsTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

$root = dirname(__DIR__);
$guest = (string) file_get_contents($root.'/src/Frontend/Checkout/GuestCheckout.php');

/** Il corpo di un metodo statico fino al metodo dopo. */
function corpoMetodo(string $sorgente, string $metodo): string
{
    if (!preg_match('/(private|public) static function '.preg_quote($metodo, '/').'\\(/', $sorgente, $trovato, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $inizio = $trovato[0][1];
    $fine = preg_match('/(private|public) static function /', $sorgente, $dopo, PREG_OFFSET_CAPTURE, $inizio + 1) ? $dopo[0][1] : false;

    return substr($sorgente, $inizio, $fine === false ? null : $fine - $inizio);
}

$account = corpoMetodo($guest, 'account');
$claimed = corpoMetodo($guest, 'claimed');

check('account(): il blocco arriva prima di consensi, contatto e creazione', function () use ($account) {
    $blocco = strpos($account, 'self::refuse($email, $userId)');
    if ($blocco === false) {
        throw new RuntimeException('manca il blocco');
    }
    foreach (['registerLeadConsents(', 'CustomerContact::forUser(', 'createUserWithoutPassword('] as $dopo) {
        $posizione = strpos($account, $dopo);
        if ($posizione === false || $posizione < $blocco) {
            throw new RuntimeException($dopo.' prima del blocco');
        }
    }

    return true;
});

check('l\'account è di qualcuno con password, Google o se non è un cliente del negozio', fn () =>
    str_contains($claimed, '->hasLocalPassword($userId)')
    && str_contains($claimed, "->canAccessArea(\$userId, 'frontend', ['client'])")
    && str_contains($claimed, 'FederatedIdentityRepository())->findByUserId($userId) !== []'));

check('il link per la password usa la stessa regola', fn () =>
    str_contains(corpoMetodo($guest, 'passwordLink'), 'self::claimed($users, $userId)'));

check('GuestAccountExists è un RuntimeException con il testo del modulo', function () use ($root) {
    $classe = (string) file_get_contents($root.'/src/Frontend/Checkout/GuestAccountExists.php');

    return is_subclass_of(\Wonder\Plugin\Ecommerce\Frontend\Checkout\GuestAccountExists::class, RuntimeException::class)
        && str_contains($classe, "'ecommerce.checkout.errors.account_exists'");
});

$refuse = corpoMetodo($guest, 'refuse');

check('il blocco conta per hash dell\'email (3/900) e per IP (10/3600), poi risponde come ai troppi ordini', function () use ($guest, $refuse, $account) {
    $tentativo = strpos($refuse, "Throttle::attempt('guest_exists', 'email:'.\$hash");
    $generico = strpos($refuse, "'ecommerce.checkout.errors.too_many_orders'");
    $blocco = strpos($refuse, 'throw new GuestAccountExists()');

    return str_contains($guest, 'EXISTS_EMAIL = [3, 900]')
        && str_contains($guest, 'EXISTS_IP = [10, 3600]')
        && str_contains($refuse, "\$hash = hash('sha256', \$email)")
        && str_contains($refuse, "Throttle::attempt('guest_exists', \$ip === '' ? '' : 'ip:'.\$ip")
        && $tentativo !== false && $generico !== false && $blocco !== false
        && $tentativo < $generico && $generico < $blocco
        && str_contains($account, 'self::refuse($email, $userId)')
        && !str_contains($account, 'throw new GuestAccountExists()');
});

check('il registro ha l\'hash dell\'email, mai l\'email', fn () =>
    str_contains($refuse, "AuthLog::write('guest_checkout_existing', \$userId, 'frontend', false, ['email_hash' => \$hash")
    && !preg_match('/AuthLog::write\([^;]*\$email\b/', $refuse));

$controller = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
$express = (string) file_get_contents($root.'/src/Frontend/Checkout/ExpressController.php');
$exists = (string) file_get_contents($root.'/src/Frontend/Checkout/GuestAccountExists.php');
$place = corpoMetodo($controller, 'place');
$risposta = corpoMetodo($controller, 'accountExists');

check('place intercetta GuestAccountExists prima di UserError e RuntimeException', function () use ($place) {
    $blocco = strpos($place, 'catch (GuestAccountExists $e)');

    return $blocco !== false
        && $blocco < strpos($place, 'catch (UserError $error)')
        && $blocco < strpos($place, 'catch (RuntimeException $error)')
        && str_contains($place, 'self::accountExists($e, $post, $json)');
});

check('in JSON è un 409 account_exists con i link e senza redirect (il JS lo seguirebbe)', fn () =>
    str_contains($risposta, "self::json(['success' => false, 'error' => 'account_exists', 'message' => \$error->getMessage()] + GuestAccountExists::links(), 409)")
    && !str_contains($risposta, "'redirect'"));

check('senza JavaScript il modulo torna com\'era, con l\'avviso e i link', fn () =>
    str_contains($risposta, '$_SESSION[self::ACCOUNT_EXISTS] = true')
    && str_contains($risposta, 'self::rememberErrors([$error->getMessage()], CheckoutRules::rememberedPost($post, $_POST))')
    && str_contains($risposta, "self::redirect(self::route('ecommerce.checkout.index'))")
    && str_contains($controller, "'account_exists' => self::pullAccountExists()"));

check('i link: accesso con continue al checkout e recupero della password', fn () =>
    str_contains($exists, "\\__r('ecommerce.auth.login').'?continue='.rawurlencode((string) \\__r('ecommerce.checkout.index'))")
    && str_contains($exists, "\\__r('ecommerce.auth.password.recovery')"));

check('Express risponde allo stesso modo, prima del rifiuto generico', function () use ($express) {
    $blocco = strpos($express, 'catch (GuestAccountExists $error)');

    return $blocco !== false
        && strpos($express, 'catch (UserError|RuntimeException $error)', $blocco) !== false
        && str_contains($express, "'error' => 'account_exists', 'message' => \$error->getMessage()] + GuestAccountExists::links(), 409)");
});

check('la pagina del checkout ha i link, nascosti finché non servono', function () use ($root) {
    $vista = (string) file_get_contents($root.'/view/pages/checkout/index.php');

    return str_contains($vista, 'data-checkout-account-exists<?=!empty($account_exists) ? \'\' : \' hidden\'?>')
        && str_contains($vista, 'data-account-login href="<?=e($links[\'login_url\'])?>"')
        && str_contains($vista, 'data-account-recovery href="<?=e($links[\'recovery_url\'])?>"');
});

check('le chiavi account_exists, login_cta e recovery_cta esistono in italiano e in inglese', function () use ($root) {
    foreach (['it', 'en'] as $lingua) {
        $testi = json_decode((string) file_get_contents($root.'/lang/'.$lingua.'/ecommerce.json'), true, 64, JSON_THROW_ON_ERROR);
        foreach (['account_exists', 'login_cta', 'recovery_cta'] as $chiave) {
            if (trim((string) ($testi['checkout']['errors'][$chiave] ?? '')) === '') {
                throw new RuntimeException($lingua.': manca '.$chiave);
            }
        }
    }

    return true;
});

check('checkout.js mostra i link col 409 account_exists senza azzerare il modulo', function () use ($root) {
    $js = (string) file_get_contents($root.'/resources/assets/js/checkout.js');
    $funzione = substr($js, (int) strpos($js, 'function showAccountExists('), 900);

    return str_contains($js, "} else if (payload.error === 'account_exists') {\n            showAccountExists(payload);")
        && str_contains($js, "if (placed.data.error === 'account_exists') {\n                showAccountExists(placed.data);")
        && str_contains($funzione, "querySelector('[data-checkout-account-exists]')")
        && str_contains($funzione, 'login.href = payload.login_url')
        && str_contains($funzione, 'recovery.href = payload.recovery_url')
        && str_contains($funzione, 'box.hidden = false')
        && !str_contains($funzione, '.reset(');
});

summary();
