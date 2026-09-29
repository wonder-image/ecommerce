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
