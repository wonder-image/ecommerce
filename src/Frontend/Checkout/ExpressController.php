<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use RuntimeException;
use Throwable;
use Wonder\App\Security\RecaptchaChallenge;
use Wonder\App\Security\RecaptchaGuard;
use Wonder\Plugin\Ecommerce\Frontend\Auth\AuthSession;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Plugin\Gestionale\Support\Shipping\ShippingZones;

/**
 * Le rotte JSON del check-out rapido, chiamate dall'elemento di Stripe: il
 * carrello a parte (`start`), indirizzo e tariffa scelti nel foglio del wallet
 * (`shipping`), l'ordine e l'intento (`place`) e il foglio chiuso (`cancel`).
 * L'ospite passa dal reCAPTCHA a `start` (azione `ecommerce_express`): il
 * segno in sessione lega la verifica al carrello rapido e `place` lo vuole.
 */
final class ExpressController
{
    public static function handle(string $action): void
    {
        if (!in_array($action, ['start', 'shipping', 'place', 'cancel'], true)) {
            self::json(['success' => false], 404);
        }

        self::guard();

        match ($action) {
            'start' => self::start(),
            'shipping' => self::shipping(),
            'place' => self::place(),
            'cancel' => self::cancel(),
        };
    }

    /** Il carrello a parte: il solo prodotto della scheda, oppure la copia del carrello. */
    private static function start(): never
    {
        try {
            // Ogni avvio rifà la verifica: il segno vecchio non vale per il carrello nuovo.
            ExpressVerification::clear();
            $guest = !CartSession::authenticated();
            if ($guest) {
                RecaptchaGuard::for('ecommerce_express')
                    ->withService('ecommerce-express')
                    ->withLogAction('start')
                    ->verify();
            }

            self::dropPending();
            $cartId = self::begin($_POST);
            if ($guest) {
                ExpressVerification::mark($cartId);
            }
            $order = CheckoutSummary::payload($cartId, ['fulfillment_type' => 'shipping'], CartSession::user())['order'];
        } catch (RecaptchaChallenge $e) {
            // Punteggio basso: il JavaScript apre la casella sotto i bottoni.
            self::json(['success' => false, 'status' => RecaptchaChallenge::CHALLENGE_CODE, 'message' => $e->getMessage()], 422);
        } catch (UserError|RuntimeException $error) {
            self::json(['success' => false, 'message' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.express.start');
            self::json(['success' => false, 'message' => (string) __t('ecommerce.checkout.express_error')], 500);
        }

        self::json(['success' => true] + self::amounts($order));
    }

    /**
     * Il carrello a parte del foglio, dalla scheda prodotto o dal carrello;
     * senza un metodo rapido non se ne crea nessuno.
     *
     * @param array<string, mixed> $post
     */
    public static function begin(array $post): int
    {
        if (!ExpressCheckout::available()) {
            throw new RuntimeException((string) __t('ecommerce.checkout.express_error'));
        }

        if ((string) ($post['source'] ?? '') === 'product') {
            $quantity = (float) str_replace(',', '.', trim((string) ($post['quantity'] ?? '1')));

            return ExpressCart::start((int) ($post['product_id'] ?? 0), $quantity > 0 ? $quantity : 1.0);
        }

        $cart = CartSession::current(false);
        if ((array) ($cart['items'] ?? []) === []) {
            throw new RuntimeException((string) __t('ecommerce.checkout.errors.empty'));
        }

        return ExpressCart::fromCart((int) $cart['order']['id']);
    }

    /** Indirizzo parziale e tariffa del foglio: le tariffe della zona e il nuovo totale. */
    private static function shipping(): never
    {
        $cartId = self::expressCart();
        $address = json_decode((string) ($_POST['address'] ?? ''), true, 4);
        $post = ExpressAddress::partial(is_array($address) ? $address : [], (string) ($_POST['rate'] ?? ''), ExpressAddress::knownBilling(self::contact()));
        $shipping = Gestionale::feature('shipping');

        if ($shipping && ShippingZones::resolve($post['shipping_country'], $post['shipping_province']) === null) {
            self::json(['success' => false, 'error' => 'no_shipping'], 422);
        }

        try {
            $payload = CheckoutSummary::payload($cartId, $post, CartSession::user());
            $options = (array) $payload['shipping_methods']['options'];
            $selected = (int) $payload['shipping_methods']['selected'];
            // Stripe dà per scelta la prima tariffa: il totale deve già contarla.
            if ($shipping && $options !== [] && !in_array($selected, array_map('intval', array_column($options, 'method_id')), true)) {
                $selected = (int) $options[0]['method_id'];
                $payload = CheckoutSummary::payload($cartId, ['shipping_method_id' => (string) $selected] + $post, CartSession::user());
            }
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.express.shipping');
            self::json(['success' => false, 'error' => 'failed'], 500);
        }

        if (!$shipping) {
            $rates = [['id' => '0', 'displayName' => (string) __t('ecommerce.checkout.express_no_shipping'), 'amount' => 0]];
        } elseif ($options === []) {
            self::json(['success' => false, 'error' => 'no_shipping'], 422);
        } else {
            $rates = [];
            foreach ($options as $option) {
                $rate = [
                    'id' => (string) (int) $option['method_id'],
                    'displayName' => (string) $option['name'],
                    'amount' => !empty($option['free']) ? 0 : (int) round((float) ($option['price'] ?? 0) * 100),
                ];
                (int) $option['method_id'] === $selected ? array_unshift($rates, $rate) : $rates[] = $rate;
            }
        }

        self::json(['success' => true, 'rates' => $rates] + self::amounts($payload['order']));
    }

    /** I dati del wallet diventano l'ordine e l'intento, come «Paga» nel checkout. */
    private static function place(): never
    {
        $cartId = self::expressCart();
        // L'ospite paga solo il carrello rapido nato da un avvio verificato.
        if (!CartSession::authenticated() && !ExpressVerification::verified($cartId)) {
            self::json(['success' => false, 'error' => 'unverified', 'message' => (string) __t('ecommerce.checkout.express_unverified')], 403);
        }
        $method = Checkout::expressMethod();
        $type = (string) ($_POST['type'] ?? '');
        $wallet = json_decode((string) ($_POST['wallet'] ?? ''), true, 8);
        $user = CartSession::user();
        $contact = self::contact();
        $asked = CheckoutRules::askedConsents((int) ($user->id ?? 0));

        try {
            $post = CheckoutRules::post(ExpressAddress::post(is_array($wallet) ? $wallet : [], $contact, $method['id'], $type, $asked), $user);
            $placed = CheckoutPlacement::place($cartId, $post, $user, ['json' => true, 'expected_total' => $_POST['expected_total'] ?? null, 'express' => true]);
        } catch (CheckoutRejected $error) {
            if ($error->totalChanged()) {
                // Il prossimo foglio conta l'imposta su questa fatturazione: il totale torna.
                ExpressAddress::remember($post);
                self::json(['success' => false, 'error' => 'total_changed', 'message' => $error->getMessage()], 409);
            }

            self::json(['success' => false, 'error' => 'rejected', 'message' => implode("\n", $error->errors())], 422);
        } catch (UserError|RuntimeException $error) {
            self::json(['success' => false, 'error' => 'rejected', 'message' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.express.place', ['cart_id' => $cartId]);
            self::json(['success' => false, 'error' => 'rejected', 'message' => (string) __t('ecommerce.checkout.express_error')], 500);
        }

        try {
            $started = CheckoutPlacement::startOnline($placed['receipt']);
        } catch (Throwable $error) {
            // L'ordine c'è ed è in sospeso: il foglio si chiude e `cancel` lo annulla.
            Errors::internal($error, 'ecommerce.express.start_online', ['order_id' => $placed['receipt']['order_id']]);
            self::json(['success' => false, 'error' => 'start_failed', 'message' => (string) __t('ecommerce.checkout.express_error')], 502);
        }

        self::json(['success' => true] + $started);
    }

    /** Il foglio si è chiuso o il pagamento non è partito: l'ordine rapido si annulla e il carrello a parte si butta. */
    private static function cancel(): never
    {
        ExpressVerification::clear();

        try {
            self::dropPending();
            ExpressCart::discard();
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.express.cancel');
            ExpressCart::forget();
        }

        self::json(['success' => true]);
    }

    /** Solo l'ordine nato dal carrello a parte: quello del checkout normale non si tocca. */
    private static function dropPending(): void
    {
        if (ExpressCart::owns(OnlinePayment::sessionOrder())) {
            OnlinePayment::reopen();
        }
    }

    /** POST, CSRF e accesso: le stesse regole del checkout. */
    private static function guard(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            self::json(['success' => false], 404);
        }

        if (!AuthSession::verify($_POST['csrf_token'] ?? '')) {
            self::json(['success' => false, 'error' => (string) __t('ecommerce.checkout.summary_error')], 419);
        }

        if (!GuestCheckout::enabled() && !CartSession::authenticated()) {
            self::json(['success' => false, 'redirect' => \__r('ecommerce.auth.login') ?: '/'], 401);
        }
    }

    /** @return array<string, mixed>|null La scheda del cliente entrato. */
    private static function contact(): ?array
    {
        return CartSession::authenticated() ? (array) Contact::findById(CartSession::customerId()) : null;
    }

    private static function expressCart(): int
    {
        $cartId = ExpressCart::current();

        return $cartId > 0 ? $cartId : self::json(['success' => false, 'error' => 'no_cart'], 409);
    }

    /**
     * @param array<string, mixed> $order
     * @return array{amount: int, total: string, currency: string}
     */
    private static function amounts(array $order): array
    {
        return [
            'amount' => (int) round((float) $order['total'] * 100),
            'total' => (string) $order['total'],
            'currency' => strtolower((string) $order['currency']),
        ];
    }

    private static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
