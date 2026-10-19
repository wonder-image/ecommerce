<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use OutOfBoundsException;
use Throwable;
use Wonder\App\Credentials;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentState;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Payments\OnlinePayments;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;

/**
 * Il pagamento online visto dal negozio: l'ordine in sospeso della sessione,
 * l'avvio dell'intento e l'esito al ritorno dal gateway. Niente risposte HTTP
 * qui dentro: le dà il controller.
 *
 * L'ordine nasce a «Paga» «In pagamento» e il carrello diventa l'ordine;
 * finché il denaro non arriva l'id resta in sessione: si riprova a pagare o,
 * alla prima modifica, l'ordine torna carrello sulla stessa riga.
 */
final class OnlinePayment
{
    public const SESSION = 'ecommerce_checkout_pending';
    public const RECEIPT = 'ecommerce_checkout_receipt';
    /** Chi ha avviato il pagamento: 0 per l'ospite. */
    public const OWNER = 'ecommerce_checkout_pending_user';
    /** La scelta Stripe dell'ordine riaperto («891:klarna»), da rispuntare nel modulo. */
    public const CHOICE = 'ecommerce_checkout_reopened_choice';

    /**
     * L'ordine ricordato in sessione, senza guardare in che stato è. Il login
     * tiene la sessione: se nel frattempo è entrato un altro utente, l'ordine
     * non è suo e si dimentica, ricevuta compresa.
     */
    public static function sessionOrder(): int
    {
        $id = (int) ($_SESSION[self::SESSION] ?? 0);
        if ($id > 0 && (int) ($_SESSION[self::OWNER] ?? 0) !== (int) ($_SESSION['user_id'] ?? 0)) {
            self::forget();
            unset($_SESSION[self::RECEIPT]);

            return 0;
        }

        return $id;
    }

    /**
     * L'ordine in sessione se è «In pagamento»; altrimenti lo dimentica.
     * L'abbandonato resta in sessione: `reopen()` lo riporta a carrello.
     */
    public static function pending(): int
    {
        $id = self::sessionOrder();
        if ($id <= 0) {
            return 0;
        }

        $status = self::status($id);
        if ($status === 'awaiting_payment') {
            return $id;
        }

        if ($status !== 'abandoned') {
            self::forget();
        }

        return 0;
    }

    /** Lo stato dell'ordine `$id` se è ancora un ordine, vuoto per un carrello o un id perso. */
    private static function status(int $id): string
    {
        $order = Order::findById($id);

        return is_array($order) && ($order['stage'] ?? '') === 'order' ? (string) ($order['status'] ?? '') : '';
    }

    public static function remember(int $orderId): void
    {
        $_SESSION[self::SESSION] = $orderId;
        $_SESSION[self::OWNER] = (int) ($_SESSION['user_id'] ?? 0);
    }

    public static function forget(): void
    {
        unset($_SESSION[self::SESSION], $_SESSION[self::OWNER]);
    }

    /**
     * Le sole cose di Stripe che arrivano al browser.
     *
     * @return array{publishable_key: string, account: string}
     */
    public static function browserKeys(): array
    {
        $api = Credentials::api();

        return [
            'publishable_key' => (string) ($api->stripe_publishable_key ?? ''),
            'account' => (string) ($api->stripe_id ?? ''),
        ];
    }

    /**
     * I dati del cliente per Stripe, presi dall'ordine: il Payment Element non
     * li chiede e Stripe li vuole tutti alla conferma. Senza fatturazione valgono
     * destinatario e indirizzo della consegna.
     *
     * @return array{name: string, email: string, phone: string, address: array<string, string>}
     */
    public static function billingDetails(array $order): array
    {
        $value = static fn (string $key): string => trim((string) ($order[$key] ?? ''));
        $prefix = $value('billing_street') === '' && $value('shipping_street') !== '' ? 'shipping_' : 'billing_';

        $name = $value('billing_type') === 'business' ? $value('billing_business_name') : '';
        $name = $name !== '' ? $name : trim($value('billing_name').' '.$value('billing_surname'));
        $name = $name !== '' ? $name : trim($value('shipping_name').' '.$value('shipping_surname'));

        $phone = $value('phone');
        foreach (['billing_', 'shipping_'] as $side) {
            if ($phone === '' && $value($side.'phone') !== '') {
                $phone = trim($value($side.'phone_prefix').' '.$value($side.'phone'));
            }
        }

        return [
            'name' => $name,
            'email' => $value('email'),
            'phone' => $phone,
            'address' => [
                'line1' => trim($value($prefix.'street').' '.$value($prefix.'number')),
                'line2' => $value($prefix.'more'),
                'city' => $value($prefix.'city'),
                'state' => $value($prefix.'province'),
                'postal_code' => $value($prefix.'cap'),
                'country' => strtoupper($value($prefix.'country')) ?: 'IT',
            ],
        ];
    }

    /** Il totale visto dal cliente è quello dell'ordine, al centesimo. */
    public static function sameTotal(mixed $seen, mixed $actual): bool
    {
        if (!is_numeric($seen) || !is_numeric($actual)) {
            return false;
        }

        return (int) round((float) $seen * 100) === (int) round((float) $actual * 100);
    }

    /**
     * Crea o riusa l'intento dell'ordine e lo ricorda in sessione.
     *
     * @return array{client_secret: string}
     */
    public static function start(int $orderId): array
    {
        self::remember($orderId);

        return ['client_secret' => OnlinePayments::start($orderId)->clientSecret];
    }

    /**
     * Com'è andata, chiesto al gateway e non al browser: `succeeded`
     * (incasso registrato), `processing` (l'ordine passa al commerciante, il
     * denaro arriverà col webhook), `retry` (rifiutato, si riprova) o
     * `canceled`.
     */
    public static function settle(int $orderId, string $reference): string
    {
        $payment = OnlinePayments::payment($orderId);
        if ($payment === null || $reference === '' || (string) ($payment['provider_reference'] ?? '') !== $reference) {
            throw new OutOfBoundsException("Il pagamento {$reference} non è dell'ordine {$orderId}.");
        }

        if ((string) $payment['status'] === 'paid') {
            return 'succeeded';
        }

        $provider = PaymentProviders::get((string) $payment['provider']);
        if ($provider === null) {
            return 'processing';
        }

        try {
            $state = $provider->status($reference);
        } catch (Throwable $error) {
            // Il webhook e il riallineamento chiudono il giro anche senza di noi.
            Errors::internal($error, 'ecommerce.checkout.return', ['order_id' => $orderId]);

            return 'processing';
        }

        return match ($state->status) {
            PaymentState::SUCCEEDED => self::confirm($provider->code(), $reference, $state),
            PaymentState::PROCESSING => self::processing($provider->code(), $reference, $state),
            PaymentState::CANCELED => 'canceled',
            default => self::declined($provider->code(), $reference, $state),
        };
    }

    /**
     * Lascia cadere l'ordine in pagamento della sessione: si abbandona, intento
     * compreso, salvo che il denaro sia già arrivato o in arrivo. In quel caso
     * l'ordine resta in sessione, così il ritorno dal gateway lo trova; resta
     * anche quando l'intento non si chiude (`kept`).
     *
     * @return 'none'|'abandoned'|'kept'|'succeeded'|'processing'
     */
    public static function dropPending(): string
    {
        $id = self::pending();
        self::forget();
        if ($id <= 0) {
            return 'none';
        }

        $reference = (string) (OnlinePayments::payment($id)['provider_reference'] ?? '');
        $outcome = $reference !== '' ? self::settle($id, $reference) : '';
        if (in_array($outcome, ['succeeded', 'processing'], true)) {
            self::remember($id);

            return $outcome;
        }

        if (!Lifecycle::abandon($id, ['reason' => 'Abbandonato dal cliente nel checkout', 'source' => 'ecommerce', 'user_id' => (int) ($_SESSION['user_id'] ?? 0)])) {
            // Il pagamento è partito fra la lettura e l'abbandono, o Stripe non risponde.
            self::remember($id);

            return 'kept';
        }

        return 'abandoned';
    }

    /**
     * Il cliente cambia qualcosa (carrello, campi del checkout, coupon) con un
     * ordine in pagamento: l'ordine torna carrello sulla stessa riga
     * (`Lifecycle::reopen`, che annulla prima l'intento), con righe, scelte e
     * indirizzi; il prossimo «Paga» lo rifà ordine con un intento nuovo. Vale
     * anche per l'abbandonato, così chi torna dopo la scadenza ritrova il
     * carrello. Se il denaro è arrivato o è in arrivo non si riapre niente:
     * `reference` porta al ritorno. L'ordine della barra rapida si abbandona,
     * di quello annullato da altri si copiano le righe in un carrello.
     *
     * @return array{outcome: string, reference: string, removed: list<string>}
     */
    public static function reopen(): array
    {
        $id = self::sessionOrder();
        $status = $id > 0 ? self::status($id) : '';
        $payment = $id > 0 ? (array) OnlinePayments::payment($id) : [];
        $reference = (string) ($payment['provider_reference'] ?? '');

        if (in_array($status, ['awaiting_payment', 'abandoned'], true) && !ExpressCart::owns($id)) {
            $outcome = $status === 'awaiting_payment' && $reference !== '' ? self::settle($id, $reference) : '';
            if (in_array($outcome, ['succeeded', 'processing'], true)) {
                return ['outcome' => $outcome, 'reference' => $reference, 'removed' => []];
            }

            // Prima di riaprire: dopo, la sessione vedrebbe due carrelli.
            $existing = (int) (CartSession::current(false)['order']['id'] ?? 0);
            $customerId = CartSession::customerId();
            try {
                Lifecycle::reopen($id, [
                    // L'ospite ritrova il carrello dal gettone, con il cliente a zero.
                    'customer_id' => $customerId,
                    'cart_token' => CartSession::token(true),
                    'user_id' => $customerId > 0 ? (int) ($_SESSION['user_id'] ?? 0) : 0,
                    'source' => 'ecommerce',
                    // Nella storia del pagamento si distingue da un rifiuto della banca.
                    'reason' => 'Ordine modificato dal cliente',
                ]);
            } catch (UserError $error) {
                // Il pagamento è partito fra la lettura e l'annullo: il ritorno dice com'è finita.
                if ($error->key() === 'order.payment_in_progress') {
                    return ['outcome' => 'processing', 'reference' => $reference, 'removed' => []];
                }

                throw $error;
            }
            self::forget();
            unset($_SESSION[self::RECEIPT]);
            self::rememberChoice($payment);
            // Il carrello nato nel frattempo si unisce: resta la riga dell'ordine.
            $cart = $existing > 0 && $existing !== $id ? Cart::merge($existing, $id) : Cart::recalculate($id);

            return ['outcome' => 'reopened', 'reference' => $reference, 'removed' => array_values((array) ($cart['removed'] ?? []))];
        }

        $outcome = self::dropPending();
        if ($outcome === 'none' && $id > 0 && in_array($status, ['cancelled', 'abandoned'], true)) {
            // Chiuso da altri (scadenza, webhook): le righe tornano lo stesso.
            $outcome = $status;
        }
        if (!in_array($outcome, ['abandoned', 'cancelled'], true)) {
            return ['outcome' => $outcome, 'reference' => $reference, 'removed' => []];
        }

        self::forget();
        unset($_SESSION[self::RECEIPT]);
        // L'express compra da un carrello a parte: chiuso, si butta e basta.
        if (ExpressCart::owns($id)) {
            ExpressCart::forget();

            return ['outcome' => 'abandoned', 'reference' => $reference, 'removed' => []];
        }

        self::rememberChoice($payment);
        $cart = CartSession::current(true);
        $restored = Cart::restore($id, (int) ($cart['order']['id'] ?? 0));

        return ['outcome' => 'abandoned', 'reference' => $reference, 'removed' => array_values((array) ($restored['removed'] ?? []))];
    }

    /**
     * La riapertura dei punti che modificano il carrello: `reopen()` se c'è
     * un ordine in pagamento (o abbandonato) del checkout normale, senza
     * lasciar uscire un errore. `none` se non c'era niente da riaprire,
     * `kept` se l'intento non si è chiuso (il messaggio è in `error`).
     *
     * @return array{outcome: string, reference: string, removed: list<string>, error?: string}
     */
    public static function resume(): array
    {
        $id = self::sessionOrder();
        if ($id <= 0 || ExpressCart::owns($id) || !in_array(self::status($id), ['awaiting_payment', 'abandoned'], true)) {
            return ['outcome' => 'none', 'reference' => '', 'removed' => []];
        }

        try {
            return self::reopen();
        } catch (UserError $error) {
            // Stripe non ha annullato l'intento: l'ordine resta da pagare.
            return ['outcome' => 'kept', 'reference' => '', 'removed' => [], 'error' => $error->getMessage()];
        } catch (Throwable $error) {
            // Senza riapertura l'ordine si abbandona da solo con le prenotazioni.
            Errors::internal($error, 'ecommerce.checkout.reopen', ['order_id' => $id]);

            return ['outcome' => 'none', 'reference' => '', 'removed' => []];
        }
    }

    /** La scelta ricordata da `reopen()`, una volta sola; vuota se non c'è. */
    public static function pullChoice(): string
    {
        $choice = (string) ($_SESSION[self::CHOICE] ?? '');
        unset($_SESSION[self::CHOICE]);

        return $choice;
    }

    /** @param array<string, mixed> $payment */
    private static function rememberChoice(array $payment): void
    {
        $method = (string) ($payment['provider_method'] ?? '');
        if ($method !== '' && $method !== 'card' && (int) ($payment['payment_method_id'] ?? 0) > 0) {
            $_SESSION[self::CHOICE] = (int) $payment['payment_method_id'].':'.$method;
        }
    }

    /**
     * Il rifiuto finisce nella storia del pagamento dell'ordine, col motivo
     * della banca: una riga per tentativo, anche se il webhook non arriva.
     */
    private static function declined(string $provider, string $reference, PaymentState $state): string
    {
        if ($state->failure === '') {
            // Intento nuovo o rifiuto senza motivo: non c'è un tentativo da scrivere.
            return 'retry';
        }

        try {
            OnlinePayments::fail($provider, $reference, $state->failure, $state->attempt);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.declined');
        }

        return 'retry';
    }

    /** In elaborazione (SEPA): l'ordine passa al commerciante, l'incasso arriverà col webhook. */
    private static function processing(string $provider, string $reference, PaymentState $state): string
    {
        try {
            OnlinePayments::processing($provider, $reference, $state->amount, $state->currency, $state->orderId, 'ecommerce');
        } catch (Throwable $error) {
            // Lo rifà il webhook `payment_intent.processing` o il riallineamento.
            Errors::internal($error, 'ecommerce.checkout.processing');
        }

        return 'processing';
    }

    private static function confirm(string $provider, string $reference, PaymentState $state): string
    {
        try {
            $result = OnlinePayments::succeeded($provider, $reference, $state->amount, $state->currency, $state->orderId, 'ecommerce');
        } catch (Throwable $error) {
            // Anche `PaymentMismatch`: il commerciante è già avvisato e il cliente non vede un esito falso.
            Errors::internal($error, 'ecommerce.checkout.return');

            return 'processing';
        }

        return ($result['status'] ?? '') === 'cancelled' ? 'processing' : 'succeeded';
    }
}
