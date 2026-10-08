# Scheda prodotto

La route pubblica `ecommerce.catalog.product` risponde a
`/prodotto/{slug}/`. Accetta lo slug di una variante visibile oppure, come
ripiego, quello del modello e legge i dati dalle tabelle catalogo di
`wonder-image/gestionale`.

La pagina usa `ProductCatalog` per assemblare modello, variante, opzioni,
immagini, marchio, categoria principale e disponibilita; `ProductDetail`
normalizza una sola volta i dati usati da HTML, schema.org e tracking.

Le varianti mostrano esclusivamente il proprio nome e, quando disponibile,
una miniatura quadrata. Le opzioni seguono gli assi del modello (per esempio
Taglia e Materiale) e mostrano soltanto il valore dell'asse. La configurazione
`catalog.option_selector` puo forzare `buttons` o `select` per slug; in
modalita `auto` usa bottoni per valori visuali o fino a quattro elementi e
select per le liste piu lunghe.

Disponibile/Non disponibile compare soltanto se esiste una sede attiva che
gestisce la giacenza. La stessa regola governa `availability` nello schema;
senza magazzino il prodotto resta ordinabile.

## SEO e dati strutturati

- indicizzazione `INDEX,FOLLOW`, canonical assoluto e immagine principale;
- un solo `h1`, descrizione server-side e galleria responsive del core;
- `Product` JSON-LD generato con `json_encode`, con `Offer` per una sola
  opzione e `AggregateOffer` quando i prezzi disponibili sono piu di uno;
- nessun rating o recensione viene dichiarato finche non e visibile in pagina.

## Breadcrumb

Il percorso e Home > Prodotti > gerarchia della categoria principale >
prodotto. Lo stesso array alimenta il breadcrumb visibile e `$SEO->breadcrumb`;
il relativo `BreadcrumbList` resta responsabilita di `wonder-image/app`.

Gli URL dell'indice e delle categorie sono configurabili in
`config/module.php` (`catalog.index_url`, `catalog.category_url`).

## dataLayer

La pagina inizializza sempre `window.dataLayer`, invia i dati comuni di utente
(solo id interno), tipo pagina, lingua e valuta, azzera `ecommerce`, quindi
pubblica l'evento custom richiesto `view_product`.

Il payload conserva l'oggetto storico `product` e aggiunge `ecommerce` con la
struttura item di GA4. `item_id`, `productID` schema.org e id usato dal
carrello sono lo stesso identificativo numerico dell'opzione; lo SKU resta nel
campo `sku`. Prezzo e sconto sono numeri, non stringhe formattate.

In fondo alla scheda compaiono, quando hanno risultati, tre slider: articoli
simili della stessa categoria, prodotti visti di recente (cookie tecnico
HttpOnly di 30 giorni) e “Potrebbero piacerti”, basato sui tag in comune e poi
sul marchio. Ogni slider invia un `view_item_list`; `view_product` resta il
primo evento ecommerce della pagina.

# Head e breadcrumb condivisi

Catalogo e prodotto passano lo schema come array a `$SEO->schemaOrg`.
Il core lo stampa nell'head, unito a `$SEO->breadcrumb` in un unico `@graph`.
Il breadcrumb visibile usa `Breadcrumb::make($list)` di wonder-image/app,
con label predefinita `breadcrumb` e renderer scelto dal tema attivo.
CSS e dataLayer sono registrati con `View::head()`; gli script checkout/carrello
sono nell'head con `defer`. Anche font e stili di accesso/account vengono
registrati nell'head. Eventi GA4, canonical e robots mantengono la semantica
esistente; le pagine private non ricevono dati strutturati.
