<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductPlacement;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Catalog\Placements;
use Wonder\Sql\Query;
use Throwable;

/**
 * Traduce URL e query string in un filtro sicuro per il catalogo pubblico.
 *
 * L'ordine: se chi visita sceglie `?ordina=`, vale quello. Altrimenti decide
 * chi vende ({@see Placements}): in una categoria o nel tag della collezione
 * prima gli articoli «in evidenza», nel loro ordine, poi la regola della
 * categoria o del tag; «Come il catalogo» e le pagine senza contesto usano
 * `catalog_sort` delle impostazioni. In evidenza e foto di contesto valgono
 * solo se la categoria o il tag ha `show_placements` acceso: spento (o
 * colonna assente) è come se non ci fossero.
 */
final class CatalogFilter
{
    /** La posizione di chi non è in evidenza: dopo tutti quelli che lo sono. */
    private const UNPINNED = 2147483647;

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
    /** La regola: `position`, `newest`, `name`, `name_desc`, `price_asc` o `price_desc`. */
    private string $sort = 'position';
    private bool $pinned = false;
    private string $contextType = '';
    private int $contextId = 0;
    private string $contextSort = 'catalog';
    private bool $showPlacements = false;
    /** @var array<string, mixed> la riga della categoria o del tag */
    private array $contextRow = [];
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
    public function sort(): string { return $this->sort; }
    /** Vero quando gli articoli in evidenza del contesto vanno in cima. */
    public function pinned(): bool { return $this->pinned; }
    /** Il contesto mostra i suoi articoli in evidenza (e le loro foto di contesto). */
    public function showsPlacements(): bool { return $this->showPlacements; }
    /** @return array{type:string,id:int} la categoria o il tag dell'elenco; tipo vuoto senza */
    public function context(): array { return ['type' => $this->contextType, 'id' => $this->contextId]; }
    /** @return array<string, mixed> la riga della categoria o del tag del contesto */
    public function contextRow(): array { return $this->contextRow; }
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
            $this->conditions[] = self::offersCondition();
            return;
        }

        if ($action === 'new') {
            $this->titleKey = 'ecommerce.catalog.listing.new.title';
            $this->descriptionKey = 'ecommerce.catalog.listing.new.description';
            $this->canonical = '/novita/';
            $this->breadcrumbs[] = ['url' => $this->canonical, 'name' => (string) __t($this->titleKey)];
            $this->conditions[] = self::noveltyCondition();
            return;
        }

        if ($action === 'collection') {
            $this->titleKey = 'ecommerce.catalog.listing.collection.title';
            $this->descriptionKey = 'ecommerce.catalog.listing.collection.description';
            $this->canonical = '/collezione/';
            $this->breadcrumbs[] = ['url' => $this->canonical, 'name' => (string) __t($this->titleKey)];
            $this->applyTag((string) Ecommerce::config('catalog.collection_tag', 'collezione'), true);
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

        $this->conditions[] = $this->categoryCondition((int) $category['id']);
        $this->titleKey = '';
        $this->descriptionKey = '';
        $this->routeTitle = (string) ($category['name'] ?? '');
        $this->routeDescription = trim(strip_tags((string) ($category['description'] ?? '')));
        if ($this->routeDescription === '') {
            $this->routeDescription = (string) __t('ecommerce.catalog.listing.category.description');
        }
        $this->canonical = '/prodotti/'.implode('/', array_map('rawurlencode', $path)).'/';
        $this->useContext(Placements::CATEGORY, $category);
    }

    /**
     * Gli articoli con il tag di quello slug, nel suo ordine. `$onlyVisible`
     * per le pagine del tag: un blocco del sito («Giorno», «Notte») può usare
     * anche un tag nascosto, che non ha una pagina sua.
     *
     * @return array<string, mixed>|null il tag, o null se non c'è
     */
    private function applyTag(string $slug, bool $onlyVisible): ?array
    {
        $where = ['slug' => trim($slug), 'deleted' => 'false'];
        if ($onlyVisible) $where['visible'] = 'true';
        $tag = trim($slug) !== '' ? Tag::find($where, 1) : null;

        if (!is_array($tag) || (int) ($tag['id'] ?? 0) <= 0) {
            $this->conditions[] = '1 = 0';
            return null;
        }

        $id = (int) $tag['id'];
        $this->conditions[] = "EXISTS (SELECT 1 FROM `gst_product_model_tags` mt WHERE mt.`product_model_id` = `gst_product_models`.`id` AND mt.`tag_id` = {$id} AND mt.`deleted` = 'false')";
        $this->useContext(Placements::TAG, $tag);

        return $tag;
    }

    /** @param array<string, mixed> $row la categoria o il tag */
    private function useContext(string $type, array $row): void
    {
        $id = (int) ($row['id'] ?? 0);
        $this->contextType = in_array($type, Placements::CONTEXTS, true) && $id > 0 ? $type : '';
        $this->contextId = $this->contextType !== '' ? $id : 0;
        $this->contextSort = Placements::contextSortMode($row['sort_mode'] ?? null);
        $this->contextRow = $this->contextType !== '' ? $row : [];
        $this->showPlacements = $this->contextType !== '' && self::flag($row['show_placements'] ?? null);
    }

    /** `show_placements` letto con tolleranza: senza colonna vale spento. */
    private static function flag(mixed $value): bool
    {
        return $value === true || (is_scalar($value) && in_array(strtolower(trim((string) $value)), ['1', 'true'], true));
    }

    /**
     * Il filtro degli articoli di un tag, per un blocco del sito: stessi
     * criteri del catalogo, l'ordine del tag. Null se il tag non c'è.
     */
    public static function forTag(string $slug): ?self
    {
        $filter = new self([]);
        $tag = $filter->applyTag($slug, false);
        $filter->applyOrder(null);

        return $tag === null ? null : $filter;
    }

    /**
     * Il filtro della fonte automatica di una selezione (`gst_product_selections.source`):
     * tutto il catalogo (`catalog`), gli articoli in offerta (`offers`), o
     * quelli di almeno una delle categorie (sottocategorie comprese), dei tag
     * o dei marchi con gli slug in `$refSlugs`; mai gli articoli in `$excluded`. `$sortMode`
     * è la regola della selezione: «Nell'ordine dato» segue il catalogo, o la
     * categoria o il tag quando è uno solo. Null per `manual`, per una fonte
     * ignota o se nessun riferimento c'è più.
     *
     * @param list<string> $refSlugs
     * @param list<int> $excluded
     */
    public static function forSelection(string $source, array $refSlugs = [], array $excluded = [], string $sortMode = 'manual'): ?self
    {
        $filter = new self([]);

        switch ($source) {
            case 'catalog':
                break;
            case 'offers':
                $filter->conditions[] = self::offersCondition();
                break;
            case 'category':
                $rows = self::refRows(Category::class, $refSlugs, ['visible' => 'true', 'deleted' => 'false']);
                if ($rows === []) return null;
                $filter->conditions[] = '('.implode(' OR ', array_map(
                    static fn (array $row): string => $filter->categoryCondition((int) $row['id']),
                    $rows
                )).')';
                if (count($rows) === 1) $filter->useContext(Placements::CATEGORY, $rows[0]);
                break;
            case 'tag':
                // Come forTag(): anche un tag nascosto può alimentare un blocco.
                $rows = self::refRows(Tag::class, $refSlugs, ['deleted' => 'false']);
                if ($rows === []) return null;
                $ids = implode(',', array_map(static fn (array $row): int => (int) $row['id'], $rows));
                $filter->conditions[] = "EXISTS (SELECT 1 FROM `gst_product_model_tags` mt WHERE mt.`product_model_id` = `gst_product_models`.`id` AND mt.`tag_id` IN ({$ids}) AND mt.`deleted` = 'false')";
                if (count($rows) === 1) $filter->useContext(Placements::TAG, $rows[0]);
                break;
            case 'brand':
                $rows = self::refRows(Brand::class, $refSlugs, ['visible' => 'true', 'deleted' => 'false']);
                if ($rows === []) return null;
                $filter->conditions[] = '`brand_id` IN ('.implode(',', array_map(static fn (array $row): int => (int) $row['id'], $rows)).')';
                break;
            default:
                return null;
        }

        $excluded = array_values(array_filter(array_map('intval', $excluded), static fn (int $id): bool => $id > 0));
        if ($excluded !== []) {
            $filter->conditions[] = '`gst_product_models`.`id` NOT IN ('.implode(',', array_unique($excluded)).')';
        }

        $filter->applyOrder(match ($sortMode) {
            'newest' => 'recenti',
            'name' => 'nome_asc',
            'price_asc' => 'prezzo_asc',
            'price_desc' => 'prezzo_desc',
            default => null,
        });

        return $filter;
    }

    /**
     * Le righe ancora valide fra gli slug di una selezione, nell'ordine dato.
     *
     * @param class-string $model
     * @param list<string> $slugs
     * @param array<string, string> $where
     * @return list<array<string, mixed>>
     */
    private static function refRows(string $model, array $slugs, array $where): array
    {
        $rows = [];

        foreach (array_unique(array_map(static fn (mixed $slug): string => is_scalar($slug) ? trim((string) $slug) : '', $slugs)) as $slug) {
            $row = $slug !== '' ? $model::find(['slug' => $slug] + $where, 1) : null;
            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) $rows[] = $row;
        }

        return $rows;
    }

    /** Gli articoli nuovi: creati negli ultimi `catalog.new_days` giorni, come /novita/. */
    private static function noveltyCondition(): string
    {
        $days = max(1, (int) Ecommerce::config('catalog.new_days', 30));

        return "`creation` >= DATE_SUB(NOW(), INTERVAL {$days} DAY)";
    }

    /** Gli articoli con almeno un prodotto scontato, come /offerte/. */
    private static function offersCondition(): string
    {
        return "EXISTS (SELECT 1 FROM `gst_products` p WHERE p.`product_model_id` = `gst_product_models`.`id` AND p.`active` = 'true' AND p.`deleted` = 'false' AND p.`sale_price` > 0 AND p.`sale_price` < p.`price`)";
    }

    private function categoryCondition(int $id): string
    {
        $ids = $this->descendantIds($id);

        return 'EXISTS (SELECT 1 FROM `gst_product_model_categories` mc WHERE mc.`product_model_id` = `gst_product_models`.`id` AND mc.`category_id` IN ('.implode(',', $ids).") AND mc.`deleted` = 'false')";
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
        $like = self::likeTerm($term);
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
        // Una variante vive nella tabella delle varianti quando ha le pagine
        // accese, in quella dei prodotti quando sono spente (#85): si cerca
        // in tutte e due.
        $sources = $level === 'model'
            ? [['gst_product_model_attributes', 'a.`product_model_id` = `gst_product_models`.`id`']]
            : [
                ['gst_product_variant_attributes', 'EXISTS (SELECT 1 FROM `gst_product_variants` v WHERE v.`id` = a.`product_variant_id` AND v.`product_model_id` = `gst_product_models`.`id` AND v.`deleted` = \'false\')'],
                ['gst_product_attributes', 'EXISTS (SELECT 1 FROM `gst_products` p WHERE p.`id` = a.`product_id` AND p.`product_model_id` = `gst_product_models`.`id` AND p.`deleted` = \'false\')'],
            ];

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
            $match = "a.`value_text` LIKE '%".self::likeTerm((string) ($selected[0] ?? ''))."%'";
        }

        $exists = array_map(
            static fn (array $source): string => "EXISTS (SELECT 1 FROM `{$source[0]}` a WHERE a.`attribute_id` = {$attributeId} AND a.`deleted` = 'false' AND {$source[1]} AND {$match})",
            $sources
        );
        $this->conditions[] = '('.implode(' OR ', $exists).')';
    }

    private function applyOrder(mixed $value): void
    {
        $visitor = match (is_scalar($value) ? (string) $value : '') {
            'nome_asc' => 'name',
            'nome_desc' => 'name_desc',
            'prezzo_asc' => 'price_asc',
            'prezzo_desc' => 'price_desc',
            'recenti' => 'newest',
            default => '',
        };

        // La scelta di chi visita batte quella di chi vende, in evidenza compresi.
        $this->sort = $visitor !== '' ? $visitor : $this->siteSort();
        $this->pinned = $visitor === '' && $this->contextType !== '' && $this->showPlacements;

        [$order, $this->direction] = match ($this->sort) {
            'name' => ['name', 'ASC'],
            'name_desc' => ['name', 'DESC'],
            'price_asc' => [self::priceOrder(), 'ASC'],
            'price_desc' => [self::priceOrder(), 'DESC'],
            'newest' => ['creation', 'DESC'],
            default => ['position, name', 'ASC'],
        };
        $this->order = ($this->pinned ? self::pinOrder($this->contextType, $this->contextId, 'model').', ' : '').$order;
    }

    /** La regola di chi vende: quella della categoria o del tag, altrimenti del catalogo. */
    private function siteSort(): string
    {
        if ($this->contextSort !== 'catalog') {
            return $this->contextSort;
        }

        try {
            return Placements::catalogSortMode(Setting::current()['catalog_sort'] ?? null);
        } catch (Throwable) {
            return 'position';
        }
    }

    /**
     * La posizione «in evidenza» di ogni riga nel contesto, da mettere prima
     * dell'ordine: chi non è in evidenza vale {@see UNPINNED} e va dopo. Per
     * `model` conta qualunque riga dell'articolo; per `variant` vince la riga
     * della variante, poi quella dell'articolo intero.
     */
    public static function pinOrder(string $type, int $id, string $entity = 'model'): string
    {
        if (!in_array($type, Placements::CONTEXTS, true) || $id <= 0) {
            return (string) self::UNPINNED;
        }

        $table = ProductPlacement::$table;
        $rows = "SELECT MIN(pl.`position`) FROM `{$table}` pl WHERE pl.`context_type` = '{$type}' AND pl.`context_id` = {$id} AND pl.`deleted` = 'false'";

        if ($entity === 'variant') {
            return "COALESCE(({$rows} AND pl.`product_variant_id` = `gst_product_variants`.`id`), "
                ."({$rows} AND pl.`product_model_id` = `gst_product_variants`.`product_model_id` AND COALESCE(pl.`product_variant_id`, 0) = 0), "
                .self::UNPINNED.')';
        }

        return "COALESCE(({$rows} AND pl.`product_model_id` = `gst_product_models`.`id`), ".self::UNPINNED.')';
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

    public static function priceOrder(): string
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

    /** Termine per `LIKE '%…%'`: `%`, `_` e `\` restano letterali, come `Popup::likeLiteral()` del core. */
    public static function likeTerm(string $term): string { return self::escape(self::likeLiteral($term)); }
    public static function likeLiteral(string $term): string { return addcslashes($term, '%_\\'); }
    private static function decimal(float $value): string { return number_format($value, 3, '.', ''); }
    private static function clean(string $value): string { return trim(str_replace(['\\', '"'], '', $value)); }
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) return [];
        return array_is_list($rows) ? array_values(array_filter($rows, 'is_array')) : [$rows];
    }
}
