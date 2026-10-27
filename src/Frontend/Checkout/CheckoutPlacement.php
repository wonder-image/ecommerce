<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use RuntimeException;
use Throwable;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Gestionale\Support\Throttle;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;

/**
 * La creazione dell'ordine dal carrello, uguale per il modulo del checkout
 * e per la barra rapida: rilegge l'anteprima, controlla campi, metodo e
 * totale, dà un account all'ospite e passa l'ordine al gestionale.
 * L'intento Stripe parte dopo, con startOnline().
 */
final class CheckoutPlacement
{
    // Cinque ordini in quindici minuti: c'è posto per chi riprova dopo una carta rifiutata.
    private const ORDER_LIMIT = 5;
    private const ORDER_WINDOW = 900;

    private const CART_KEYS = ['email', 'phone', 'fulfillment_type', 'shipping_method_id', 'location_id', 'payment_method_id', 'customer_note'];

    /**
     * @param array<string, mixed> $post Già passato da CheckoutRules::post.
     * @param array{json?: bool, expected_total?: mixed, express?: bool} $options
     * @return array{receipt: array<string, mixed>, online: bool, method: array<string, mixed>}
     */
    public static function place(int $cartId, array $post, ?object $user, array $options): array
    {
        $json = !empty($options['json']);

        // Il cliente entrato non ha il reCAPTCHA dell'ospite: lo ferma un limite di ordini.
        $limited = CartSession::authenticated() ? (int) ($user->id ?? 0) : 0;
        $customerKey = $limited > 0 ? (string) $limited : '';
        if (!Throttle::allowed('orders', $customerKey, self::ORDER_LIMIT, self::ORDER_WINDOW)) {
            throw new TooManyOrders();
        }

        // L'anteprima scrive contatti e consegna sul carrello; poi si rilegge.
        $preview = CheckoutSummary::payload($cartId, $post, $user);
        $order = (array) Order::findById($cartId);
        $userId = (int) ($user->id ?? 0);
        $asked = CheckoutRules::askedConsents($userId);
        $billing = CheckoutRules::billing($post, $order);
        // Il modulo chiede civico e provincia; la barra rapida prende l'indirizzo del wallet com'è.
        $form = empty($options['express']);
        $missing = array_values(array_unique(array_merge(
            CheckoutRules::deliveryErrors($order, $preview, Gestionale::feature('shipping'), $form),
            CheckoutRules::paymentErrors($post, $billing, $asked, $form)
        )));
        if ($missing !== []) {
            throw new CheckoutRejected(array_map(static fn (string $key): string => (string) __t('ecommerce.checkout.errors.'.$key), $missing));
        }

        // Vale solo un metodo che la pagina ha offerto.
        $method = CheckoutRules::method($preview, (int) ($post['payment_method_id'] ?? 0));
        if ($method === null) {
            throw new RuntimeException((string) __t('ecommerce.checkout.errors.payment_method'));
        }

        $online = !CheckoutForm::isManual($method);
        if ($online && !$json) {
            throw new RuntimeException((string) __t('ecommerce.checkout.errors.online_javascript'));
        }
        // Il Payment Element mostra un importo: se nel frattempo è cambiato, il cliente lo rivede prima di pagare.
        // Un metodo manuale si controlla solo se il modulo manda il totale: un JS vecchio non lo manda.
        $seen = $options['expected_total'] ?? null;
        $checked = $online || (is_scalar($seen) && trim((string) $seen) !== '');
        if ($checked && !OnlinePayment::sameTotal($seen, $preview['order']['total'] ?? null)) {
            throw new CheckoutRejected([(string) __t('ecommerce.checkout.errors.total_changed')], $preview);
        }

        $data = CheckoutForm::data([
            'payment_method_id' => (string) $method['id'],
            'customer_note' => (string) ($post['customer_note'] ?? ''),
        ] + $billing + self::fromCart($order), $user);

        // L'ospite ordina sempre su un account: si trova o nasce dalla sua email.
        $guest = !CartSession::authenticated();
        $customerId = CartSession::customerId();
        $passwordLink = '';
        if ($guest) {
            // L'ospite non ha il limite per cliente: si conta per indirizzo e per carrello, prima dell'account.
            if (!self::guestAllowed($cartId)) {
                throw new TooManyOrders();
            }
            $account = GuestCheckout::account($order, $post, $asked, (bool) ($options['express'] ?? false));
            $userId = $account['user_id'];
            $customerId = $account['customer_id'];
            // Online il link parte con la conferma, dal modulo (Ecommerce::orderEmailExtras).
            if (!$online) {
                $passwordLink = GuestCheckout::passwordLink($userId, self::route('ecommerce.auth.password.restore'));
            }
        }

        // Un ordine online lasciato a metà non resta a tenere la merce: si
        // abbandona. Se lo lascia la barra rapida e veniva dal checkout normale,
        // torna carrello: l'express compra da un carrello a parte. Con il
        // pagamento già in corso non nasce un secondo ordine.
        $outcome = 'none';
        try {
            $pending = OnlinePayment::sessionOrder();
            $outcome = !empty($options['express']) && $pending > 0 && !ExpressCart::owns($pending)
                ? OnlinePayment::reopen()['outcome']
                : OnlinePayment::dropPending();
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.drop');
        }
        if (in_array($outcome, ['kept', 'succeeded', 'processing'], true)) {
            throw UserError::make('order.payment_in_progress');
        }

        $result = Checkout::place($cartId, $data + [
            // Su una riga Stripe vuoto vale «card»: lo decide il gestionale.
            'stripe_method_type' => (string) ($post['stripe_method_type'] ?? ''),
            // Dalla barra rapida valgono anche i wallet (Apple Pay, Google Pay).
            'express' => !empty($options['express']),
            'customer_id' => $customerId,
            'source' => 'ecommerce',
            'user_id' => $userId,
            'customer_email' => $passwordLink === '' ? [] : ['account_url' => $passwordLink],
        ]);
        Throttle::hit('orders', $customerKey, self::ORDER_WINDOW);

        // Solo chi è entrato: l'ospite non scrive nell'account che trova dalla sua email.
        if (!$guest && $customerId > 0) {
            try {
                AccountAddress::remember($customerId, (array) Order::findById((int) ($result['order_id'] ?? 0)));
            } catch (Throwable $error) {
                // L'ordine è nato: un indirizzo non salvato non lo ferma.
                Errors::internal($error, 'ecommerce.checkout.address');
            }
            try {
                AccountAddress::rememberBilling($customerId, $billing, $post);
            } catch (Throwable $error) {
                Errors::internal($error, 'ecommerce.checkout.billing');
            }
        }

        if (!$guest && $userId > 0 && $asked !== []) {
            try {
                consentService()->registerBaseConsents($userId, $post, ['required_document_types' => $asked, 'ui_surface' => 'checkout']);
            } catch (Throwable $error) {
                // L'ordine è nato: un consenso non registrato non lo ferma.
                Errors::internal($error, 'ecommerce.checkout.consents');
            }
        }

        $receipt = [
            'order_id' => (int) ($result['order_id'] ?? 0),
            'order_number' => (string) ($result['order_number'] ?? ''),
            'total' => (string) ($result['total'] ?? ''),
            'status' => (string) ($result['status'] ?? 'pending'),
            'instructions' => (string) ($method['instructions'] ?? ''),
            'guest' => $guest,
            'email_sent' => $guest && ($result['customer_email_sent'] ?? false),
        ];

        return ['receipt' => $receipt, 'online' => $online, 'method' => $method];
    }

    /**
     * L'ordine online resta in sessione finché il denaro non arriva; l'intento
     * nasce qui. Un errore esce: chi chiama manda alla pagina di pagamento.
     *
     * @param array<string, mixed> $receipt
     * @return array{client_secret: string, return_url: string, billing_details: array<string, mixed>}
     */
    public static function startOnline(array $receipt): array
    {
        $_SESSION[OnlinePayment::RECEIPT] = $receipt;

        return [
            'client_secret' => (string) OnlinePayment::start((int) $receipt['order_id'])['client_secret'],
            'return_url' => self::route('ecommerce.checkout.return'),
            'billing_details' => OnlinePayment::billingDetails((array) Order::findById((int) $receipt['order_id'])),
        ];
    }

    /**
     * I valori dei moduli presi dal carrello: contatto, scelte, nota e indirizzi.
     *
     * @param array<string, mixed> $order
     * @return array<string, string>
     */
    public static function fromCart(array $order): array
    {
        $values = [];
        foreach (array_merge(self::CART_KEYS, Order::shippingAddress()->keys(), Order::billingAddress()->keys()) as $key) {
            $values[$key] = trim((string) ($order[$key] ?? ''));
        }

        return $values;
    }

    /** I limiti `checkout.guest_order_limit`: ogni tentativo conta, anche quello che poi non diventa ordine. */
    private static function guestAllowed(int $cartId): bool
    {
        $ip = Throttle::ip();
        foreach (['ip' => $ip === '' ? '' : 'order_ip:'.$ip, 'cart' => 'order_cart:'.$cartId] as $scope => $key) {
            [$limit, $window] = self::guestLimit($scope);
            if (!Throttle::attempt('orders', $key, $limit, $window)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{0: int, 1: int} [tentativi, secondi]; [0, 0] se il sito l'ha spento. */
    public static function guestLimit(string $scope): array
    {
        $value = Ecommerce::config('checkout.guest_order_limit.'.$scope, 0);
        if (!is_array($value) || count($value) < 2) {
            return [0, 0];
        }
        $value = array_values($value);

        return [max(0, (int) $value[0]), max(0, (int) $value[1])];
    }

    private static function route(string $name): string
    {
        return \__r($name) ?: '/';
    }
}
