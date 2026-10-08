# Carrello e checkout

## Stato del flusso

Il modulo usa il contratto corrente di `wonder-image/gestionale`:

- il carrello è un `gst_orders` con `stage=cart`;
- prezzi, imposte e disponibilità vengono ricalcolati da `Cart` a ogni mutazione;
- la merce non viene prenotata finché `Checkout::place()` non trasforma il
  carrello in ordine;
- la creazione dell'ordine, la numerazione, la prenotazione e l'apertura del
  pagamento avvengono nella transazione del gestionale.

Il frontend del modulo non duplica queste regole. `CartSession` collega il
cookie anonimo al carrello e, dopo il login, lo unisce a quello della scheda
cliente. Un semplice GET su `/cart/` non crea né cookie né righe nel database.

## Componenti pubblici

- `view/components/cart/add.php`: form POST riutilizzabile per aggiungere un
  prodotto. Richiede `product_id`; accetta `quantity`, `label` e `continue`.
  CSRF e id del form GTM sono gestiti dal componente.
- `/cart/`: riepilogo, modifica quantità e rimozione, nel layout del negozio.
- `/checkout/`: una pagina sola con riepilogo a destra (vedi sotto).
- `/checkout/completed/`: conferma legata alla sessione che ha creato l'ordine.

I `FormField` ereditano il tema della pagina; nessuna view forza un renderer.

## La pagina del checkout

Il checkout sta in una pagina sola, nello stile di Shopify. Le rotte sono
`index` (GET `/checkout/`), `place` (POST, crea l'ordine), `summary` e `coupon`
(JSON, vedi sotto) e `completed`. Non ci sono più i passi Spedizione e
Pagamento.

Le sezioni, in ordine: check-out rapido, contatti (con «Esci»), consegna
(spedizione o ritiro, indirizzo, metodo o sede), pagamento, indirizzo di
fatturazione, fattura (privato o azienda, solo i campi necessari) e il bottone
che conferma. Il bottone dice «Paga ora» con un provider online e «Ordina» con
un metodo manuale.

Le regole stanno in `Checkout\CheckoutRules`:

- `post()` prepara il POST per il carrello: il telefono del contatto vale anche
  per il corriere, col ritiro si svuota l'indirizzo, con l'accesso l'email è
  quella dell'utente;
- `deliveryErrors()` dice cosa manca alla consegna;
- `billing()` costruisce la fatturazione: di partenza è «uguale all'indirizzo
  di spedizione»; col ritiro o con «indirizzo diverso» usa i campi compilati;
- `method()` accetta solo i metodi di pagamento offerti dall'anteprima.

Ogni metodo di pagamento mostra fino a tre loghi (poi «+N»), la commissione e,
quando è scelto, un pannello con le istruzioni o il rinvio al provider. I loghi
stanno in `resources/assets/payment-icons` (licenza MIT, file `LICENSE`).

`checkout.js` mostra gli errori sotto il campo (`aria-invalid`) e porta la
pagina al primo. Nome, cognome e telefono della fatturazione partono da quelli
della consegna finché il cliente non li cambia.

`StoreFont::style($area)` stampa il font scelto per una delle quattro aree
(`auth`, `account`, `checkout` e `cart`) nel riquadro «Negozio online» delle
Impostazioni di Set Up (`OnlineShopSettings`, colonne `font_*` del gestionale).
Si sceglie fra le righe visibili di `css_font`, salvate per `name`; vuoto vuol
dire «come il sito». Cambia solo le variabili del sito, perché la testa carica
già ogni font di `css_font`.

## Consegna e coupon

La scelta tra spedizione e ritiro, i metodi di spedizione con il loro costo, le
sedi di ritiro, i metodi di pagamento e il coupon arrivano dal gestionale
(`Checkout::preview`) attraverso due rotte JSON:

- `POST /checkout/summary/` ricalcola il riepilogo con quello che il cliente ha
  compilato fin lì; gli importi tornano già formattati dal server.
- `POST /checkout/coupon/` con `action=apply|remove` e `code`; risponde come
  `summary` e aggiunge `error` se il codice non vale.

`checkout.js` chiede il riepilogo dopo circa 300 ms dall'ultima modifica,
scarta le risposte arrivate in ritardo e scrive solo con `textContent`. La
prima anteprima la genera il server e la passa in `data-initial`. Gli errori
(419, 401, 409) sono JSON con un eventuale `redirect`. Il JS racconta a GTM
`begin_checkout` (al caricamento), `add_shipping_info` e `add_payment_info`.

Senza JavaScript il modulo si compila e si invia lo stesso; il coupon è un form
a parte che ricarica la pagina. Con `shipping` spenta la consegna non c'è, con
`coupons` spenta non c'è il coupon. `place` rifiuta la spedizione senza metodo
e il ritiro senza sede.

## Account e ospite

Il checkout richiede un account per default. Login, registrazione locale e
Google conservano il parametro interno `continue` e riportano al checkout; il
carrello ospite viene unito dopo l'autenticazione.

Gli ordini senza account si accendono nel riquadro «Negozio online» delle
Impostazioni di Set Up (`checkout_guest`, spento per default); come il resto di
quella pagina, in produzione si leggono soltanto. L'ospite vede «Accedi» nei Contatti e
scrive la sua email; l'invio è protetto da reCAPTCHA (action
`ecommerce_checkout`). Al «Ordina» `GuestCheckout` trova l'account con quella
email o lo crea senza password, collega il contatto e salva i consensi
(`registerLeadConsents` per un account che c'era già, i cui dati non cambiano).
Una scheda del commerciante con quella email e senza account si collega senza
cambiarne nome e telefono. Se l'account è un cliente del negozio attivo, senza
password e senza accesso con Google, l'email dell'ordine porta il link «Scegli
la password» (7 giorni, i link già mandati restano validi); scegliendola l'email
risulta verificata. Se l'account non si può creare (per esempio l'email di un
utente cancellato) l'errore va nel log e l'ordine nasce lo stesso, senza account.
La sessione resta da ospite. La conferma dice all'ospite che l'email è partita,
con lo stesso testo per tutti: non rivela se l'account ha già una password.

## Limiti intenzionali della prima fase

- I metodi manuali (`PaymentMethod::MANUAL_PROVIDERS`: `bank_transfer`, `cash`,
  `manual`) possono creare l'ordine. Gli altri provider vengono rifiutati prima
  della creazione: non si simulano pagamenti.
- Stripe richiede ancora adapter, sessione, callback idempotente e gestione
  esplicita di successo, annullamento e fallimento.
- L'ospite non va abilitato finché non sono definiti backend, consensi del
  checkout e collegamento successivo all'account.

## Verifica locale

```bash
php tests/run.php
curl -k -I https://ecommerce.test/cart/
curl -k -I https://ecommerce.test/checkout/
```

L'ultimo comando deve reindirizzare al login con `continue` quando la sessione
non appartiene a un cliente e il checkout ospite è disabilitato.
