# Autenticazione ecommerce

## Responsabilità

- `wonder-image/app` fornisce token monouso, reset password, verifica OIDC,
  session login federato, impersonificazione e token colore generici.
- `wonder-image/ecommerce` definisce il cliente, i flussi, le route, le view
  sigillate, i consensi ecommerce e il collegamento a `gst_contacts`.
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
- `auth.federated.google` e `auth.federated.apple`;
- `impersonation.enabled`, `actor_authorities` e `token_ttl`;
- `checkout.guest_enabled`, attualmente solo contratto per la fase checkout e
  disabilitato per default.

Il Client ID Google arriva dal sistema credenziali del core o dalla variabile
d'ambiente `GOOGLE_OAUTH_CLIENT_ID`. Nel backend è disponibile la scheda
"Login con Google"; per l'ambiente locale l'applicazione web Google deve
autorizzare l'origine JavaScript `https://ecommerce.test`. Il flusso popup
Google Identity Services non usa Client Secret o redirect URI.

reCAPTCHA Enterprise usa le credenziali Google Cloud già gestite dal core:
site key, project ID e API key server. I metadati SEO di auth e account vengono
impostati dal modulo (titolo, descrizione, canonical e breadcrumb vuoto). Il
core accetta inoltre `$SEO->robots`: auth usa `NOINDEX,FOLLOW`, mentre l'area
privata usa `NOINDEX,NOFOLLOW`; le altre pagine mantengono `INDEX,FOLLOW`.

## Tema

Il pannello usa i token del core con fallback ai colori globali:

- `--auth-bg-color`, `--auth-tx-color`;
- `--auth-form-bg-color`, `--auth-form-tx-color`;
- `--auth-form-border-color`.

Input, focus, pulsanti ed errori continuano a usare i token condivisi. I campi
chiamano `render()` senza specificare un tema: il renderer segue la pagina.

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
