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
- `/checkout/`: contatto, fatturazione, spedizione, pagamento e riepilogo.
- `/checkout/completed/`: conferma legata alla sessione che ha creato l'ordine.

I `FormField` ereditano il tema della pagina; nessuna view forza un renderer.

## Account e ospite

Il checkout richiede un account per default. Login, registrazione locale e
Google conservano il parametro interno `continue` e riportano al checkout; il
carrello ospite viene unito dopo l'autenticazione.

`checkout.guest_enabled` è `false`. Il percorso ospite è predisposto e, quando
abilitato, verifica reCAPTCHA con action `ecommerce_checkout`. Prima di
attivarlo in produzione restano da aggiungere l'impostazione backend e la
decisione su collegamento/conversione dell'ordine a un account successivo.

## Limiti intenzionali della prima fase

- I metodi manuali online possono creare l'ordine. I provider diversi da
  `manual` vengono rifiutati prima della creazione: non si simulano pagamenti.
- Stripe richiede ancora adapter, sessione, callback idempotente e gestione
  esplicita di successo, annullamento e fallimento.
- La scelta e il costo della spedizione attendono le strutture G7 del
  gestionale; per ora il flusso usa `fulfillment_type=shipping` senza tariffa.
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
