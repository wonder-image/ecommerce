<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutSummary;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Locations\PickupPoints;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Shipping\Carriers;

/** Il dettaglio di un ordine come lo vede il cliente: tutto testo semplice, salvo gli indirizzi. */
final class AccountOrder
{
    /**
     * I dati della vista del dettaglio. Gli indirizzi (`delivery.html`, `billing`) arrivano già escapati da
     * `OrderSheet::address()`; ogni altro testo va escapato da chi lo stampa.
     *
     * @param array<string, mixed> $order una riga di `gst_orders`
     * @return array{number: string, date: string, info: list<array{label: string, value: string, icons: list<array{src: string, alt: string}>, href: string}>, items: list<array<string, mixed>>, summary: list<array{label: string, value: string}>, total: string, delivery: ?array{title: string, html: string}, billing: string}
     */
    public static function present(array $order): array
    {
        $currency = (string) (($order['currency'] ?? '') ?: 'EUR');
        $money = static fn (mixed $value): string => CartPresenter::money($value, $currency);
        $pickup = (string) ($order['fulfillment_type'] ?? '') === 'pickup' ? self::pickupPoint((int) ($order['location_id'] ?? 0)) : null;

        return [
            'number' => (string) ($order['order_number'] ?? ''),
            'date' => OrderSheet::date((string) ($order['ordered_at'] ?? '')),
            'info' => self::info($order, $pickup),
            'items' => self::items((int) $order['id'], $money),
            'summary' => self::summary($order, $money),
            'total' => $money($order['total'] ?? 0),
            'delivery' => self::delivery($order, $pickup),
            'billing' => OrderSheet::address($order, 'billing'),
        ];
    }

    /**
     * Stato, consegna, pagamento e, per ogni spedizione non annullata con il numero, tracking e corriere.
     * Se un metodo non c'è più la riga resta con un ripiego.
     *
     * @param array<string, mixed> $order
     * @param array{id: int, name: string, address: string}|null $pickup
     * @return list<array{label: string, value: string, icons: list<array{src: string, alt: string}>, href: string}>
     */
    private static function info(array $order, ?array $pickup): array
    {
        $row = static fn (string $label, string $value, array $icons = [], string $href = ''): array => ['label' => $label, 'value' => $value, 'icons' => $icons, 'href' => $href];
        $status = (string) ($order['status'] ?? '');
        $paymentStatus = (string) ($order['payment_status'] ?? '');
        $rows = [$row((string) __t('ecommerce.account.orders.status_label'), in_array($status, Order::LIVE_STATUSES, true) ? (string) __t('ecommerce.account.orders.status.'.$status) : '—')];

        $type = (string) ($order['fulfillment_type'] ?? '');
        if ($type === 'pickup') {
            $rows[] = $row((string) __t('ecommerce.checkout.delivery'), (string) __t('ecommerce.checkout.fulfillment_pickup').($pickup !== null ? ' · '.$pickup['name'] : ''));
        } elseif ($type === 'shipping') {
            $method = self::byId(ShippingMethod::class, (int) ($order['shipping_method_id'] ?? 0));
            $rows[] = $row((string) __t('ecommerce.checkout.delivery'), $method !== [] ? (string) $method['name'] : (string) __t('ecommerce.checkout.fulfillment_shipping'));
        }

        $payment = self::byId(PaymentMethod::class, (int) ($order['payment_method_id'] ?? 0));
        $rows[] = $payment !== []
            ? $row((string) __t('ecommerce.account.orders.payment'), (string) $payment['name'], CheckoutSummary::paymentIcons(PaymentMethod::iconsOf((string) ($payment['icons'] ?? ''))))
            : $row((string) __t('ecommerce.account.orders.payment'), '—');
        $rows[] = $row((string) __t('ecommerce.account.orders.payment_status_label'), in_array($paymentStatus, Order::PAYMENT_STATUSES, true) ? (string) __t('ecommerce.account.orders.payment_status.'.$paymentStatus) : '—');

        foreach (EcommerceAccountController::rows(Shipment::find(['order_id' => (int) $order['id'], 'type' => 'delivery'], null, 'id', 'ASC')) as $shipment) {
            $tracking = trim((string) ($shipment['tracking_number'] ?? ''));
            if ($tracking === '' || (string) ($shipment['status'] ?? '') === 'cancelled') {
                continue;
            }
            $carrier = self::byId(Carrier::class, (int) ($shipment['carrier_id'] ?? 0));
            $href = trim((string) ($shipment['tracking_url'] ?? '')) ?: Carriers::trackingUrl($carrier, $tracking);
            // Il modello del corriere e l'indirizzo salvato sono testo libero e `e()` non spegne `javascript:` o `data:`: si linka solo http(s), come il backend.
            $href = preg_match('#^https?://#i', $href) === 1 ? $href : '';
            $rows[] = $row((string) __t('ecommerce.account.orders.tracking'), $tracking);
            $rows[] = $row((string) __t('ecommerce.account.orders.carrier'), (string) ($carrier['name'] ?? '—'), [], $href);
        }

        return $rows;
    }

    /**
     * Le righe dell'ordine senza spedizione e commissione, con le scelte di un prodotto composto sotto il loro prodotto.
     *
     * @return list<array<string, mixed>>
     */
    private static function items(int $orderId, callable $money): array
    {
        $lines = CartPresenter::lines(EcommerceAccountController::rows(OrderItem::find(['order_id' => $orderId], null, 'position', 'ASC')));
        $line = static fn (array $item): array => [
            'name' => (string) ($item['name'] ?? ''),
            'image' => trim((string) ($item['image'] ?? '')),
            'details' => Customizations::lines($item),
            'quantity' => (string) ($item['type'] ?? '') === 'text' ? '' : CartPresenter::quantity($item['quantity'] ?? 1),
            'total' => (string) ($item['type'] ?? '') === 'text' ? '' : $money($item['line_total'] ?? 0),
            'choice' => (int) ($item['bundle_option_id'] ?? 0) > 0,
        ];
        $children = [];
        foreach ($lines as $item) {
            if ((int) ($item['parent_item_id'] ?? 0) > 0) {
                $children[(int) $item['parent_item_id']][] = $line($item);
            }
        }
        $items = [];
        foreach ($lines as $item) {
            if ((int) ($item['parent_item_id'] ?? 0) === 0) {
                $items[] = $line($item) + ['children' => $children[(int) $item['id']] ?? []];
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $order
     * @return list<array{label: string, value: string}>
     */
    private static function summary(array $order, callable $money): array
    {
        $rows = [['label' => (string) __t('ecommerce.cart.products_total'), 'value' => $money($order['products_total'] ?? 0)]];
        if ((float) ($order['discount_total'] ?? 0) > 0) {
            $coupon = trim((string) ($order['coupon_code'] ?? ''));
            $rows[] = ['label' => (string) __t('ecommerce.cart.discount').($coupon !== '' ? ' ('.$coupon.')' : ''), 'value' => '− '.$money($order['discount_total'])];
        }
        if ((string) ($order['fulfillment_type'] ?? '') === 'shipping') {
            $rows[] = ['label' => (string) __t('ecommerce.checkout.shipping_total'), 'value' => $money($order['shipping_total'] ?? 0)];
        }
        if ((float) ($order['fees_total'] ?? 0) > 0) {
            $rows[] = ['label' => (string) __t('ecommerce.checkout.fees_total'), 'value' => $money($order['fees_total'])];
        }

        return $rows;
    }

    /**
     * La scheda dell'indirizzo di consegna o di ritiro; nessuna se l'ordine non ha consegna.
     *
     * @param array<string, mixed> $order
     * @param array{id: int, name: string, address: string}|null $pickup
     * @return array{title: string, html: string}|null
     */
    private static function delivery(array $order, ?array $pickup): ?array
    {
        return match ((string) ($order['fulfillment_type'] ?? '')) {
            'pickup' => [
                'title' => (string) __t('ecommerce.account.orders.pickup_address'),
                'html' => $pickup !== null
                    ? e($pickup['name']).'<br>'.e($pickup['address'])
                    : e((string) __t('ecommerce.checkout.fulfillment_pickup')),
            ],
            'shipping' => ['title' => (string) __t('ecommerce.account.orders.delivery_address'), 'html' => OrderSheet::address($order, 'shipping')],
            default => null,
        };
    }

    /** @return array{id: int, name: string, address: string}|null */
    private static function pickupPoint(int $locationId): ?array
    {
        foreach (PickupPoints::all() as $point) {
            if ($point['id'] === $locationId) {
                return $point;
            }
        }

        return null;
    }

    /**
     * Una riga per id, o `[]` se non c'è più: `find()` salta già le eliminate.
     *
     * @param class-string $model
     * @return array<string, mixed>
     */
    private static function byId(string $model, int $id): array
    {
        $row = $id > 0 ? $model::find(['id' => $id], 1) : null;

        return is_array($row) && isset($row['id']) ? $row : [];
    }
}
