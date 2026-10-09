<?php
/** php tests/CheckoutPlaceJsonTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');

/** Il corpo di un metodo privato del controller, fino al metodo dopo. */
function corpo(string $sorgente, string $metodo): string
{
    $inizio = strpos($sorgente, 'private static function '.$metodo.'(');
    if ($inizio === false) {
        return '';
    }
    $fine = strpos($sorgente, 'private static function ', $inizio + 1);

    return substr($sorgente, $inizio, $fine === false ? null : $fine - $inizio);
}

$place = corpo($controller, 'place');

check('place risponde in JSON o con un redirect solo attraverso leave e reject', fn () => $place !== ''
    && str_contains($place, 'self::wantsJson()')
    && !str_contains($place, 'self::redirect(')
    && !str_contains($place, 'self::rememberErrors('));

check('place confronta il totale visto e lascia cadere l\'ordine in sospeso prima di crearne uno', fn () =>
    str_contains($place, "OnlinePayment::sameTotal(\$_POST['expected_total'] ?? null")
    && strpos($place, 'OnlinePayment::dropPending()') !== false
    && strpos($place, 'OnlinePayment::dropPending()') < strpos($place, 'Checkout::place('));

check('place avvia il pagamento online e risponde col segreto dell\'intento', fn () =>
    str_contains($place, 'OnlinePayment::start(')
    && str_contains($place, "'client_secret' =>")
    && str_contains($place, "'return_url' => self::route('ecommerce.checkout.return')"));

check('leave e reject esistono e terminano la richiesta', fn () =>
    str_contains(corpo($controller, 'leave'), 'self::json(')
    && str_contains(corpo($controller, 'leave'), 'self::redirect(')
    && str_contains(corpo($controller, 'reject'), '422')
    && str_contains(corpo($controller, 'reject'), 'self::rememberErrors('));

summary();
