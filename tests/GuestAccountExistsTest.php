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
    && str_contains($claimed, "in_array('frontend', \$user['area'], true)")
    && str_contains($claimed, "in_array('client', \$user['authority'], true)")
    && str_contains($claimed, 'FederatedIdentityRepository())->findByUserId($userId) !== []'));

check('il cliente disattivato senza password si aggancia come prima (Q-GUEST-NOPWD)', fn () =>
    !str_contains($claimed, 'canAccessArea(')
    && !str_contains($claimed, "['active']"));

check('il link per la password usa la stessa regola, solo per un cliente attivo', function () use ($guest) {
    $link = corpoMetodo($guest, 'passwordLink');

    return str_contains($link, 'self::claimed($users, $userId)')
        && str_contains($link, "!\$users->canAccessArea(\$userId, 'frontend', ['client'])");
});

check('GuestAccountExists è un RuntimeException con il testo del modulo', function () use ($root) {
    $classe = (string) file_get_contents($root.'/src/Frontend/Checkout/GuestAccountExists.php');

    return is_subclass_of(\Wonder\Plugin\Ecommerce\Frontend\Checkout\GuestAccountExists::class, RuntimeException::class)
        && str_contains($classe, "'ecommerce.checkout.errors.account_exists'");
});

$refuse = corpoMetodo($guest, 'refuse');

check('il blocco conta per hash dell\'email (3/900) e per IP (10/3600), poi risponde come ai troppi ordini', function () use ($guest, $refuse, $account) {
    $tentativo = strpos($refuse, "Throttle::attempt('guest_exists', 'email:'.\$hash");
    $generico = strpos($refuse, 'throw new TooManyOrders()');
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

check('il blocco conta con Throttle del gestionale, su gst_throttles', fn () =>
    str_contains($guest, "use Wonder\\Plugin\\Gestionale\\Support\\Throttle;")
    && !str_contains($guest, 'Ecommerce\\Support\\Throttle'));

// Che il registro non abbia l'email in chiaro lo prova integrazione/GuestCheckoutTest sulla riga scritta.
check('il registro ha l\'hash dell\'email', fn () =>
    str_contains($refuse, "AuthLog::write('guest_checkout_existing', \$userId, 'frontend', false, ['email_hash' => \$hash"));

$unlinked = corpoMetodo($guest, 'unlinked');

check('dal rapido l\'account con un modo per entrare non ferma l\'ordine: nasce slegato', function () use ($guest, $account) {
    $rifiuto = strpos($account, "if (!\$express) {\n                    self::refuse(\$email, \$userId);");
    $slegato = strpos($account, 'self::unlinked($email, $userId)');
    $ritorno = strpos($account, "return ['user_id' => 0, 'customer_id' => 0, 'created' => false];");

    return str_contains($guest, 'public static function account(array $order, array $post, array $asked, bool $express = false): array')
        && $rifiuto !== false && $slegato !== false && $ritorno !== false
        && $rifiuto < $slegato && $slegato < $ritorno
        && strpos($account, 'registerLeadConsents($email, $post, $context)', $slegato) < $ritorno
        && $ritorno < strpos($account, 'CustomerContact::forUser(');
});

check('l\'ordine slegato va nel registro con l\'hash e senza limite guest_exists', fn () =>
    str_contains($unlinked, "AuthLog::write('guest_checkout_existing_unlinked', \$userId, 'frontend', null, ['email_hash' => hash('sha256', \$email)])")
    && !str_contains($unlinked, 'Throttle::'));

$placement = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutPlacement.php');

check('il piazzamento passa a account() se l\'ordine viene dal rapido', fn () =>
    str_contains($placement, "GuestCheckout::account(\$order, \$post, \$asked, (bool) (\$options['express'] ?? false))"));

$controller = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
$express = (string) file_get_contents($root.'/src/Frontend/Checkout/ExpressController.php');
$exists = (string) file_get_contents($root.'/src/Frontend/Checkout/GuestAccountExists.php');
$place = corpoMetodo($controller, 'place');
$risposta = corpoMetodo($controller, 'accountExists');

check('il checkout normale non passa express al piazzamento: con un account il 409 resta', fn () =>
    str_contains($place, "CheckoutPlacement::place(\$cartId, \$post, \$user, ['json' => \$json, 'expected_total' => \$_POST['expected_total'] ?? null]);")
    && !str_contains($place, "'express'")
    && str_contains(corpoMetodo($express, 'place'), "'express' => true]"));

check('place intercetta GuestAccountExists prima di UserError e RuntimeException', function () use ($place) {
    $blocco = strpos($place, 'catch (GuestAccountExists $e)');

    return $blocco !== false
        && $blocco < strpos($place, 'catch (UserError $error)')
        && $blocco < strpos($place, 'catch (RuntimeException $error)')
        && str_contains($place, 'self::accountExists($e, $post, $json)');
});

check('in JSON è un 409 account_exists col solo messaggio, senza link e senza redirect (il JS lo seguirebbe)', fn () =>
    str_contains($risposta, "self::json(['success' => false, 'error' => 'account_exists', 'message' => \$error->getMessage()], 409)")
    && !str_contains($risposta, "'redirect'"));

check('senza JavaScript il modulo torna com\'era con l\'avviso, senza stato per i link', fn () =>
    str_contains($risposta, 'self::rememberErrors([$error->getMessage()], CheckoutRules::rememberedPost($post, $_POST))')
    && str_contains($risposta, "self::redirect(self::route('ecommerce.checkout.index'))")
    && !str_contains($controller, 'ACCOUNT_EXISTS')
    && !str_contains($controller, 'pullAccountExists'));

check('GuestAccountExists non produce più link', fn () =>
    !str_contains($exists, 'function links(')
    && !str_contains($exists, 'login_url')
    && !str_contains($exists, 'recovery_url'));

check('Express tiene il 409 solo come difesa, prima del rifiuto generico', function () use ($express) {
    $blocco = strpos($express, 'catch (GuestAccountExists $error)');

    return $blocco !== false
        && strpos($express, 'catch (UserError|RuntimeException $error)', $blocco) !== false
        && str_contains($express, "'error' => 'account_exists', 'message' => \$error->getMessage()], 409)")
        && !str_contains($express, 'GuestAccountExists::links()');
});

check('sotto l\'email non ci sono più i link: resta «Accedi» accanto a «Contatti» con continue al checkout', function () use ($root) {
    $vista = (string) file_get_contents($root.'/view/pages/checkout/index.php');

    return !str_contains($vista, 'data-checkout-account-exists')
        && !str_contains($vista, 'data-account-login')
        && !str_contains($vista, 'data-account-recovery')
        && !str_contains($vista, 'GuestAccountExists')
        && str_contains($vista, "__r('ecommerce.auth.login').'?continue='.rawurlencode((string) __r('ecommerce.checkout.index'))")
        && str_contains($vista, "__t('ecommerce.checkout.login')");
});

check('account_exists esiste in italiano e in inglese, login_cta e recovery_cta non più', function () use ($root) {
    foreach (['it', 'en'] as $lingua) {
        $testi = json_decode((string) file_get_contents($root.'/lang/'.$lingua.'/ecommerce.json'), true, 64, JSON_THROW_ON_ERROR);
        if (trim((string) ($testi['checkout']['errors']['account_exists'] ?? '')) === '') {
            throw new RuntimeException($lingua.': manca account_exists');
        }
        foreach (['login_cta', 'recovery_cta'] as $chiave) {
            if (array_key_exists($chiave, (array) ($testi['checkout']['errors'] ?? []))) {
                throw new RuntimeException($lingua.': resta '.$chiave);
            }
        }
    }

    return true;
});

check('checkout.js col 409 account_exists mostra solo l\'alert, senza link e senza azzerare il modulo', function () use ($root) {
    $js = (string) file_get_contents($root.'/resources/assets/js/checkout.js');

    return !str_contains($js, 'AccountExists')
        && !str_contains($js, 'data-checkout-account-exists')
        && !str_contains($js, 'login_url')
        && !str_contains($js, 'recovery_url')
        && str_contains($js, "const errors = (payload.errors || [payload.message || payload.error || this.labels.summary_error]).filter(Boolean);")
        && str_contains($js, "this.say(placed.data.message || this.labels.error);");
});

summary();
