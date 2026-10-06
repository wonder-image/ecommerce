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
- `/cart/`: riepilogo, modifica quantità e rimozione.
- `/checkout/`: contatto, fatturazione, consegna (spedizione o ritiro), pagamento, coupon e riepilogo.
- `/checkout/completed/`: conferma legata alla sessione che ha creato l'ordine.

I `FormField` ereditano il tema della pagina; nessuna view forza un renderer.

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

`checkout.guest_enabled` è `false`. Il percorso ospite è predisposto e, quando
abilitato, verifica reCAPTCHA con action `ecommerce_checkout`. Prima di
attivarlo in produzione restano da aggiungere l'impostazione backend e la
decisione su collegamento/conversione dell'ordine a un account successivo.

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
