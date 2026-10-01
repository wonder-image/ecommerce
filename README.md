# Wonder Ecommerce

Negozio online per `wonder-image/app`: vetrina, carrello, checkout e area
cliente. I dati sono quelli del gestionale (catalogo, prezzi, giacenze,
ordini): questo modulo aggiunge solo le pagine pubbliche e richiede
`wonder-image/gestionale`.

Il modulo non porta header, footer e grafica del sito: le sue pagine passano
dai tre layout sottili `ecommerce.shop`, `ecommerce.checkout` e
`ecommerce.auth`, che chainano sui layout del sito.

## Installazione in sviluppo

Nel `composer.json` del sito basta chiedere il negozio: il gestionale arriva
come sua dipendenza. I `repositories` vanno però dichiarati tutti, perché
Composer li legge solo dal pacchetto radice.

```json
"repositories": [
    { "type": "path", "url": "../../packages/app", "options": { "symlink": true } },
    { "type": "path", "url": "../../packages/gestionale", "options": { "symlink": true } },
    { "type": "path", "url": "../../packages/ecommerce", "options": { "symlink": true } }
],
"require": { "wonder-image/ecommerce": "@dev" }
```

Poi `composer update wonder-image/ecommerce` e, in `custom/config/modules.php`,
**entrambi** i moduli abilitati per nome: il core pretende che le dipendenze
siano abilitate, non solo installate.

```php
return [
    'gestionale' => [ 'enabled' => true ],
    'ecommerce' => [ 'enabled' => true ],
];
```

## Test

```bash
php tests/run.php
```

## Documentazione

- Stato del lavoro e compiti: `TODO.md`
- Flussi e configurazione auth: `docs/authentication.md`
- Spec del guscio: `packages/gestionale/docs/superpowers/specs/2026-09-29-negozio-online-guscio-design.md`
- Architettura del gestionale e del negozio:
  `packages/gestionale/docs/superpowers/specs/2026-09-11-gestionale-ecommerce-architettura-design.md`
