# Scheda prodotto

La route pubblica `ecommerce.catalog.product` risponde a
`/prodotto/{slug}/`. Accetta lo slug di una variante visibile oppure, come
ripiego, quello del modello e legge i dati dalle tabelle catalogo di
`wonder-image/gestionale`.

La pagina usa `ProductCatalog` per assemblare modello, variante, opzioni,
immagini, marchio, categoria principale e disponibilita; `ProductDetail`
normalizza una sola volta i dati usati da HTML, schema.org e tracking.

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
