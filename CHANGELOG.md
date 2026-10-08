# Changelog

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/) e il
versionamento semantico.

## 0.1.0 — non rilasciata

### Aggiunto

- Scheletro del modulo: manifest, entrypoint `Ecommerce`, configurazione,
  permessi, rotte frontend e cartelle di lavoro.
- Test del modulo con harness proprio e `php tests/run.php`; CI su GitHub
  Actions con il core e il gestionale accanto al pacchetto.
- Pagina di controllo `/negozio/stato/`: dice se il modulo è attivo e se vede
  il gestionale. Sparisce quando arrivano le pagine vere.
- Pannello auth con registrazione in due passaggi, verifica email, login/logout,
  recupero password e accesso federato Google; Apple resta disattivato per una
  fase successiva.
- reCAPTCHA Enterprise con verifica server-side sui form auth pubblici
  tradizionali, senza bloccare l'accesso Google, e metadati SEO per le pagine
  auth/account.
- Cellulare obbligatorio e collegamento alla scheda cliente senza richiedere
  fatturazione o regolamento di gioco.
- Token colore auth dedicati, view auth sigillate e rendering coerente col tema
  della pagina.
- Impersonificazione cliente protetta da authority, token monouso, CSRF e audit.
- Checkout con consegna: spedizione o ritiro in sede, metodi di spedizione,
  sedi, coupon e riepilogo che si ricalcola mentre si compila (`checkout.js`,
  rotte `summary` e `coupon`); `place` accetta i pagamenti manuali
  `bank_transfer` e `cash` e passa consegna, metodo e sede al gestionale; eventi
  `begin_checkout`, `add_shipping_info` e `add_payment_info`.
- Loghi dei metodi di pagamento, pannello sotto il metodo scelto, errori sotto
  il campo, font del commerciante su accesso, account, checkout e carrello.
- Cambio o impostazione della password da un modal di «Dati personali» (nessuna
  pagina a parte); la logica è quella del core (`AccountPassword`).
- Account sul pannello del core (`AccountRoutes`): il modulo non ha più un pannello
  suo, lo estende con `EcommerceAccountExtension` (`routes()`, `navigation()`,
  `personalRows()` e `head()`) e con `EcommerceAccountController`, che estende
  `AccountController` e risponde a `payment-methods`. Aggiunge la pagina
  `account.payment-methods` (`/account/metodi-di-pagamento/`, dentro «Dati
  personali» come voce attiva), la riga «Metodi di pagamento» in «Dati personali»
  (spenta finché `account.payment_methods.enabled` è `false`) e il font e lo stile
  del negozio nell'head del pannello. Panoramica, dati personali (con data di
  nascita e cambio email), indirizzi e fatturazione sono del core. Ordini e coupon
  arrivano col piano 2.
- Checkout ospite (`checkout.guest_enabled`): `GuestCheckout` trova o crea l'account
  dall'email, collega contatto e consensi e manda nell'email dell'ordine il link per
  scegliere la password (non a chi entra con Google, senza annullare i link già
  mandati); la conferma dice che l'email è partita senza rivelare se l'account ha
  una password. La scheda del commerciante con la stessa email si collega senza
  cambiarne i dati.

### Modificato

- Checkout in una pagina sola (via i passi Spedizione e Pagamento); carrello nel
  layout del negozio.
- Il default di `account.panel` è `\Wonder\Auth\Frontend\AccountPanel` (era
  `EcommerceAccountPanel`). La rotta del pannello controlla con `is_a` che la classe
  sia un `AccountPanel`.
- `account.navigation` ritocca il menu del core per chiave: `overview`, `personal`,
  `addresses`, `billing` (`false` toglie la voce, un array la ritocca o la aggiunge).
  Le chiavi precedenti `profile`, `shipping`, `payment-methods` e `password` non
  esistono più.
- Gli URL dell'account sono in italiano, come quelli del core: `/account/dati-personali/`,
  `/account/fatturazione/`, `/account/indirizzi/…` e `/account/metodi-di-pagamento/`.

### Rimosso

- Rotte `ecommerce.checkout.shipping` e `ecommerce.checkout.payment`.
- Il pannello account del modulo: `EcommerceAccountPanel`, il controller
  `AccountController` e `AccountPresenter` di `src/Frontend/Account`, le viste
  `pages/account/{index,profile,shipping,address-form,password,message}.php`, il layout
  `ecommerce.account` e i componenti `account/navigation` e `account/row`. Restano
  `pages/account/payment-methods.php` e le traduzioni `ecommerce.account.payment_methods.*`.
- Le rotte `ecommerce.account.*` (tabella sotto), senza alias dei vecchi nomi né
  redirect dei vecchi URL.

### BREAKING — migrazione dei siti

Chi usa già il modulo deve ritoccare il sito prima di aggiornarlo. Servono anche il
core `app` con `AccountRoutes` e la lib `wonder-image` con i CSS e i JS nuovi del
pannello (`side-nav`, `row-table`, apertura dei modal dal server).

- **Nomi delle rotte.** Ogni `Route::url('ecommerce.account.…')` del sito (e dei
  suoi menu, per esempio `custom/config/navigation.php`) va cambiato; un nome
  vecchio non risolve più e `Route::url()` dà una stringa vuota.

  | Prima | Ora | URL |
  |---|---|---|
  | `ecommerce.account.index` | `account.index` | `/account/` |
  | `ecommerce.account.profile` | `account.personal` | `/account/dati-personali/` (era `/account/personal-data/`) |
  | `ecommerce.account.billing` | `account.billing` | `/account/fatturazione/` (era `/account/billing-address/`) |
  | `ecommerce.account.shipping` | `account.addresses` | `/account/indirizzi/` (era `/account/shipping-addresses/`) |
  | `ecommerce.account.shipping.create` | `account.addresses.create` | `/account/indirizzi/nuovo/` |
  | `ecommerce.account.shipping.edit` | `account.addresses.edit` | `/account/indirizzi/{id}/` |
  | `ecommerce.account.payment-methods` | `account.payment-methods` | `/account/metodi-di-pagamento/` (era `/account/payment-methods/`) |
  | `ecommerce.account.password` | tolta | il cambio password è un modal di `account.personal` |

  Nuove, senza equivalente prima: `account.addresses.delete` (solo POST) e
  `account.email.confirm`. Gli URL `/account/auth/…` non cambiano.
- **Menu del sito.** Una voce che punta a `ecommerce.account.index` passa a `account.index`.
- **`account.navigation`.** Le chiavi `profile`, `shipping`, `payment-methods` e
  `password` sono ignorate; quelle valide sono `overview`, `personal`, `addresses` e
  `billing`. Una vecchia chiave a `false` non fa più nulla. Una vecchia chiave con un
  array che ha `href` e `label` propri compare come voce nuova; con un `route` che non
  risolve più l'`href` resta vuoto e la voce sparisce.
- **`account.panel`.** `EcommerceAccountPanel` non esiste più. Un sito che lo nomina,
  o che nomina una classe che non estende `Wonder\Auth\Frontend\AccountPanel`, fa lanciare
  `LogicException('Invalid ecommerce account panel')` a `route.frontend.php`, e così
  nessuna rotta del frontend viene registrata (non solo quelle dell'account). Togliere
  la chiave o puntarla a una sottoclasse di `AccountPanel`.
- **Viste e componenti.** Le sostituzioni in `custom/view` delle viste e dei
  componenti rimossi non servono più: le viste del pannello sono sigillate.
- **Password.** `/account/password/` e la sua voce di menu non esistono più.
- **Database.** Dopo l'aggiornamento lanciare `php forge update`: aggiunge la colonna
  `birth_date` alla scheda contatto. Senza, «Dati personali» non riesce a salvare.
