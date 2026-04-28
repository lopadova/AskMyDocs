# Company AI Conventions

Questa raccolta contiene istruzioni riusabili per progetti software diversi. Va usata come base, poi ogni repo puo' aggiungere il proprio overlay locale.

## Principi

- Analizza il codebase prima di scrivere codice.
- Non assumere architetture o naming se il repo mostra pattern diversi.
- Riusa componenti e servizi esistenti prima di crearne di nuovi.
- Mantieni i controller sottili e concentra la logica in servizi o moduli dedicati.
- Evita side effect nascosti, helper globali non documentati e accoppiamento implicito.
- Tratta test, logging, sicurezza e performance come requisiti, non come rifiniture.

## Stile

- Preferire early return e nesting minimo.
- Dare nomi espliciti a classi, metodi, variabili e file.
- Dichiarare type hints e return types dove lo stack li supporta.
- Scrivere commenti solo quando spiegano una decisione o un vincolo non ovvio.

## Workflow

- Leggere i file correlati prima di modificare un'area.
- Eseguire il test piu' stretto possibile prima di allargare lo scope.
- Non introdurre tool, package o convenzioni globali senza motivazione chiara.
- Per modifiche frontend, valutare sempre testabilita', stati UI e impatto prestazionale.

## Struttura di questa repo

- Materiale generale: root
- Materiale Laravel: `laravel/`
- Materiale Playwright/E2E: `playwright/`
