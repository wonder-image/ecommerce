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

$reopen = corpo($controller, 'reopen');
$returned = corpo($controller, 'returned');

check('reopen: POST e CSRF, poi l\'ordine si annulla e il modulo torna col riepilogo', fn () => $reopen !== ''
    && str_contains($controller, "'reopen' => self::reopen()")
    && !str_contains($controller, "'abandon'")
    && strpos($reopen, 'self::requireCsrf()') < strpos($reopen, 'self::reopenPending()')
    && str_contains($reopen, "AuthSession::verify(\$_POST['csrf_token'] ?? '')")
    && str_contains($reopen, 'CheckoutSummary::payload(')
    && str_contains($reopen, "self::redirect(self::route('ecommerce.checkout.index'))"));

check('reopen e il ritorno, se il denaro è arrivato, portano al ritorno col suo intento', fn () =>
    str_contains($reopen, "in_array(\$reopened['outcome'], ['succeeded', 'processing'], true)")
    && str_contains($reopen, "self::returnUrl(\$reopened['reference'])")
    && str_contains(corpo($controller, 'returnUrl'), "'?payment_intent='.rawurlencode("));

check('un pagamento rifiutato al ritorno riapre il checkout invece della pagina di pagamento', fn () =>
    str_contains($returned, 'self::reopenPending()')
    && str_contains($returned, "self::redirect(self::route('ecommerce.checkout.index'))")
    && !str_contains($returned, "self::route('ecommerce.checkout.pay')"));

check('reopenPending non lascia uscire un errore: l\'ordine scade da solo con le prenotazioni', fn () =>
    str_contains(corpo($controller, 'reopenPending'), 'OnlinePayment::reopen()')
    && str_contains(corpo($controller, 'reopenPending'), "Errors::internal(\$error, 'ecommerce.checkout.reopen')"));

summary();
