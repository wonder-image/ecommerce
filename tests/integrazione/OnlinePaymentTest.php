<?php
/** php tests/integrazione/OnlinePaymentTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require SITE.'/vendor/wonder-image/gestionale/tests/integrazione/supporto/compra.php';
require SITE.'/vendor/wonder-image/gestionale/tests/integrazione/supporto/FakePaymentProvider.php';
// Il sito di prova ha indirizzi veri: la posta di fondo si butta via.
require SITE.'/vendor/wonder-image/gestionale/tests/integrazione/supporto/posta.php';

use Wonder\Sql\Transaction;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\OnlinePayment;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentState;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;

final class Annulla extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    }

    return $esito;
}

$_SESSION = [];
$finto = new FakePaymentProvider();
PaymentProviders::register($finto);

/** L'ordine come lo lascia `Checkout::place` con un metodo Stripe, già avviato dal negozio. */
function avviato(float $totale = 50.0): array
{
    $_SESSION = [];
    $ordine = ordineDiProva($totale);
    $pagamento = Ledger::open(['order_id' => $ordine, 'amount' => $totale, 'provider' => 'stripe'])['payment_id'];
    OnlinePayment::start($ordine);

    return [$ordine, $pagamento, (string) (Payment::findById($pagamento)['provider_reference'] ?? '')];
}

check('sameTotal confronta i centesimi', fn () => OnlinePayment::sameTotal('10', 10.00)
    && OnlinePayment::sameTotal(19.9, '19.90')
    && !OnlinePayment::sameTotal('10.01', 10)
    && !OnlinePayment::sameTotal('', 10)
    && !OnlinePayment::sameTotal(null, 10));

check('start ricorda l\'ordine in sessione e dà il segreto dell\'intento', fn () => prova(static function (): bool {
    [$ordine, , $intento] = avviato();

    return $intento !== ''
        && OnlinePayment::start($ordine)['client_secret'] !== ''
        && OnlinePayment::sessionOrder() === $ordine
        && OnlinePayment::pending() === $ordine;
}));

check('settle con l\'intento riuscito registra l\'incasso e conferma l\'ordine', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = avviato(50.0);
    $finto->states[$intento] = new PaymentState(PaymentState::SUCCEEDED, 5000, 'eur', $ordine);

    return OnlinePayment::settle($ordine, $intento) === 'succeeded'
        && (Payment::findById($pagamento)['status'] ?? '') === 'paid'
        && (Order::findById($ordine)['status'] ?? '') !== 'pending';
}));

check('settle sulla riga già pagata non chiede di nuovo al fornitore', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = avviato(50.0);
    $finto->states[$intento] = new PaymentState(PaymentState::SUCCEEDED, 5000, 'eur', $ordine);
    OnlinePayment::settle($ordine, $intento);
    unset($finto->states[$intento]);

    return OnlinePayment::settle($ordine, $intento) === 'succeeded';
}));

check('settle distingue in lavorazione, da riprovare e annullato', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = avviato();
    $esiti = [];

    foreach ([PaymentState::PROCESSING, PaymentState::REQUIRES_PAYMENT_METHOD, PaymentState::CANCELED, PaymentState::OTHER] as $stato) {
        $finto->states[$intento] = new PaymentState($stato);
        $esiti[] = OnlinePayment::settle($ordine, $intento);
    }

    return $esiti === ['processing', 'retry', 'canceled', 'retry']
        && (Order::findById($ordine)['status'] ?? '') === 'pending';
}));

check('settle rifiuta un intento che non è dell\'ordine', fn () => prova(static function (): bool {
    [$ordine] = avviato();

    try {
        OnlinePayment::settle($ordine, 'pi_estraneo');
    } catch (OutOfBoundsException) {
        return true;
    }

    return false;
}));

check('pending dimentica un ordine che non è più in attesa', fn () => prova(static function (): bool {
    [$ordine] = avviato();
    Lifecycle::cancel($ordine, ['notify' => false]);

    return OnlinePayment::pending() === 0 && OnlinePayment::sessionOrder() === 0;
}));

check('dropPending annulla l\'ordine in sospeso e il suo intento', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = avviato();
    OnlinePayment::dropPending();

    return (Order::findById($ordine)['status'] ?? '') === 'cancelled'
        && in_array($intento, $finto->cancelled, true)
        && OnlinePayment::sessionOrder() === 0;
}));

check('dropPending lascia stare un pagamento in lavorazione', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = avviato();
    $finto->states[$intento] = new PaymentState(PaymentState::PROCESSING);
    $annullati = count($finto->cancelled);
    OnlinePayment::dropPending();

    return (Order::findById($ordine)['status'] ?? '') === 'pending'
        && count($finto->cancelled) === $annullati
        && OnlinePayment::sessionOrder() === 0;
}));

check('dropPending conferma invece un pagamento già riuscito', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = avviato(50.0);
    $finto->states[$intento] = new PaymentState(PaymentState::SUCCEEDED, 5000, 'eur', $ordine);
    OnlinePayment::dropPending();

    return (Payment::findById($pagamento)['status'] ?? '') === 'paid'
        && !in_array((string) (Order::findById($ordine)['status'] ?? ''), ['pending', 'cancelled'], true);
}));

PaymentProviders::reset();
summary();
