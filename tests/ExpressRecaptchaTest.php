<?php
/** php tests/ExpressRecaptchaTest.php — il reCAPTCHA dell'ospite nel check-out rapido. */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressVerification;

$root = dirname(__DIR__);

/** Il corpo di un metodo PHP: dalla firma alla prima graffa chiusa a quattro spazi; vuoto se non c'è. */
$metodo = static function (string $file, string $nome): string {
    $codice = (string) file_get_contents($file);
    $inizio = strpos($codice, "function {$nome}(");
    if ($inizio === false) {
        return '';
    }
    $fine = strpos($codice, "\n    }\n", $inizio);

    return $fine === false ? substr($codice, $inizio) : substr($codice, $inizio, $fine - $inizio);
};

$_SESSION = [];
check('senza segno nessun carrello è verificato', fn () => !ExpressVerification::verified(12));

ExpressVerification::mark(12);
check('il segno vale per il suo carrello', fn () => ExpressVerification::verified(12));
check('il segno non vale per un altro carrello', fn () => !ExpressVerification::verified(13));

ExpressVerification::mark(0);
check('il carrello 0 non è mai verificato', fn () => !ExpressVerification::verified(0));

ExpressVerification::mark(12);
ExpressVerification::clear();
check('clear toglie il segno', fn () => !ExpressVerification::verified(12) && !isset($_SESSION[ExpressVerification::SESSION]));

$settled = $metodo($root.'/src/Frontend/Checkout/ExpressCart.php', 'settled');
check('settled toglie il segno insieme al carrello rapido', fn () =>
    (bool) preg_match('/self::forget\(\);\s*ExpressVerification::clear\(\);/', $settled));

$controller = $root.'/src/Frontend/Checkout/ExpressController.php';
$codiceController = (string) file_get_contents($controller);
$start = $metodo($controller, 'start');
$place = $metodo($controller, 'place');
$cancel = $metodo($controller, 'cancel');
$guardia = "RecaptchaGuard::for('ecommerce_express')";

check('use della guardia e della sfida', fn () =>
    str_contains($codiceController, 'use Wonder\App\Security\RecaptchaChallenge;')
    && str_contains($codiceController, 'use Wonder\App\Security\RecaptchaGuard;'));
check('il docblock non dice più «Niente reCAPTCHA»', fn () => !str_contains($codiceController, 'Niente reCAPTCHA'));
check('start: il segno vecchio si toglie prima della guardia', fn () =>
    str_contains($start, 'ExpressVerification::clear();')
    && strpos($start, 'ExpressVerification::clear();') < (int) strpos($start, $guardia));
check('start: la guardia solo per l\'ospite, con servizio e azione di log', fn () =>
    str_contains($start, '$guest = !CartSession::authenticated();')
    && (bool) preg_match('/if \(\$guest\) \{\s*RecaptchaGuard::for\(\'ecommerce_express\'\)\s*->withService\(\'ecommerce-express\'\)\s*->withLogAction\(\'start\'\)\s*->verify\(\);/', $start));
check('start: la guardia prima del carrello, il segno subito dopo', fn () =>
    strpos($start, $guardia) < (int) strpos($start, 'self::begin(')
    && (bool) preg_match('/\$cartId = self::begin\(\$_POST\);\s*if \(\$guest\) \{\s*ExpressVerification::mark\(\$cartId\);/', $start));
check('start: la sfida 618 si prende prima dei 422 generici', fn () =>
    str_contains($start, 'catch (RecaptchaChallenge $e)')
    && strpos($start, 'catch (RecaptchaChallenge $e)') < (int) strpos($start, 'catch (UserError|RuntimeException $error)')
    && str_contains($start, "self::json(['success' => false, 'status' => RecaptchaChallenge::CHALLENGE_CODE, 'message' => \$e->getMessage()], 422);"));
check('place: l\'ospite senza segno riceve 403 unverified, dopo il controllo del carrello', fn () =>
    (bool) preg_match('/\$cartId = self::expressCart\(\);\s*(?:\/\/[^\n]*\s*)?if \(!CartSession::authenticated\(\) && !ExpressVerification::verified\(\$cartId\)\) \{\s*self::json\(\[\'success\' => false, \'error\' => \'unverified\', \'message\' => \(string\) __t\(\'ecommerce\.checkout\.express_unverified\'\)\], 403\);/', $place));
check('cancel toglie il segno', fn () => str_contains($cancel, 'ExpressVerification::clear();'));

foreach (['it', 'en'] as $lingua) {
    $testi = json_decode((string) file_get_contents($root."/lang/{$lingua}/ecommerce.json"), true);
    check("lang {$lingua}: express_unverified", fn () => ($testi['checkout']['express_unverified'] ?? '') !== '');
}

summary();
