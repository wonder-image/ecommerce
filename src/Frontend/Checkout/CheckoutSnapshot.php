<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Throwable;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartSession;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Promotions\Campaigns;

/**
 * La prima anteprima della pagina checkout, ricordata in sessione.
 *
 * Una GET con il carrello uguale all'ultima anteprima riusa quella: niente
 * ricalcolo e niente FOR UPDATE. «Uguale» è l'impronta di fingerprint(). Il
 * POST che crea l'ordine non passa di qui e ricalcola sempre.
 */
final class CheckoutSnapshot
{
    private const SESSION = 'ecommerce_checkout_snapshot';

    /** Oltre questo l'anteprima si rifà comunque: copre listini, metodi e impostazioni che l'impronta non legge. */
    public const TTL = 600;

    /** Colonne che l'anteprima riscrive a ogni giro senza cambiare niente. */
    private const VOLATILE = ['last_activity_at', 'last_modified', 'creation'];

    /**
     * @param array{order: array<string, mixed>, items: list<array<string, mixed>>} $cart da CartSession::peek()
     * @param array<string, mixed> $post l'input dell'anteprima
     * @param (callable(array<string, mixed>): array<string, mixed>)|null $next l'input che la prossima GET darebbe a questo carrello; null se questa pagina non si riusa
     * @return array{summary: array<string, mixed>|null, cart: array<string, mixed>, reused: bool}
     */
    public static function page(array $cart, array $post, ?object $user, bool $keepCart, ?callable $next): array
    {
        $cartId = (int) ($cart['order']['id'] ?? 0);

        if ($next !== null) {
            $summary = self::reuse($cartId, self::fingerprint($cart, $post));
            if ($summary !== null) {
                return ['summary' => $summary, 'cart' => $cart, 'reused' => true];
            }
        }

        self::forget();

        try {
            $summary = CheckoutSummary::payload($cartId, $post, $user, $keepCart);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.index');

            // Come prima: la pagina parte dal carrello ricalcolato.
            return ['summary' => null, 'cart' => CartSession::current(false), 'reused' => false];
        }

        // L'anteprima ha appena ricalcolato e scritto: basta rileggere.
        $cart = Cart::contents($cartId) + ['removed' => []];

        if ($next !== null) {
            self::remember($cartId, self::fingerprint($cart, $next($cart)), $summary);
        }

        return ['summary' => $summary, 'cart' => $cart, 'reused' => false];
    }

    /**
     * L'impronta di quello che decide l'anteprima; vuota se non si riusa.
     *
     * Dentro: testata (indirizzi, consegna, pagamento, cliente, totali) e
     * righe come sono scritte, tranne le date di attività; per ogni prodotto
     * prezzo, saldo, stato, peso e modifica in anagrafica, e la campagna che
     * vale adesso; l'input dell'anteprima e la lingua. Con un coupon non si
     * riusa: la sua validità dipende dall'ora e la decide il gestionale.
     *
     * @param array{order?: array<string, mixed>, items?: list<array<string, mixed>>} $cart
     * @param array<string, mixed> $post
     */
    public static function fingerprint(array $cart, array $post): string
    {
        $order = (array) ($cart['order'] ?? []);
        $items = (array) ($cart['items'] ?? []);

        if ((int) ($order['id'] ?? 0) <= 0 || $items === [] || (string) ($order['stage'] ?? '') !== 'cart'
            || trim((string) ($order['coupon_code'] ?? '')) !== '' || (int) ($order['coupon_id'] ?? 0) > 0) {
            return '';
        }

        $now = date('Y-m-d H:i:s');
        $channel = (string) ($order['channel'] ?? 'online');
        $lines = [];
        $products = [];

        foreach ($items as $item) {
            foreach (array_merge([$item], (array) ($item['children'] ?? [])) as $line) {
                $line = (array) $line;
                unset($line['children']);
                $lines[] = array_diff_key($line, array_flip(self::VOLATILE));
                $productId = (int) ($line['product_id'] ?? 0);

                if ($productId > 0 && !isset($products[$productId])) {
                    $products[$productId] = self::product($productId, $now, $channel);
                }
            }
        }

        ksort($products);

        return hash('sha256', (string) json_encode(self::sorted([
            'order' => array_diff_key($order, array_flip(self::VOLATILE)),
            'items' => $lines,
            'products' => $products,
            'post' => $post,
            'lang' => function_exists('__l') ? (string) \__l() : '',
        ]), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * L'anteprima ricordata, se l'impronta è la stessa e non è scaduta. Gli
     * avvisi (coupon tolto, spedizione tolta) erano di quel giro: non si ripetono.
     *
     * @return array<string, mixed>|null
     */
    public static function reuse(int $cartId, string $fingerprint): ?array
    {
        $saved = $_SESSION[self::SESSION] ?? null;

        if ($fingerprint === '' || !is_array($saved)
            || (int) ($saved['cart_id'] ?? 0) !== $cartId
            || !hash_equals((string) ($saved['fingerprint'] ?? ''), $fingerprint)
            || time() - (int) ($saved['at'] ?? 0) >= self::TTL
            || !is_array($saved['summary'] ?? null)) {
            return null;
        }

        $summary = $saved['summary'];
        $summary['notices'] = [];
        if (isset($summary['coupon']) && is_array($summary['coupon'])) {
            $summary['coupon']['dropped'] = '';
        }

        return $summary;
    }

    /** @param array<string, mixed> $summary */
    public static function remember(int $cartId, string $fingerprint, array $summary): void
    {
        if ($fingerprint === '' || $cartId <= 0) {
            self::forget();

            return;
        }

        $_SESSION[self::SESSION] = ['cart_id' => $cartId, 'fingerprint' => $fingerprint, 'at' => time(), 'summary' => $summary];
    }

    public static function forget(): void
    {
        unset($_SESSION[self::SESSION]);
    }

    /** @return array<string, mixed> */
    private static function product(int $productId, string $now, string $channel): array
    {
        $row = Product::findById($productId);
        $row = is_array($row) ? $row : [];
        $campaign = $row === [] ? null : Campaigns::forProduct($productId, $now, $channel);

        return array_intersect_key($row, array_flip(['price', 'sale_price', 'active', 'deleted', 'weight', 'product_model_id', 'tax_category_id', 'last_modified'])) + [
            'campaign' => $campaign === null ? null : [(int) ($campaign['campaign_id'] ?? 0), (string) ($campaign['price'] ?? '')],
        ];
    }

    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_scalar($value) || $value === null ? $value : (string) json_encode($value);
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }
}
