<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use RuntimeException;
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
        self::discard();
        $id = Transaction::run(static function () use ($productId, $quantity): int {
            $id = self::open();
            Cart::add($id, ['product_id' => $productId, 'quantity' => $quantity]);

            return $id;
        });
        $_SESSION[self::SESSION] = ['id' => $id, 'source_cart' => 0, 'source_items' => []];

        return $id;
    }

    /**
     * Un carrello nuovo con righe, scelte e coupon del carrello vero, di cui
     * ricorda righe e quantità. Se un articolo non c'è più o non basta, niente
     * copia: si paga solo ciò che il cliente vede nel carrello.
     */
    public static function fromCart(int $realId): int
    {
        self::discard();
        $real = Cart::contents($realId)['items'];
        $id = Transaction::run(static function () use ($realId, $real): int {
            $id = self::open();
            $restored = Cart::restore($realId, $id);

            if ((array) ($restored['removed'] ?? []) !== [] || self::quantities($real) !== self::quantities(Cart::contents($id)['items'])) {
                throw new RuntimeException((string) __t('ecommerce.checkout.express_changed'));
            }

            return $id;
        });
        $products = array_filter($real, static fn (array $item): bool => (string) ($item['type'] ?? '') === 'product');
        $items = array_column(array_map(static fn (array $item): array => [(int) $item['id'], (float) $item['quantity']], $products), 1, 0);
        $_SESSION[self::SESSION] = ['id' => $id, 'source_cart' => $realId, 'source_items' => $items];

        return $id;
    }

    public static function id(): int
    {
        return (int) ($_SESSION[self::SESSION]['id'] ?? 0);
    }

    /** Il carrello a parte finché è ancora un carrello; 0 se manca o se è già diventato un ordine. */
    public static function current(): int
    {
        $id = self::id();

        return $id > 0 && (string) (Order::findById($id)['stage'] ?? '') === 'cart' ? $id : 0;
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

    /** Dimentica il carrello a parte e lo butta, se non è già diventato un ordine. */
    public static function discard(): void
    {
        $id = self::current();
        self::forget();

        if ($id > 0) {
            sqlModify(Order::$table, ['deleted' => 'true'], 'id', $id);
        }
    }

    /**
     * Pagamento riuscito: dal carrello vero va via quanto copiato delle righe
     * ancora presenti; se intanto una riga è cresciuta, resta la differenza.
     */
    public static function settled(int $orderId): void
    {
        if (!self::owns($orderId)) {
            return;
        }

        $source = (int) ($_SESSION[self::SESSION]['source_cart'] ?? 0);
        $remembered = (array) ($_SESSION[self::SESSION]['source_items'] ?? []);
        self::forget();
        ExpressVerification::clear();
        unset($_SESSION[ExpressAddress::SESSION]);

        if ($source <= 0 || (string) (Order::findById($source)['stage'] ?? '') !== 'cart') {
            return;
        }

        foreach (Cart::contents($source)['items'] as $item) {
            $id = (int) $item['id'];
            if (!isset($remembered[$id])) {
                continue;
            }

            $left = round((float) $item['quantity'] - (float) $remembered[$id], 3);
            $left > 0 ? Cart::setQuantity($source, $id, $left) : Cart::remove($source, $id);
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<int, float> Quantità per prodotto, in ordine di prodotto.
     */
    private static function quantities(array $items): array
    {
        $totals = [];
        foreach ($items as $item) {
            // Come Cart::restore: la spedizione e le altre righe non sono articoli.
            if ((string) ($item['type'] ?? '') !== 'product') {
                continue;
            }
            $product = (int) ($item['product_id'] ?? 0);
            $totals[$product] = round(($totals[$product] ?? 0.0) + (float) $item['quantity'], 3);
        }
        ksort($totals);

        return $totals;
    }

    private static function open(): int
    {
        return (int) Cart::open(['customer_id' => 0, 'cart_token' => 'express-'.bin2hex(random_bytes(16))])['id'];
    }
}
