<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use OutOfBoundsException;
use Throwable;
use Wonder\App\Credentials;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentState;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Payments\OnlinePayments;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;

/**
 * Il pagamento online visto dal negozio: l'ordine in sospeso della sessione,
 * l'avvio dell'intento e l'esito al ritorno dal gateway. Niente risposte HTTP
 * qui dentro: le dà il controller.
 *
 * L'ordine nasce a «Paga» e il carrello diventa l'ordine; finché il denaro non
 * arriva, l'id resta in sessione per riprovare dalla pagina di pagamento.
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

    /** L'ordine in sessione se è ancora da pagare; altrimenti lo dimentica. */
    public static function pending(): int
    {
        $id = self::sessionOrder();
        if ($id <= 0) {
            return 0;
        }

        $order = Order::findById($id);
        if (is_array($order) && ($order['stage'] ?? '') === 'order' && ($order['status'] ?? '') === 'pending') {
            return $id;
        }

        self::forget();

        return 0;
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
     * (incasso registrato), `processing` (arriverà col webhook), `retry`
     * (rifiutato, si riprova) o `canceled`.
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
            PaymentState::PROCESSING => 'processing',
            PaymentState::CANCELED => 'canceled',
            default => 'retry',
        };
    }

    /**
     * Lascia cadere l'ordine in sospeso della sessione: si annulla, intento
     * compreso, salvo che il denaro sia già arrivato o in arrivo. In quel caso
     * l'ordine resta in sessione, così il ritorno dal gateway lo trova.
     *
     * @return 'none'|'cancelled'|'succeeded'|'processing'
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

        Lifecycle::cancel($id, ['reason' => 'Abbandonato dal cliente nel checkout', 'source' => 'ecommerce', 'notify' => false]);

        return 'cancelled';
    }

    /**
     * Dopo un pagamento non riuscito il checkout si riapre: l'ordine si annulla
     * e righe, scelte, indirizzi e coupon tornano nel carrello della sessione.
     * Se il denaro è arrivato non si riapre niente: `reference` porta al ritorno.
     *
     * @return array{outcome: string, reference: string, removed: list<string>}
     */
    public static function reopen(): array
    {
        $id = self::sessionOrder();
        $payment = $id > 0 ? (array) OnlinePayments::payment($id) : [];
        $reference = (string) ($payment['provider_reference'] ?? '');
        $outcome = self::dropPending();
        if ($outcome === 'none' && $id > 0 && (Order::findById($id)['status'] ?? '') === 'cancelled') {
            // Annullato da altri (scadenza, webhook): le righe tornano lo stesso.
            $outcome = 'cancelled';
        }
        if ($outcome !== 'cancelled') {
            return ['outcome' => $outcome, 'reference' => $reference, 'removed' => []];
        }

        unset($_SESSION[self::RECEIPT]);
        $method = (string) ($payment['provider_method'] ?? '');
        if ($method !== '' && $method !== 'card' && (int) ($payment['payment_method_id'] ?? 0) > 0) {
            $_SESSION[self::CHOICE] = (int) $payment['payment_method_id'].':'.$method;
        }
        $cart = CartSession::current(true);
        $restored = Cart::restore($id, (int) ($cart['order']['id'] ?? 0));

        return ['outcome' => 'cancelled', 'reference' => $reference, 'removed' => array_values((array) ($restored['removed'] ?? []))];
    }

    /** La scelta ricordata da `reopen()`, una volta sola; vuota se non c'è. */
    public static function pullChoice(): string
    {
        $choice = (string) ($_SESSION[self::CHOICE] ?? '');
        unset($_SESSION[self::CHOICE]);

        return $choice;
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
