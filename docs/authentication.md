# Autenticazione ecommerce

## Responsabilità

- `wonder-image/app` possiede flussi/controller/view auth, registrazione delle
  route opt-in, profili estendibili, token, OIDC, sessioni, impersonificazione,
  layout account e token colore generici (`Wonder\Auth\Frontend`).
- `wonder-image/ecommerce` definisce `EcommerceAuthProfile`, il cliente, i
  consensi ecommerce, il cellulare obbligatorio e il collegamento a
  `contacts`; `EcommerceAccountExtension` aggiunge al pannello del core la
  riga e la pagina dei metodi di pagamento.
- Il sito configura credenziali, colori, testi consentiti dagli slot e flag del
  modulo. Non duplica controller o logica account.

## Registrazione

Il flusso locale è diviso in due passaggi:

1. nome, cognome, email, privacy e condizioni ecommerce;
2. dopo la verifica email: prefisso, cellulare obbligatorio e password.

La registrazione non raccoglie né valida fatturazione e non richiede il
regolamento di gioco. La scheda cliente viene collegata o creata senza quei
dati; fatturazione e indirizzi saranno raccolti nell'area cliente o checkout.

Google verifica l'ID token nel server. Per un nuovo account richiede un'email
verificata, ma non i checkbox del flusso locale: il collegamento alle policy
mostrato da Google non viene salvato come accettazione esplicita nel database.
Il completamento richiede il cellulare, ma non impone una password locale. Un
account già collegato viene autenticato direttamente quando il cellulare è
presente. Apple resta disattivato finché non verrà affrontato come fase
separata.

Il gestionale mostra per ogni cliente i metodi di accesso disponibili (email e
password, Google e in futuro Apple). Se un account possiede soltanto
un'identità federata, il tentativo di login con email e password viene fermato
dopo reCAPTCHA e l'alert di pagina indica il provider da usare.

I POST dei form pubblici tradizionali sono protetti da CSRF e reCAPTCHA
Enterprise con action distinte. La callback Google non dipende da reCAPTCHA:
resta protetta da CSRF, nonce e verifica server-side dell'ID token. Gli errori
sono mostrati nell'alert di pagina.

## Configurazione

`config/module.php` espone:

- `auth.completion_token_ttl` e `auth.password_reset_ttl`;
- `auth.profile`: classe che estende `EcommerceAuthProfile`; configurare
  `fields()`, `validate()`, `validationMessages()`, `userValues()` e
  `afterUserSaved()` insieme, non solo il campo visibile. La policy Google è
  separata (`validateFederated()` / `requiresCompletion()`).
- `account.panel`, `account.navigation` e `account.payment_methods`: il pannello
  account, vedi «Pannello account»;
- `auth.federated.google` e `auth.federated.apple`;
- `impersonation.enabled`, `actor_authorities` e `token_ttl`;
- `checkout.guest_enabled`, disabilitato per default: accende il checkout
  ospite, vedi `cart-checkout.md`, «Account e ospite».

Il Client ID Google arriva dal sistema credenziali del core o dalla variabile
d'ambiente `GOOGLE_OAUTH_CLIENT_ID`. Nel backend è disponibile la scheda
"Google Auth Platform*". Google non accetta domini privati `.test`: usare
localhost consentito o un dominio pubblico di sviluppo autorizzato. Il flusso
popup Google Identity Services non usa Client Secret o redirect URI; vedere
`app/docs/app/servizi/configurazione/google-sign-in-oauth.md` nel core.

reCAPTCHA Enterprise usa le credenziali Google Cloud già gestite dal core:
site key, project ID e API key server. I metadati SEO di auth e account vengono
impostati dal core, sia per auth sia per il pannello account (titolo,
descrizione, canonical e breadcrumb vuoto). Il
core accetta inoltre `$SEO->robots`: auth usa `NOINDEX,FOLLOW`, mentre l'area
privata usa `NOINDEX,NOFOLLOW`; le altre pagine mantengono `INDEX,FOLLOW`.

## Pannello account

Il pannello del cliente (`/account/`) è del core: l'ecommerce lo registra con
`AccountRoutes::register()` e lo estende con `EcommerceAccountExtension`; non ha
controller, viste o presentazione propri per le sezioni del core. Panoramica,
Dati personali (nome, data di nascita, cellulare, email con link di conferma,
password in un modal), Indirizzi e Fatturazione sono del core: vedi
`docs/app/concetti/utenti/auth-frontend.md`, «Pannello account».

| Route | URL | Di chi |
|---|---|---|
| `account.index` | `/account/` | core |
| `account.personal` | `/account/dati-personali/` | core |
| `account.addresses`, `.create`, `.edit`, `.delete` | `/account/indirizzi/…` | core |
| `account.billing` | `/account/fatturazione/` | core |
| `account.email.confirm` | `/account/email/conferma/` | core |
| `account.payment-methods` | `/account/metodi-di-pagamento/` | ecommerce |

Ordini e coupon (piano 2 del pannello) arriveranno come altre sezioni della stessa
estensione. `EcommerceAccountExtension` usa quattro ganci di `BaseAccountExtension`:

- `routes()`: `account.payment-methods`, nel gruppo privato del pannello;
- `navigation()`: applica `account.navigation` al menu del core;
- `personalRows()`: la riga «Metodi di pagamento» in «Dati personali»;
- `head()`: font e stile del negozio nell'head delle pagine del pannello.

`EcommerceAccountController` estende `AccountController`: risponde a
`payment-methods` e passa le altre azioni al core. La pagina sta dentro «Dati
personali», che resta la voce di menu attiva. Una nuova sezione segue lo stesso
schema: route in `routes()`, azione nel controller, voce in `navigation()`.

Il sito configura il pannello da `config/module.php`:

- `account.panel`: sottoclasse di `Wonder\Auth\Frontend\AccountPanel` per titolo,
  sezioni, authority e layout; l'hook che sovrascrive chiama `parent::`, altrimenti
  si perdono le estensioni. `route.frontend.php` controlla la classe con `is_a`:
  una classe che non è un `AccountPanel` fa lanciare `LogicException` e non registra
  nessuna route del frontend, non solo quelle dell'account;
- `account.navigation`: ritocchi al menu per chiave. Le chiavi sono quelle del core,
  `overview`, `personal`, `addresses` e `billing`. `false` nasconde la voce (non
  cambia le autorizzazioni); un array ne ritocca `label`, `icon`, `href` (o `route`,
  risolta in `href`) o aggiunge una voce nuova. Una voce senza `href` o etichetta
  non esce. Le chiavi del vecchio pannello (`profile`, `shipping`, `payment-methods`,
  `password`) sono ignorate;
- `account.payment_methods.enabled`: `false` per default. Spento, la riga «Metodi di
  pagamento» ha il bottone disabilitato con la nota «Presto disponibile»; acceso,
  il bottone porta a `account.payment-methods`. Il flag va acceso solo quando il
  gestionale espone il customer Stripe: la sessione del Billing Portal non parte
  ancora (vedi `TODO.md`, C5).

Non ci sono alias dei vecchi nomi `ecommerce.account.*`: la tabella dei rinomini e
la migrazione dei siti sono nel `CHANGELOG.md`.

## Tema

Il pannello usa i token del core con fallback ai colori globali:

- `--auth-bg-color`, `--auth-tx-color`;
- `--auth-form-bg-color`, `--auth-form-tx-color`;
- `--auth-form-border-color`.

Input, focus, pulsanti ed errori continuano a usare i token condivisi. I campi
chiamano `render()` senza specificare un tema: il renderer segue la pagina.

Il riferimento visivo è `elenajossifov-com/account`: nav laterale senza box
annidati, menu orizzontale su telefono, righe compatte con separatore, dati a
sinistra e azioni a destra. Non ne vengono copiati helper, query o CSS float.
I componenti sono `frontend.account.navigation` / `frontend.account.row`, del core;
ordini e coupon li riutilizzeranno quando saranno disponibili i flussi.

La fatturazione unica, le spedizioni multiple e i riferimenti esterni sono
modelli del core `Wonder\App\Models\Contacts` / `Models\System`.
I nomi SQL sono `contacts`, `contact_addresses`, `external_references`, con
migrazione conservativa dei vecchi nomi `gst_*`; i namespace del gestionale sono
subclass compatibili e conservano le sole estensioni commerciali.
Gli indirizzi usano `AccountAddressForm` del core per label tradotte, default paese e
prefisso, e griglia responsive con i Container del framework. I POST falliti
mantengono anche i campi svuotati.
Stripe rimane un'integrazione server-side da completare, non un link pubblico
costruito con un customer id. La guida del core è
`docs/app/concetti/utenti/auth-frontend.md`.

Gli indirizzi completi sono validati da `AccountAddressValidation` prima dei
write: campi omessi, vuoti e provincia incompatibile con il paese non vengono
salvati. Il destinatario è richiesto per la spedizione; l'etichetta è opzionale. I dati
fiscali non diventano obbligatori nella registrazione. I campi required usano
l'asterisco del renderer. Il «Salva» di ogni modal del pannello (classe
`wi-input-submit`) resta spento finché mancano i campi obbligatori; gli errori del
server tornano dentro lo stesso modal, riaperto con i valori inseriti.

La lista indirizzi del core apre aggiunta e modifica in un modal (`AccountModal`,
basato su `Modal::frontend()` e `Button::opensModal()` secondo il tema della pagina).
La lib mantiene `modal()` per compatibilità e gestisce Esc, focus, Tab e campi
inert quando il dialogo è chiuso. I POST sono protetti da CSRF e ownership. Le
route editor (`account.addresses.create` e `.edit`) rimangono come fallback senza
JavaScript. I modal sono passati alla pagina in `modals` e resi dopo il `main` dal
layout del core, non dentro la colonna dei contenuti o un altro form.
Il core offre Resource generiche `ContactResource` / `ContactAddressResource`
su `/backend/contacts/` e `/backend/contact-addresses/`, riservate agli
amministratori. Il gestionale conserva il suo pannello più completo e la
precedenza nei link per tabella. Vedi la documentazione contatti del core.

## Impersonificazione

La conferma parte dal backend ed emette un token monouso breve. Il core verifica
che l'attore sia backend e possieda una authority ammessa, che il soggetto sia
attivo e solo frontend, rigenera la sessione, conserva entrambe le identità e
registra gli eventi. Un banner sempre visibile permette il ritorno all'attore
con POST protetto da CSRF.

La funzionalità non sostituisce autorizzazioni e audit applicativi ed è
disattivabile eliminando le route tramite `impersonation.enabled=false`.

## Verifiche

`php tests/run.php` copre validazione, manifest, configurazione e integrazione
transazionale con il database di `ecommerce-site`. La callback reale Google e
la consegna email end-to-end richiedono credenziali/trasporto di test e restano
indicate in `TODO.md` finché non sono configurati.
