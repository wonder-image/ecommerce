# Card prodotto

`ProductCard` normalizza una singola voce di catalogo; `ProductList` la dispone
come griglia, elenco orizzontale o carosello Swiper. Le classi non eseguono
query: ricevono array, oggetti o DTO, quindi la sorgente dati può cambiare senza
dover riscrivere le viste.

Il markup usa esclusivamente componenti e utility di `wonder-image/lib`: non è
richiesto CSS dedicato. La card è volutamente minimale, con immagine quadrata,
badge del design system, prezzo in evidenza e varianti circolari facoltative.

La pagina dimostrativa completa è intenzionalmente esclusa dalle route del
modulo. Nel progetto locale `ecommerce-site` si trova in
`demo/product-cards.php` ed è raggiungibile come `/demo/product-cards.php`.

```php
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductList;

$products = [
    [
        'id' => 12,
        'name' => 'T-shirt girocollo',
        'url' => '/shop/t-shirt-girocollo/',
        'image' => '/assets/upload/prodotti/t-shirt.jpg',
        'price' => 39.90,
        'sale_price' => 29.90,
        'short_description' => 'Cotone biologico.',
        'variants' => [
            ['name' => 'Blu', 'url' => '/shop/t-shirt-girocollo/?color=blu', 'active' => true],
            ['name' => 'Nero', 'url' => '/shop/t-shirt-girocollo/?color=nero'],
        ],
    ],
];

echo ProductList::make($products)
    ->title('In evidenza', 'Una selezione dal catalogo')
    ->columns(4, 3, 2)
    ->renderGrid();
```

`columns($desktop, $tablet, $phone)` stabilisce quanti prodotti mostrare per
riga. I valori predefiniti sono `4`, `3` e `2`; la griglia occupa sempre il
100% del contenitore indipendentemente dal numero scelto.

Gli altri layout usano gli stessi dati:

```php
echo ProductList::make($products)->renderList();

echo ProductList::make($products)
    ->swiper(1.2, 12, [
        640 => ['slidesPerView' => 2, 'spaceBetween' => 16],
        1024 => ['slidesPerView' => 4, 'spaceBetween' => 24],
    ])
    ->renderSwiper();
```

Gli ID effettivamente mostrati sono disponibili con `shownIds()`.

Ogni vista può essere pubblicata/ridefinita dal sito sotto
`custom/modules/ecommerce/view/components/catalog/product/`, senza modificare
il pacchetto.

## Catalogo reale, filtri e paginazione

Le route `/prodotti/`, `/offerte/`, `/novita/`, `/collezione/`,
`/marchi/{marca}/` e `/cerca/?q=` usano tutte `CatalogFilter`. Le categorie
mantengono l'intera gerarchia nell'URL, ad esempio
`/prodotti/abbigliamento/felpe/`. I filtri `marca`, `prezzo`, `ordina` e ogni
attributo del gestionale con `is_filterable = true` sono query parameter e
restano attivi nei link generati da `pagination()` di `wonder-image/app`.

Formati prezzo accettati: `20-100`, `50+`, `-80` oppure un prezzo esatto.
Gli ordinamenti sono `recenti`, `prezzo_asc`, `prezzo_desc`, `nome_asc` e
`nome_desc`.
La griglia usa 4 colonne desktop, 3 tablet e 2 mobile; `catalog.per_page`
stabilisce il numero di prodotti per pagina (12 per impostazione predefinita).

La granularita delle card si sceglie in `config/module.php`:

```php
'catalog' => [
    'listing_entity' => 'variant', // `model` oppure `variant`
    'card_show_variants' => true,  // miniature delle altre varianti in card
],
```

Con `model` il catalogo mostra una card per modello. Con `variant` ogni
variante visibile e acquistabile riceve la propria card, immagine, prezzo e
URL; il nome unisce modello e variante con uno spazio (`Dinosauro Verde`) e
senza trattini. Paginazione, schema `ItemList` e `view_item_list` usano la
stessa granularita. Impostando `card_show_variants` a `false` la riga di
miniature sotto la card non viene renderizzata.

# Ricerca nell'header e catalogo filtrato

Il sito configura la ricerca come voce in `custom/config/navigation.php`:

```php
[
    'key' => 'search',
    'route' => 'ecommerce.catalog.search',
    'placement' => 'utility',
    'icon' => 'bi bi-search',
    'modal' => 'search-input',
    'enabled' => true,
    'drawerHidden' => true,
],
```

La posizione nell'array stabilisce l'ordine (prima di Account).
`enabled => false` disabilita icona e overlay. Tutti i cinque layout desktop
e la barra mobile usano le medesime utility; `modal` aggiunge il contratto
`data-wi-modal-target` al link, mantenendo la route come fallback.
Il sito inserisce `components/catalog/search.php` dopo l'header, fuori da contenitori
trasformati. La ricerca usa `FormField::searchText()` del core, il modal della
lib e il form GET `search_product` verso `ecommerce.catalog.search` (`/cerca/?q=`).
La lib gestisce apertura, focus, Escape e sfondo.

L'endpoint pubblico POST `api.ecommerce.catalog.products.search`
(`/api/ecommerce/catalog/products/search/`) restituisce al massimo otto
suggerimenti dai prodotti attivi e visibili online. Accetta `search`, limitato
a 120 caratteri; i suggerimenti sono escaped per il markup della lib.
Il JSON viaggia come testo perché `searchText()` chiama `JSON.parse`.

Un filtro query effettivo (ricerca, brand, attributi o prezzo) cambia H1,
descrizione, metadati SEO e nome ItemList/GA4 in «Prodotti filtrati».
Il solo ordinamento mantiene il titolo della pagina. Canonical e gerarchia
degli URL restano quelli della pagina base; il breadcrumb corrente usa il
titolo filtrato. L'endpoint non genera pagine, schema o eventi GTM.
