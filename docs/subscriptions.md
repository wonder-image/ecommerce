# Abbonamenti (E3)

Gli abbonamenti online di G10: pagina dei piani, sottoscrizione con Stripe e
sezione dell'area cliente. Le regole e lo stato stanno nel gestionale
(`OnlineSubscriptions`, `SubscriptionEvents`). Il modulo mostra, raccoglie i dati
e manda il cliente su Stripe.

Tutto si accende con la funzionalità **`online_subscriptions`** del gestionale
(richiede *Vendita online* e *Abbonamenti*). Spenta, le rotte rispondono 404 e la
voce del menu dell'account non c'è.

## Pagine

| Rotta | Nome | Cosa fa |
|---|---|---|
| `GET /abbonamenti/` | `ecommerce.subscriptions.plans` | piani visibili e attivi, con i prezzi attivi |
| `GET /abbonamenti/{ppr_…}/` | `ecommerce.subscriptions.configure` | account obbligatorio; quantità, personalizzazioni e servizio a cui si collega |
| `POST /abbonamenti/{ppr_…}/` | `ecommerce.subscriptions.add` | aggiunge il servizio alla selezione e manda al checkout |
| `GET /abbonamenti/riepilogo/` | `ecommerce.subscriptions.summary` | 301 a `/checkout/`: i servizi si pagano lì, insieme ai prodotti (vedi `checkout-abbonamenti.md`) |
| `POST /abbonamenti/riepilogo/` | `ecommerce.subscriptions.start` | 303 a `/checkout/`: il vecchio modulo rimasto aperto |
| `POST /abbonamenti/riepilogo/togli/` | `ecommerce.subscriptions.remove` | toglie un servizio e quelli collegati a lui, poi torna al checkout |
| `GET /abbonamenti/conferma/?abbonamento=sub_…` | `ecommerce.subscriptions.confirm` | aspetta la notifica di Stripe e poi conferma |
| `GET /account/abbonamenti/` | `account.subscriptions` | sezione dell'area cliente |
| `GET /account/abbonamenti/{sub_…}/aggiungi/` | `account.subscriptions.add` | Aggiungi servizio: scelta del prezzo e configurazione (`?price=ppr_…`) |
| `POST /account/abbonamenti/{sub_…}/aggiungi/` | `account.subscriptions.add.preview` | anteprima della quota pro-rata (o della prova), niente cambia |
| `POST /account/abbonamenti/{sub_…}/aggiungi/conferma/` | `account.subscriptions.add.confirm` | il servizio entra subito, pro-rata |
| `POST /account/abbonamenti/{sub_…}/righe/{item}/togli/` | `account.subscriptions.item.remove` | Togli: a fine periodo, con le figlie |
| `POST /account/abbonamenti/{sub_…}/righe/{item}/cambia/` | `account.subscriptions.item.change` | Cambia: subito, pro-rata |
| `POST /account/abbonamenti/{sub_…}/disdici/` | `account.subscriptions.cancel` | disdetta |
| `POST /account/abbonamenti/{sub_…}/carta/` | `account.subscriptions.card` | portale di Stripe |

- I servizi scelti stanno in sessione (`SubscriptionSelection`), non nel carrello
  degli ordini, e appartengono al cliente che li ha scelti. Ogni aggiunta passa
  dal controllo del gestionale sulla selezione intera: piano visibile,
  personalizzazioni, servizio madre, stessi intervalli di addebito.
- Un servizio che si appoggia a un altro (piano *figlio*) chiede di scegliere la
  riga madre fra quelle della selezione; senza una madre adatta la pagina dice
  quali piani aggiungere prima. Togliere una madre toglie anche le figlie.
- Il codice sconto è quello del checkout: si prova sull'ordine e
  sull'abbonamento, vale dove è valido e l'errore esce solo se non vale da
  nessuna parte.
- Chi ha già il numero massimo di abbonamenti va nell'area cliente, dove può
  aggiungere servizi a quello che ha.
- La selezione si svuota alla conferma, non quando parte Stripe: chi torna
  indietro da Stripe ritrova i servizi scelti.
- La conferma non decide niente: legge lo stato che le notifiche hanno scritto.
- Finché lo stato è *in attesa*, la pagina si ricarica ogni 4 secondi.
- L'area cliente non mostra gli abbonamenti *in attesa*: sono pagamenti mai
  completati.

## Una tantum

Un prezzo può avere un importo una tantum (`one_time_price` del gestionale, zero
se non c'è). Le regole sono del gestionale: si paga una volta sola col primo
addebito, anche in prova (il canone parte alla fine della prova); non torna sui
rinnovi, sui cambi e sui servizi aggiunti dall'area cliente; ha l'aliquota della
riga e il coupon non lo sconta. Il `total` dell'abbonamento resta il solo canone.

- Piani: «+ 49,00 € una tantum» sotto il canone (`PlanCatalog::present()` dà
  `one_time`); la pagina del servizio ricorda che arriva col primo pagamento.
- Riepilogo: «Una tantum» sotto ogni servizio che ce l'ha, una riga «Una tantum»
  nel primo addebito e il totale `first_charge_total` di
  `OnlineSubscriptions::preview()`. In prova il primo addebito è la sola una
  tantum e sotto il totale c'è quanto si paga alla fine della prova
  (`SubscriptionView::firstCharge()`).
- Area cliente: Aggiungi e Cambia usano `OnlineSubscriptions::quote()`, che non
  conta l'una tantum, e non la mostrano.

## SEO, schema.org, breadcrumb, dataLayer

| Pagina | SEO | schema.org | Breadcrumb | dataLayer |
|---|---|---|---|---|
| Piani | `INDEX,FOLLOW`, canonical assoluto, un `<h1>`, contenuto lato server | `ItemList` di `Product` (uno per piano); per ogni prezzo un `Offer` con `UnitPriceSpecification` (`unitCode` `MON` o `ANN`, `billingDuration`, `billingIncrement`) e il prezzo pieno come `StrikethroughPrice` quando in pagina è barrato; l'una tantum come `PriceSpecification` senza unità né durata | Home › Abbonamenti, a video e dal core | pagina `subscription_plans`; `view_item_list` alla vista, `select_item` al clic su «Aggiungi»; `add_to_cart` e `remove_from_cart` dopo un'aggiunta o una rimozione |
| Servizio | `NOINDEX,NOFOLLOW` | nessuno | `[]`; a video Abbonamenti › servizio | pagina `subscription_configure` |
| Checkout | `NOINDEX,NOFOLLOW` (quello dei prodotti) | nessuno | `[]` | `begin_checkout`, `add_shipping_info` e `add_payment_info` con prodotti e servizi; `value` = totale di oggi (ordine più primo addebito: canone più una tantum, in prova solo l'una tantum) |
| Conferma | `NOINDEX,NOFOLLOW` | nessuno | `[]` | pagina `subscription_confirmation`; `purchase` una volta sola per abbonamento (la sessione ricorda i codici), con `transaction_id` = codice `sub_`, `value` = canone del primo periodo più una tantum (in prova solo l'una tantum, zero se non c'è) |
| Area cliente | del pannello | nessuno | del pannello | del pannello |

Gli identificativi sono gli stessi ovunque:

- `item_id` è il codice del prezzo (`ppr_…`), come lo `sku` dell'`Offer`;
- `item_category` vale `abbonamento`;
- `item_name` è «Piano — Prezzo»;
- `price` è il prezzo di un pezzo con i sovrapprezzi delle personalizzazioni,
  `quantity` la quantità del servizio.

`add_to_cart` e `remove_from_cart` si mettono da parte in sessione e partono
alla pagina successiva (piani o checkout), ognuno preceduto da
`{ecommerce: null}`.

I form hanno `id` semantici: `subscription_configure`, `subscription_change_{id riga}`, `subscription_pick_{sub_…}`, `subscription_add_pick`, `subscription_add` e `subscription_add_confirm`.

## Limiti nei testi

I limiti del piano (`max_progetti` → `20`) si mostrano con il nome tradotto in
`ecommerce.subscriptions.features.<chiave>` quando il sito lo scrive. Senza
traduzione si mostra la chiave resa leggibile («Max progetti»).
