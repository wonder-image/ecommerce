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
| `GET /abbonamenti/{ppr_…}/` | `ecommerce.subscriptions.subscribe` | account obbligatorio; riepilogo, fatturazione, codice sconto |
| `POST /abbonamenti/{ppr_…}/` | `ecommerce.subscriptions.start` | crea l'abbonamento in attesa e manda a Stripe |
| `GET /abbonamenti/conferma/?abbonamento=sub_…` | `ecommerce.subscriptions.confirm` | aspetta la notifica di Stripe e poi conferma |
| `GET /account/abbonamenti/` | `account.subscriptions` | sezione dell'area cliente |
| `POST /account/abbonamenti/{sub_…}/cambia/` | `account.subscriptions.change` | cambio piano |
| `POST /account/abbonamenti/{sub_…}/disdici/` | `account.subscriptions.cancel` | disdetta |
| `POST /account/abbonamenti/{sub_…}/carta/` | `account.subscriptions.card` | portale di Stripe |

- La conferma non decide niente: legge lo stato che le notifiche hanno scritto.
- Finché lo stato è *in attesa*, la pagina si ricarica ogni 4 secondi.
- L'area cliente non mostra gli abbonamenti *in attesa*: sono pagamenti mai
  completati.

## SEO, schema.org, breadcrumb, dataLayer

| Pagina | SEO | schema.org | Breadcrumb | dataLayer |
|---|---|---|---|---|
| Piani | `INDEX,FOLLOW`, canonical assoluto, un `<h1>`, contenuto lato server | `ItemList` di `Product` (uno per piano); per ogni prezzo un `Offer` con `UnitPriceSpecification` (`unitCode` `MON` o `ANN`, `billingDuration`, `billingIncrement`) e il prezzo pieno come `StrikethroughPrice` quando in pagina è barrato | Home › Abbonamenti, a video e dal core | pagina `subscription_plans`; `view_item_list` alla vista, `select_item` al clic su «Scegli» |
| Sottoscrizione | `NOINDEX,NOFOLLOW` | nessuno | `[]`; a video Abbonamenti › piano | pagina `subscription_checkout`; `begin_checkout` |
| Conferma | `NOINDEX,NOFOLLOW` | nessuno | `[]` | pagina `subscription_confirmation`; `purchase` una volta sola per abbonamento (la sessione ricorda i codici), con `transaction_id` = codice `sub_`, `value` zero in prova |
| Area cliente | del pannello | nessuno | del pannello | del pannello |

Gli identificativi sono gli stessi ovunque:

- `item_id` è il codice del prezzo (`ppr_…`), come lo `sku` dell'`Offer`;
- `item_category` vale `abbonamento`;
- `item_name` è «Piano — Prezzo».

I form hanno `id` semantici: `subscription_checkout` e `subscription_change`.

## Limiti nei testi

I limiti del piano (`max_progetti` → `20`) si mostrano con il nome tradotto in
`ecommerce.subscriptions.features.<chiave>` quando il sito lo scrive. Senza
traduzione si mostra la chiave resa leggibile («Max progetti»).
