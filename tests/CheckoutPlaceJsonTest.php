<?php
/** php tests/CheckoutPlaceJsonTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
$placement = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutPlacement.php');

/** Il corpo di un metodo statico, privato o pubblico, fino al metodo dopo. */
function corpo(string $sorgente, string $metodo): string
{
    if (!preg_match('/(private|public) static function '.preg_quote($metodo, '/').'\\(/', $sorgente, $trovato, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $inizio = $trovato[0][1];
    $fine = preg_match('/(private|public) static function /', $sorgente, $dopo, PREG_OFFSET_CAPTURE, $inizio + 1) ? $dopo[0][1] : false;

    return substr($sorgente, $inizio, $fine === false ? null : $fine - $inizio);
}

$place = corpo($controller, 'place');
$placed = corpo($placement, 'place');
$started = corpo($placement, 'startOnline');

check('place risponde in JSON o con un redirect solo attraverso leave e reject', fn () => $place !== ''
    && str_contains($place, 'self::wantsJson()')
    && !str_contains($place, 'self::redirect(')
    && !str_contains($place, 'self::rememberErrors('));

check('CheckoutPlacement confronta il totale visto e lascia cadere l\'ordine in sospeso prima di crearne uno', fn () =>
    str_contains($placed, "OnlinePayment::sameTotal(\$options['expected_total'] ?? null")
    && strpos($placed, 'OnlinePayment::dropPending()') !== false
    && strpos($placed, 'OnlinePayment::dropPending()') < strpos($placed, 'Checkout::place('));

check('place: il totale cambiato è un 409 col riepilogo nuovo, i campi mancanti un rifiuto', fn () =>
    str_contains($place, 'catch (CheckoutRejected $e)')
    && str_contains($place, '$e->totalChanged()')
    && str_contains($place, "'total_changed'")
    && str_contains($place, "'summary' => \$e->summary()")
    && str_contains($place, 'self::reject($e->errors(), $post, $json)')
    && strpos($place, 'catch (CheckoutRejected $e)') < strpos($place, 'catch (RuntimeException $error)'));

check('CheckoutPlacement salva l\'indirizzo di consegna nell\'account del cliente autenticato, dopo l\'ordine e senza fermarlo', function () use ($placed) {
    $salva = strpos($placed, 'AccountAddress::remember($customerId,');

    return $salva !== false
        && $salva > strpos($placed, 'Checkout::place(')
        && str_contains($placed, 'if (!$guest && $customerId > 0)')
        && str_contains($placed, "Errors::internal(\$error, 'ecommerce.checkout.address')");
});

check('place avvia il pagamento online e risponde col segreto dell\'intento', fn () =>
    str_contains($place, 'CheckoutPlacement::startOnline($receipt)')
    && str_contains($started, 'OnlinePayment::start(')
    && str_contains($started, "'client_secret' =>")
    && str_contains($started, "'return_url' => self::route('ecommerce.checkout.return')"));

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
    && str_contains($returned, "in_array(\$reopened['outcome'], ['succeeded', 'processing'], true)")
    && str_contains($returned, "self::redirect(self::returnUrl(\$reopened['reference']))")
    && str_contains($reopen, "self::returnUrl(\$reopened['reference'])")
    && str_contains(corpo($controller, 'returnUrl'), "'?payment_intent='.rawurlencode("));

check('un pagamento rifiutato al ritorno riapre il checkout invece della pagina di pagamento', fn () =>
    str_contains($returned, 'self::reopenPending()')
    && str_contains($returned, "self::redirect(self::route('ecommerce.checkout.index'))")
    && !str_contains($returned, "self::route('ecommerce.checkout.pay')"));

check('reopenPending non lascia uscire un errore: l\'ordine scade da solo con le prenotazioni', fn () =>
    str_contains(corpo($controller, 'reopenPending'), 'OnlinePayment::reopen()')
    && str_contains(corpo($controller, 'reopenPending'), "Errors::internal(\$error, 'ecommerce.checkout.reopen')"));

check('il ritorno con un payment_intent non stringa è un 404 senza avvisi', fn () =>
    str_contains($returned, "\$reference = \$_GET['payment_intent'] ?? '';")
    && str_contains($returned, 'if (!is_string($reference)) {')
    && str_contains($returned, 'OnlinePayment::settle($orderId, $reference)'));

summary();
