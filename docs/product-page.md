# Scheda prodotto

La route pubblica `ecommerce.catalog.product` risponde a
`/prodotto/{slug}/`. Accetta lo slug di una variante visibile oppure, come
ripiego, quello del modello e legge i dati dalle tabelle catalogo di
`wonder-image/gestionale`.

La pagina usa `ProductCatalog` per assemblare modello, variante, opzioni,
immagini, marchio, categoria principale e disponibilita; `ProductDetail`
normalizza una sola volta i dati usati da HTML, schema.org e tracking.

Il selettore varianti è in `view/components/catalog/variants.php`, sovrascrivibile
nel sito in `custom/modules/ecommerce/view/components/catalog/variants.php`.
Non mostra il titolo “Varianti”. Il CSS `resources/assets/css/product.css`
espone `--variant-size`, `--variant-border-width` e
`--variant-selected-border-width`: la variante corrente ha un bordo più spesso.
La griglia dei dati usa righe dimensionate sul contenuto.

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

## Ricerca

Il filtro ricerca anche il nome composto da modello e variante e limita i
risultati alle varianti visibili con articoli attivi. In modalità catalogo
`variant`, il termine filtra anche la singola variante, evitando di mostrare
gli altri colori quando si cerca un colore specifico.
I suggerimenti contengono il nome e l’URL della scheda. Selezionando un
suggerimento e inviando il form si apre quella scheda; modificando il testo,
l’invio torna alla ricerca nel catalogo. Canonical, Product/ItemList,
breadcrumb e dataLayer rimangono quelli della pagina di destinazione.

## Cambio colore e cronologia

I link dei colori includono gli slug delle opzioni correnti, anche per la
selezione iniziale. Quando taglia o materiale cambiano, i link si aggiornano.
La destinazione mantiene le opzioni compatibili tramite `OptionQuery`; valori
assenti vengono ignorati e combinazioni inesistenti usano la scelta predefinita.
Il cambio colore usa `location.replace`, mentre la modifica delle opzioni
usa `replaceState`: tutti i colori condividono una sola voce nella cronologia.
Indietro torna alla pagina da cui si è entrati nel prodotto; Avanti riapre
l’ultima variante visitata, senza attraversare i cambi colore.
Il clic sul colore già attivo non crea una voce duplicata.
Canonical e breadcrumb restano privi dei parametri opzione; schema.org e
tracking vengono generati dalla scheda della variante di destinazione.
