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
  prodotto. Richiede `product_id`; accetta `quantity`, `label`, `continue`,
  `stepper` (−/+), `fields` e `id` (vuoto se il form si ripete, come nelle card).
  Il componente scrive il campo CSRF `_csrf` e l'id del form GTM.
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
Impostazioni di Set Up (`OnlineShopSettings`, colonne `font_<area>_id` del
gestionale, chiavi esterne verso `css_font`). Si sceglie fra le righe visibili
e non cancellate di `css_font`, salvate per `id`; NULL vuol dire «come il
sito», anche quando la riga sparisce. Cambia solo le variabili del sito, perché
la testa carica già ogni font di `css_font`.

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
`ecommerce_checkout`). Al «Ordina» `GuestCheckout` cerca l'account con quella
email.

- Se l'account ha una password, un accesso federato (Google) o non è un cliente
  del negozio (area frontend con ruolo client), l'ordine del checkout normale si ferma (`GuestAccountExists`) e l'ospite viene
  invitato ad accedere o a recuperare la password. L'ordine non si aggancia più
  a quell'account e il contatto non viene collegato.
- Se l'account è un cliente senza password e senza accesso federato (per
  esempio nato da un ordine da ospite), l'ordine si aggancia come prima, anche
  se il cliente è disattivato: il contatto si collega, i consensi passano da
  `registerLeadConsents` e i dati dell'account non cambiano. L'email
  dell'ordine porta il link «Scegli la password» (7 giorni, i link già mandati
  restano validi) solo se il cliente è attivo; scegliendola l'email risulta
  verificata.
- Se l'account non c'è, `GuestCheckout` lo crea senza password, collega il
  contatto e salva i consensi. Una scheda del commerciante con quella email e
  senza account si collega senza cambiarne nome e telefono. Se l'account non si
  può creare (per esempio l'email di un utente cancellato) l'errore va nel log
  e l'ordine nasce lo stesso, senza account.

La sessione resta da ospite. La conferma dice all'ospite che l'email è partita,
con lo stesso testo per tutti.

Il blocco risponde così:

- col JavaScript, 409 con `error: account_exists` e il messaggio. Non c'è
  `redirect`: `checkout.js` mostra il messaggio nell'alert e lascia il modulo
  com'è;
- senza JavaScript, un messaggio flash e il ritorno al checkout con i dati già
  scritti;
- il check-out rapido non si ferma: vedi sotto. `ExpressController::place`
  intercetta ancora `GuestAccountExists` e risponde con lo stesso 409, ma solo
  come difesa.

Dal check-out rapido (`GuestCheckout::account(..., express: true)`) l'email
arriva dal wallet e il pagamento non è ancora partito: `ExpressController::place`
crea il PaymentIntent dopo `CheckoutPlacement::place`. Con l'email di un account
con password, con accesso federato o non cliente l'ordine nasce senza account
(`user_id` e `customer_id` a 0), senza contatto e senza link per la password.
L'argine contro chi usa l'email di un altro è reCAPTCHA su `start` (action
`ecommerce_express`) più i limiti di `checkout.guest_order_limit`; il limite di
`guest_exists` non conta. Un ordine rapido mai pagato resta `awaiting_payment`:
nascosto dall'elenco degli ordini dell'account, ma agganciato all'account come
gli altri. I consensi chiesti si tracciano sull'email (`registerLeadConsents`).
Il registro degli accessi scrive `guest_checkout_existing_unlinked` con l'id
dell'account e `email_hash`, mai l'email.

L'ordine entra nell'account dopo, con `OrderClaim::run()`. Si chiama solo
sulle pagine che mostrano ordini: nell'area cliente (`EcommerceAccountController`)
`index`, `orders` e `orders.show`; nel checkout `index`, `completed` e `return`.
Non sulle azioni (`summary`, `coupon`, `place`, abbonamenti...). Senza segno in
sessione: a ogni apertura riprova, così si aggancia anche un ordine fatto da un
altro dispositivo. Agisce solo per un account con ruolo cliente nel frontend
(area frontend e authority `client`, anche se ha pure il backend, come
`CartSession::authenticated()`), non cancellato, entrato, con l'email verificata
(`isUserEmailVerified`) e con una scheda cliente. Poi `OrderOwner::claim` del
gestionale prende gli ordini e i preventivi con quell'email e senza utente, mai
i carrelli, come alla registrazione e alla verifica. Un cliente non verificato
li ritrova dopo aver verificato l'email, alla prima pagina successiva. Un errore
va nel registro (`ecommerce.account.order_claim`) e non ferma la pagina. Serve
il gestionale con la correzione di `OrderOwner::claim` che lascia i carrelli:
prima un carrello lasciato da un ospite con l'email di un altro finiva sul suo
account.

Rischio accettato: l'email del wallet non è verificata da noi. Chi conosce
l'email di un altro può far finire un ordine rapido, pagato da lui, nello
storico di quella persona, che ne vedrà indirizzo e righe. Chi ordina non
acquisisce accesso all'account.

Sotto l'email non compaiono link: per accedere c'è «Accedi» accanto a
«Contatti», che porta al login con `continue` verso il checkout; dal login si
recupera la password.

Il testo è uguale per password, Google e account non cliente, quindi non dice
quale metodo di accesso ha l'account. Dice però che l'email ha un account:
per frenare chi prova molte email, il blocco conta i tentativi in
`gst_throttles` (bucket `guest_exists`: 3 ogni 15 minuti per hash
dell'email, 10 ogni ora per IP). Oltre il limite l'ospite vede il generico
`too_many_orders`. Ogni blocco scrive nel registro degli accessi del core
l'evento `guest_checkout_existing` con l'id dell'account, `email_hash`
(sha256) e `limited`; l'email in chiaro non viene salvata. L'invio resta
protetto da reCAPTCHA.

Gli ordini dell'ospite hanno un limite, in `checkout.guest_order_limit` della
config del modulo: `ip` vale `[10, 900]` (10 ordini ogni 15 minuti per
indirizzo) e `cart` vale `[5, 600]` (5 ogni 10 minuti per carrello). Con `0` al
posto della coppia quel limite si spegne. Superato il limite, l'ospite vede
`ecommerce.checkout.errors.too_many_orders`. I conteggi stanno nella tabella
`gst_throttles` del gestionale (Model `System\Throttle`, classe
`Wonder\Plugin\Gestionale\Support\Throttle`), che nasce con
`php forge update`. Ogni tentativo è un solo UPSERT, quindi due richieste
insieme non superano il limite. Se la tabella non risponde,
l'ordine passa e l'errore va nel registro (`gestionale.throttle`). L'indirizzo
è quello di `ClientIp::resolve()` del core: dietro un proxy o un CDN serve
`APP_TRUST_PROXY`, altrimenti tutti i clienti hanno l'IP del proxy.

Limite di quanto sopra: anche oltre il limite di `guest_exists` la differenza
resta osservabile. Un'email con un account riceve `too_many_orders`, una
nuova crea l'ordine (e un account senza password). Il limite per email non
frena chi prova molte email diverse: il freno vero all'enumerazione è
`checkout.guest_order_limit` per IP, insieme a reCAPTCHA. Non spegnere quel
limite (`0`) senza reCAPTCHA attivo.

## CSRF

Moduli e richieste del negozio usano `Wonder\Http\Csrf` del core.

- Le view scrivono `Csrf::field()`, cioè il campo `_csrf`.
- `checkout.js` e `mini-cart.js` mandano lo stesso token anche nell'header
  `X-WI-CSRF` di ogni `fetch`. Il token viene dal meta `wi-csrf` del layout,
  poi dal campo `_csrf` di un modulo della pagina, infine dal valore passato
  dal server (Express).
- I controller verificano con `Csrf::verify()`.
- Fa eccezione il form `checkout-logout` del checkout: va al logout del core,
  che verifica ancora `csrf_token`, quindi scrive `Csrf::field('csrf_token')`.

Il token di sessione è lo stesso di prima, quindi nessuno perde la sessione.
Con un token non valido (pagina scaduta) i form HTML tornano alla pagina di
partenza con l'avviso 929; checkout e abbonamenti ripropongono i valori
scritti. I `fetch` ricevono 419 in JSON.

Se il 929 compare a ogni invio, anche dopo aver ricaricato la pagina, il
modulo non manda `_csrf`: di solito è un override del sito con il vecchio
campo `csrf_token`. Va rigenerato con `Csrf::field()`.

La ricerca del catalogo (`POST`, route `catalog.products.search`) dichiara
`->csrf()`. Senza un token valido nell'header `X-WI-CSRF` il guard del core
risponde 403, non 419. L'header lo manda la lib wonder-image dalla #12
(`form/list.js`): un sito con una lib precedente deve aggiornarla con
`npm install`, altrimenti la ricerca risponde 403.

## Pagamento online (Stripe)

- Il «Paga» crea l'ordine una volta sola, **«In pagamento»**
  (`awaiting_payment`): senza numero, fuori dalla lista del commerciante e
  dall'area cliente. Diventa «In attesa», col numero e l'email «ricevuto», solo
  quando Stripe incassa o mette il pagamento in elaborazione (SEPA). Dopo un
  rifiuto (carta, 3DS, metodo a reindirizzamento abbandonato) l'ordine resta e
  il cliente riprova sullo stesso intento, dal checkout o da «Paga ora».
- Il checkout resta sempre modificabile: non c'è «Modifica l'ordine». Il primo
  cambio nel checkout (`checkout.js`, `reopen()` una volta, i campi si
  sbloccano), il carrello, le sue righe e il coupon riaprono da soli l'ordine
  in pagamento con `OnlinePayment::reopen` (`Lifecycle::reopen` del
  gestionale, stessa riga; un carrello nato nel frattempo si unisce). Prima
  annulla l'intento: se il pagamento è partito (riuscito o in elaborazione) si
  va al ritorno, se Stripe non annulla per altri motivi l'ordine resta e il
  cliente vede l'errore. `CheckoutController::index` non rimanda più a «Paga
  ora».
- Un nuovo «Paga» sopra un ordine in pagamento lo abbandona
  (`dropPending()` → `Lifecycle::abandon`, stato `abandoned`, nessuna email):
  il commerciante lo vede solo col filtro «Pagamenti non riusciti».
- Ogni rifiuto (carta rifiutata, 3DS fallito) resta nella storia del pagamento
  dell'ordine anche senza webhook: dopo un errore di `confirmPayment` il JS fa
  un POST a `/checkout/declined/` (`ecommerce.checkout.declined`, CSRF, 20
  chiamate ogni 10 minuti per ordine). L'ordine viene dalla sessione e lo stato
  lo legge il server da Stripe: `settle` registra il rifiuto con
  `OnlinePayments::fail` una volta per tentativo, col motivo della banca. Gli
  errori di validazione del modulo non chiamano il server.
- Con il pagamento in elaborazione la pagina di ritorno mostra il blocco
  «pagamento in corso»; la conferma regge un ordine ancora senza numero.
- Pagato non vuol dire confermato: l'ordine resta «In attesa» con pagamento
  «pagato» e lo conferma il commerciante dal backend, dopo aver controllato la
  merce. La pagina «ordine completato» lo dice al cliente.

## Limiti intenzionali della prima fase

- I metodi manuali (`PaymentMethod::MANUAL_PROVIDERS`: `bank_transfer`, `cash`,
  `manual`) possono creare l'ordine. Gli altri provider vengono rifiutati prima
  della creazione: non si simulano pagamenti.
- L'ospite non va abilitato finché non sono definiti backend, consensi del
  checkout e collegamento successivo all'account.
- Oggi il modulo non riceve file. Un nuovo ingresso di file (`$_FILES` o
  `type="file"`) deve passare da `UploadValidator::validate()` del core: il
  test `UploadGuardTest` fallisce se manca.

## Verifica locale

```bash
php tests/run.php
curl -k -I https://ecommerce.test/cart/
curl -k -I https://ecommerce.test/checkout/
```

L'ultimo comando deve reindirizzare al login con `continue` quando la sessione
non appartiene a un cliente e il checkout ospite è disabilitato.
