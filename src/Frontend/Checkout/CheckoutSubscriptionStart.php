<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Throwable;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OnlineSubscriptions;

/**
 * Il «Paga» del checkout con servizi nel carrello. Con i prodotti l'ordine
 * nasce come sempre e la sua cifra entra nella prima fattura dell'abbonamento:
 * un solo pagamento, confermato nel Payment Element. Senza prodotti nasce solo
 * l'abbonamento. Il ritorno è la conferma dell'abbonamento.
 */
final class CheckoutSubscriptionStart
{
    /** L'abbonamento partito dal checkout e non ancora confermato. */
    public const STARTED = 'ecommerce_checkout_subscription';

    /**
     * @param array<string, mixed> $post Già passato da CheckoutRules::post.
     * @param array<string, mixed> $raw Il POST com'è: porta i totali visti dal cliente.
     * @return array{client_secret: string, intent: string, code: string, billing_details: array<string, mixed>}
     */
    public static function start(int $cartId, array $post, array $raw, ?object $user): array
    {
        $items = CheckoutSubscriptions::items();
        $products = CheckoutSubscriptions::hasProducts($cartId);

        // Senza prodotti la fatturazione si scrive sempre a mano: non c'è spedizione da copiare.
        if (!$products) {
            $post['same_as_shipping'] = '0';
        }

        $preview = CheckoutSummary::payload($cartId, $post, $user);
        $error = (string) ($preview['subscriptions']['error'] ?? '');

        if ($error !== '') {
            throw new CheckoutRejected([$error]);
        }

        if (!OnlinePayment::sameTotal($raw['expected_subscription'] ?? null, $preview['subscriptions']['first_charge'] ?? null)) {
            throw new CheckoutRejected([(string) __t('ecommerce.checkout.errors.total_changed')], $preview);
        }

        $customerId = CartSession::customerId();
        self::drop();

        if ($products) {
            return self::withOrder($cartId, $post, $raw, $user, $items, $customerId, (array) ($preview['items'] ?? []));
        }

        $billing = self::alone($preview, $post, $user);
        $started = self::begin($customerId, $items, $billing, 0);

        return $started + ['billing_details' => OnlinePayment::billingDetails($billing + ['email' => (string) ($user->email ?? '')])];
    }

    /**
     * L'ordine nasce con i controlli di sempre, poi l'abbonamento lo porta
     * nella prima fattura. Se Stripe non apre il pagamento l'ordine torna carrello.
     *
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $lines le righe dell'anteprima, per il tracciamento
     * @return array{client_secret: string, intent: string, code: string, billing_details: array<string, mixed>}
     */
    private static function withOrder(int $cartId, array $post, array $raw, ?object $user, array $items, int $customerId, array $lines): array
    {
        $placed = CheckoutPlacement::place($cartId, $post, $user, ['json' => true, 'expected_total' => $raw['expected_total'] ?? null]);
        $receipt = $placed['receipt'];
        $orderId = (int) $receipt['order_id'];
        $_SESSION[OnlinePayment::RECEIPT] = $receipt;
        OnlinePayment::remember($orderId);
        $order = (array) Order::findById($orderId);

        try {
            $started = self::begin($customerId, $items, CheckoutSubscriptions::billing(CheckoutRules::billing($post, $order), CheckoutSubscriptions::contact()), $orderId);
            // Per il `purchase` della conferma: i prodotti pagati nella stessa fattura.
            $_SESSION[self::STARTED]['tracking'] = [
                'value' => round((float) ($order['total'] ?? 0), 2),
                'items' => self::trackingItems($lines),
            ];
        } catch (Throwable $error) {
            try {
                OnlinePayment::reopen();
            } catch (Throwable $reopen) {
                Errors::internal($reopen, 'ecommerce.checkout.subscription_reopen', ['order_id' => $orderId]);
            }

            throw $error;
        }

        return $started + ['billing_details' => OnlinePayment::billingDetails($order)];
    }

    /**
     * Solo servizi: i controlli della fatturazione e dei consensi del checkout,
     * poi indirizzo e consensi si ricordano come dopo un ordine.
     *
     * @param array<string, mixed> $preview
     * @return array<string, string>
     */
    private static function alone(array $preview, array $post, ?object $user): array
    {
        // Nome e cognome li dà il riquadro dei contatti, come per il ritiro.
        foreach (['name', 'surname'] as $field) {
            if (trim((string) ($post['billing_'.$field] ?? '')) === '') {
                $post['billing_'.$field] = (string) ($post['shipping_'.$field] ?? '');
            }
        }

        $userId = (int) ($user->id ?? 0);
        $asked = CheckoutRules::askedConsents($userId);
        $billing = CheckoutRules::billing($post, $post);
        $missing = CheckoutRules::paymentErrors($post, $billing, $asked, true);

        if ($missing !== []) {
            throw new CheckoutRejected(array_map(static fn (string $key): string => (string) __t('ecommerce.checkout.errors.'.$key), $missing));
        }

        if (CheckoutRules::method($preview, (int) ($post['payment_method_id'] ?? 0)) === null) {
            throw new CheckoutRejected([(string) __t('ecommerce.checkout.errors.payment_method')]);
        }

        $customerId = CartSession::customerId();

        try {
            AccountAddress::rememberBilling($customerId, $billing, $post);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.billing');
        }

        if ($userId > 0 && $asked !== []) {
            try {
                consentService()->registerBaseConsents($userId, $post, ['required_document_types' => $asked, 'ui_surface' => 'checkout']);
            } catch (Throwable $error) {
                Errors::internal($error, 'ecommerce.checkout.consents');
            }
        }

        return $billing;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, string> $billing
     * @return array{client_secret: string, intent: string, code: string}
     */
    private static function begin(int $customerId, array $items, array $billing, int $orderId): array
    {
        $started = OnlineSubscriptions::startInPage($customerId, $items, $billing, CheckoutSubscriptions::coupon(), $orderId);

        if ($started['client_secret'] === '') {
            throw UserError::make('subscription.stripe_unavailable');
        }

        $_SESSION[self::STARTED] = [
            'code' => $started['code'],
            'order_id' => $orderId,
            'invoice_id' => $started['invoice_id'],
            'environment' => $started['environment'],
        ];

        return ['client_secret' => $started['client_secret'], 'intent' => $started['intent'], 'code' => $started['code']];
    }

    /**
     * Il tentativo di prima, se il cliente ripaga: col solo abbonamento la
     * fattura aperta cade qui; con l'ordine cade con lui (`dropPending`).
     */
    public static function drop(): void
    {
        $started = (array) ($_SESSION[self::STARTED] ?? []);
        unset($_SESSION[self::STARTED]);

        if ((int) ($started['order_id'] ?? 0) > 0 || (string) ($started['invoice_id'] ?? '') === '') {
            return;
        }

        try {
            OnlineSubscriptions::dropInPage((string) $started['invoice_id'], (string) ($started['environment'] ?? ''));
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.subscription_drop');
        }
    }

    /**
     * Gli item GA4 dei prodotti, come nel `begin_checkout` della pagina.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function trackingItems(array $items): array
    {
        $products = array_filter($items, static fn (array $item): bool => (string) ($item['type'] ?? 'product') === 'product');

        return array_values(array_map(static fn (array $item): array => [
            'item_id' => (string) ($item['sku'] ?? $item['product_id'] ?? ''),
            'item_name' => (string) ($item['name'] ?? ''),
            'price' => round((float) ($item['line_total'] ?? 0) / max((float) ($item['quantity'] ?? 1), 1), 2),
            'quantity' => (float) ($item['quantity'] ?? 1),
        ], $products));
    }

    /** Il tentativo in sessione, se è quello dell'abbonamento `$code`. */
    public static function started(string $code): ?array
    {
        $started = $_SESSION[self::STARTED] ?? null;

        return is_array($started) && (string) ($started['code'] ?? '') === $code ? $started : null;
    }

    public static function forget(): void
    {
        unset($_SESSION[self::STARTED]);
    }
}
