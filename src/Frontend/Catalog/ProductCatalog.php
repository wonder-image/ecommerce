<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Bundles;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;

/** Legge una scheda pubblica dalle tabelle catalogo del gestionale. */
final class ProductCatalog
{
    /**
     * La scheda di `/prodotto/{modello}/` o `/prodotto/{modello}/{variante}/`,
     * oppure dove rimandare. Tutti e due null: il modello non c'è, è un 404.
     *
     * @param array<mixed> $query la query della richiesta: sceglie l'opzione
     * @return array{detail: ?ProductDetail, redirect: ?string}
     */
    public static function resolve(string $modelSlug, string $variantSlug = '', array $query = []): array
    {
        $none = ['detail' => null, 'redirect' => null];
        $modelSlug = trim($modelSlug);
        $variantSlug = trim($variantSlug);

        if ($modelSlug === '') {
            return $none;
        }

        $model = ProductModel::find([
            'slug' => $modelSlug,
            'visible' => 'true',
            'visible_online' => 'true',
            'deleted' => 'false',
        ], 1);

        if (!is_array($model) || (int) ($model['id'] ?? 0) <= 0) {
            return $none;
        }

        $variants = self::rows(ProductVariant::find(
            ['product_model_id' => (int) $model['id'], 'visible' => 'true', 'deleted' => 'false'],
            null,
            'position',
            'ASC'
        ));
        $variant = $variants[0] ?? null;

        if ($variantSlug !== '') {
            $variant = null;

            foreach ($variants as $row) {
                if ((string) ($row['slug'] ?? '') === $variantSlug) {
                    $variant = $row;
                    break;
                }
            }

            // Variante sconosciuta o nascosta, o modello con una sola
            // variante: l'indirizzo giusto è quello del modello.
            if ($variant === null || count($variants) < 2) {
                return ['detail' => null, 'redirect' => ProductUrl::make($modelSlug, '', $query)];
            }
        }

        if (!is_array($variant) || (int) ($variant['id'] ?? 0) <= 0) {
            return $none;
        }

        return ['detail' => self::build($model, $variant, $variants, $query), 'redirect' => null];
    }

    /** Solo la scheda del modello, senza rimandi. */
    public static function find(string $slug): ?ProductDetail
    {
        return self::resolve($slug)['detail'];
    }

    /**
     * @param list<array<string, mixed>> $variants le varianti visibili, per posizione
     * @param array<mixed> $query sceglie l'opzione, se combacia
     */
    private static function build(array $model, array $variant, array $variants, array $query = []): ?ProductDetail
    {
        $modelId = (int) $model['id'];
        $variantId = (int) $variant['id'];
        $visible = count($variants);
        $modelSlug = (string) ($model['slug'] ?? '');
        $products = self::rows(Product::find([
            'product_model_id' => $modelId,
            'product_variant_id' => $variantId,
            'active' => 'true',
        ], null, 'position', 'ASC'));

        if ($products === []) {
            return null;
        }

        $allImages = self::rows(ProductImage::find(
            ['product_model_id' => $modelId],
            null,
            'position',
            'ASC'
        ));
        $images = [];

        foreach (ProductImages::for($allImages, $variantId) as $image) {
            $url = ProductImages::url($image);
            if ($url !== '') {
                $images[] = [
                    'url' => self::absolute($url),
                    'alt' => trim((string) ($image['alt'] ?? '')),
                ];
            }
        }

        $brand = (int) ($model['brand_id'] ?? 0) > 0
            ? Brand::find(['id' => (int) $model['brand_id'], 'visible' => 'true'], 1)
            : null;
        $categories = self::categories($modelId);
        $mainCategory = $categories !== [] ? $categories[array_key_last($categories)] : [];
        $variantName = trim((string) ($variant['name'] ?? ''));
        $modelName = trim((string) ($model['name'] ?? ''));
        $name = self::productName($modelName, $variantName, count($variants));
        $url = self::absolute(ProductUrl::make($modelSlug, ProductUrl::variantSlugFor($variant, $visible)));
        $offers = [];
        $stockManaged = self::stockManaged();
        $optionGroups = self::optionGroups($model, $products);

        // Un multiprodotto non ha giacenza propria: si vende finché i suoi
        // pezzi bastano per almeno una confezione.
        $bundle = ($model['type'] ?? 'simple') === 'bundle';
        $bundleQuantity = $bundle && $stockManaged ? Bundles::available($modelId) : 0.0;

        foreach ($products as $product) {
            $quantity = $bundle ? $bundleQuantity : self::onHand((int) $product['id']);

            $sku = trim((string) ($product['sku'] ?? ''));
            $offers[] = [
                'product_id' => (int) $product['id'],
                'item_id' => (string) $product['id'],
                'name' => trim((string) ($product['name'] ?? '')),
                'option_name' => self::optionName((string) ($product['name'] ?? ''), $modelName, $variantName),
                'sku' => $sku,
                'gtin' => trim((string) ($product['ean'] ?? '')),
                'mpn' => trim((string) ($product['mpn'] ?? '')),
                'regular_price' => (float) ($product['price'] ?? 0),
                'sale_price' => (float) ($product['sale_price'] ?? 0),
                'stock_managed' => $stockManaged,
                'available' => !$stockManaged || $quantity > 0 || Stock::allowsBackorder($product),
                'attributes' => self::productOptions((int) $product['id'], $optionGroups),
            ];
        }

        $variantCards = [];
        foreach ($variants as $row) {
            $variantImages = ProductImages::for($allImages, (int) ($row['id'] ?? 0));
            $variantImage = $variantImages[0] ?? [];
            $variantCards[] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'url' => ProductUrl::make($modelSlug, ProductUrl::variantSlugFor($row, $visible)),
                'image' => is_array($variantImage) ? self::absolute(ProductImages::url($variantImage)) : '',
                'active' => (int) ($row['id'] ?? 0) === $variantId,
            ];
        }

        $breadcrumbs = [[
            'url' => self::absolute('/'),
            'name' => (string) __t('ecommerce.catalog.breadcrumb_home'),
        ], [
            'url' => self::absolute((string) Ecommerce::config('catalog.index_url', '/prodotti/')),
            'name' => (string) __t('ecommerce.catalog.breadcrumb_products'),
        ]];

        $categoryPath = [];
        foreach ($categories as $category) {
            $categoryPath[] = (string) ($category['slug'] ?? '');
            $breadcrumbs[] = [
                'url' => self::absolute(self::categoryUrl($categoryPath)),
                'name' => self::breadcrumbName((string) ($category['name'] ?? '')),
            ];
        }

        $breadcrumbs[] = ['url' => $url, 'name' => self::breadcrumbName($name)];

        return ProductDetail::make([
            'id' => $modelId,
            'name' => $name,
            'slug' => ProductUrl::variantSlugFor($variant, $visible) ?: $modelSlug,
            'url' => $url,
            'short_description' => (string) ($model['short_description'] ?? ''),
            'description_html' => (string) ($model['description'] ?? ''),
            'brand' => is_array($brand) ? (string) ($brand['name'] ?? '') : '',
            'category' => (string) ($mainCategory['name'] ?? ''),
            'category_url' => $categoryPath !== []
                ? self::categoryUrl($categoryPath)
                : '',
            'variant' => count($variants) > 1 ? $variantName : '',
            'variants' => $variantCards,
            'breadcrumbs' => $breadcrumbs,
            'images' => $images,
            'offers' => $offers,
            'option_groups' => $optionGroups,
            'bundle' => $bundle ? self::bundle($modelId) : [],
            'preferred_product_id' => OptionQuery::match($optionGroups, $offers, $query) ?? 0,
            'stock_managed' => $stockManaged,
            'currency' => (string) Ecommerce::config('catalog.currency', 'EUR'),
            'details' => self::details($model),
        ]);
    }

    /**
     * Cosa contiene il multiprodotto e cosa si sceglie: i componenti contano
     * solo fuori da `choice`, i gruppi solo fuori da `fixed`, come in
     * {@see Bundles::resolve()}.
     *
     * @return array<string, mixed>
     */
    private static function bundle(int $modelId): array
    {
        $bundle = Bundles::forModel($modelId);
        $mode = (string) $bundle['mode'];

        $withImage = static fn (array $row): array => $row + ['image' => self::productImage((int) ($row['product_id'] ?? 0))];
        $groups = $mode === 'fixed' ? [] : $bundle['groups'];
        foreach ($groups as $i => $group) {
            $groups[$i]['options'] = array_map($withImage, (array) ($group['options'] ?? []));
        }

        return [
            'mode' => $mode,
            'components' => $mode === 'choice' ? [] : array_map($withImage, $bundle['components']),
            'groups' => $groups,
            'value' => $bundle['show_value'] ? (float) Bundles::componentsValue($modelId) : null,
        ];
    }

    /**
     * La prima foto di un articolo: la sua, se ne ha, se no del suo colore o
     * del modello ({@see ProductImages::for()}). Vuota se non ce n'è.
     */
    private static function productImage(int $productId): string
    {
        $product = $productId > 0 ? Product::find(['id' => $productId], 1) : null;
        if (!is_array($product)) {
            return '';
        }

        $images = ProductImages::for(
            self::rows(ProductImage::find(['product_model_id' => (int) ($product['product_model_id'] ?? 0)], null, 'position', 'ASC')),
            (int) ($product['product_variant_id'] ?? 0),
            $productId
        );

        return isset($images[0]) && is_array($images[0]) ? self::absolute(ProductImages::url($images[0])) : '';
    }

    /** @return list<array<string, mixed>> */
    private static function optionGroups(array $model, array $products): array
    {
        $ids = array_values(array_filter(array_map('intval', explode('-', (string) ($model['axes_order'] ?? '')))));
        $groups = [];

        foreach ($ids as $attributeId) {
            $attribute = Attribute::find(['id' => $attributeId, 'level' => 'product'], 1);
            if (!is_array($attribute)) continue;

            $values = [];
            foreach ($products as $product) {
                $link = ProductAttributes::read('product', (int) $product['id'])[$attributeId] ?? null;
                if (!is_array($link)) continue;

                $valueId = (int) ($link['attribute_value_id'] ?? 0);
                $value = $valueId > 0 ? AttributeValue::find(['id' => $valueId], 1) : [];
                $label = Attributes::format($attribute, $link, is_array($value) ? [$valueId => $value] : []);
                if ($label === '') continue;

                $key = $valueId > 0 ? (string) $valueId : sha1($label);
                $visual = is_array($value)
                    ? Attributes::valueVisual((string) ($attribute['type'] ?? ''), $value, AttributeValue::imageUrl($value))
                    : [];
                $values[$key] = ['id' => $key, 'label' => $label, 'slug' => OptionQuery::slug($label)] + $visual;
            }

            if ($values === []) continue;
            $slug = trim((string) ($attribute['slug'] ?? ''));
            $override = (string) Ecommerce::config('product.option_selector.attributes.'.$slug, '');
            $default = (string) Ecommerce::config('product.option_selector.default', 'auto');
            // Un attributo di tipo «Bottone» chiede il gruppo di bottoni a scelta singola.
            $fallback = (string) ($attribute['type'] ?? '') === 'button' ? 'group' : $default;
            $selector = in_array($override, ['buttons', 'select', 'group'], true) ? $override : $fallback;
            if ($selector === 'auto') {
                $hasVisuals = array_filter($values, static fn (array $value): bool => isset($value['image']) || isset($value['color'])) !== [];
                $selector = $hasVisuals || count($values) <= 4 ? 'buttons' : 'select';
            }

            $groups[] = [
                'id' => $attributeId,
                'slug' => $slug,
                'name' => trim((string) ($attribute['name'] ?? '')),
                'selector' => $selector,
                'values' => array_values($values),
            ];
        }

        return $groups;
    }

    /** @param list<array<string, mixed>> $groups @return array<string, string> */
    private static function productOptions(int $productId, array $groups): array
    {
        $links = ProductAttributes::read('product', $productId);
        $options = [];

        foreach ($groups as $group) {
            $attributeId = (int) ($group['id'] ?? 0);
            $link = $links[$attributeId] ?? null;
            if (!is_array($link)) continue;
            $valueId = (int) ($link['attribute_value_id'] ?? 0);
            if ($valueId > 0) {
                $options[(string) $attributeId] = (string) $valueId;
                continue;
            }
            $attribute = Attribute::find(['id' => $attributeId], 1);
            if (is_array($attribute)) {
                $options[(string) $attributeId] = sha1(Attributes::format($attribute, $link));
            }
        }

        return $options;
    }

    /**
     * Si può comprare adesso? Con le giacenze attive serve merce o la vendita
     * in arretrato; un multiprodotto si vende finché i pezzi bastano.
     *
     * @param array<string, mixed> $product la riga di `gst_products`
     */
    public static function purchasable(array $product, bool $bundle = false): bool
    {
        if (!self::stockManaged()) return true;

        $quantity = $bundle
            ? Bundles::available((int) ($product['product_model_id'] ?? 0))
            : self::onHand((int) ($product['id'] ?? 0));

        return $quantity > 0 || Stock::allowsBackorder($product);
    }

    private static function stockManaged(): bool
    {
        static $managed = null;
        if ($managed !== null) return $managed;

        $location = Location::find(['has_stock' => 'true', 'active' => 'true', 'deleted' => 'false'], 1);
        return $managed = is_array($location) && $location !== [];
    }

    /** @return list<array<string, mixed>> */
    private static function categories(int $modelId): array
    {
        $links = self::rows(ProductModelCategory::find(
            ['product_model_id' => $modelId],
            null,
            'position',
            'ASC'
        ));
        $main = null;

        foreach ($links as $link) {
            if (($link['is_main'] ?? 'false') === 'true') {
                $main = Category::findById((int) ($link['category_id'] ?? 0));
                break;
            }
        }

        if (!is_array($main) && isset($links[0])) {
            $main = Category::findById((int) ($links[0]['category_id'] ?? 0));
        }

        $path = [];
        $seen = [];

        while (is_array($main) && (int) ($main['id'] ?? 0) > 0 && !isset($seen[(int) $main['id']])) {
            $seen[(int) $main['id']] = true;
            array_unshift($path, $main);
            $parentId = (int) ($main['parent_id'] ?? 0);
            $main = $parentId > 0 ? Category::find(['id' => $parentId, 'visible' => 'true'], 1) : null;
        }

        return $path;
    }

    /** @return list<array{label:string,value:string}> */
    private static function details(array $model): array
    {
        $details = [];

        foreach ([
            'weight' => 'ecommerce.catalog.details.weight',
            'length' => 'ecommerce.catalog.details.length',
            'width' => 'ecommerce.catalog.details.width',
            'height' => 'ecommerce.catalog.details.height',
        ] as $key => $translation) {
            $value = trim((string) ($model[$key] ?? ''));
            if ($value !== '' && (float) $value > 0) {
                $details[] = ['label' => (string) __t($translation), 'value' => $value];
            }
        }

        return $details;
    }

    private static function productName(string $model, string $variant, int $variantCount): string
    {
        if ($variantCount <= 1 || $variant === '' || mb_strtolower($variant) === mb_strtolower($model)) {
            return $model;
        }

        if ($model === '' || str_starts_with(mb_strtolower($variant), mb_strtolower($model))) return $variant;

        return trim($model.' '.$variant);
    }

    private static function optionName(string $name, string $model, string $variant): string
    {
        $option = trim((string) preg_replace(
            array_values(array_filter([
                $model !== '' ? '/'.preg_quote($model, '/').'/iu' : null,
                $variant !== '' ? '/'.preg_quote($variant, '/').'/iu' : null,
            ])),
            '',
            $name
        ));
        $option = trim($option, " \t\n\r\0\x0B-–—|/");

        return $option !== '' ? $option : trim($name);
    }

    /** @param string|list<string> $slug */
    private static function categoryUrl(string|array $slug): string
    {
        $path = is_array($slug) ? $slug : [$slug];
        $path = array_values(array_filter(array_map('trim', $path)));

        return rtrim((string) Ecommerce::config('catalog.category_url', '/prodotti/categoria/'), '/')
            .'/'.implode('/', array_map('rawurlencode', $path)).'/';
    }

    private static function absolute(string $url): string
    {
        if ($url === '' || preg_match('~^https?://~i', $url)) {
            return $url;
        }

        $site = rtrim((string) ($GLOBALS['PATH']->site ?? (defined('APP_URL') ? APP_URL : '')), '/');

        return $site !== '' ? $site.'/'.ltrim($url, '/') : $url;
    }

    private static function breadcrumbName(string $name): string
    {
        return trim(str_replace(['\\', '"'], '', $name));
    }

    /** @return list<array<string, mixed>> */
    /**
     * I pezzi che si possono vendere online: quelli della sede da cui escono
     * gli ordini, senza i lotti scaduti (gestionale G3, spec §2.2). Con più
     * sedi la merce dei negozi non conta.
     *
     * Il ripiego somma tutte le righe di giacenza, come prima: serve finché
     * il gestionale installato non ha `Levels::forSale()`.
     */
    private static function onHand(int $productId): float
    {
        if (method_exists(Levels::class, 'forSale') && method_exists(Locations::class, 'forSale')) {
            $level = Levels::forSale([$productId], Locations::forSale())[$productId] ?? [];

            return round((float) ($level['quantity'] ?? 0) - (float) ($level['expired'] ?? 0), 3);
        }

        $quantity = 0.0;

        foreach (self::rows(StockRow::find(['product_id' => $productId])) as $stock) {
            $quantity += (float) ($stock['quantity'] ?? 0);
        }

        return $quantity;
    }

    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return array_is_list($rows) ? array_values(array_filter($rows, 'is_array')) : [$rows];
    }
}
