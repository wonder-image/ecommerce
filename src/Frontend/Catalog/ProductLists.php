<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductPlacement;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSelection;
use Wonder\Plugin\Gestionale\Support\Catalog\Placements;
use Wonder\Plugin\Gestionale\Support\Catalog\SelectionBlocks;

/**
 * Gli elenchi di articoli per i blocchi del sito, come li ordina chi vende
 * nel gestionale ({@see Placements}):
 *
 * - {@see selection()}: una selezione di Catalogo → Selezioni, per url
 *   pubblico (`home-prodotti`), con il blocco che la mostra, i suoi testi,
 *   l'immagine e i campi in più; gli articoli scelti a mano o presi da una
 *   fonte del catalogo;
 * - {@see tag()}: gli articoli di un tag («giorno», «notte»), prima quelli in
 *   evidenza, poi la regola del tag.
 *
 * Le card sono quelle del catalogo (`ProductListing`), con `context_image`
 * quando la riga ha la foto di contesto (in categorie e tag solo se mostrano
 * gli articoli in evidenza). I testi arrivano già decodificati:
 * la vista li stampa con `e()`, tranne `text`, che è HTML ripulito dal
 * gestionale. Selezioni e foto di contesto esistono solo con la vendita
 * online: spenta, `selection()` risponde null.
 */
final class ProductLists
{
    /** Più di così un blocco non ne mostra: è una vetrina, non il catalogo. */
    public const MAX = 48;

    /** Quanti articoli scelti a mano, se chi chiama non lo dice: come prima delle fonti. */
    public const MANUAL_LIMIT = 12;

    /**
     * Gli articoli e il blocco di una selezione.
     *
     * - fonte `manual`: le righe della selezione; `$limit` (di serie
     *   {@see MANUAL_LIMIT}) taglia, e un ordine diverso da «Nell'ordine dato»
     *   riordina tutta la selezione prima di tagliare;
     * - fonti automatiche: i primi `max_items` articoli della fonte, nel suo
     *   ordine ({@see CatalogFilter::forSelection()}); `$limit` può solo
     *   ridurli; un ordine diverso da «Nell'ordine dato» riordina quelli presi.
     *
     * `block` è la chiave del blocco ({@see SelectionBlocks}), `meta` i suoi
     * campi in più già decodificati, una chiave per campo ('' se vuoto).
     *
     * @param int|null $limit null: tutti quelli della selezione (fino a `max_items` o {@see MANUAL_LIMIT})
     * @param array<string, mixed>|null $priceList il listino del cliente (CustomerPriceList::current())
     * @return array{name:string,slug:string,block:string,meta:array<string, string>,source:string,sort_mode:string,title:string,subtitle:string,text:string,image:string,products:list<array<string, mixed>>}|null
     */
    public static function selection(string $slug, ?int $limit = null, ?array $priceList = null): ?array
    {
        $slug = trim($slug);
        if ($slug === '' || !self::onlineSales()) return null;

        $selection = ProductSelection::find(['slug' => $slug, 'visible' => 'true', 'deleted' => 'false'], 1);
        if (!is_array($selection) || (int) ($selection['id'] ?? 0) <= 0) return null;

        $mode = Placements::selectionSortMode($selection['sort_mode'] ?? null);
        $source = Placements::selectionSource($selection['source'] ?? null);

        if ($source === 'manual') {
            $limit = self::limit($limit ?? self::MANUAL_LIMIT);
            $rows = self::rows(ProductPlacement::find(
                ['context_type' => Placements::SELECTION, 'context_id' => (int) $selection['id'], 'deleted' => 'false'],
                null,
                'position',
                'ASC'
            ));
            // Un'altra regola riordina tutta la selezione, poi taglia.
            $cards = ProductListing::byPlacements($rows, $mode === 'manual' ? $limit : self::MAX, $priceList);
        } else {
            $max = Placements::selectionMaxItems($selection['max_items'] ?? null);
            $limit = self::limit(min($limit ?? $max, $max));
            $column = Placements::SELECTION_SOURCE_REFS[$source] ?? '';
            $filter = CatalogFilter::forSelection($source, $column !== '' ? (int) ($selection[$column] ?? 0) : 0);
            // La fonte sceglie quali, la regola della selezione li riordina.
            $cards = $filter === null ? [] : ProductListing::cards($filter, (string) $limit, $priceList);
        }

        $cards = array_slice(self::sorted($cards, $mode), 0, $limit);
        $block = SelectionBlocks::normalize($selection['block'] ?? null);

        return [
            'name' => self::text($selection['name'] ?? ''),
            'slug' => (string) ($selection['slug'] ?? ''),
            'block' => $block,
            'meta' => array_map(self::text(...), SelectionBlocks::cleanMeta($block, SelectionBlocks::decodeMeta($selection['block_meta'] ?? null))),
            'source' => $source,
            'sort_mode' => $mode,
            'title' => self::text($selection['title'] ?? ''),
            'subtitle' => self::text($selection['subtitle'] ?? ''),
            'text' => trim((string) ($selection['text'] ?? '')),
            'image' => ProductSelection::imageUrl($selection),
            'products' => $cards,
        ];
    }

    /**
     * @param array<string, mixed>|null $priceList come in selection()
     * @return array{name:string,slug:string,products:list<array<string, mixed>>}|null
     */
    public static function tag(string $slug, int $limit = 12, ?array $priceList = null): ?array
    {
        $filter = CatalogFilter::forTag($slug);
        if ($filter === null) return null;

        return [
            'name' => self::text($filter->contextRow()['name'] ?? ''),
            'slug' => trim($slug),
            'products' => ProductListing::cards($filter, (string) self::limit($limit), $priceList),
        ];
    }

    /**
     * Le card nell'ordine di una regola; `manual` le lascia come sono. A
     * parità vale l'ordine dato.
     *
     * @param list<array<string, mixed>> $cards
     * @param (callable(list<int>): array<int, string>)|null $created la data di creazione degli articoli; di serie dal database
     * @return list<array<string, mixed>>
     */
    public static function sorted(array $cards, string $mode, ?callable $created = null): array
    {
        if ($mode === 'manual' || count($cards) < 2) return $cards;

        $dates = $mode === 'newest'
            ? ($created ?? self::created(...))(array_values(array_unique(array_map(static fn (array $card): int => (int) ($card['model_id'] ?? 0), $cards))))
            : [];
        $price = static fn (array $card): float => (float) ($card['meta']['price'] ?? 0);
        $keyed = array_map(null, array_keys($cards), $cards);

        usort($keyed, static function (array $a, array $b) use ($mode, $price, $dates): int {
            [$ia, $ca] = $a;
            [$ib, $cb] = $b;
            $compare = match ($mode) {
                'name' => strnatcasecmp((string) ($ca['name'] ?? ''), (string) ($cb['name'] ?? '')),
                'price_asc' => $price($ca) <=> $price($cb),
                'price_desc' => $price($cb) <=> $price($ca),
                'newest' => strcmp($dates[(int) ($cb['model_id'] ?? 0)] ?? '', $dates[(int) ($ca['model_id'] ?? 0)] ?? ''),
                default => 0,
            };

            return $compare !== 0 ? $compare : $ia <=> $ib;
        });

        return array_column($keyed, 1);
    }

    /** @param list<int> $ids @return array<int, string> */
    private static function created(array $ids): array
    {
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) return [];

        $dates = [];
        foreach (self::rows(ProductModel::find('`id` IN ('.implode(',', $ids).")", null, null, null, ['id', 'creation'])) as $row) {
            $dates[(int) $row['id']] = (string) ($row['creation'] ?? '');
        }

        return $dates;
    }

    private static function onlineSales(): bool
    {
        try {
            return Gestionale::feature('online_sales');
        } catch (Throwable) {
            return false;
        }
    }

    private static function limit(int $limit): int
    {
        return max(1, min(self::MAX, $limit));
    }

    private static function text(mixed $value): string
    {
        return trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) return [];
        return array_is_list($rows) ? array_values(array_filter($rows, 'is_array')) : [$rows];
    }
}
