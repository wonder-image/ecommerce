# Checkout unico: prodotti e abbonamenti

Fatto (2026-11-03). Decisioni dell'utente:

- prodotti e abbonamenti stanno nello stesso acquisto, con **un solo pagamento**;
- la carta si inserisce **dentro la pagina** (Payment Element), come per i prodotti.

Niente copia del checkout: `/checkout/`, `CheckoutController` e
`view/pages/checkout/index.php` servono tutti e due.

## Cosa c'è oggi (verificato sul gestionale 4329b46)

- Prodotti: l'ordine in bozza (`CartSession`) → `CheckoutPlacement::place()` →
  `OnlinePayments::start()` crea un PaymentIntent con `order_id` nei metadati;
  `payment_intent.succeeded` registra l'incasso sull'ordine.
- Abbonamenti: `SubscriptionSelection` in sessione → `OnlineSubscriptions::start()`
  scrive l'abbonamento `pending` e apre una Checkout Session di Stripe
  (`mode: subscription`, `billing_mode: flexible`, l'una tantum come voce
  senza ricorrenza); `invoice.paid` registra **tutto l'importo della fattura**
  come pagamento dell'abbonamento.
- `PaymentEvents` scarta i `payment_intent.*` senza `order_id`: quelli delle
  fatture li registra `invoice.paid`.
- stripe-php 19: la fattura non ha più `payment_intent`; il client secret del
  primo addebito sta in `latest_invoice.confirmation_secret`.

## Il pagamento unico

Con almeno un abbonamento nel carrello si crea su Stripe una Subscription con
`payment_behavior: default_incomplete` e
`expand: [latest_invoice.confirmation_secret, pending_setup_intent]`:

- righe ricorrenti come oggi (`StripeCatalog::priceFor`, aliquote, coupon);
- l'una tantum dei servizi e **le righe dell'ordine** (prodotti, spedizione,
  commissioni, sconto dell'ordine già applicato) in `add_invoice_items` con
  `price_data`, come fa già l'una tantum: finiscono solo nella prima fattura;
- metadati dell'abbonamento: `subscription_id`, `subscription_code`,
  `trial_days`, e **`order_id`** quando c'è un ordine.

Il client secret va al Payment Element:

- prima fattura con importo → `confirmation_secret` → `stripe.confirmPayment`;
- prima fattura a zero (prova, niente una tantum, niente prodotti) →
  `pending_setup_intent` → `stripe.confirmSetup`. checkout.js ha già il giro
  di `elements.submit()` e `confirmParams`: si sceglie solo il metodo.

Senza abbonamenti nel carrello il checkout resta esattamente com'è (PaymentIntent).

## Gestionale (PR separata in wonder-image/gestionale)

1. `OnlineSubscriptions::start()` si divide in due, senza copie:
   `create()` scrive abbonamento e righe `pending` (oggi dentro la
   transazione) e `open()` apre il pagamento. Il Checkout Session resta per
   chi lo usa ancora; il nuovo `startInPage(..., ?int $orderId)` usa
   `default_incomplete` e restituisce `{subscription_id, code, client_secret, intent: payment|setup}`.
2. `invoice.paid` con `order_id` nei metadati divide l'importo:
   la parte dell'ordine (la somma delle sue `add_invoice_items`, salvata in
   `external_references` alla creazione) va a `OnlinePayments::succeeded()`
   sull'ordine con riferimento `invoice_id:order`; il resto è il pagamento
   dell'abbonamento come oggi. Idempotente sul riferimento.
3. `invoice.payment_failed` sulla prima fattura con ordine → l'ordine torna
   pagabile come per un PaymentIntent rifiutato.
4. Test in `tests/` del gestionale su divisione degli importi, idempotenza,
   prima fattura a zero.

## Modulo ecommerce (questo repo, branch `claude/checkout-abbonamenti`)

- **Carrello**: la selezione degli abbonamenti resta `SubscriptionSelection`
  (le righe ordine non sanno di prova, ricorrenza e righe figlie: `OrderItem::TYPES`
  non ha un tipo abbonamento). Il checkout mostra le due liste insieme; la
  pagina `/carrello/` mostra solo i prodotti e oggi non c'è un pulsante per
  togliere un servizio (la rotta `POST /abbonamenti/riepilogo/togli/` resta,
  senza interfaccia). Anche il mini-carrello conta solo i prodotti. `/abbonamenti/{price}/`
  aggiunge e manda al checkout; `/abbonamenti/riepilogo/` fa 301 a `/checkout/`.
- **CheckoutController::index**: con abbonamenti nel carrello login
  obbligatorio (niente ospite, niente express checkout); senza prodotti la
  sezione Consegna sparisce (restano nome e telefono dentro Fatturazione);
  metodi di pagamento: solo la carta di Stripe (con Link), filtrata da
  `CheckoutSubscriptions::paymentOptions()`. Klarna, PayPal, Satispay, i
  wallet e i metodi manuali spariscono perché non fanno addebiti ricorrenti.
- **Riepilogo laterale** (`components/checkout/aside.php`, `lines.php`,
  `totals.php`): la riga abbonamento ha il nome del piano (senza il
  periodo) e sotto il prezzo col periodo, «9,90 €/Mese»
  (`SubscriptionView::per()`), poi prova, vincolo e una tantum. Badge della
  quantità e «cad.» solo con quantità maggiore di 1. Totale «Totale di oggi»
  = ordine + primo addebito; sotto, come Stripe Checkout, «Poi 9,90 €/Mese»
  o con la prova «Poi 9,90 €/Mese dal 18/11/2026»
  (`CheckoutSubscriptions::then()`, chiavi `subscriptions.per.*`,
  `subscriptions.summary.then` e `then_trial`).
- **Coupon**: un solo campo. Il codice si prova sull'ordine e
  sull'abbonamento; vale dove è valido, l'errore esce solo se non vale da
  nessuna parte.
- **place()**: valida come oggi, piazza l'ordine (se ci sono prodotti),
  chiama `startInPage()` e risponde a checkout.js con il client secret.
  Il ritorno è la conferma dell'abbonamento (`/abbonamenti/conferma/`), che
  con l'ordine dice anche il suo numero; la selezione si svuota alla conferma.
  Il vecchio POST del riepilogo fa 303 al checkout.

## I quattro punti (AGENTS.md)

1. **SEO**: checkout e completato restano `NOINDEX,NOFOLLOW`, un `<h1>`;
   `/abbonamenti/riepilogo/` fa 301 a `/checkout/`.
2. **schema.org**: nessun JSON-LD (pagine private).
3. **Breadcrumb**: `$SEO->breadcrumb = []`; nessun percorso visibile nuovo.
4. **dataLayer**: `begin_checkout`, `add_payment_info` e `purchase` con
   **tutti** gli item: prodotti (`item_id` = sku) e servizi (`item_id` = codice
   prezzo, come `SubscriptionTracking`); `value` = quanto si paga oggi;
   `purchase` una volta sola, alla conferma dell'abbonamento, con
   `transaction_id` = codice abbonamento: con l'ordine il `value` e gli item
   comprendono i prodotti (`SubscriptionTracking::withOrder`). `ecommerce: null` prima di ogni evento;
   `user.id` lo manda il core.

## Ordine dei lavori

1. PR gestionale (punti 1-4), provata dal sito puntando composer al branch.
2. Modulo: carrello e riepilogo misti, poi il controller, poi checkout.js.
3. Prova nel browser su ecommerce.test con carta di test, con e senza prova,
   solo abbonamento, solo prodotti, misto.
4. PR #54 (#21) resta separata: questo branch parte da lei.
