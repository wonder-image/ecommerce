# Changelog

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/) e il
versionamento semantico.

## Unreleased

### BREAKING

- CSRF: moduli, viste e JS del negozio usano `Wonder\Http\Csrf` del core. Il
  campo è `_csrf` e ogni `fetch` manda anche l'header `X-WI-CSRF`. Il token di
  sessione non cambia, quindi nessuno perde la sessione. Gli override di viste
  e JS del sito che mandano ancora `csrf_token` ricevono 419: vanno aggiornati
  con `Csrf::field()` e l'header `X-WI-CSRF`. Resta `csrf_token` solo dove il
  form va a una route del core (logout del checkout, accesso federato,
  impersonificazione).
- La ricerca del catalogo dichiara `->csrf()`: senza l'header `X-WI-CSRF`
  risponde 403. Serve la lib wonder-image dalla #12, che manda l'header; con
  una lib precedente la ricerca smette di funzionare finché non si aggiorna
  con `npm install`.

### Modificato

- Limite agli ordini dell'ospite (`checkout.guest_order_limit`: ip `[10, 900]`,
  carrello `[5, 600]`, 0 spegne). I conteggi stanno nella nuova tabella
  `ecommerce_throttle`, che nasce con `php forge update`; se la tabella non
  risponde, l'ordine passa. L'IP viene da `ClientIp::resolve()`: dietro un
  proxy serve `APP_TRUST_PROXY`.
- Catalogo: `%` e `_` nella ricerca restano caratteri normali nel LIKE.
  `nome_desc` e `prezzo_desc` ordinano al contrario anche in modalità variant.

## 0.1.0 — non rilasciata

### Aggiunto

- API Google dichiarate al registro del core (`ModuleGoogleApis`, richiede
  wonder-image/app#97): reCAPTCHA Enterprise, Maps JavaScript, Places e
  Accedi con Google, quest'ultima obbligatoria solo con
  `auth.federated.google` acceso.

- Registrazione con i campi di `signup.fields` di
  wonder-image/customer-account (data di nascita, fatturazione, primo
  indirizzo di spedizione): `EcommerceAuthProfile` estende
  `CustomerAuthProfile` e tiene il cellulare obbligatorio come default.

- **Pronto per le IA e i motori**: `/llms.txt` e `/llms-full.txt` (negozio,
  categorie e prodotti in Markdown), `/shared/catalogo.json` (feed del catalogo) e
  `/shared/sitemap/catalogo.xml`, dichiarata in robots.txt tramite `module.json`.
  Open Graph `product` sulla pagina prodotto. Testi in
  `lang/{it,en}/discovery.json`.

- Area cliente, dettaglio dell'ordine: le informazioni tre per riga, la riga
  **Evasione**, il pagamento col solo nome del metodo (senza le icone delle
  carte) e **Scarica la proforma** (`account.orders.proforma`, il PDF
  `order_proforma` del gestionale, per gli ordini non in bozza né annullati).
  Richiede il gestionale con `OrderProforma`.

- **Abbonamenti** (E3, con `online_subscriptions` del gestionale):
  - pagina pubblica dei piani `/abbonamenti/` con JSON-LD `ItemList` di
    `Product` e `UnitPriceSpecification`, breadcrumb, `view_item_list` e
    `select_item`;
  - sottoscrizione con account obbligatorio, dati di fatturazione e codice
    sconto, poi Stripe Checkout (`begin_checkout`);
  - conferma che aspetta la notifica di Stripe e manda `purchase` una volta
    sola;
  - sezione **Abbonamenti** nell'area cliente: piano, stato, rinnovo,
    pagamenti, cambio piano, disdetta e aggiornamento della carta sul portale
    di Stripe.

- **Abbonamenti con più servizi** (G10b, con il gestionale dello stesso ramo):
  - dalla pagina dei piani si compone una selezione in sessione: quantità,
    personalizzazioni e servizio a cui si lega (`/abbonamenti/{ppr_…}/`);
    togliere una madre toglie le figlie; `add_to_cart` e `remove_from_cart`;
  - riepilogo con importi del primo addebito e coupon, poi un solo Stripe
    Checkout con una riga per servizio; `begin_checkout` e `purchase` con un
    item per servizio;
  - area cliente → Abbonamenti: i servizi di ogni abbonamento, **Aggiungi
    servizio** con la quota pro-rata mostrata prima della conferma, **Togli**
    a fine periodo (con le figlie) e **Cambia** subito, pro-rata. Il vecchio
    *Cambia piano* dell'abbonamento non c'è più;
  - al limite di abbonamenti la pagina dei piani porta ad Aggiungi servizio.

  Documentazione in `docs/subscriptions.md`.

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
  nascita e cambio email), indirizzi e fatturazione sono del core. Ordini, dettaglio
  dell'ordine e Coupon sono qui sotto.
- Ordini, dettaglio dell'ordine e Coupon nel pannello del cliente, come sezioni di
  `EcommerceAccountExtension` (le azioni `orders`, `orders.show` e `coupons` di
  `EcommerceAccountController`):
  - `account.orders` su `/account/ordini/`: gli ordini della scheda del cliente (in
    qualsiasi stato, i carrelli no), dal più recente, a dieci per pagina (`?pagina=N`,
    paginazione del core), con numero, data, totale e «Visualizza ›». Senza ordini c'è lo
    stato vuoto; con la scheda mancante non si cerca nulla (`customer_id = 0` sono gli
    ordini degli ospiti) ed esce l'errore sul contatto.
  - `account.orders.show` su `/account/ordini/{code}/`: informazioni (stato, consegna o
    ritiro, pagamento con i loghi, stato del pagamento, tracking e corriere), prodotti con
    immagine, personalizzazioni e scelte dei prodotti composti, riepilogo e schede
    dell'indirizzo di consegna (o della sede di ritiro) e della fatturazione, da
    `AccountOrder::present()`. L'ordine si cerca per `code`: carrelli, ordini di altri
    clienti o degli ospiti e codici che non esistono danno tutti 404. Se un metodo di
    pagamento o di spedizione o la sede non ci sono più, il dettaglio si apre con un
    ripiego.
  - `account.coupons` su `/account/coupon/`: i coupon riservati al cliente che può usare
    adesso (attivi, non scaduti né programmati; quelli con tutti gli usi spesi restano),
    con codice, valore e usi (`AccountCoupons::forCustomer()`, sopra `Coupons::reserved()`
    del gestionale). La pagina e la voce di menu ci sono solo con la funzionalità Coupon
    accesa (la route risponde 404 se è spenta).
  - Ordini e Coupon entrano nel menu subito dopo Panoramica; `account.navigation` li
    ritocca con le chiavi `orders` e `coupons`. Testi in `ecommerce.account.orders.*` e
    `ecommerce.account.coupons.*`.
- `CheckoutSummary::paymentIcons(array $keys)` è pubblico: dà i loghi di un metodo di
  pagamento (solo le chiavi note, con il loro svg). Lo usano il checkout, come prima, e il
  dettaglio dell'ordine.
- Checkout ospite (`checkout.guest_enabled`): `GuestCheckout` trova o crea l'account
  dall'email, collega contatto e consensi e manda nell'email dell'ordine il link per
  scegliere la password (non a chi entra con Google, senza annullare i link già
  mandati); la conferma dice che l'email è partita senza rivelare se l'account ha
  una password. La scheda del commerciante con la stessa email si collega senza
  cambiarne i dati.
- reCAPTCHA v3 nel check-out: sul `618` (`RecaptchaChallenge`, punteggio basso) il
  check-out risponde JSON 422 con `status: 618` e `checkout.js` apre la casella v2,
  rinnovandola con `wiRecaptchaReset` dopo un invio rifiutato. Il check-out rapido
  dell'ospite ha un riquadro autonomo (`data-wi-recaptcha-standalone`, azione
  `ecommerce_express`): il token si chiede a `start`, il segno in sessione lega la
  verifica al carrello rapido (`ExpressVerification`) e `place` rifiuta senza di esso;
  senza chiavi reCAPTCHA (`RecaptchaGuard::configured()`) l'express dell'ospite non
  compare. Richiede `wonder-image/app` `>= 2.4.0-beta.2` (`RecaptchaGuard::configured()`
  e `RecaptchaChallenge`; `composer.json` e `module.json` alzati a `^2.4.0-beta.2`) e
  `wonder-image/lib` `>= 2.1.2-alpha.27`; con l'app vecchia l'express dell'ospite non
  compare. Le celle del riquadro nel check-out e nell'express hanno la classe
  `wi-recaptcha-cell`: con la sola v3 restano nascoste finché sono vuote, senza spazio vuoto.
- Apple Pay, Google Pay e Link anche fra le scelte di pagamento del check-out, dopo la
  carta: chi chiede fattura o ritira in negozio (niente barra rapida) li può usare.
  Restano nascosti finché un Express Checkout Element invisibile non conferma che il
  browser li sa usare (`CheckoutRules::wallet`, `walletProbe`); quelli assenti
  spariscono. Scelto un wallet, «Paga» sparisce e nel pannello c'è il suo bottone: il
  clic controlla il modulo, la conferma fa nascere l'ordine e poi Stripe incassa. Un
  wallet salvato non torna spuntato. Richiede il gestionale con `StripeMethods::choices`
  che mette i wallet fra le scelte.

### Modificato

- **dataLayer senza `user`**: `DataLayer::script()` manda solo `page` e gli
  eventi ecommerce. `user.id` lo manda il core prima di GTM, cifrato con
  `APP_KEY`; il modulo mandava dopo l'id numerico in chiaro e sovrascriveva
  quello del core. La firma non cambia: `$userId` è accettato e ignorato.
  Serve il core con `Wonder\Frontend\Support\DataLayer`, altrimenti GTM non
  riceve più `user.id`.
- **Scheda e indirizzi solo da customer-account**: la scheda del cliente entrato
  la dà `CustomerContact::forUser()` (carrello, Panoramica, checkout ospite con
  account esistente); l'ospite nuovo la collega con
  `ContactAccount::linkKeepingIdentity()`; `EcommerceAuthProfile::afterUserSaved()`
  chiama il genitore e l'assegnazione degli ordini passa in
  `afterContactLinked()`; gli indirizzi del checkout si ricordano con
  `AccountAddresses::save()`. Cellulare e prefisso proposti al checkout con
  `AuthValidator::localPhone()`/`defaultPrefix()` del core.
  Visibile al cliente: un indirizzo arrivato incompleto dal pagamento rapido
  (Apple Pay, Google Pay, Link: senza civico o provincia) non entra più nella
  rubrica; l'ordine nasce lo stesso. Il prefisso si toglie dal numero salvato
  solo se questo comincia con `+`. Richiede `wonder-image/app` `fix/2026-10-1`
  (`AuthValidator::localPhone()`/`defaultPrefix()`, commit `f2c594ba`, non
  ancora in una release) e `wonder-image/customer-account` `fix/2026-10-1`
  (`afterContactLinked()`, `ContactAccount::linkKeepingIdentity()`,
  `AccountAddresses::save()`); con quelli vecchi il checkout va in errore.

- **Scheda cliente da `wonder-image/customer-account`**: collegamento utente↔scheda,
  fatturazione, indirizzi e data di nascita nell'area account arrivano dal nuovo
  modulo, dipendenza dichiarata in `module.json`; l'ecommerce tiene solo la parte
  commerciale (cellulare obbligatorio alla registrazione, ordini, checkout).
  Gli import di `Wonder\App\Models\Contacts\*` e
  `Wonder\Auth\Frontend\ContactAccount` passano a
  `Wonder\Plugin\CustomerAccount\…`. Abilitare `customer-account` in
  `modules.php` prima di gestionale ed ecommerce ed eseguire `forge update`.

- Contatti e indirizzi letti con `Contact` e `ContactAddress` di
  `Wonder\Plugin\CustomerAccount\Models` invece delle sottoclassi
  `Wonder\Plugin\Gestionale\Models\Contacts\*`: l'ecommerce non legge colonne
  del gestionale. `CustomerAccountExtension` la registra solo customer-account
  nelle proprie route; l'ecommerce registra la sua estensione dopo.
- Carrello, checkout, abbonamenti e impersonazione usano direttamente le classi
  del core: `Wonder\Auth\Frontend\{AuthSession,AuthValidator,UserAccountGateway}`
  e `Wonder\Http\SafeRedirect`. Gli errori di registrazione usano le chiavi
  `auth.validation.*` del core.

- Area cliente: ogni ordine dell'elenco è una riga di `frontend.account.row`
  come «Dati personali» (Ordine, Data, Totale, **Visualizza**), al posto della
  tabella `.wi-row-table`; la riga la costruisce `AccountOrder::row()`. In
  Panoramica l'ultimo ordine, con il link al dettaglio, o la riga «Non hai
  ancora effettuato ordini» (`overviewRows()`). Richiede l'app con
  `AccountOverview` e la fatturazione in «Dati personali».

- Vetrina: un'opzione è disponibile se ha pezzi nella sede da cui escono gli
  ordini, senza i lotti scaduti (`Levels::forSale()` del gestionale, G3). Con
  più sedi la merce dei negozi non rende disponibile un'opzione online. Con un
  gestionale che non ha ancora `Levels::forSale()` si sommano tutte le righe
  di giacenza, come prima. Disponibilità visibile e `offers.availability` del
  JSON-LD leggono lo stesso dato e restano allineate; breadcrumb e dataLayer
  non cambiano.

- Checkout in una pagina sola (via i passi Spedizione e Pagamento); carrello nel
  layout del negozio.
- Il default di `account.panel` è `\Wonder\Auth\Frontend\AccountPanel` (era
  `EcommerceAccountPanel`). La rotta del pannello controlla con `is_a` che la classe
  sia un `AccountPanel`.
- `account.navigation` ritocca il menu per chiave: `overview`, `personal`,
  `addresses`, `billing` del core e `orders` e `coupons` (`coupons` solo con la
  funzionalità Coupon accesa) dell'ecommerce; `false` toglie la voce, un array la
  ritocca o la aggiunge.
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
- Le facciate auth di compatibilità, che inoltravano al core:
  `Wonder\Plugin\Ecommerce\Frontend\Auth\{AuthController,AuthSession,AuthValidator,AuthValidationAlert,EcommerceUserAccountGateway}`
  e `Wonder\Plugin\Ecommerce\Support\SafeRedirect`; l'handler
  `http/frontend/auth.php` e i sette stub `view/pages/auth/*.php`, che nessuna
  rotta raggiungeva (le pagine auth sono quelle del core). Resta
  `EcommerceAuthProfile`. Di conseguenza `pages/auth` esce da `views.sealed` di
  `module.json`.
- Il blocco `ecommerce.auth.validation` di `lang/{it,en}/ecommerce.json`
  (titolo, riepilogo e dieci messaggi), identico a `auth.validation` del core.

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
  `password` sono ignorate; quelle valide sono `overview`, `personal`, `addresses`,
  `billing`, `orders` e `coupons`. Una vecchia chiave a `false` non fa più nulla. Una
  vecchia chiave con un array che ha `href` e `label` propri compare come voce nuova; con
  un `route` che non risolve più l'`href` resta vuoto e la voce sparisce. Lo stesso vale
  per una chiave ancora valida (`overview`, `billing`, `orders`…) con un `route` che non
  risolve più, per esempio `ecommerce.account.index`: l'`href` si svuota al posto di
  tenere quello del core e la voce sparisce dal menu.
- **`account.panel`.** `EcommerceAccountPanel` non esiste più. Un sito che lo nomina,
  o che nomina una classe che non estende `Wonder\Auth\Frontend\AccountPanel`, fa lanciare
  `LogicException('Invalid ecommerce account panel')` a `route.frontend.php`, e così
  nessuna rotta del frontend viene registrata (non solo quelle dell'account). Togliere
  la chiave o puntarla a una sottoclasse di `AccountPanel`. Una classe che *estende*
  `EcommerceAccountPanel` non arriva nemmeno a quel controllo: PHP dà un errore fatale per
  classe non trovata mentre la carica, prima che `is_a` possa lanciare la `LogicException`.
  Una sottoclasse di `AccountPanel` che sovrascrive il vecchio
  `navigation(object $user): array` deve adottare `navigation(object $user, string $active = ''): array`
  e il ritorno per chiave (partendo da `parent::navigation($user, $active)`): con la firma
  vecchia PHP dà un errore fatale di dichiarazione incompatibile.
- **Viste e componenti.** Le sostituzioni in `custom/view` delle viste e dei
  componenti rimossi non servono più: le viste del pannello sono sigillate.
- **Password.** `/account/password/` e la sua voce di menu non esistono più.
- **Database.** Dopo l'aggiornamento lanciare `php forge update`: aggiunge la colonna
  `birth_date` alla scheda contatto. Senza, «Dati personali» non riesce a salvare.
- **Facciate auth.** Un sito che importa una delle classi auth rimosse (vedi
  «Rimosso») passa alla classe omonima del core in `Wonder\Auth\Frontend`
  (`EcommerceUserAccountGateway` → `UserAccountGateway`, `SafeRedirect` →
  `Wonder\Http\SafeRedirect`) e alle chiavi `auth.validation.*`. I siti di
  riferimento non ne usano nessuna.
