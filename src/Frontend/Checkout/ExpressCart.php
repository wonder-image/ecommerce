<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Sql\Transaction;

/**
 * Il carrello a parte della barra rapida: dalla scheda prodotto si compra
 * solo quel prodotto, dal carrello una copia del carrello. Il carrello vero
 * resta com'è finché il pagamento non torna riuscito; allora perde le righe
 * copiate, e solo quelle.
 */
final class ExpressCart
{
    public const SESSION = 'ecommerce_express_cart';

    /** Un carrello nuovo con il solo prodotto dato; niente sessione se non si compra. */
    public static function start(int $productId, float $quantity): int
    {
        $id = Transaction::run(static function () use ($productId, $quantity): int {
            $id = self::open();
            Cart::add($id, ['product_id' => $productId, 'quantity' => $quantity]);

            return $id;
        });
        $_SESSION[self::SESSION] = ['id' => $id, 'source_cart' => 0, 'source_items' => []];

        return $id;
    }

    /** Un carrello nuovo con righe, scelte e coupon del carrello vero, di cui ricorda le righe. */
    public static function fromCart(int $realId): int
    {
        $id = Transaction::run(static function () use ($realId): int {
            $id = self::open();
            Cart::restore($realId, $id);

            return $id;
        });
        $items = array_map(static fn (array $item): int => (int) $item['id'], Cart::contents($realId)['items']);
        $_SESSION[self::SESSION] = ['id' => $id, 'source_cart' => $realId, 'source_items' => $items];

        return $id;
    }

    public static function id(): int
    {
        return (int) ($_SESSION[self::SESSION]['id'] ?? 0);
    }

    public static function owns(int $orderId): bool
    {
        return $orderId > 0 && self::id() === $orderId;
    }

    /** Dimentica il carrello a parte; il carrello in sé resta abbandonato. */
    public static function forget(): void
    {
        unset($_SESSION[self::SESSION]);
    }

    /** Pagamento riuscito: dal carrello vero vanno via le righe copiate ancora presenti. */
    public static function settled(int $orderId): void
    {
        if (!self::owns($orderId)) {
            return;
        }

        $source = (int) ($_SESSION[self::SESSION]['source_cart'] ?? 0);
        $remembered = array_map('intval', (array) ($_SESSION[self::SESSION]['source_items'] ?? []));
        self::forget();

        if ($source <= 0 || (string) (Order::findById($source)['stage'] ?? '') !== 'cart') {
            return;
        }

        foreach (Cart::contents($source)['items'] as $item) {
            if (in_array((int) $item['id'], $remembered, true)) {
                Cart::remove($source, (int) $item['id']);
            }
        }
    }

    private static function open(): int
    {
        return (int) Cart::open(['customer_id' => 0, 'cart_token' => 'express-'.bin2hex(random_bytes(16))])['id'];
    }
}
