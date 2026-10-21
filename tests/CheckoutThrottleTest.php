<?php
/** php tests/CheckoutThrottleTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Support\Throttle;

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
$placement = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutPlacement.php');
$session = (string) file_get_contents($root.'/src/Frontend/Cart/CartSession.php');

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

// Il contatore scrive in una cartella temporanea, mai nel pacchetto.
$cartella = sys_get_temp_dir().'/wi-throttle-'.getmypid();
Throttle::useDirectory($cartella);

check('Throttle: sotto il limite si prova, al limite no', function (): bool {
    for ($i = 0; $i < 3; $i++) {
        if (!Throttle::allowed('prova', 'a', 3, 60)) {
            return false;
        }
        Throttle::hit('prova', 'a', 60);
    }

    return !Throttle::allowed('prova', 'a', 3, 60);
});

check('Throttle: chiavi e gruppi diversi non si sommano', fn () =>
    Throttle::allowed('prova', 'b', 3, 60) && Throttle::allowed('altro', 'a', 3, 60));

check('Throttle: i tentativi fuori dalla finestra non contano più', function () use ($cartella): bool {
    Throttle::hit('vecchi', 'a', 60);
    Throttle::hit('vecchi', 'a', 60);
    $file = glob($cartella.'/*.json') ?: [];
    // Si invecchiano a mano i tempi salvati: due minuti fa.
    foreach ($file as $percorso) {
        $tempi = json_decode((string) file_get_contents($percorso), true);
        if (is_array($tempi) && count($tempi) === 2) {
            file_put_contents($percorso, json_encode(array_map(static fn (int $t): int => $t - 120, $tempi)));
        }
    }

    return Throttle::allowed('vecchi', 'a', 2, 60);
});

check('Throttle: una chiave vuota non si limita e non scrive', function () use ($cartella): bool {
    $prima = count(glob($cartella.'/*.json') ?: []);
    Throttle::hit('prova', '', 60);

    return Throttle::allowed('prova', '', 0, 60) && count(glob($cartella.'/*.json') ?: []) === $prima;
});

foreach (glob($cartella.'/*') ?: [] as $file) {
    unlink($file);
}
@rmdir($cartella);
Throttle::useDirectory(null);

// attempt(): la tabella si sostituisce con una finestra fissa in memoria, la stessa regola dell'upsert.
$righe = [];
$adesso = 1_000_000;
Throttle::useStore(function (string $hash, int $now, int $since) use (&$righe, &$adesso): int {
    $now = $adesso;
    $riga = $righe[$hash] ?? null;
    if ($riga === null) {
        $righe[$hash] = ['hits' => 1, 'window_start' => $now];
    } else {
        $scaduta = $riga['window_start'] <= $since + ($adesso - time());
        $righe[$hash] = ['hits' => $scaduta ? 1 : $riga['hits'] + 1, 'window_start' => $scaduta ? $now : $riga['window_start']];
    }

    return $righe[$hash]['hits'];
});

check('attempt: dentro il limite sì, oltre no', function (): bool {
    $esiti = [];
    for ($i = 0; $i < 4; $i++) {
        $esiti[] = Throttle::attempt('prova', 'x', 3, 60);
    }

    return $esiti === [true, true, true, false];
});

check('attempt: scaduta la finestra il conto riparte', function () use (&$adesso): bool {
    $adesso += 61;

    return Throttle::attempt('prova', 'x', 3, 60) && Throttle::attempt('prova', 'x', 3, 60);
});

check('attempt: chiave vuota o limite 0 non limitano e non contano', function () use (&$righe): bool {
    $prima = count($righe);

    return Throttle::attempt('prova', '', 1, 60) && Throttle::attempt('prova', '', 1, 60)
        && Throttle::attempt('prova', 'y', 0, 60) && Throttle::attempt('prova', 'y', 0, 60)
        && count($righe) === $prima;
});

check('attempt: gruppi diversi, contatori diversi (sha256 di gruppo|chiave)', function () use (&$righe): bool {
    Throttle::attempt('uno', 'k', 5, 60);
    Throttle::attempt('due', 'k', 5, 60);

    return isset($righe[hash('sha256', 'uno|k')], $righe[hash('sha256', 'due|k')]);
});

check('attempt: se il contatore non risponde l\'ordine passa (fail-open)', function (): bool {
    Throttle::useStore(static function (): int {
        throw new RuntimeException('tabella assente');
    });
    $esito = Throttle::attempt('prova', 'z', 1, 60) && Throttle::attempt('prova', 'z', 1, 60);

    return $esito;
});
Throttle::useStore(null);

$throttle = (string) file_get_contents($root.'/src/Support/Throttle.php');
check('attempt: un solo INSERT … ON DUPLICATE KEY UPDATE, hits aggiornato prima di window_start', fn () =>
    str_contains(Throttle::UPSERT, 'ON DUPLICATE KEY UPDATE')
    && strpos(Throttle::UPSERT, 'hits = IF(') < strpos(Throttle::UPSERT, 'window_start = IF(')
    && substr_count(Throttle::UPSERT, 'INSERT') === 1);

check('attempt: il fallimento va nel registro con Errors::internal', fn () =>
    str_contains(corpo($throttle, 'attempt'), 'Errors::internal(')
    && str_contains(corpo($throttle, 'attempt'), 'return true;'));

$modello = (string) file_get_contents($root.'/src/Models/Throttle.php');
$manifesto = json_decode((string) file_get_contents($root.'/module.json'), true);
check('Models\Throttle: tabella ecommerce_throttle con chiave unica di 64, hits e window_start; il manifest la dichiara', fn () =>
    str_contains($modello, "'ecommerce_throttle'")
    && preg_match("/Column::key\('throttle_key'\)->type\('CHAR'\)->length\(64\).*->unique\(\)/", $modello) === 1
    && str_contains($modello, "Column::key('hits')->int()")
    && str_contains($modello, "Column::key('window_start')->int()")
    && ($manifesto['database']['models'] ?? '') === 'src/Models');

$coupon = corpo($controller, 'coupon');
$placed = corpo($placement, 'place');

check('coupon: il limite vale solo per «applica», si controlla prima del tentativo e conta solo i falliti', fn () =>
    str_contains($coupon, "\$action === 'apply'")
    && str_contains($coupon, 'self::couponAllowed(')
    && strpos($coupon, 'self::couponAllowed(') < strpos($coupon, 'CheckoutSummary::coupon(')
    && str_contains($coupon, 'self::couponFailed(')
    && strpos($coupon, 'self::couponFailed(') > strpos($coupon, 'CheckoutSummary::coupon('));

check('coupon: oltre il limite risponde 429 col riepilogo, così il JavaScript lo disegna', fn () =>
    str_contains($coupon, 'ecommerce.checkout.errors.too_many_coupons')
    && str_contains($coupon, 'CheckoutSummary::payload(')
    && str_contains($coupon, '429'));

check('coupon: si conta per indirizzo IP e per carrello', fn () =>
    str_contains(corpo($controller, 'couponAllowed'), "'coupon_ip'")
    && str_contains(corpo($controller, 'couponAllowed'), "'coupon_cart'")
    && str_contains(corpo($controller, 'couponFailed'), "'coupon_ip'")
    && str_contains(corpo($controller, 'couponFailed'), "'coupon_cart'"));

check('ordini: il limite per cliente sta in CheckoutPlacement, così vale anche per la barra rapida', fn () =>
    str_contains($placed, "Throttle::allowed('orders'")
    && strpos($placed, "Throttle::allowed('orders'") < strpos($placed, 'Checkout::place(')
    && str_contains($placed, "Throttle::hit('orders'")
    && strpos($placed, "Throttle::hit('orders'") > strpos($placed, 'Checkout::place(')
    && str_contains($placed, 'ecommerce.checkout.errors.too_many_orders'));

check('carrello: in HTTPS il cookie è __Host- e il nome semplice non si legge', fn () =>
    str_contains($session, "'__Host-ecommerce_cart'")
    && str_contains(corpo($session, 'token'), 'self::cookieName()')
    && !str_contains(corpo($session, 'token'), '$_COOKIE[self::COOKIE]')
    && str_contains(corpo($session, 'cookieName'), 'self::secureCookie()'));

foreach (['it', 'en'] as $lingua) {
    $testi = json_decode((string) file_get_contents($root.'/lang/'.$lingua.'/ecommerce.json'), true);
    check("lang/{$lingua}: ci sono i due messaggi dei limiti", fn () =>
        ($testi['checkout']['errors']['too_many_coupons'] ?? '') !== ''
        && ($testi['checkout']['errors']['too_many_orders'] ?? '') !== '');
}

summary();
