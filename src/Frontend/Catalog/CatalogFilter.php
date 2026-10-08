<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Sql\Query;

/** Traduce URL e query string in un filtro sicuro per il catalogo pubblico. */
final class CatalogFilter
{
    private array $conditions = [];
    private array $breadcrumbs = [];
    private array $attributes = [];
    private bool $valid = true;
    private string $titleKey = 'ecommerce.catalog.listing.all.title';
    private string $descriptionKey = 'ecommerce.catalog.listing.all.description';
    private string $routeTitle = '';
    private string $routeDescription = '';
    private string $canonical = '/prodotti/';
    private string $order = 'position, name';
    private string $direction = 'ASC';
    private string $priceContextWhere = '';
    private ?float $priceMin = null;
    private ?float $priceMax = null;
    private bool $filtered = false;

    private function __construct(private readonly array $query)
    {
        $this->conditions[] = "`visible` = 'true'";
        $this->conditions[] = "`visible_online` = 'true'";
        $this->conditions[] = "`deleted` = 'false'";
    }

    public static function fromRequest(string $action, array $parameters, array $query): self
    {
        $filter = new self($query);
        $filter->applyRoute($action, $parameters);

        if ($filter->valid) {
            $routeConditions = count($filter->conditions);
            $filter->applyBrand($query['marca'] ?? null);
            $filter->applySearch($action === 'search' ? ($query['q'] ?? null) : null);
            $filter->applyAttributes();
            // Il massimo disponibile dipende dal catalogo corrente, non
            // dall'intervallo prezzo già selezionato dall'utente.
            $filter->priceContextWhere = $filter->where();
            $filter->applyPrice(
                $query['prezzo_da'] ?? null,
                $query['prezzo_a'] ?? null,
                $query['prezzo'] ?? null
            );
            $filter->applyOrder($query['ordina'] ?? null);
            $filter->filtered = count($filter->conditions) > $routeConditions;
        }

        return $filter;
    }

    public function valid(): bool { return $this->valid; }
    // Il core riconosce la parola WHERE anche dentro le sottoquery: il prefisso
    // esplicito evita che una EXISTS faccia perdere il WHERE esterno.
    public function where(): string { return 'WHERE '.implode(' AND ', $this->conditions); }
    public function order(): string { return $this->order; }
    public function direction(): string { return $this->direction; }
    public function title(): string { return $this->filtered ? (string) __t('ecommerce.catalog.listing.filtered.title') : ($this->routeTitle !== '' ? $this->routeTitle : (string) __t($this->titleKey)); }
    public function description(): string { return $this->filtered ? (string) __t('ecommerce.catalog.listing.filtered.description') : ($this->routeDescription !== '' ? $this->routeDescription : (string) __t($this->descriptionKey)); }
    public function hasActiveFilters(): bool { return $this->filtered; }
    public function canonical(): string { return $this->canonical; }
    public function query(): array { return $this->query; }
    public function attributes(): array { return $this->attributes; }
    public function priceContextWhere(): string { return $this->priceContextWhere !== '' ? $this->priceContextWhere : $this->where(); }
    /** @return array{min:?float,max:?float} */
    public function selectedPriceRange(): array { return ['min' => $this->priceMin, 'max' => $this->priceMax]; }

    /** @return list<array{url:string,name:string}> */
    public function breadcrumbs(): array
    {
        return $this->breadcrumbs;
    }

    private function applyRoute(string $action, array $parameters): void
    {
        if ($action === 'category') {
            $this->applyCategory($parameters);
            return;
        }

        if ($action === 'offers') {
            $this->titleKey = 'ecommerce.catalog.listing.offers.title';
            $this->descriptionKey = 'ecommerce.catalog.listing.offers.description';
            $this->canonical = '/offerte/';
            $this->breadcrumbs[] = ['url' => $this->canonical, 'name' => (string) __t($this->titleKey)];
            $this->conditions[] = "EXISTS (SELECT 1 FROM `gst_products` p WHERE p.`product_model_id` = `gst_product_models`.`id` AND p.`active` = 'true' AND p.`deleted` = 'false' AND p.`sale_price` > 0 AND p.`sale_price` < p.`price`)";
            return;
        }

        if ($action === 'new') {
            $days = max(1, (int) Ecommerce::config('catalog.new_days', 30));
            $this->titleKey = 'ecommerce.catalog.listing.new.title';
            $this->descriptionKey = 'ecommerce.catalog.listing.new.description';
            $this->canonical = '/novita/';
            $this->breadcrumbs[] = ['url' => $this->canonical, 'name' => (string) __t($this->titleKey)];
            $this->conditions[] = "`creation` >= DATE_SUB(NOW(), INTERVAL {$days} DAY)";
            return;
        }

        if ($action === 'collection') {
            $slug = (string) Ecommerce::config('catalog.collection_tag', 'collezione');
            $tag = Tag::find(['slug' => $slug, 'visible' => 'true', 'deleted' => 'false'], 1);
            $this->titleKey = 'ecommerce.catalog.listing.collection.title';
            $this->descriptionKey = 'ecommerce.catalog.listing.collection.description';
            $this->canonical = '/collezione/';
            $this->breadcrumbs[] = ['url' => $this->canonical, 'name' => (string) __t($this->titleKey)];
            if (is_array($tag) && (int) ($tag['id'] ?? 0) > 0) {
                $id = (int) $tag['id'];
                $this->conditions[] = "EXISTS (SELECT 1 FROM `gst_product_model_tags` mt WHERE mt.`product_model_id` = `gst_product_models`.`id` AND mt.`tag_id` = {$id} AND mt.`deleted` = 'false')";
            } else {
                $this->conditions[] = '1 = 0';
            }
            return;
        }

        if ($action === 'brand') {
            $slug = trim((string) ($parameters['marca'] ?? ''));
            $brand = Brand::find(['slug' => $slug, 'visible' => 'true', 'deleted' => 'false'], 1);
            $this->valid = is_array($brand) && (int) ($brand['id'] ?? 0) > 0;
            if ($this->valid) {
                $this->titleKey = '';
                $this->routeTitle = (string) $brand['name'];
                $this->descriptionKey = 'ecommerce.catalog.listing.brand.description';
                $this->canonical = '/marchi/'.rawurlencode($slug).'/';
                $this->conditions[] = '`brand_id` = '.(int) $brand['id'];
                $this->breadcrumbs[] = ['url' => $this->canonical, 'name' => self::clean((string) $brand['name'])];
            }
            return;
        }

        if ($action === 'search') {
            $this->titleKey = 'ecommerce.catalog.listing.search.title';
            $this->descriptionKey = 'ecommerce.catalog.listing.search.description';
            $this->canonical = '/cerca/';
            $this->breadcrumbs[] = ['url' => $this->canonical, 'name' => (string) __t($this->titleKey)];
        }
    }

    private function applyCategory(array $parameters): void
    {
        $slugs = [];
        foreach ($parameters as $key => $value) {
            if (str_starts_with((string) $key, 'category_') && trim((string) $value) !== '') {
                $slugs[(int) substr((string) $key, 9)] = trim((string) $value);
            }
        }
        ksort($slugs);

        $parentId = 0;
        $category = null;
        $path = [];
        foreach ($slugs as $slug) {
            $category = Category::find(['slug' => $slug, 'visible' => 'true', 'deleted' => 'false'], 1);
            if (!is_array($category) || (int) ($category['id'] ?? 0) <= 0 || (int) ($category['parent_id'] ?? 0) !== $parentId) {
                $this->valid = false;
                return;
            }
            $parentId = (int) $category['id'];
            $path[] = (string) $category['slug'];
            $this->breadcrumbs[] = [
                'url' => '/prodotti/'.implode('/', array_map('rawurlencode', $path)).'/',
                'name' => self::clean((string) $category['name']),
            ];
        }

        if (!is_array($category) || (int) ($category['id'] ?? 0) <= 0) {
            $this->valid = false;
            return;
        }

        $ids = $this->descendantIds((int) $category['id']);
        $this->conditions[] = 'EXISTS (SELECT 1 FROM `gst_product_model_categories` mc WHERE mc.`product_model_id` = `gst_product_models`.`id` AND mc.`category_id` IN ('.implode(',', $ids).") AND mc.`deleted` = 'false')";
        $this->titleKey = '';
        $this->descriptionKey = '';
        $this->routeTitle = (string) ($category['name'] ?? '');
        $this->routeDescription = trim(strip_tags((string) ($category['description'] ?? '')));
        if ($this->routeDescription === '') {
            $this->routeDescription = (string) __t('ecommerce.catalog.listing.category.description');
        }
        $this->canonical = '/prodotti/'.implode('/', array_map('rawurlencode', $path)).'/';
    }

    private function applyBrand(mixed $value): void
    {
        $slugs = self::values($value);
        if ($slugs === [] || str_contains($this->where(), '`brand_id` =')) {
            return;
        }

        $ids = [];
        foreach ($slugs as $slug) {
            $brand = Brand::find(['slug' => $slug, 'visible' => 'true', 'deleted' => 'false'], 1);
            if (is_array($brand) && (int) ($brand['id'] ?? 0) > 0) {
                $ids[] = (int) $brand['id'];
            }
        }

        if ($ids !== []) {
            $this->conditions[] = '`brand_id` IN ('.implode(',', array_unique($ids)).')';
        }
    }

    private function applyPrice(mixed $from, mixed $to, mixed $legacy): void
    {
        $hasRangeFields = trim((string) $from) !== '' || trim((string) $to) !== '';
        if ($hasRangeFields) {
            $min = self::amount($from);
            $max = self::amount($to);
            if ($min !== null && $max !== null && $min > $max) {
                [$min, $max] = [$max, $min];
            }
        } else {
            [$min, $max] = self::range((string) $legacy);
        }

        $this->priceMin = $min;
        $this->priceMax = $max;
        if ($min === null && $max === null) return;
        $price = '(CASE WHEN p.`sale_price` > 0 AND p.`sale_price` < p.`price` THEN p.`sale_price` ELSE p.`price` END)';
        $range = [];
        if ($min !== null) $range[] = $price.' >= '.self::decimal($min);
        if ($max !== null) $range[] = $price.' <= '.self::decimal($max);
        $this->conditions[] = "EXISTS (SELECT 1 FROM `gst_products` p WHERE p.`product_model_id` = `gst_product_models`.`id` AND p.`active` = 'true' AND p.`deleted` = 'false' AND ".implode(' AND ', $range).')';
    }

    private function applySearch(mixed $value): void
    {
        $term = trim((string) $value);
        if ($term === '') return;
        $like = self::escape($term);
        $this->conditions[] = "(`name` LIKE '%{$like}%' OR `short_description` LIKE '%{$like}%' OR EXISTS (SELECT 1 FROM `gst_product_variants` v WHERE v.`product_model_id` = `gst_product_models`.`id` AND v.`visible` = 'true' AND v.`deleted` = 'false' AND (v.`name` LIKE '%{$like}%' OR CONCAT(`gst_product_models`.`name`, ' ', v.`name`) LIKE '%{$like}%') AND EXISTS (SELECT 1 FROM `gst_products` vp WHERE vp.`product_variant_id` = v.`id` AND vp.`active` = 'true' AND vp.`deleted` = 'false')) OR EXISTS (SELECT 1 FROM `gst_products` p WHERE p.`product_model_id` = `gst_product_models`.`id` AND p.`active` = 'true' AND p.`deleted` = 'false' AND (p.`name` LIKE '%{$like}%' OR p.`sku` LIKE '%{$like}%' OR p.`ean` LIKE '%{$like}%' OR p.`mpn` LIKE '%{$like}%')))";
    }

    private function applyAttributes(): void
    {
        $rows = self::rows(Attribute::find(['is_filterable' => 'true', 'is_visible' => 'true', 'deleted' => 'false'], null, 'position, name', 'ASC'));
        foreach ($rows as $attribute) {
            $slug = trim((string) ($attribute['slug'] ?? ''));
            if ($slug === '') continue;
            $values = self::rows(AttributeValue::find(['attribute_id' => (int) $attribute['id'], 'deleted' => 'false'], null, 'position, label', 'ASC'));
            $attribute['values'] = $values;
            $this->attributes[] = $attribute;
            $selected = self::values($this->query[$slug] ?? null);
            if ($selected === []) continue;
            $this->applyAttribute($attribute, $values, $selected);
        }
    }

    private function applyAttribute(array $attribute, array $values, array $selected): void
    {
        $attributeId = (int) $attribute['id'];
        $level = (string) ($attribute['level'] ?? 'product');
        $type = (string) ($attribute['type'] ?? 'select');
        [$table, $relation] = match ($level) {
            'model' => ['gst_product_model_attributes', 'a.`product_model_id` = `gst_product_models`.`id`'],
            'variant' => ['gst_product_variant_attributes', 'EXISTS (SELECT 1 FROM `gst_product_variants` v WHERE v.`id` = a.`product_variant_id` AND v.`product_model_id` = `gst_product_models`.`id` AND v.`deleted` = \'false\')'],
            default => ['gst_product_attributes', 'EXISTS (SELECT 1 FROM `gst_products` p WHERE p.`id` = a.`product_id` AND p.`product_model_id` = `gst_product_models`.`id` AND p.`deleted` = \'false\')'],
        };

        if (in_array($type, ['select', 'color', 'pattern', 'icon'], true)) {
            $ids = [];
            foreach ($values as $value) {
                if (in_array((string) ($value['id'] ?? ''), $selected, true) || in_array(mb_strtolower((string) ($value['label'] ?? '')), array_map('mb_strtolower', $selected), true)) {
                    $ids[] = (int) $value['id'];
                }
            }
            if ($ids === []) return;
            $match = 'a.`attribute_value_id` IN ('.implode(',', array_unique($ids)).')';
        } elseif ($type === 'number') {
            [$min, $max] = self::range((string) ($selected[0] ?? ''));
            $parts = [];
            if ($min !== null) $parts[] = 'a.`value_number` >= '.self::decimal($min);
            if ($max !== null) $parts[] = 'a.`value_number` <= '.self::decimal($max);
            if ($parts === []) return;
            $match = implode(' AND ', $parts);
        } else {
            $match = "a.`value_text` LIKE '%".self::escape((string) ($selected[0] ?? ''))."%'";
        }

        $this->conditions[] = "EXISTS (SELECT 1 FROM `{$table}` a WHERE a.`attribute_id` = {$attributeId} AND a.`deleted` = 'false' AND {$relation} AND {$match})";
    }

    private function applyOrder(mixed $value): void
    {
        [$this->order, $this->direction] = match ((string) $value) {
            'nome_asc' => ['name', 'ASC'],
            'nome_desc' => ['name', 'DESC'],
            'prezzo_asc' => [self::priceOrder(), 'ASC'],
            'prezzo_desc' => [self::priceOrder(), 'DESC'],
            'recenti' => ['creation', 'DESC'],
            default => ['position, name', 'ASC'],
        };
    }

    private function descendantIds(int $root): array
    {
        $ids = [$root];
        $queue = [$root];
        while ($queue !== []) {
            $parent = array_shift($queue);
            foreach (self::rows(Category::find(['parent_id' => $parent, 'visible' => 'true', 'deleted' => 'false'])) as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0 && !in_array($id, $ids, true)) { $ids[] = $id; $queue[] = $id; }
            }
        }
        return $ids;
    }

    private static function priceOrder(): string
    {
        return "(SELECT MIN(CASE WHEN p.`sale_price` > 0 AND p.`sale_price` < p.`price` THEN p.`sale_price` ELSE p.`price` END) FROM `gst_products` p WHERE p.`product_model_id` = `gst_product_models`.`id` AND p.`active` = 'true' AND p.`deleted` = 'false')";
    }

    public static function range(string $value): array
    {
        $value = trim(str_replace(',', '.', $value));
        if ($value === '') return [null, null];
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\+$/', $value, $m)) return [(float) $m[1], null];
        if (preg_match('/^-([0-9]+(?:\.[0-9]+)?)$/', $value, $m)) return [null, (float) $m[1]];
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*-\s*([0-9]+(?:\.[0-9]+)?)$/', $value, $m)) return [(float) min($m[1], $m[2]), (float) max($m[1], $m[2])];
        if (is_numeric($value)) return [(float) $value, (float) $value];
        return [null, null];
    }

    private static function amount(mixed $value): ?float
    {
        $value = preg_replace('/[^0-9,.-]/', '', trim((string) $value)) ?? '';
        if (str_contains($value, ',') && str_contains($value, '.')) {
            // Il separatore più a destra è quello decimale; l'altro raggruppa.
            $commaIsDecimal = strrpos($value, ',') > strrpos($value, '.');
            $value = $commaIsDecimal
                ? str_replace(['.', ','], ['', '.'], $value)
                : str_replace(',', '', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }
        if ($value === '' || !is_numeric($value)) return null;
        return max(0.0, (float) $value);
    }

    /** @return list<string> */
    private static function values(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $values
        ), static fn (string $item): bool => $item !== '')));
    }

    private static function escape(string $value): string { return (new Query())->mysqli->real_escape_string($value); }
    private static function decimal(float $value): string { return number_format($value, 3, '.', ''); }
    private static function clean(string $value): string { return trim(str_replace(['\\', '"'], '', $value)); }
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) return [];
        return array_is_list($rows) ? array_values(array_filter($rows, 'is_array')) : [$rows];
    }
}
