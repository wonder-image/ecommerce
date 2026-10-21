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
    $blocco = strpos($account, 'throw new GuestAccountExists()');
    if ($blocco === false) {
        throw new RuntimeException('manca il throw');
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

summary();
