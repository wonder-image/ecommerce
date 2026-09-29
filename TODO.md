# wonder-image/ecommerce — TODO

Modulo del negozio online del gestionale Wonder Image. Questo file è lo stato del
lavoro: chi apre il modulo parte da qui.

## Come si lavora qui

- **Spec di questa fetta (E1a):** `../gestionale/docs/superpowers/specs/2026-09-29-negozio-online-guscio-design.md` — approvata il 2026-09-29
- **Architettura del progetto:** `../gestionale/docs/superpowers/specs/2026-09-11-gestionale-ecommerce-architettura-design.md`
- **Piani:** `../gestionale/docs/superpowers/plans/`
- **Mappa generale del progetto:** `../gestionale/TODO.md`
- Il modulo si sviluppa in parallelo su più agenti: **aggiorna questo file nello
  stesso commit del lavoro**, non dopo. Compito chiuso, compito aggiunto, compito
  riaperto: sempre qui.
- Stati: `[ ]` da fare · `[~]` in corso, con chi ce l'ha tra parentesi · `[x]` fatto.
- Un compito è chiuso quando ha una prova: un test che passa, o la verifica descritta
  accanto al compito.
- Convenzioni del codice e dei commit: quelle del gestionale (`../gestionale`), test
  compresi.

## Decisioni aperte

| Cosa | Chi decide | Cosa blocca |
|---|---|---|
| Campi della registrazione: minimo, minimo + telefono, o scheda fiscale completa | Andrea | 3.3, 3.4 |
| Sigillatura di vetrina, catalogo e scheda prodotto | si decide in E1b | niente in E1a |

## Fuori da E1a — non farlo adesso

- Header, footer, menu, logo: sono del sito, il modulo non li tocca.
- Componenti da innestare nell'header e nel footer (mini-carrello, ricerca, voce
  dell'account, avvisi): fetta a sé dopo E1a, va prima organizzata.
- Vetrina, catalogo pubblico, scheda prodotto: E1b.
- Carrello, checkout, pagamenti, ordini e resi nell'area cliente: E1c, dopo G4.
- `account/auth/impersonate/`: fetta a sé dopo il rilascio.

## Piano 1 — Pacchetto e collegamento

- [x] 1.1 `composer.json`: `wonder-image/ecommerce`, php `^8.2`, `wonder-image/app ^2.4.0-beta.1 || dev-main`, `wonder-image/gestionale @dev`; `repositories` path `../app` e `../gestionale` con symlink; psr-4 `Wonder\Plugin\Ecommerce\` → `src/`, `files: src/helpers.php`; platform php 8.2.0
- [x] 1.2 `module.json`: slug `ecommerce`, namespace, entrypoint `Wonder\Plugin\Ecommerce\Ecommerce`, `dependencies.modules: ["gestionale"]`, `paths`, `routes` (solo `frontend`), `permissions`
- [x] 1.3 `src/Ecommerce.php`: classe di ingresso sul modello di `../gestionale/src/Gestionale.php` e di `../immobili/src/Immobili.php` (`root()`, `viewPath()`, `layout()`, `config()`)
- [x] 1.4 Albero delle cartelle: `src/{Frontend,Http,Support,Extensions,Seeding}`, `http/`, `view/{layout,components,pages,emails}`, `lang/`, `config/`, `tests/`
- [x] 1.5 `lang/it/` con la prima voce e il meccanismo dei testi
- [x] 1.6 `config/routes/route.frontend.php` con una rotta di prova che risponde (si toglie con il piano 2)
- [x] 1.7 `tests/`: harness del gestionale, `php tests/run.php`, `ManifestTest` (manifest valido per il core, dipendenza dal gestionale, solo rotte frontend, niente database né comandi) ed `EcommerceTest` (percorsi, `viewPath()`, configurazione) — 10 test verdi
- [ ] 1.7b Test d'integrazione: database `ecommerce_site` con transazioni annullate, sul modello di `../gestionale/tests/integrazione/`. Serve dalla prima pagina che legge i dati del gestionale: per ora `tests/integrazione/` è vuota e `run.php` la salta
- [x] 1.8 GitHub Actions: unitari copiati dal gestionale, con il checkout del core e del gestionale accanto al pacchetto, `config.platform.php` a 8.2
- [x] 1.9 `README.md` e `CHANGELOG.md`: a cosa serve il modulo, come si installa, rimando a spec e TODO
- [ ] 1.10 `git init`, primo commit, repository **privato** `wonder-image/ecommerce`, push su `main`
- [ ] 1.11 Collegamento a `../../boilerplates/ecommerce-site`: nel `composer.json` del sito `require` solo `wonder-image/ecommerce: "@dev"` (toglie `wonder-image/gestionale`, che diventa transitivo) e `repositories` + path `../../packages/ecommerce`; in `custom/config/modules.php` **entrambi** i moduli abilitati
- [ ] 1.12 Verifica: `php forge update`, `php forge config`, `php forge modules` senza errori, due moduli validi, la rotta di prova risponde nel browser
- [ ] 1.13 Secret `MODULI_TOKEN` nel repository (token personale con lettura su `wonder-image/gestionale`): senza quello la CI non riesce a fare il checkout del gestionale, che è privato. **Lo fa Andrea**

## Piano 2 — Guscio

- [ ] 2.1 `view/layout/frontend/ecommerce.shop.php`: chaina `frontend.main` del sito (modello: `../immobili/view/layout/frontend/immobili.main.php`)
- [ ] 2.2 `view/layout/frontend/ecommerce.checkout.php`: chaina `frontend.main`
- [ ] 2.3 `view/layout/frontend/ecommerce.auth.php`: chaina `frontend.minimal`
- [ ] 2.4 Asset e dati JS del negozio nei layout: dove stanno nel pacchetto e come si pubblicano
- [ ] 2.5 `module.json`: `views.sealed` con `pages/cart`, `pages/checkout`, `pages/account`, `pages/auth`
- [ ] 2.6 `Ecommerce::viewPath()` che **non** consulta `custom/modules/ecommerce/view/` per i percorsi sigillati, + test: override ignorato su una view sigillata, rispettato su una non sigillata
- [ ] 2.7 Slot: meccanismo (dichiarazione nella view, riempimento dalla configurazione del modulo) e i primi slot delle pagine di autenticazione e dell'area cliente
- [ ] 2.8 Core `wonder-image/app`: `publish:module` legge `views.sealed` e salta quei file con un avviso, + test. PR a sé su `../app`
- [ ] 2.9 `docs/` del modulo: elenco dei layout, degli slot e degli hook — è contratto pubblico, i cambi vanno nel changelog

## Piano 3 — Account dei clienti

Rotte esattamente quelle di `clients/spingy/projects/spingy-it/account/auth/`.

- [ ] 3.1 `config/permissions.php`: `frontend.client` con `link`, `function` (`creation`, `modify`, `info`, `validate`) e `verification('email', required: true)`
- [ ] 3.2 Hook `validateClient`, `client`, `infoClient` in `src/Frontend/`
- [ ] 3.3 Pagine: `login/`, `logout/`, `signup/request/`, `signup/completion/` — **bloccate dai campi della registrazione**
- [ ] 3.4 Pagine: `email-verification/send/` e `verify/`, `password/restore/`, `recovery/`, `set/`
- [ ] 3.5 Email di verifica e di recupero: `view/emails/` e testi in `lang/`
- [ ] 3.6 Segmenti degli URL in `lang/`, presi con `__u()` come in spingy
- [ ] 3.7 Collegamento a `gst_contacts` alla verifica dell'email: scheda mancante → creata; scheda senza `user_id` → collegata; scheda con un altro `user_id` → account senza scheda e caso in "Da controllare"
- [ ] 3.8 Area cliente: profilo, indirizzi (`gst_contact_addresses`), consensi, cambio password, con la sua navigazione interna
- [ ] 3.9 Test: giro completo registrazione → verifica → accesso → uscita → recupero password; i tre casi del collegamento alla scheda
