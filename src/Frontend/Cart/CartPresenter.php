<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Cart;

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductUrl;
use Wonder\Plugin\GeoPlugin\Geo;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Shipping\Shipping;

final class CartPresenter
{
    /** Senza decimali indicati usa quelli dei totali (`catalog.price_decimals.totals`). */
    public static function money(mixed $value, string $currency = 'EUR', ?int $decimals = null): string
    {
        $amount = number_format((float) $value, $decimals ?? self::decimals('totals'), ',', '.');

        return strtoupper($currency) === 'EUR' ? $amount.' €' : $amount.' '.strtoupper($currency);
    }

    /** I decimali dei prezzi per `product`, `card` o `totals`, da 0 a 4. */
    public static function decimals(string $context): int
    {
        return max(0, min(4, (int) Ecommerce::config('catalog.price_decimals.'.$context, 2)));
    }

    /**
     * La spedizione del carrello nel riepilogo: la più economica tra i metodi
     * che il checkout offrirebbe, per il paese dell'indirizzo del carrello;
     * senza indirizzo quello del visitatore (IP), poi l'Italia. Il totale la
     * comprende al posto di quella eventualmente già scelta. `null` con la
     * spedizione spenta, col ritiro o se nessun metodo copre il carrello.
     *
     * @return array{country: string, label: string, price: float, total: float}|null
     */
    public static function cartShipping(array $order): ?array
    {
        $cartId = (int) ($order['id'] ?? 0);

        if ($cartId <= 0 || !Gestionale::feature('shipping') || (string) ($order['fulfillment_type'] ?? 'shipping') === 'pickup') {
            return null;
        }

        $country = Shipping::country($order, Geo::country());
        $prices = array_map(static fn (array $option): float => $option['free'] ? 0.0 : (float) $option['price'], Shipping::options($cartId, $country));

        if ($prices === []) {
            return null;
        }

        $price = min($prices);

        return [
            'country' => $country,
            'label' => (string) __t('ecommerce.cart.shipping_to', ['country' => (string) ((function_exists('countries') ? countries() : [])[$country] ?? $country)]),
            'price' => $price,
            'total' => round((float) ($order['total'] ?? 0) - (float) ($order['shipping_total'] ?? 0) + $price, 2),
        ];
    }

    public static function quantity(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, ',', ''), '0'), ',');
    }

    /**
     * Le righe da mostrare come articoli: spedizione e commissione le scrive
     * l'anteprima del checkout e stanno solo nei totali.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function lines(array $items): array
    {
        return array_values(array_map(
            static function (array $item): array {
                [$title, $variant] = self::split((string) ($item['name'] ?? ''));

                return ['name' => self::name((string) ($item['name'] ?? '')), 'title' => $title, 'variant' => $variant] + $item;
            },
            array_filter(
                $items,
                static fn (array $item): bool => !in_array((string) ($item['type'] ?? 'product'), ['shipping', 'fee'], true)
            )
        ));
    }

    /**
     * La scheda del prodotto di una riga, con la variante quando il modello
     * ne mostra più d'una. Vuota se il modello non è più visibile online.
     *
     * @param array<string, mixed> $item
     */
    public static function url(array $item): string
    {
        $product = Product::findById((int) ($item['product_id'] ?? 0));
        if (!is_array($product) || empty($product['id'])) {
            return '';
        }

        $model = ProductModel::find([
            'id' => (int) ($product['product_model_id'] ?? 0),
            'visible' => 'true',
            'visible_online' => 'true',
            'deleted' => 'false',
        ], 1);
        if (!is_array($model) || trim((string) ($model['slug'] ?? '')) === '') {
            return '';
        }

        $variants = ProductVariant::find([
            'product_model_id' => (int) $model['id'],
            'visible' => 'true',
            'deleted' => 'false',
        ]);
        $variants = is_array($variants) && isset($variants['id']) ? [$variants] : array_values(array_filter((array) $variants, 'is_array'));
        $variant = [];
        foreach ($variants as $row) {
            if ((int) ($row['id'] ?? 0) === (int) ($product['product_variant_id'] ?? 0)) {
                $variant = $row;
            }
        }

        return ProductUrl::make((string) $model['slug'], ProductUrl::variantSlugFor($variant, count($variants)));
    }

    /**
     * Il nome per il cliente, senza i separatori dell'ordine: "Maglia — Blu / M"
     * diventa "Maglia Blu M". Una barra dentro una parola ("24/7") resta.
     */
    public static function name(string $name): string
    {
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('~\s+[—–/|]\s+~u', ' ', $name));
    }

    /**
     * Il nome diviso in modello e variante: "Maglia — Blu / M" dà
     * ["Maglia", "Blu · M"]. Senza trattino la variante è vuota.
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $name): array
    {
        $pieces = preg_split('~\s+[—–]\s+~u', html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 2) ?: [$name];
        $options = array_filter(array_map('trim', preg_split('~\s+[/|]\s+~u', (string) ($pieces[1] ?? '')) ?: []), 'strlen');

        return [self::name((string) $pieces[0]), implode(' · ', $options)];
    }

    /**
     * Le personalizzazioni della riga da leggere sotto il nome: «Incisione: Marco».
     *
     * @param array<string, mixed> $item
     * @return list<string>
     */
    public static function details(array $item): array
    {
        return Customizations::lines($item);
    }

    /**
     * Il prezzo pieno della riga, da barrare accanto a quello pagato: c'è solo
     * se è più alto (offerta, listino). Un multiprodotto paga più del suo
     * listino per i sovrapprezzi delle scelte: lì resta 0.
     *
     * @param array<string, mixed> $item
     */
    public static function regularTotal(array $item): float
    {
        $regular = round(((float) ($item['list_price'] ?? 0) + (float) ($item['customization_surcharge'] ?? 0)) * (float) ($item['quantity'] ?? 1), 2);

        return $regular > round((float) ($item['line_total'] ?? 0), 2) ? $regular : 0.0;
    }

    /**
     * I pezzi di un multiprodotto, da leggere sotto la riga madre: «2 × Pane».
     * Prezzo e controlli sono della madre.
     *
     * @param array<string, mixed> $item
     * @return list<string>
     */
    public static function parts(array $item): array
    {
        return array_values(array_map(
            static fn (array $child): string => self::quantity($child['quantity'] ?? 1).' × '.self::name((string) ($child['name'] ?? ''))
                .implode('', array_map(static fn (string $detail): string => ' · '.$detail, Customizations::lines($child))),
            array_filter((array) ($item['children'] ?? []), 'is_array')
        ));
    }

    /**
     * I pezzi nel carrello. Il carrello porta le righe figlie di un
     * multiprodotto in `children`; se arrivano piatte non contano lo stesso.
     *
     * @param list<array<string, mixed>> $items
     */
    public static function count(array $items): string
    {
        $quantity = array_sum(array_map(
            static fn (array $item): float => (string) ($item['type'] ?? '') === 'product'
                && (int) ($item['parent_item_id'] ?? 0) === 0
                ? (float) ($item['quantity'] ?? 0)
                : 0.0,
            $items
        ));

        return self::quantity($quantity);
    }

    /**
     * L'evento GTM `add_to_cart` della riga appena aggiunta, coi prezzi che il
     * server ha scritto (sovrapprezzi compresi). Di un multiprodotto la riga è
     * quella con le stesse opzioni scelte nei `children`; null se non c'è.
     *
     * @param array<string, mixed> $cart
     * @param list<int> $choices
     * @return array<string, mixed>|null
     */
    public static function addEvent(array $cart, int $productId, array $choices, float $quantity, string $currency = 'EUR'): ?array
    {
        $items = array_values((array) ($cart['items'] ?? []));
        $wanted = array_map('intval', $choices);
        sort($wanted);
        $line = null;

        foreach ($items as $item) {
            if ((int) ($item['product_id'] ?? 0) !== $productId || (int) ($item['parent_item_id'] ?? 0) !== 0) {
                continue;
            }

            $chosen = [];
            foreach ((array) ($item['children'] ?? []) as $child) {
                if ((int) ($child['bundle_option_id'] ?? 0) > 0) {
                    $chosen[] = (int) $child['bundle_option_id'];
                }
            }
            sort($chosen);

            if ($chosen === $wanted) {
                $line = $item;
                break;
            }
        }

        if ($line === null || $quantity <= 0) {
            return null;
        }

        $price = round((float) ($line['unit_price'] ?? 0), 2);
        $currency = strtoupper($currency) ?: 'EUR';

        return [
            'event' => 'add_to_cart',
            'ecommerce' => [
                'currency' => $currency,
                'value' => round($price * $quantity, 2),
                'items' => [[
                    'item_id' => (string) $productId,
                    'item_name' => self::name((string) ($line['name'] ?? '')),
                    'price' => $price,
                    'quantity' => $quantity,
                ]],
            ],
        ];
    }
}
