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

summary();
