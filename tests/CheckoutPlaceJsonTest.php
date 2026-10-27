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
    str_contains($placed, "\$seen = \$options['expected_total'] ?? null;")
    && str_contains($placed, 'OnlinePayment::sameTotal($seen, ')
    && strpos($placed, 'OnlinePayment::dropPending()') !== false
    && strpos($placed, 'OnlinePayment::dropPending()') < strpos($placed, 'Checkout::place('));

check('place: il totale cambiato è un 409 col riepilogo nuovo, i campi mancanti un rifiuto', fn () =>
    str_contains($place, 'catch (CheckoutRejected $e)')
    && str_contains($place, '$e->totalChanged()')
    && str_contains($place, "'total_changed'")
    && str_contains($place, "'summary' => \$e->summary()")
    && str_contains($place, 'self::reject($e->errors(), $post, $json)')
    && strpos($place, 'catch (CheckoutRejected $e)') < strpos($place, 'catch (RuntimeException $error)'));

check('il totale visto vale online sempre, per un metodo manuale solo se il modulo lo manda', fn () =>
    str_contains($placed, "\$checked = \$online || (is_scalar(\$seen) && trim((string) \$seen) !== '');")
    && str_contains($placed, 'if ($checked && !OnlinePayment::sameTotal($seen, '));

check('place: il 409 col riepilogo è solo in JSON; senza JSON il totale cambiato torna al modulo come rifiuto', fn () =>
    str_contains($place, 'if ($e->totalChanged() && $json)')
    && strpos($place, 'if ($e->totalChanged() && $json)') < strpos($place, 'self::reject($e->errors(), $post, $json)'));

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

check('reopen: POST e CSRF, poi l\'ordine si riapre e il modulo torna col riepilogo', fn () => $reopen !== ''
    && str_contains($controller, "'reopen' => self::reopen()")
    && !str_contains($controller, "'abandon'")
    && strpos($reopen, 'self::requireCsrf()') < strpos($reopen, 'self::resume($json, false)')
    && str_contains($reopen, 'Csrf::verify()')
    && str_contains($reopen, 'CheckoutSummary::payload(')
    && str_contains($reopen, "self::redirect(self::route('ecommerce.checkout.index'))"));

check('resume: se il denaro è arrivato porta al ritorno, se l\'intento non si chiude a «Paga ora»', fn () =>
    str_contains(corpo($controller, 'resume'), "in_array(\$reopened['outcome'], ['succeeded', 'processing'], true)")
    && str_contains(corpo($controller, 'resume'), "self::returnUrl(\$reopened['reference'])")
    && str_contains(corpo($controller, 'resume'), "self::leave(self::route('ecommerce.checkout.pay'), \$json)")
    && str_contains(corpo($controller, 'returnUrl'), "'?payment_intent='.rawurlencode("));

check('un pagamento rifiutato al ritorno torna al checkout, quello della barra rapida a «Paga ora»', fn () =>
    !str_contains($returned, 'self::resume(')
    && str_contains($returned, "self::redirect(self::route(ExpressCart::owns(\$orderId) ? 'ecommerce.checkout.pay' : 'ecommerce.checkout.index'))"));

check('resume non lascia uscire un errore: OnlinePayment::resume() lo chiude in «kept»', fn () =>
    str_contains(corpo($controller, 'resume'), 'OnlinePayment::resume()')
    && !str_contains($controller, 'reopenPending'));

check('il ritorno con un payment_intent non stringa è un 404 senza avvisi', fn () =>
    str_contains($returned, "\$reference = \$_GET['payment_intent'] ?? '';")
    && str_contains($returned, 'if (!is_string($reference)) {')
    && str_contains($returned, 'OnlinePayment::settle($orderId, $reference)'));

summary();
