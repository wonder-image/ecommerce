<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use InvalidArgumentException;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

/**
 * Dati e rendering di una card prodotto.
 *
 * Accetta array o DTO/oggetti nel formato corrente del modulo. La
 * normalizzazione mantiene il componente indipendente dalla query con cui
 * nasce il catalogo.
 */
final class ProductCard
{
    /** @var array<string, mixed> */
    private array $product;

    public function __construct(array|object $product)
    {
        $this->product = self::normalize($product);
    }

    public static function make(array|object $product): self
    {
        return new self($product);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->product;
    }

    public function id(): int|string|null
    {
        return $this->product['id'];
    }

    public function render(string $view = 'grid'): string
    {
        $view = strtolower(trim($view));

        if (!in_array($view, ['grid', 'list'], true)) {
            throw new InvalidArgumentException("Vista card prodotto non valida: {$view}");
        }

        return View::component(
            Ecommerce::viewPath('components/catalog/product/card-'.$view.'.php'),
            ['product' => $this->product]
        );
    }

    /** @return array<string, mixed> */
    private static function normalize(array|object $product): array
    {
        $row = is_object($product) ? get_object_vars($product) : $product;
        $name = self::string($row['name'] ?? null);
        $image = self::string($row['image'] ?? null);
        $regular = self::moneyLabel($row, 'price_formatted', 'price');
        $sale = self::moneyLabel($row, 'sale_price_formatted', 'sale_price');

        $onSale = $sale !== '' && self::isSale($row);
        $price = $onSale ? $sale : $regular;
        $compareAtPrice = $onSale ? $regular : '';
        $badge = self::string($row['badge'] ?? null);

        if ($badge === '' && $onSale) {
            $badge = 'In offerta';
        }

        return [
            'id' => $row['id'] ?? null,
            'name' => $name,
            'description' => self::string($row['short_description'] ?? null),
            'url' => self::string($row['url'] ?? null),
            'image' => $image,
            'image_alt' => self::string($row['image_alt'] ?? null) ?: $name,
            'price' => $price,
            'compare_at_price' => $compareAtPrice,
            'badge' => $badge,
            'variants' => self::variants((array) ($row['variants'] ?? [])),
            'meta' => is_array($row['meta'] ?? null) ? $row['meta'] : [],
        ];
    }

    /** @return list<array{name:string,url:string,image:string,active:bool}> */
    private static function variants(array $variants): array
    {
        $normalized = [];

        foreach ($variants as $variant) {
            $variant = is_object($variant) ? get_object_vars($variant) : $variant;

            if (!is_array($variant)) {
                continue;
            }

            $normalized[] = [
                'name' => self::string($variant['name'] ?? null),
                'url' => self::string($variant['url'] ?? null),
                'image' => self::string($variant['image'] ?? null),
                'active' => (bool) ($variant['active'] ?? false),
            ];
        }

        return $normalized;
    }

    private static function moneyLabel(array $row, string $formattedKey, string $numericKey): string
    {
        $formatted = self::string($row[$formattedKey] ?? null);

        if ($formatted !== '') {
            return $formatted;
        }

        $value = $row[$numericKey] ?? null;

        if (!is_numeric($value)) {
            return self::string($value);
        }

        return number_format((float) $value, 2, ',', '.').' €';
    }

    private static function isSale(array $row): bool
    {
        if (isset($row['sale_price']) && is_numeric($row['sale_price'])) {
            $sale = (float) $row['sale_price'];
            $price = isset($row['price']) && is_numeric($row['price']) ? (float) $row['price'] : null;

            return $sale > 0 && ($price === null || $sale < $price);
        }

        return false;
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable
            ? trim((string) $value)
            : '';
    }
}
