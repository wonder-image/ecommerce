# wonder-image/ecommerce — TODO

Stato operativo del modulo. In questo progetto di prova non viene introdotto
un `PRODUCT.md`: decisioni e prove restano qui e nella documentazione tecnica.

Stati: `[ ]` da fare · `[~]` in corso · `[x]` completato. Un'attività è chiusa
solo quando ha una prova automatica o una verifica descritta.

## Decisioni confermate

- Registrazione in due passaggi: identità e consensi ecommerce, verifica email,
  poi cellulare obbligatorio e password.
- Google è il primo flusso federato attivo. Apple resta predisposto nel core ma
  verrà integrato in una fase successiva. Per un account federato il cellulare
  è obbligatorio, la password locale no.
- Fatturazione e regolamento di gioco non appartengono alla registrazione.
- Checkout ospite previsto, ma disabilitato per default e attivabile soltanto da
  configurazione backend.
- Checkout e area cliente completa attendono la conclusione di G4 nel gestionale.
- Impersonificazione autorizzata come estensione generica del core.
- I `FormField` usano il tema della pagina: non forzare mai `render('wonder')`.

## Fase A — Fondazioni condivise (`wonder-image/app`)

- [x] A1 Token auth (`--auth-bg-color`, `--auth-tx-color`,
  `--auth-form-bg-color`, `--auth-form-tx-color`, `--auth-form-border-color`)
  integrati in model, resource, seed e generazione CSS del core.
- [x] A2 Token monouso con selector/validator, hash del validator, scadenza,
  revoca e consumo atomico.
- [x] A3 Recupero password costruito sui token monouso.
- [x] A4 Verifica ID token OIDC Google/Apple con firma JWK, issuer, audience,
  scadenza e nonce.
- [x] A5 Rigenerazione dell'ID sessione dopo login locale, federato e cambi di
  identità per impersonificazione.
- [x] A6 Impersonificazione generica: authority esplicite, attore backend,
  soggetto solo frontend, token breve, CSRF, audit, banner e ritorno all'attore.
- [x] A7 Correzione persistenza `provider_email_verified` come booleano SQL sia
  al collegamento sia agli accessi successivi.
- [x] A8 Test fondazioni auth e regressione autorizzazioni HTTP.

## Fase B — Pannello auth (`wonder-image/ecommerce`)

- [x] B1 Permission `client`, hook separati di validazione, scrittura e lettura,
  verifica email obbligatoria.
- [x] B2 Layout auth sigillato e pagine login, logout POST, registrazione in due
  passaggi, verifica email, recupero e ripristino password.
- [x] B3 Presentazione basata sui componenti correnti e sul tema della pagina,
  senza helper legacy di apertura/chiusura markup e senza tema forzato.
- [x] B4 Registrazione senza `formBilling(...)` e senza `game_rules`, anche nella
  validazione e nel collegamento backend al contatto.
- [x] B5 Cellulare obbligatorio nel secondo passaggio; password obbligatoria nel
  locale e facoltativa per un'identità federata.
- [x] B6 Login Google con nonce ed email verificata; il consenso informato è
  gestito dalla schermata OAuth di Google e non blocca il flusso federato.
  Apple è disattivato nella configurazione del modulo.
- [x] B7 Redirect interni validati per ritorno ad account/checkout.
- [x] B8 Collegamento sicuro a `contacts`: riuso per utente/email non ancora
  collegata, rifiuto del conflitto con altro account, creazione senza dati fiscali.
- [x] B9 Configurazione `checkout.guest_enabled=false` predisposta; nessun flusso
  ospite implementato prima del checkout.
- [x] B10 Route backend/frontend dell'impersonificazione subordinate al flag
  `impersonation.enabled` e alle authority configurate.
- [x] B11 Test unitari di validazione/manifest/configurazione e test transazionali
  di account federato, registrazione locale, reset password, contatti e
  impersonificazione, con rollback verificato.
- [ ] B12 E2E locale completo con consegna email di test: registrazione, verifica,
  login, logout e password reset. Dipende da un trasporto mail di test configurato.
- [ ] B13 Callback reale Google su `ecommerce.test`. Dipende dal Client ID e
  dall'origine JavaScript locale autorizzata nel progetto Google. Apple resta
  una fase successiva separata.
- [x] B14 Test browser dell'impersonificazione da sessione backend reale: conferma,
  avvio, banner responsive e stop con ripristino dell'amministratore verificati su
  `ecommerce.test`.
- [ ] B15 Rifinitura responsive/accessibilità sui browser target e regressione con
  combinazioni dei cinque token auth configurate dal pannello colori.
- [x] B16 `publish:module` nel core salta con avviso file e cartelle dichiarati
  `sealed`, anche per richieste puntuali e `--force`; verificato dal sito con
  `pages/auth/login.php`.
- [ ] B17 Definire slot auth solo dove emerge una personalizzazione reale del
  sito; `config.slots` è predisposto ma non viene ancora consumato.
- [ ] B18 Test browser della revoca o disattivazione dell'attore durante una
  sessione impersonata; la validazione applicativa resta coperta dai test del core.
- [x] B19 reCAPTCHA Enterprise con action dedicate e verifica server-side sui
  form auth pubblici tradizionali; la callback Google resta indipendente dal
  CAPTCHA ed è protetta da CSRF, nonce e ID token. SEO auth/account e alert
  CAPTCHA di pagina verificati su `ecommerce.test`.

## Fase C — Area cliente

- [x] C0a Estrarre auth in `app/class/Auth/Frontend` e le viste nel core;
  configurare `auth.profile`, mantenendo URL e token di completamento ecommerce.
- [x] C0b Condividere layout, navigazione e righe stile Elena tramite
  `AccountPanel`; estendere nav, riepilogo e campi personali con validazione e
  salvataggio espliciti. Test core indipendente e rendering transazionale.
- [x] C0c Spostare nel core i modelli di `gst_contacts`,
  `gst_contact_addresses`, `gst_external_references` e il collegamento account;
  usare `contacts`, `contact_addresses`, `external_references` con migrazione
  conservativa dei nomi `gst_*`; conservare ID, FK e namespace del gestionale.
  Listini e condizioni commerciali restano estensioni del modulo.
- [x] C0f Correggere label e default del prefisso negli indirizzi account;
  comporre la griglia responsive con `AccountAddressForm` e `Container` del core.
- [x] C0g Eliminare wrapper flottanti annidati, rendere condizionali le celle
  aziendali e aggiornare la lib per paese/provincia con select e text-list
  legacy, richieste concorrenti ed errori. Test browser dei renderer reali a
  1280/768/390px e API locale; nessun bypass dell'autenticazione.
- [x] C0h Validare salvataggi indirizzi completi lato server, inclusi campi
  omessi e provincia/paese, con etichetta opzionale; mostrare i dettagli nell'alert di pagina
  e riusare il modal del core per aggiunta/modifica, con fallback senza JS.
- [x] C0i Predisporre Resource backend generiche del core per contatti e
  indirizzi, senza API pubbliche, con permessi admin/administrator e CSRF.
  Menu nascosto quando un modulo offre un pannello contatti più completo;
  lookup per tabella conserva il modulo. Eliminazione non abilitata.
- [x] C0k Etichetta spedizione opzionale anche lato server; modal account
  resi in `page_modals` dopo `main`, non nella colonna dei contenuti/form.
- [x] C0j Completare nella lib l'accessibilità di `modal()` (focus, Esc e
  campi non raggiungibili nei modal chiusi), mantenendo compatibilità;
  i Button usano `opensModal()` secondo il tema, senza onclick nelle viste.
- [ ] C0d Verificare visualmente account autenticato desktop/mobile e varianti
  colore su altri siti; completare E2E Google/email con credenziali di test.
  Fatta (2026-10-09) la prova nel browser del pannello del core su
  `ecommerce-account.test` a 1280, 768 e 386 px: Panoramica, Ordini (lista,
  paginazione, dettaglio), Coupon, Dati personali con i tre modal, Indirizzi,
  Fatturazione. Restano le varianti colore su altri siti e l'E2E Google/email.
- [x] C0e Verificare seed/import ed eseguire `forge update --local` nel sito
  demo autorizzato: completato (94 tabelle, nessun reset dei contatti), poi
  `forge start --driver=herd`. Sync API esterna non disponibile.

- [x] C0 Layout account responsive con riepilogo, navigazione laterale, stato
  attivo e logout. Dal 2026-10-08 è il pannello del core (`AccountRoutes`; spec del
  gestionale `2026-10-08-pannello-account-design.md`): l'ecommerce lo estende con
  `EcommerceAccountExtension` (metodi di pagamento, ordini, coupon, menu, font e stile).
- [x] C1 Profilo cliente e aggiornamento del cellulare.
- [x] C2 Indirizzi di spedizione e fatturazione separati dalla registrazione.
- [ ] C3 Consultazione consensi e cambio password. Il cambio password è fatto
  (modal in «Dati personali», piano 1 del pannello account); restano i consensi.
- [x] C4 Integrazione della navigazione con ordini e coupon, senza i resi
  (2026-10-08, spec del gestionale `2026-10-08-pannello-account-design.md`, piano 2):
  Ordini (`/account/ordini/`, con paginazione), dettaglio dell'ordine
  (`/account/ordini/{code}/`) e Coupon (`/account/coupon/`, solo con la funzionalità
  accesa) sono sezioni di `EcommerceAccountExtension`, nel menu del core.
- [ ] C4b Resi nel pannello del cliente: fuori ambito della spec del pannello account
  (§10), da rifare quando ci sarà il progetto dei resi.
- [ ] C5 Collegare “Metodi di pagamento” al Billing Portal Stripe quando il
  gestionale esporrà in modo verificato il customer id; fino ad allora il
  percorso resta visibile ma non apre sessioni Stripe.

Della fase C restano aperti i resi (C4b), i consensi (C3) e il collegamento a Stripe
dei metodi di pagamento (C5): la spec del pannello account li lascia fuori ambito
(§10, `2026-10-08-pannello-account-design.md`) e vanno ripresi con un progetto a parte.

## Fase D — Carrello e checkout

- [x] D1 Verificato il contratto G4 del gestionale per carrello, righe, prezzi,
  imposte, indirizzi, creazione ordine, prenotazione stock e pagamento aperto.
  Tariffe e metodi di spedizione dipendono ancora dalla conclusione di G7.
- [x] D2 Implementare carrello persistente e merge controllato al login, evitando
  dipendenze da contenuti o configurazioni di `elenajossifov-com`.
- [x] D3 Identificazione cliente con login, registrazione e ritorno al checkout;
  anche il completamento federato conserva la destinazione.
- [x] D4 Il percorso ospite è subordinato a `checkout.guest_enabled`, protetto da
  reCAPTCHA; l'ordine si collega a un account creato o riusato dall'email, con il
  link per scegliere la password.
- [x] D5 Raccolta fatturazione e consegna, metodi di spedizione, sedi di ritiro,
  coupon, riepilogo che si ricalcola (`checkout.js`) e creazione ordine con i
  metodi manuali. Checkout in una pagina sola (spec del gestionale
  `2026-10-07-checkout-pagina-unica-design.md`, piano 2).
  **Resta la prova nel browser su `ecommerce.test`.**
- [ ] D6 Adapter dei provider pagamento, idempotenza callback e gestione esiti in
  ambiente test senza addebiti reali.
- [ ] D7 Misure ripetibili di query, tempo server e richieste client prima di
  attribuire cause alla lentezza percepita del riferimento legacy.
- [ ] D8 E2E su `ecommerce.test`: carrello conservato attraverso auth, account e
  ospite abilitato/disabilitato, success/failure/cancel pagamento, responsive e
  regressioni.
- [x] D9 Layout `ecommerce.shop` e `ecommerce.checkout`, pagine carrello,
  checkout e conferma collegate al flusso funzionale.

## Infrastruttura e rilascio

- [ ] I1 Configurare il secret CI `MODULI_TOKEN` per il gestionale privato.
- [ ] I2 Eseguire la matrice CI con i tre pacchetti su PHP 8.2 e versione runtime
  corrente prima del rilascio.
- [ ] I3 Documentare configurazione provider e mail del sito di produzione senza
  salvare segreti nel repository.
