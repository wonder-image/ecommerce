# Autenticazione ecommerce

## Responsabilità

- `wonder-image/app` possiede flussi/controller/view auth, registrazione delle
  route opt-in, profili estendibili, token, OIDC, sessioni, impersonificazione,
  layout account e token colore generici (`Wonder\Auth\Frontend`).
- `wonder-image/ecommerce` definisce `EcommerceAuthProfile`, il cliente, i
  consensi ecommerce, il cellulare obbligatorio e il collegamento a
  `contacts`; `EcommerceAccountPanel` personalizza la navigazione del core.
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
- `account.panel`: classe che estende `EcommerceAccountPanel` per navigazione,
  riepilogo e dati personali (campi, validazione e whitelist backend);
- `account.navigation`: override per chiave (`label`, `icon`, `route`, `href`)
  o `false` per nascondere una voce; nascondere non cambia le autorizzazioni;
- `auth.federated.google` e `auth.federated.apple`;
- `impersonation.enabled`, `actor_authorities` e `token_ttl`;
- `checkout.guest_enabled`, attualmente solo contratto per la fase checkout e
  disabilitato per default.

Il Client ID Google arriva dal sistema credenziali del core o dalla variabile
d'ambiente `GOOGLE_OAUTH_CLIENT_ID`. Nel backend è disponibile la scheda
"Google Auth Platform*". Google non accetta domini privati `.test`: usare
localhost consentito o un dominio pubblico di sviluppo autorizzato. Il flusso
popup Google Identity Services non usa Client Secret o redirect URI; vedere
`app/docs/app/servizi/configurazione/google-sign-in-oauth.md` nel core.

reCAPTCHA Enterprise usa le credenziali Google Cloud già gestite dal core:
site key, project ID e API key server. I metadati SEO di auth e account vengono
impostati dal controller del core per auth e dal modulo per account (titolo,
descrizione, canonical e breadcrumb vuoto). Il
core accetta inoltre `$SEO->robots`: auth usa `NOINDEX,FOLLOW`, mentre l'area
privata usa `NOINDEX,NOFOLLOW`; le altre pagine mantengono `INDEX,FOLLOW`.

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
I componenti sono `frontend.account.navigation` / `frontend.account.row`;
ordini e coupon li potranno riutilizzare quando saranno disponibili i flussi.

La fatturazione unica, le spedizioni multiple e i riferimenti esterni sono
modelli del core `Wonder\App\Models\Contacts` / `Models\System`.
I nomi SQL sono `contacts`, `contact_addresses`, `external_references`, con
migrazione conservativa dei vecchi nomi `gst_*`; i namespace del gestionale sono
subclass compatibili e conservano le sole estensioni commerciali.
Gli indirizzi usano `AccountAddressForm` per label tradotte, default paese e
prefisso, e griglia responsive con i Container del framework. I POST falliti
mantengono anche i campi svuotati e gli errori restano nell'alert di pagina.
Stripe rimane un'integrazione server-side da completare, non un link pubblico
costruito con un customer id. La guida del core è
`docs/app/concetti/utenti/auth-frontend.md`.

Gli indirizzi completi sono validati da `AccountAddressValidation` prima dei
write: campi omessi, vuoti e provincia incompatibile con il paese non vengono
salvati. Il destinatario è richiesto per la spedizione; l'etichetta è opzionale. I dati
fiscali non diventano obbligatori nella registrazione. I campi required usano
l'asterisco del renderer. Il submit resta disponibile per mostrare tutti gli
errori nell'alert di pagina, senza blocchi silenziosi o messaggi inline.

La lista spedizioni apre aggiunta/modifica in `AccountAddressModal`, basato su
`Modal::frontend()` e su `Button::opensModal()` secondo il tema della pagina.
La lib mantiene `modal()` per compatibilità e gestisce Esc, focus, Tab e campi
inert quando il dialogo è chiuso. I POST rimangono
protetti da CSRF e ownership; un errore torna alla lista con alert e conserva
i valori nel modal corrispondente. Le route editor rimangono come fallback
senza JavaScript. I modal sono passati a `page_modals` e resi dopo il `main`
dal layout del core, non dentro la colonna dei contenuti o un altro form.
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
