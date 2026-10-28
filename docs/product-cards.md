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
Card per riga e limiti vengono dalla configurazione del sito; `columns()` e
`swiperLimits()` di `ProductList` li superano per una singola lista:

```php
'catalog' => [
    'per_page' => 12,                                          // prodotti per pagina
    'columns' => ['desktop' => 4, 'tablet' => 3, 'phone' => 2], // griglia, 1-12
    'swiper' => [
        'min' => 1,   // sotto questo numero lo slider non compare
        'max' => 12,  // card al massimo
        'columns' => ['desktop' => 4, 'tablet' => 2, 'phone' => 1.2],
    ],
],
```

La granularita e il contenuto delle card si scelgono in `config/module.php`:

```php
'catalog' => [
    'listing_entity' => 'variant', // `model` oppure `variant`
    'card' => [
        'variants' => true,     // miniature delle altre varianti in card
        'add_to_cart' => false, // «Aggiungi al carrello» nella card
        'quantity' => false,    // −/+ accanto al bottone della card
    ],
],
```

Con `model` il catalogo mostra una card per modello. Con `variant` ogni
variante visibile e acquistabile riceve la propria card, immagine, prezzo e
URL; il nome unisce modello e variante con uno spazio (`Dinosauro Verde`) e
senza trattini. Paginazione, schema `ItemList` e `view_item_list` usano la
stessa granularita. Le miniature delle varianti compaiono da due varianti in
su, al massimo cinque, con quella della card selezionata (bordo più spesso,
`aria-current`); lo stile è in `resources/assets/css/card.css`, registrato da
`ProductList`. Impostando `card.variants` a `false` la riga di miniature
sotto la card non viene renderizzata.

Con `card.add_to_cart` la card compra direttamente quando corrisponde a un
solo prodotto (`gst_products`) e, se è un multiprodotto, senza gruppi di
scelta: usa `components/cart/add.php`, intercettato dal mini-carrello, con
−/+ se `card.quantity` è attivo. Un prodotto esaurito mostra «Non
disponibile» disabilitato; una card con più prodotti (taglie, scelte) mostra
«Scegli le opzioni» verso la scheda. Il dato arriva in `cart`
(`product_id`, `available`, `quantity`): `product_id` 0 porta alla scheda.

La scheda prodotto ha una sezione propria:

```php
'product' => [
    'quantity' => false, // −/+ accanto ad «Aggiungi al carrello»
    'express' => true,   // Apple Pay, Google Pay, Link sotto il bottone
    'option_selector' => ['default' => 'auto', 'attributes' => []],
],
'cart' => [
    'express' => true,   // pagamento rapido nel riepilogo del carrello
],
```

## Ordine del catalogo e articoli in evidenza

L'ordine lo decide chi vende nel gestionale (Catalogo → Selezioni, il
riquadro «Ordine nel sito» di categorie e tag, «Ordine del catalogo» nelle
impostazioni; vedi `Support\Catalog\Placements` del gestionale). Senza
`?ordina=`, `CatalogFilter` mette:

1. in una categoria o nel tag di `/collezione/`, prima gli articoli «in
   evidenza», nella posizione data, solo se la categoria o il tag ha
   `show_placements = 1` («Mostra articoli in evidenza»; colonna assente o 0:
   niente evidenza e niente foto di contesto, `showsPlacements()` lo dice). Con `listing_entity => 'variant'` vale la
   riga della variante, poi quella dell'articolo intero;
2. poi la regola della categoria o del tag (`sort_mode`); «Come il catalogo»
   e le pagine senza contesto (`/prodotti/`, `/offerte/`, `/novita/`,
   `/cerca/`, i marchi) usano `catalog_sort` delle impostazioni, di serie
   `position`, l'ordine di prima.

Se chi visita sceglie `?ordina=`, vale il suo ordine e gli articoli in
evidenza non vengono prima. `ItemList`, paginazione e `view_item_list` seguono
lo stesso ordine, perché nascono dalle stesse card.

## Selezioni e tag nei blocchi del sito

`ProductLists` dà ai blocchi del sito (la home) gli elenchi già ordinati:

```php
use Wonder\Plugin\Ecommerce\Frontend\Catalog\CustomerPriceList;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductList;
use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductLists;

$featured = ProductLists::selection('home-prodotti', null, CustomerPriceList::current());
// null se non c'è, è nascosta o la vendita online è spenta
// ['name', 'slug', 'block', 'meta', 'source', 'sort_mode',
//  'title', 'subtitle', 'text', 'image', 'products' => [...card]]

$day = ProductLists::tag('giorno', 8, CustomerPriceList::current());
// ['name', 'slug', 'products' => [...card]]: in evidenza, poi la regola del tag
```

- `selection()` legge la selezione per url pubblico, che nasce dal nome e non
  cambia. Con `source = manual` una riga dell'articolo dà la sua card (o una
  per variante con `listing_entity => 'variant'`), una riga della variante la
  card di quella variante; il limite è quello passato, `null` vale 12.
  Articoli e varianti nascosti al sito restano fuori.
- Le altre fonti prendono le card dal catalogo con
  `CatalogFilter::forSelection($source, $refIds, $excluded, $sortMode)`:
  `catalog` (tutto), `offers` (come `/offerte/`), `category` (una o più, con
  le sottocategorie), `tag` (anche nascosti) e `brand`, in OR fra gli id
  scelti; gli articoli in `excluded_model_ids` restano fuori. Il limite è
  `max_items` della selezione; uno passato più basso vince, più alto no. Se
  nessun riferimento c'è più, `products` è vuoto.
- `sort_mode` vale per tutta la fonte, prima del limite. `manual` lascia
  l'ordine del catalogo, o quello (con l'evidenza) della categoria o del tag
  quando è uno solo. Per `source = manual` le altre regole riordinano tutta
  la selezione, poi si taglia.
- `block` è la chiave del blocco scelto nel gestionale (`default`,
  `spotlight` o uno del sito, vedi `SelectionBlocks` del gestionale): il sito
  la usa per scegliere il componente. `meta` ha solo i campi in più di quel
  blocco, in testo (per `spotlight`: `cta_label`, `cta_url`); vuoto per
  `default`. `image` arriva comunque, anche se il blocco scelto non la usa.
- `tag()` usa anche un tag nascosto: un tag «di servizio» non ha la sua
  pagina, ma il blocco lo può mostrare.
- Le card hanno `context_image`, la foto di contesto caricata sulla riga (in
  una selezione scelta a mano, o fra gli articoli in evidenza di categoria e
  tag con `show_placements = 1`); vuota se non c'è. Le viste del modulo non la usano: la usa la vista del sito che la
  prevede, al posto di `image` o accanto.
- `title`, `subtitle` e i nomi arrivano come testo: si stampano con `e()`.
  `text` è HTML già ripulito dal gestionale.

Per il tracciamento di ogni blocco, `DataLayer::itemListEvent($id, $name,
$cards, $currency)` dà il `view_item_list` con `item_list_id`, nome e `index`
da 1 nell'ordine in pagina; si passa a `DataLayer::script()` con i dati di
pagina. Un id per blocco (`home_featured`, `home_ritual_giorno`), lo stesso
nel `select_item` al clic. L'`ItemList` in JSON-LD, se il blocco mostra i
prezzi, elenca le stesse card nello stesso ordine.

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
