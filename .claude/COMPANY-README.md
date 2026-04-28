# Company Claude Repo

Repository riusabile di regole, skill, command e agent derivati da `.claude` del progetto sorgente.

Struttura:

- `CLAUDE.md`: linee guida generali cross-project
- `rules/`, `skills/`, `commands/`, `instructions/`: materiale generico non legato a uno stack specifico
- `laravel/`: convenzioni Laravel e Laravel-oriented architecture
- `playwright/`: workflow E2E e testing browser
- `INVENTORY.md`: mappa file-per-file di cosa e' stato tenuto, generalizzato o escluso

Nota importante:

- il pacchetto `laravel/` e' stato allineato ai pattern realmente dominanti osservati nel progetto sorgente: `Service`, `Dto`, `Request/FormRequest`, `Job`, `Query`
- `Action` e' supportato, ma non e' il pattern primario
- il testing del progetto sorgente e' `PHPUnit`-first; `Pest` e' trattato come opzione, non come default imposto
- target corrente del pacchetto Laravel: `Laravel 13` (release ufficiale del 17 marzo 2026) con baseline `PHP 8.3+`

Obiettivo:

- mantenere solo asset riusabili su piu' progetti
- rimuovere riferimenti a domini, helper, package e naming strettamente interni
- separare il materiale per stack senza frammentare inutilmente la struttura
