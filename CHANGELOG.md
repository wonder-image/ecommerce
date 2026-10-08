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
- Pagina `/account/password/` per cambiare o impostare la password, con voce
  nel menu del pannello e riga nel riepilogo; la logica è quella del core
  (`AccountPassword`).
- Checkout ospite (`checkout.guest_enabled`): `GuestCheckout` trova o crea l'account
  dall'email, collega contatto e consensi e manda nell'email dell'ordine il link per
  scegliere la password (non a chi entra con Google, senza annullare i link già
  mandati); la conferma dice che l'email è partita senza rivelare se l'account ha
  una password. La scheda del commerciante con la stessa email si collega senza
  cambiarne i dati.

### Modificato

- Checkout in una pagina sola (via i passi Spedizione e Pagamento); carrello nel
  layout del negozio.

### Rimosso

- Rotte `ecommerce.checkout.shipping` e `ecommerce.checkout.payment`.
