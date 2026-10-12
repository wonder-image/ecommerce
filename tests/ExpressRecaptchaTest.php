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

$express = $root.'/src/Frontend/Checkout/ExpressCheckout.php';
$codiceExpress = (string) file_get_contents($express);
check('l\'ospite senza chiavi reCAPTCHA non vede i bottoni', fn () =>
    str_contains($codiceExpress, 'use Wonder\App\Security\RecaptchaGuard;')
    && (bool) preg_match('/if \(!CartSession::authenticated\(\) && !RecaptchaGuard::configured\(\)\) \{\s*return null;/', $metodo($express, 'method')));
check('la vista riceve captcha per l\'ospite, il JS l\'etichetta della casella', fn () =>
    str_contains($codiceExpress, "'captcha' => !CartSession::authenticated(),")
    && str_contains($codiceExpress, "'captcha' => (string) __t('ecommerce.checkout.express_captcha'),"));

$vista = (string) file_get_contents($root.'/view/components/checkout/express.php');
check('la vista mette il riquadro autonomo sotto i bottoni', fn () =>
    str_contains($vista, 'use Wonder\App\ResourceSchema\FormField;')
    && (bool) preg_match('/data-express-element><\/div>\s*<\?php if \(!empty\(\$captcha\)\): \?><div class="wi-recaptcha-cell w-100 mt-3" data-wi-recaptcha-standalone><\?=FormField::key\(\'recaptcha\'\)->recaptcha\(\'ecommerce_express\'\)\?><\/div><\?php endif; \?>/', $vista));

/** Un metodo della classe Express in checkout.js: dalla firma alla prima graffa chiusa a quattro spazi. */
$metodoJs = static function (string $classe, string $firma): string {
    $inizio = strpos($classe, "\n    {$firma}");
    if ($inizio === false) {
        return '';
    }
    $fine = strpos($classe, "\n    }\n", $inizio + 1);

    return $fine === false ? substr($classe, $inizio) : substr($classe, $inizio, $fine - $inizio);
};
$codiceJs = (string) file_get_contents($root.'/resources/assets/js/checkout.js');
$classe = substr($codiceJs, (int) strpos($codiceJs, 'class Express {'));
$costruttore = $metodoJs($classe, 'constructor(root) {');
$click = $metodoJs($classe, 'click(event) {');
$verifica = $metodoJs($classe, 'async verify(data) {');
$annulla = $metodoJs($classe, 'cancel() {');

check('Express: il riquadro autonomo e la casella a vista spengono i bottoni', fn () =>
    str_contains($costruttore, "this.captcha = root.querySelector('[data-wi-recaptcha-standalone]');")
    && str_contains($costruttore, "this.challenge = Boolean(box?.dataset.wiSiteKey) && (!box.dataset.wiScoreKey || box.dataset.wiChallenge === '1');")
    && str_contains($costruttore, "addEventListener('wi-recaptcha-change'")
    && str_contains($classe, "this.root.classList.toggle('wi-express-locked', on);"));
check('Express: a bottoni spenti il clic non apre il foglio', fn () =>
    str_contains($click, "this.root.classList.contains('wi-express-locked')")
    && strpos($click, "this.root.classList.contains('wi-express-locked')") < (int) strpos($click, 'event.resolve('));
check('Express: il token si chiede dopo resolve e va a start con i tre campi', fn () =>
    strpos($click, 'event.resolve(') < (int) strpos($click, 'this.verify(data)')
    && str_contains($verifica, 'window.wiRecaptchaToken(this.captcha)')
    && str_contains($verifica, "'g-recaptcha-token': answer.token, 'g-recaptcha-action': answer.action, 'g-recaptcha-type': answer.type"));
check('Express: sul 618 la casella si apre sotto i bottoni', fn () =>
    str_contains($click, 'reply.data?.status === 618')
    && str_contains($click, 'window.wiRecaptchaChallenge?.(this.captcha);')
    && str_contains($click, 'this.lockButtons(true);'));
check('Express: il token della casella si rinnova dopo start', fn () =>
    str_contains($click, "values['g-recaptcha-type'] === 'checkbox'") && str_contains($click, 'window.wiRecaptchaReset?.(this.captcha);'));
check('Express: il foglio chiuso ricorda la casella', fn () => str_contains($annulla, 'this.say(this.labels.captcha);'));

$css = (string) file_get_contents($root.'/resources/assets/css/express.css');
check('CSS: bottoni spenti', fn () =>
    (bool) preg_match('/\.wi-express\.wi-express-locked \[data-express-element\] \{\s*pointer-events: none;/', $css));

foreach (['it', 'en'] as $lingua) {
    $testi = json_decode((string) file_get_contents($root."/lang/{$lingua}/ecommerce.json"), true);
    check("lang {$lingua}: express_captcha", fn () => ($testi['checkout']['express_captcha'] ?? '') !== '');
}

summary();
