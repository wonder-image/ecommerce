# Slot delle pagine sigillate

Carrello, checkout, area cliente e autenticazione sono view sigillate: il sito
non le sovrascrive. Per aggiungere markup (un banner, i loghi dei pagamenti, un
box) usa gli slot: punti fissi della view in cui il modulo include una partial
del sito.

Gli slot aggiungono solo markup. Campi, validazione e dati restano agli hook
(profilo auth, `AccountExtension`, config del modulo).

**I nomi degli slot e i dati che ricevono sono contratto pubblico.** Si possono
aggiungere slot o dati; rinominarli, spostarli in un'altra posizione o togliere
un dato è un cambiamento BREAKING e va nel CHANGELOG.

## Configurazione

Nel config del modulo del sito (`custom/config/modules/ecommerce.php`),
la chiave `slots` associa il nome dello slot alla partial:

```php
'slots' => [
    'cart.before' => 'slots.cart-banner',
    'checkout.after_submit' => 'slots.payment-logos',
    'account.before' => 'slots.account-box',
    'auth.after' => ['slots.auth-help', 'slots.auth-trust'],
],
```

Il valore è lo stesso che accetta `View::component()` del core:

- un nome di componente, con il punto come separatore: `slots.cart-banner` è
  `custom/view/components/frontend/slots/cart-banner.php`;
- un percorso assoluto a un file esistente;
- una lista dei due: le partial escono una dopo l'altra, nell'ordine dato.

Uno slot non configurato, o con valore vuoto, non stampa niente.

**Partial mancante: errore.** Se il file non esiste la pagina va in errore con
una `RuntimeException` che nomina lo slot e il componente
(`Slot ecommerce cart.before: Component non trovato: slots.cart-banner`). Un
refuso nel config si vede subito invece di sparire in silenzio.

Anche il nome dello slot va scritto giusto: un nome che non esiste in questo
elenco è ignorato dal config (nessuna view lo chiede).

## Dati della partial

Ogni partial riceve `$slot` (il nome dello slot) più i dati elencati sotto, come
variabili. Le variabili globali della view (`$SEO`, `$PATH`, …) restano quelle
del core.

- `$order`: array dell'ordine-carrello (`total`, `currency`, `coupon_code`, …).
- `$items`: righe del carrello già preparate da `CartPresenter::lines()`.
- `$currency`: codice ISO 4217 (`EUR`).
- `$guest`: `true` se il checkout è da ospite.
- `$result`: esito dell'ordine nella pagina di conferma (`order_number`,
  `processing`, `paid`, `instructions`, `email_sent`, `guest`).
- `$section`: voce attiva del menu dell'area cliente (`overview`, `personal`,
  `addresses`, `orders`, `coupons`, `subscriptions`, …). Le pagine interne
  hanno quella della loro voce: il dettaglio ordine è `orders`, i metodi di
  pagamento sono `personal`.
- `$user`: utente entrato (oggetto del core).
- `$surface`: pagina auth (`login`, `signup-request`, `password-recovery`, …).

## Elenco

| Slot | Posizione | Dati |
| --- | --- | --- |
| `cart.before` | Carrello, sopra il titolo (anche col carrello vuoto) | `order`, `items`, `currency` |
| `cart.after_items` | Carrello, in fondo al box dei prodotti | `order`, `items`, `currency` |
| `cart.after_shipping` | Carrello, nel riepilogo sotto la riga «Spedizione da …» (solo se c'è; il coupon via AJAX la conserva) | `order`, `items`, `currency` |
| `cart.after_summary` | Carrello, sotto riepilogo e coupon | `order`, `items`, `currency` |
| `cart.after` | Carrello, in fondo alla pagina (anche col carrello vuoto) | `order`, `items`, `currency` |
| `checkout.before` | Checkout, sopra il modulo e il riepilogo | `order`, `items`, `currency`, `guest` |
| `checkout.before_submit` | Checkout, sopra il bottone di pagamento (dentro il `<form>`) | `order`, `items`, `currency`, `guest` |
| `checkout.after_submit` | Checkout, sotto il bottone di pagamento (dentro il `<form>`) | `order`, `items`, `currency`, `guest` |
| `checkout.after` | Checkout, in fondo alla pagina | `order`, `items`, `currency`, `guest` |
| `checkout.completed.before` | Conferma d'ordine, sopra il box | `result` |
| `checkout.completed.after` | Conferma d'ordine, sotto il box | `result` |
| `account.before` | Area cliente, sopra il contenuto di ogni sezione | `section`, `user` |
| `account.after` | Area cliente, sotto il contenuto di ogni sezione | `section`, `user` |
| `auth.before` | Pagine auth, sopra il modulo (sotto titolo e testo) | `surface` |
| `auth.after` | Pagine auth, sotto il modulo | `surface` |

L'elenco in codice è `Frontend\Slots::NAMES`; un test controlla che coincida
con questa tabella e con gli slot usati nelle view.

Per un box solo in una pagina, la partial guarda i dati: per esempio
`account.before` con `$section === 'overview'`, o `auth.after` con
`$surface === 'login'`.

## Area cliente e pannello del sito

Gli slot `account.*` li stampa `EcommerceAccountPanel`, il pannello di default
(`account.panel`). Un sito che usa un proprio pannello deve estendere
`Wonder\Plugin\Ecommerce\Frontend\Account\EcommerceAccountPanel`: estendendo
direttamente quello del core gli slot `account.*` non escono.

Gli slot `auth.*` li stampa `EcommerceAuthProfile::layout()`; vale lo stesso
per un profilo del sito (`auth.profile`), che di serie ne è già sottoclasse.

## Cosa non mettere in una partial

Le pagine sigillate sono private (`NOINDEX`) e hanno già il loro `<h1>`, il
loro dataLayer e nessun dato strutturato:

- niente `<h1>`: usa `<h2>` o inferiori;
- niente JSON-LD;
- niente push al dataLayer con dati personali (email, nome, telefono,
  indirizzo, id dell'utente). Gli eventi ecommerce li manda già il modulo;
- dentro il `<form>` del checkout (`checkout.before_submit`,
  `checkout.after_submit`) niente campi né form annidati: i dati del checkout
  passano dagli hook.

Il markup della partial esce così com'è: i testi che arrivano dai dati vanno
passati da `e()`.
