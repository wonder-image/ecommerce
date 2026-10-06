---
paths:
  - "src/Frontend/**"
  - "http/frontend/**"
  - "view/pages/frontend/**"
  - "view/pages/auth/**"
  - "view/pages/account/**"
  - "view/pages/cart/**"
  - "view/pages/checkout/**"
  - "view/components/**"
  - "view/layout/frontend/**"
  - "config/routes/route.frontend.php"
  - "resources/assets/**"
  - "lang/**"
---

# Frontend: SEO, schema.org, breadcrumb e dataLayer GTM

Vale solo per il frontend del negozio (pagine pubbliche, carrello, checkout,
auth, area cliente, componenti e asset). Backend, modelli, resource, seeding ed
email restano fuori: lì queste regole non si applicano.

Il frontend di questo modulo ha quattro obiettivi che pesano quanto la
funzione stessa: SEO, dati strutturati schema.org, breadcrumb e dati inviati a
Google Tag Manager. Una pagina che funziona ma li trascura non è finita.

## Ragionamento obbligatorio

Prima di creare o modificare una pagina, un componente, una rotta o un
controller frontend, rispondi a queste domande e riporta le risposte nel piano
o nel riepilogo del lavoro, anche quando la risposta è «nessun impatto»:

1. **SEO** — la pagina va indicizzata? Titolo, descrizione, canonical e robots
   sono giusti? L'HTML è semantico, con un solo `<h1>`?
2. **schema.org** — quale entità rappresenta la pagina (Product, ItemList,
   nessuna)? Il JSON-LD coincide con ciò che l'utente vede?
3. **Breadcrumb** — qual è il percorso? Quello visibile, quello JSON-LD e la
   gerarchia degli URL raccontano la stessa cosa?
4. **dataLayer** — quali dati di pagina e quali eventi deve ricevere GTM? Con
   quali nomi e in quale momento?

Se la modifica cambia un prezzo, una disponibilità, un URL, un titolo o un
flusso (aggiunta al carrello, checkout, login), ricontrolla tutti e quattro i
punti: sono i casi in cui markup, dati strutturati e tracciamento si
disallineano in silenzio.

## SEO

- Ogni pagina imposta `$SEO->title`, `$SEO->description`, `$SEO->url`,
  `$SEO->breadcrumb` e `$SEO->robots` (vedi `CartController::seo()`). Il core
  li stampa in `app/view/components/frontend/layout/head.php`: canonical, meta,
  Open Graph, hreflang. Non duplicare quei tag nelle viste del modulo.
- Testi sempre da `lang/*/ecommerce.json`, in tutte le lingue. La descrizione
  viene troncata a 140 caratteri dal core: scrivila entro quel limite.
- `$SEO->url` è il canonical: assoluto, senza parametri di filtro, ordinamento
  o tracciamento. Elenchi paginati e filtrati vanno decisi caso per caso
  (canonical alla pagina base o autoreferenziale), mai lasciati al caso.
- Robots: pagine di catalogo `INDEX,FOLLOW`; auth `NOINDEX,FOLLOW`; carrello,
  checkout e area cliente `NOINDEX,NOFOLLOW`.
- Scheda prodotto e categorie: `$SEO->image` valorizzata, un solo `<h1>`,
  gerarchia dei titoli coerente, URL parlanti e stabili. Un URL che cambia
  richiede un redirect 301.
- Immagini con `alt` significativo, dimensioni dichiarate e i componenti media
  responsive del core; niente layout shift, niente JS bloccante: i Core Web
  Vitals fanno parte della SEO.
- Contenuto rilevante per i motori reso lato server, non iniettato via JS.
- Link interni con `<a href>` reali, non `onclick`.

## schema.org

- Solo JSON-LD, in `<script type="application/ld+json">`. Niente microdata.
- Genera il JSON con `json_encode(..., JSON_UNESCAPED_SLASHES |
  JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)` partendo da un array PHP. Mai
  concatenare stringhe.
- Scheda prodotto: `Product` con `name`, `description`, `image`, `sku`,
  `brand` se presente, e `offers` (`Offer` o `AggregateOffer` con varianti)
  con `price`, `priceCurrency`, `availability`, `url`. `aggregateRating` e
  `review` solo se le recensioni esistono e sono visibili in pagina.
- Elenchi e categorie: `ItemList` con `ListItem` che puntano alle schede.
- I dati strutturati devono coincidere con il contenuto visibile: stesso
  prezzo, stessa disponibilità, stessa valuta. Un dato non mostrato all'utente
  non va nel JSON-LD.
- Pagine private o `NOINDEX` (carrello, checkout, auth, area cliente): nessun
  dato strutturato.
- Una sola entità principale per pagina, URL assoluti.

## Breadcrumb

- Il `BreadcrumbList` lo genera sempre la funzione `breadcrumb()` di
  `wonder-image/app` (`app/function/other/schemaOrg.php`): il modulo non
  scrive mai un proprio JSON-LD di breadcrumb né una funzione equivalente.
- La via normale è valorizzare `$SEO->breadcrumb`, un array `[url => nome]` in
  ordine dalla home alla pagina corrente: l'head del core chiama
  `breadcrumb()` e stampa lo script. Chiamarla a mano nelle viste produrrebbe
  un doppione.
- `breadcrumb($list, false)` restituisce il solo oggetto, senza `<script>` e
  senza `@context`: serve quando il breadcrumb va annidato in un altro
  JSON-LD.
- Pagine pubbliche di catalogo: breadcrumb sempre valorizzato e sempre
  mostrato anche a video, con gli stessi nomi e lo stesso ordine del JSON-LD.
  Markup visibile: `<nav aria-label="Breadcrumb">` con lista ordinata, ultima
  voce con `aria-current="page"`.
- Pagine private: `$SEO->breadcrumb = []`, come già fanno carrello, checkout,
  auth e account. Il percorso visibile può restare.
- URL assoluti, nomi tradotti, ultimo elemento uguale al canonical.
- Attenzione: `breadcrumb()` del core compone il JSON a mano, senza escape. Un
  nome con virgolette o backslash produce JSON-LD non valido. Finché il core
  non usa `json_encode`, passa nomi già ripuliti e segnala il problema se lo
  incontri; la correzione va nel core (`app/function/other/schemaOrg.php`),
  non aggirata qui.

## dataLayer per Google Tag Manager

Ogni pagina frontend del modulo invia i propri dati a GTM. La forma di base:

```php
<script>
    window.dataLayer = window.dataLayer || [];

    window.dataLayer.push(<?=json_encode([
        'user' => ['id' => $userId], // null se ospite
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)?>);
</script>
```

- Inizializza sempre con `window.dataLayer = window.dataLayer || [];`: il push
  deve funzionare anche se GTM è spento o caricato dopo. Il contenitore GTM lo
  carica il core nell'head, quando è attivo: il modulo non lo include mai.
- Valori PHP solo tramite `json_encode`, mai `echo` diretto dentro lo script.
- Nessun dato personale: niente email, nome, telefono, indirizzo. Dell'utente
  passa solo l'identificativo interno, `null` per l'ospite.
- Dati di pagina (utente, tipo di pagina, lingua, valuta) in un solo punto
  condiviso, non ripetuti vista per vista. Se quel punto non esiste ancora per
  il caso che stai trattando, proponilo invece di copiare lo snippet.
- Eventi ecommerce con i nomi e la struttura GA4: `view_item_list`,
  `select_item`, `view_item`, `add_to_cart`, `remove_from_cart`, `view_cart`,
  `begin_checkout`, `add_shipping_info`, `add_payment_info`, `purchase`, più
  `login` e `sign_up`. Oggetto `ecommerce` con `currency`, `value` e `items`
  (`item_id`, `item_name`, `price`, `quantity`, `item_variant`,
  `item_category`...).
- Prima di ogni evento ecommerce: `dataLayer.push({ ecommerce: null });`.
- Prezzi come numeri, non stringhe formattate; valuta in ISO 4217.
- `purchase` parte una sola volta per ordine, con `transaction_id`: ricaricare
  la pagina di conferma non deve duplicarlo.
- Le azioni AJAX (mini-carrello, quantità, rimozione) fanno il push dal JS
  alla risposta positiva del server, usando i dati restituiti dal server, non
  quelli letti dal DOM.
- Gli identificativi coincidono ovunque: `item_id` nel dataLayer = `sku` o id
  nel JSON-LD = id usato dal carrello.
- I form mantengono gli `id` semantici già coperti dal test «i form espongono
  id semantici per Google Tag Manager» (`login`, `sign_up`, ...): un form
  nuovo ne riceve uno e il test va aggiornato.

## Verifica

- Aggiungi o aggiorna i test in `tests/` per metadati SEO, JSON-LD e push al
  dataLayer della parte toccata.
- Nel browser: JSON-LD valido (parse senza errori), `window.dataLayer` con gli
  eventi attesi, un solo `<h1>`, canonical e robots corretti.
- Aggiorna la documentazione in `docs/` quando cambia un evento, uno schema o
  una regola di indicizzazione.
