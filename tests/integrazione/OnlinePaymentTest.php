<?php
/** php tests/integrazione/OnlinePaymentTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

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
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\OnlinePayment;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentEvent;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentProvider;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentStart;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentState;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Payments\OnlinePayments;
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

    Gestionale::reset();

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

check('settle con l\'intento riuscito registra l\'incasso e lascia l\'ordine in attesa del commerciante', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = avviato(50.0);
    $finto->states[$intento] = new PaymentState(PaymentState::SUCCEEDED, 5000, 'eur', $ordine);

    return OnlinePayment::settle($ordine, $intento) === 'succeeded'
        && (Payment::findById($pagamento)['status'] ?? '') === 'paid'
        && (Order::findById($ordine)['status'] ?? '') === 'pending'
        && (Order::findById($ordine)['payment_status'] ?? '') === 'paid';
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

check('l\'ordine in sospeso non passa a chi entra dopo sullo stesso browser', fn () => prova(static function (): bool {
    [$ordine] = avviato();
    $_SESSION[OnlinePayment::RECEIPT] = ['order_id' => $ordine];
    $primaDelLogin = OnlinePayment::pending();
    // Il login tiene la sessione e cambia solo l'utente.
    $_SESSION['user_id'] = 987654;

    return $primaDelLogin === $ordine
        && OnlinePayment::pending() === 0
        && OnlinePayment::sessionOrder() === 0
        && !isset($_SESSION[OnlinePayment::RECEIPT])
        && (Order::findById($ordine)['status'] ?? '') === 'pending';
}));

check('chi ha avviato il pagamento da cliente lo ritrova', fn () => prova(static function (): bool {
    [$ordine] = avviato();
    $_SESSION['user_id'] = 987654;
    OnlinePayment::start($ordine);

    return OnlinePayment::pending() === $ordine;
}));

check('dropPending annulla l\'ordine in sospeso e il suo intento', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = avviato();

    return OnlinePayment::dropPending() === 'cancelled'
        && (Order::findById($ordine)['status'] ?? '') === 'cancelled'
        && in_array($intento, $finto->cancelled, true)
        && OnlinePayment::sessionOrder() === 0;
}));

check('dropPending lascia stare un pagamento in lavorazione e lo tiene in sessione per il ritorno', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = avviato();
    $finto->states[$intento] = new PaymentState(PaymentState::PROCESSING);
    $annullati = count($finto->cancelled);

    return OnlinePayment::dropPending() === 'processing'
        && (Order::findById($ordine)['status'] ?? '') === 'pending'
        && count($finto->cancelled) === $annullati
        && OnlinePayment::sessionOrder() === $ordine;
}));

check('dropPending registra invece un pagamento già riuscito e tiene l\'ordine', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = avviato(50.0);
    $finto->states[$intento] = new PaymentState(PaymentState::SUCCEEDED, 5000, 'eur', $ordine);

    return OnlinePayment::dropPending() === 'succeeded'
        && (Payment::findById($pagamento)['status'] ?? '') === 'paid'
        && (string) (Order::findById($ordine)['status'] ?? '') === 'pending'
        && OnlinePayment::sessionOrder() === $ordine;
}));

check('dropPending senza ordine in sospeso non fa niente', fn () => prova(static function (): bool {
    $_SESSION = [];

    return OnlinePayment::dropPending() === 'none';
}));

/** Un ordine con una riga vera, nato dal carrello di un ospite e già avviato con Stripe. */
function avviatoConRighe(): array
{
    $_SESSION = [];
    accendiFunzionalita(['orders']);
    $_COOKIE[CartSession::COOKIE] = bin2hex(random_bytes(32));
    $prodotto = articoloConGiacenza(10, 'TST-RIA-'.strtoupper(substr(uniqid(), -7)));
    Product::update(['price' => '20.00'], $prodotto);
    // Il carrello di prima aveva un altro token: quello della sessione è già vuoto.
    $ordine = (int) Cart::open(['cart_token' => bin2hex(random_bytes(32)), 'channel' => 'online'])['id'];
    Cart::add($ordine, ['product_id' => $prodotto, 'quantity' => 2]);
    Order::update(['stage' => 'order', 'status' => 'pending', 'email' => 'cliente@example.com', 'total' => '40.00'], $ordine);
    $pagamento = Ledger::open(['order_id' => $ordine, 'amount' => 40.0, 'provider' => 'stripe'])['payment_id'];
    OnlinePayment::start($ordine);
    $_SESSION[OnlinePayment::RECEIPT] = ['order_id' => $ordine];

    return [$ordine, $prodotto, (string) (Payment::findById($pagamento)['provider_reference'] ?? '')];
}

check('reopen riporta l\'ordine a carrello sulla stessa riga, col suo numero, righe e contatti', fn () => prova(static function () use ($finto): bool {
    [$ordine, $prodotto, $intento] = avviatoConRighe();
    $numero = 'TST-'.strtoupper(substr(uniqid(), -7));
    Order::update(['order_number' => $numero], $ordine);
    $esito = OnlinePayment::reopen();
    $carrello = CartSession::current(false);
    $riga = $carrello['items'][0] ?? [];
    $dopo = Order::findById($ordine);

    return $esito['outcome'] === 'reopened'
        && $esito['removed'] === []
        && (int) ($carrello['order']['id'] ?? 0) === $ordine
        && (string) ($dopo['stage'] ?? '') === 'cart'
        && (string) ($dopo['status'] ?? '') === 'draft'
        && (string) ($dopo['order_number'] ?? '') === $numero
        && in_array($intento, $finto->cancelled, true)
        && count($carrello['items']) === 1
        && (int) ($riga['product_id'] ?? 0) === $prodotto
        && (float) ($riga['quantity'] ?? 0) === 2.0
        && (string) ($carrello['order']['email'] ?? '') === 'cliente@example.com'
        && OnlinePayment::sessionOrder() === 0
        && !isset($_SESSION[OnlinePayment::RECEIPT]);
}));

check('reopen unisce il carrello aperto nel frattempo: resta la riga dell\'ordine', fn () => prova(static function (): bool {
    [$ordine, $prodotto] = avviatoConRighe();
    // Un altro articolo messo nel carrello della sessione mentre l'ordine aspettava.
    $altro = articoloConGiacenza(10, 'TST-RIB-'.strtoupper(substr(uniqid(), -7)));
    $nuovo = (int) CartSession::current(true)['order']['id'];
    Cart::add($nuovo, ['product_id' => $altro, 'quantity' => 1]);
    $esito = OnlinePayment::reopen();
    $carrello = CartSession::current(false);
    $prodotti = array_map(static fn (array $r): int => (int) $r['product_id'], $carrello['items']);
    sort($prodotti);
    $attesi = [$prodotto, $altro];
    sort($attesi);

    return $esito['outcome'] === 'reopened'
        && (int) ($carrello['order']['id'] ?? 0) === $ordine
        && $prodotti === $attesi
        && empty(Order::findById($nuovo));
}));

check('reopen con il denaro già arrivato non riapre e dà l\'intento per il ritorno', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = avviatoConRighe();
    $finto->states[$intento] = new PaymentState(PaymentState::SUCCEEDED, 4000, 'eur', $ordine);
    $esito = OnlinePayment::reopen();

    return $esito['outcome'] === 'succeeded'
        && $esito['reference'] === $intento
        && CartSession::current(false)['items'] === []
        && OnlinePayment::sessionOrder() === $ordine;
}));

check('reopen riapre anche un ordine già annullato da altri, ma non uno estraneo alla sessione', fn () => prova(static function (): bool {
    [$ordine, $prodotto] = avviatoConRighe();
    Lifecycle::cancel($ordine, ['notify' => false]);
    $esito = OnlinePayment::reopen();
    $riaperto = count(CartSession::current(false)['items']) === 1;

    $_SESSION = [];
    $vuoto = OnlinePayment::reopen();

    return $esito['outcome'] === 'cancelled' && $riaperto && $vuoto['outcome'] === 'none';
}));

check('reopen ricorda la scelta Stripe diversa dalla carta: il modulo la rispunta una volta sola', fn () => prova(static function (): bool {
    [$ordine] = avviatoConRighe();
    $riga = OnlinePayments::payment($ordine);
    Order::update(['payment_method_id' => 891], $ordine);
    Payment::update(['payment_method_id' => 891, 'provider_method' => 'klarna'], (int) $riga['id']);
    OnlinePayment::reopen();
    // Il carrello riaperto ha lo stesso metodo: l'indice confronta la scelta con lui.
    $metodo = (int) (CartSession::current(false)['order']['payment_method_id'] ?? 0);
    $prima = OnlinePayment::pullChoice();
    $dopo = OnlinePayment::pullChoice();

    [$carta] = avviatoConRighe();
    Payment::update(['payment_method_id' => 891, 'provider_method' => 'card'], (int) OnlinePayments::payment($carta)['id']);
    OnlinePayment::reopen();

    return $metodo === 891 && $prima === '891:klarna' && $dopo === '' && OnlinePayment::pullChoice() === '';
}));

check('settle scrive il rifiuto della banca nella storia del pagamento, una volta per tentativo', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = avviato();
    $rifiuto = static fn (string $tentativo): PaymentState => new PaymentState(PaymentState::REQUIRES_PAYMENT_METHOD, 5000, 'eur', $ordine, 'Pagamento rifiutato: fondi insufficienti (insufficient_funds)', $tentativo);
    // Il browser chiede due volte dello stesso rifiuto, poi la seconda carta.
    $finto->states[$intento] = $rifiuto('ch_1');
    $esiti = [OnlinePayment::settle($ordine, $intento), OnlinePayment::settle($ordine, $intento)];
    $finto->states[$intento] = $rifiuto('ch_2');
    $esiti[] = OnlinePayment::settle($ordine, $intento);
    // Un intento nuovo, senza tentativi: niente da scrivere.
    $finto->states[$intento] = new PaymentState(PaymentState::REQUIRES_PAYMENT_METHOD, 5000, 'eur', $ordine);
    $esiti[] = OnlinePayment::settle($ordine, $intento);
    $righe = PaymentStatusLog::find(['payment_id' => $pagamento, 'to_value' => 'failed']);
    $righe = is_array($righe) && isset($righe['id']) ? [$righe] : array_values((array) $righe);

    return $esiti === ['retry', 'retry', 'retry', 'retry']
        && count($righe) === 2
        && (string) $righe[0]['message'] === 'Pagamento rifiutato: fondi insufficienti (insufficient_funds)'
        && (Payment::findById($pagamento)['status'] ?? '') === 'failed'
        && (Order::findById($ordine)['status'] ?? '') === 'pending';
}));

/** Stripe che non annulla perché nel frattempo il pagamento è partito: dopo, lo stato è `$dopo`. */
final class AnnulloMancato implements PaymentProvider
{
    public function __construct(private FakePaymentProvider $finto, private string $dopo) {}

    public function code(): string { return $this->finto->code(); }
    public function environment(): string { return $this->finto->environment(); }
    public function connected(): bool { return $this->finto->connected(); }
    public function start(array $order, array $payment): PaymentStart { return $this->finto->start($order, $payment); }
    public function status(string $reference): PaymentState { return $this->finto->status($reference); }
    public function event(string $raw, string $signature): ?PaymentEvent { return $this->finto->event($raw, $signature); }
    public function replay(array $payload, string $environment): ?PaymentEvent { return $this->finto->replay($payload, $environment); }

    public function cancel(string $reference): void
    {
        $this->finto->states[$reference] = new PaymentState($this->dopo);

        throw new RuntimeException('Stripe non annulla');
    }
}

/** Riapre con Stripe che non annulla: l'esito, o la chiave dell'errore. */
function riapriConAnnulloMancato(FakePaymentProvider $finto, string $dopo): array
{
    [$ordine, , $intento] = avviatoConRighe();
    $finto->states[$intento] = new PaymentState(PaymentState::REQUIRES_PAYMENT_METHOD);
    PaymentProviders::register(new AnnulloMancato($finto, $dopo));

    try {
        $esito = OnlinePayment::reopen()['outcome'];
    } catch (UserError $errore) {
        $esito = $errore->key();
    } finally {
        PaymentProviders::register($finto);
    }

    return [$esito, (string) (Order::findById($ordine)['stage'] ?? ''), OnlinePayment::pending() === $ordine];
}

check('reopen: se il pagamento parte mentre si annulla l\'ordine resta e si va al ritorno', fn () => prova(
    static fn (): bool => riapriConAnnulloMancato($finto, PaymentState::PROCESSING) === ['processing', 'order', true]
));

check('reopen: se Stripe non annulla senza motivo l\'ordine resta e l\'errore arriva al controller', fn () => prova(
    static fn (): bool => riapriConAnnulloMancato($finto, PaymentState::OTHER) === ['order.reopen_retry', 'order', true]
));

PaymentProviders::reset();
summary();
