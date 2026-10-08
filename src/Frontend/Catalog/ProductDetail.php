<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

/**
 * View-model della scheda prodotto.
 *
 * Riceve dati gia assemblati dal catalogo e mantiene allineati pagina,
 * schema.org e tracciamento: prezzi, disponibilita e identificativi nascono
 * dalla stessa struttura normalizzata.
 */
final class ProductDetail
{
    /** @var array<string, mixed> */
    private array $data;

    public function __construct(array $data)
    {
        $this->data = self::normalize($data);
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function seoDescription(int $limit = 140): string
    {
        $description = self::plain(
            $this->data['short_description'] ?: $this->data['description_html']
        );

        if ($description === '') {
            $description = $this->data['name'];
        }

        return mb_substr($description, 0, max(1, $limit));
    }

    /** @return array<string, mixed> */
    public function schemaOrg(): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $this->data['name'],
            'description' => $this->seoDescription(5000),
            'url' => $this->data['url'],
            'productID' => $this->data['item_id'],
        ];

        if ($this->data['images'] !== []) {
            $schema['image'] = array_values(array_column($this->data['images'], 'url'));
        }

        foreach (['sku', 'gtin', 'mpn'] as $key) {
            if ($this->data[$key] !== '') {
                $schema[$key] = $this->data[$key];
            }
        }

        if ($this->data['brand'] !== '') {
            $schema['brand'] = [
                '@type' => 'Brand',
                'name' => $this->data['brand'],
            ];
        }

        $offers = $this->data['offers'];

        if (count($offers) === 1) {
            $offer = $offers[0];
            $schema['offers'] = [
                '@type' => 'Offer',
                'url' => $this->data['url'],
                'priceCurrency' => $this->data['currency'],
                'price' => $offer['price'],
                'itemCondition' => 'https://schema.org/NewCondition',
            ];
            if ($offer['stock_managed']) {
                $schema['offers']['availability'] = $offer['available']
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock';
            }
        } elseif ($offers !== []) {
            $prices = array_column($offers, 'price');
            $schema['offers'] = [
                '@type' => 'AggregateOffer',
                'url' => $this->data['url'],
                'priceCurrency' => $this->data['currency'],
                'lowPrice' => min($prices),
                'highPrice' => max($prices),
                'offerCount' => count($offers),
            ];
            if ($this->data['stock_managed']) {
                $schema['offers']['availability'] = $this->data['available']
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock';
            }
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    public function viewProductEvent(): array
    {
        $price = (float) $this->data['price'];
        $regular = (float) $this->data['regular_price'];
        $discount = max(0.0, $regular - $price);
        $item = [
            'item_id' => $this->data['item_id'],
            'item_name' => $this->data['name'],
            'price' => $price,
            'discount' => $discount,
            'quantity' => 1,
        ];

        if ($this->data['brand'] !== '') {
            $item['item_brand'] = $this->data['brand'];
        }

        if ($this->data['category'] !== '') {
            $item['item_category'] = $this->data['category'];
        }

        if ($this->data['variant'] !== '') {
            $item['item_variant'] = $this->data['variant'];
        }

        return [
            'event' => 'view_product',
            'product' => [
                'id' => $this->data['item_id'],
                'name' => $this->data['name'],
                'category' => $this->data['category'],
                'price' => $price,
                'discount' => $discount,
                'quantity' => 1,
                'brand' => $this->data['brand'],
            ],
            'ecommerce' => [
                'currency' => $this->data['currency'],
                'value' => $price,
                'items' => [$item],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function normalize(array $row): array
    {
        $offers = [];

        foreach ((array) ($row['offers'] ?? []) as $offer) {
            $offer = is_object($offer) ? get_object_vars($offer) : $offer;

            if (!is_array($offer) || (int) ($offer['product_id'] ?? 0) <= 0) {
                continue;
            }

            $regular = max(0.0, (float) ($offer['regular_price'] ?? $offer['price'] ?? 0));
            $sale = max(0.0, (float) ($offer['sale_price'] ?? 0));
            $price = $sale > 0 && ($regular <= 0 || $sale < $regular) ? $sale : $regular;

            $stockManaged = (bool) ($offer['stock_managed'] ?? $row['stock_managed'] ?? false);
            $offers[] = [
                'product_id' => (int) $offer['product_id'],
                'item_id' => self::string($offer['item_id'] ?? $offer['sku'] ?? $offer['product_id']),
                'name' => self::string($offer['name'] ?? ''),
                'option_name' => self::string($offer['option_name'] ?? $offer['name'] ?? ''),
                'sku' => self::string($offer['sku'] ?? ''),
                'gtin' => self::string($offer['gtin'] ?? ''),
                'mpn' => self::string($offer['mpn'] ?? ''),
                'regular_price' => $regular,
                'sale_price' => $sale,
                'price' => $price,
                'stock_managed' => $stockManaged,
                'available' => !$stockManaged || (bool) ($offer['available'] ?? false),
                'attributes' => (array) ($offer['attributes'] ?? []),
            ];
        }

        $selected = null;
        foreach ($offers as $offer) {
            if ($offer['available']) {
                $selected = $offer;
                break;
            }
        }
        $selected ??= $offers[0] ?? [
            'product_id' => 0,
            'item_id' => self::string($row['id'] ?? ''),
            'name' => '',
            'option_name' => '',
            'sku' => '',
            'gtin' => '',
            'mpn' => '',
            'regular_price' => 0.0,
            'price' => 0.0,
            'available' => false,
            'stock_managed' => false,
            'attributes' => [],
        ];

        $images = [];
        foreach ((array) ($row['images'] ?? []) as $image) {
            $image = is_object($image) ? get_object_vars($image) : $image;
            $url = is_array($image) ? self::string($image['url'] ?? '') : self::string($image);

            if ($url !== '') {
                $images[] = [
                    'url' => $url,
                    'alt' => is_array($image) ? self::string($image['alt'] ?? '') : '',
                ];
            }
        }

        $currency = strtoupper(self::string($row['currency'] ?? 'EUR')) ?: 'EUR';

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => self::string($row['name'] ?? ''),
            'slug' => self::string($row['slug'] ?? ''),
            'url' => self::string($row['url'] ?? ''),
            'short_description' => self::string($row['short_description'] ?? ''),
            'description_html' => self::string($row['description_html'] ?? $row['description'] ?? ''),
            'brand' => self::string($row['brand'] ?? ''),
            'category' => self::string($row['category'] ?? ''),
            'category_url' => self::string($row['category_url'] ?? ''),
            'variant' => self::string($row['variant'] ?? ''),
            'variants' => array_values((array) ($row['variants'] ?? [])),
            'breadcrumbs' => array_values((array) ($row['breadcrumbs'] ?? [])),
            'images' => $images,
            'offers' => $offers,
            'option_groups' => array_values((array) ($row['option_groups'] ?? [])),
            'selected_product_id' => $selected['product_id'],
            'item_id' => $selected['item_id'],
            'sku' => $selected['sku'],
            'gtin' => $selected['gtin'],
            'mpn' => $selected['mpn'],
            'price' => $selected['price'],
            'regular_price' => $selected['regular_price'],
            'available' => $offers !== [] && in_array(true, array_column($offers, 'available'), true),
            'stock_managed' => $offers !== [] && in_array(true, array_column($offers, 'stock_managed'), true),
            'currency' => $currency,
            'details' => array_values((array) ($row['details'] ?? [])),
        ];
    }

    private static function plain(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(
            strip_tags((string) $value),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        )));
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable
            ? trim((string) $value)
            : '';
    }
}
