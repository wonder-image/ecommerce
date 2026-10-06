# AGENTS.md

Modulo ecommerce del framework Wonder Image (`wonder-image/app`).

## Frontend: SEO, schema.org, breadcrumb e dataLayer GTM

Quando lavori sul frontend del negozio, e solo in quel caso, leggi e applica
`.claude/rules/frontend-seo-tracking.md` prima di toccare il codice. Il
frontend comprende:

- `src/Frontend/`, `http/frontend/`, `config/routes/route.frontend.php`
- `view/pages/` (tranne `view/pages/backend/`), `view/components/`,
  `view/layout/frontend/`
- `resources/assets/` e i testi in `lang/`

In sintesi: ogni intervento frontend va ragionato su quattro punti, e il
ragionamento va riportato nel piano o nel riepilogo.

1. SEO: indicizzazione, titolo, descrizione, canonical, robots, HTML semantico.
2. schema.org: JSON-LD coerente con il contenuto visibile.
3. Breadcrumb: visibile, JSON-LD e gerarchia degli URL allineati. Il JSON-LD
   lo genera la funzione `breadcrumb()` di `wonder-image/app` tramite
   `$SEO->breadcrumb`: non va riscritto nel modulo.
4. dataLayer: dati di pagina ed eventi GA4 inviati a Google Tag Manager, a
   partire da `window.dataLayer.push({ user: { id: ... } })` su ogni pagina.

Backend, modelli, resource, seeding ed email sono fuori da queste regole.
