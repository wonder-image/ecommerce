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
- [x] B12 E2E locale completo con consegna email di test: registrazione, verifica,
  login, logout e password reset (verificato il 2026-10-20).
- [x] B13 Callback reale Google su `ecommerce.test` (verificato il 2026-10-20).
  Apple resta una fase successiva separata.
- [x] B14 Test browser dell'impersonificazione da sessione backend reale: conferma,
  avvio, banner responsive e stop con ripristino dell'amministratore verificati su
  `ecommerce.test`.
- [ ] B15 Rifinitura responsive/accessibilità sui browser target e regressione con
  combinazioni dei cinque token auth configurate dal pannello colori.
- [x] B16 `publish:module` nel core salta con avviso file e cartelle dichiarati
  `sealed`, anche per richieste puntuali e `--force`; verificato dal sito con
  una view sigillata del modulo (le view auth oggi sono del core e non sono
  più sigillate dall'ecommerce).
- [ ] B17 **Priorità alta**, tra le prime dopo la 1.0.0: slot di markup in
  carrello, checkout, area cliente e autenticazione; `config.slots` è predisposto
  ma non viene ancora consumato. Issue #25.
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
- [x] C0c Spostare in `wonder-image/customer-account` i modelli di `gst_contacts`,
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
- [x] C0d Verificare visualmente account autenticato desktop/mobile e varianti
  colore su altri siti; completare E2E Google/email con credenziali di test.
  Prova nel browser del pannello del core su `ecommerce-account.test` a 1280,
  768 e 386 px (2026-10-09): Panoramica, Ordini (lista, paginazione, dettaglio),
  Coupon, Dati personali con i tre modal, Indirizzi, Fatturazione. Varianti
  colore ed E2E Google/email verificati il 2026-10-20.
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
- [x] C5 Collegare “Metodi di pagamento” al Billing Portal Stripe (verificato il
  2026-10-20); la sezione si accende con `account.payment_methods.enabled`.

Della fase C restano aperti i resi (C4b) e i consensi (C3): la spec del pannello
account li lascia fuori ambito (§10, `2026-10-08-pannello-account-design.md`) e
vanno ripresi con un progetto a parte. La spec di architettura mette la richiesta
di reso nell'area cliente dentro E1c.

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
  `2026-10-07-checkout-pagina-unica-design.md`, piano 2). Provato nel browser su
  `ecommerce.test` il 2026-10-20.
- [x] D6 Pagamento con Stripe (spec del gestionale
  `2026-10-09-pagamento-stripe-design.md`). **Piano 1 fatto** (2026-10-09): Payment
  Element nel pannello della scelta «Carta», solo numero/scadenza/CVC; spinner
  «Elaborazione pagamento»; 3DS nella finestra di Stripe; ritorno e webhook
  idempotenti; dopo un rifiuto il modulo si riapre (ordine annullato, righe e
  coupon tornano nel carrello con `Cart::restore`, rotta `/checkout/reopen/`);
  link all'ordine nelle email (cliente → account, commerciante → backend);
  l'indirizzo di consegna resta nell'account del cliente autenticato. Provato su
  `ecommerce.test` con 4242, 0002 e la 3DS 4000 0027 6000 3184.
  - [x] Piano 2: checkout rapidi in alto (Express Checkout Element con Link,
    Apple Pay, Google Pay) e i metodi Stripe separati sotto la carta, uno per
    metodo acceso nel conto (PaymentMethodConfiguration). Unito con il ramo
    `stripe-rapido` (piano 2b), con reCAPTCHA v3 per l'ospite nell'express.
  - [x] Ordine in pagamento (spec del gestionale `2026-10-19-ordine-in-pagamento-design.md`):
    il checkout resta modificabile e l'ordine in pagamento si riapre da solo.
  - Prove in locale: `stripe listen --forward-to https://ecommerce.test/api/gestionale/stripe/webhook/`
    (eventi diretti, non Connect). `forge update --local` rimette `active` dei
    metodi di pagamento come nella sincronizzazione: dopo, riaccendere «Carta».
- [ ] D7 Misure ripetibili di query, tempo server e richieste client prima di
  attribuire cause alla lentezza percepita del riferimento legacy.
- [x] D8 E2E su `ecommerce.test`: carrello conservato attraverso auth, account e
  ospite abilitato/disabilitato, success/failure/cancel pagamento, responsive e
  regressioni (verificato il 2026-10-20).
- [x] D9 Layout `ecommerce.shop` e `ecommerce.checkout`, pagine carrello,
  checkout e conferma collegate al flusso funzionale.

## Fase E — Abbonamenti (E3, con G10 del gestionale)

Spec nel gestionale: `docs/superpowers/specs/2026-10-13-abbonamenti-design.md`
§2 e §5; piano `docs/superpowers/plans/2026-10-13-g10-piano-2-abbonamenti-online.md`.
Unito su `main` (PR #11 e, per i servizi, #15), inerte con `online_subscriptions` spenta.

- [x] E3.1 Pagina dei piani `/abbonamenti/` con `ItemList` di `Product`,
  breadcrumb, `view_item_list` e `select_item` (`SubscriptionsPageTest`).
- [x] E3.2 Sottoscrizione con account obbligatorio, fatturazione e codice
  sconto, `begin_checkout`, poi Stripe Checkout.
- [x] E3.3 Conferma che aspetta la notifica, `purchase` una volta sola.
- [x] E3.4 Area cliente → Abbonamenti: cambia piano, disdici, aggiorna la carta.
- [ ] E3.5 Prova nel browser con Stripe in modalità test (aspetta le chiavi di
  prova sul sito di prova): carta, carta con autenticazione, prova gratuita,
  cambio piano, disdetta, portale.
- [x] E3.6 Abbonamenti con più servizi (G10b §7, ramo `abbonamenti-con-servizi`,
  unito con la PR #15): selezione, riepilogo, un Checkout per più servizi, area cliente
  con Aggiungi, Togli e Cambia per riga (`SubscriptionSelectionTest`,
  `AccountSubscriptionsTest`).
- [ ] E3.7 Prova nel browser di E3.6 sul sito `ecommerce-site-servizi`: la
  lista è nel TODO del gestionale, voce G10b.

## Infrastruttura e rilascio

- [x] I1 Configurare il secret CI `MODULI_TOKEN` per il gestionale privato: **superato** (2026-10-16), i repository ecommerce, gestionale e customer-account sono pubblici e la CI li scarica senza token.
- [ ] I2 Eseguire la matrice CI con i tre pacchetti su PHP 8.2 e versione runtime
  corrente prima del rilascio.
- [ ] I3 Documentare configurazione provider e mail del sito di produzione senza
  salvare segreti nel repository.
- [ ] I4 Unire su `main` il ramo `fix/2026-10-1` (scheda del cliente e indirizzi
  da `wonder-image/customer-account`).
