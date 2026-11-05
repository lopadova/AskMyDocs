# Consolida develop in main: agent, connettori, interfacce e client

## Problema e risultato

Lo sviluppo è proseguito a lungo fuori dalla storia di produzione: 588 commit esclusivi di develop rendono poco leggibile una PR diretta. Questo ramo ricostruisce il delta finale sopra main in 15 commit tematici più un commit di documentazione, con un inventario verificabile file per file.

Il consolidamento conserva anche le funzioni esclusive di main: 2FA, IAM, approvazione azioni, export/import e routine Wiki, Vision.

## Modifiche

- **Piattaforma, dipendenze e integrazione** — Riconcilia le dipendenze dei due rami, conservando gli aggiornamenti di produzione e aggiungendo i pacchetti richiesti dalle nuove funzioni. Raccoglie bootstrap, provider condivisi, configurazione delle code, routing e contratti dei test di architettura.
- **Connettori API, MCP, OAuth e ingestione email** — Integra il runtime MCP e i plugin, le connessioni OAuth e le configurazioni dei connettori API. Comprende identità dei record, import/export delle configurazioni, operazioni di installazione e recupero delle sincronizzazioni IMAP/Freshdesk.
- **Composizione ed esecuzione dei tool API** — Introduce tool composti con definizioni versionate, validazione degli input, compilazione in grafo, esecuzione limitata e continuazioni durevoli. Include verifica prima dell’attivazione, isolamento per tenant, misurazioni e superfici amministrative.
- **Knowledge base, ricerca e documenti** — Integra profili di ricerca, indagine ricorsiva, lettura delle fonti e gestione dei documenti. Conserva export/import Wiki, routine e colonne Vision già presenti in produzione e riporta le modifiche delle relative interfacce.
- **Chat, agent, grounding e memoria verificata** — Raccoglie orchestrazione delle run, pianificazione e ricerca dipendente, budget, timeout e recupero dei worker. Integra verifica delle affermazioni sulle fonti, provenienza dei record API, memoria di sessione, tabelle verificate e workspace delle conversazioni.
- **Tenant, autenticazione e amministrazione** — Integra branding dei tenant, gestione e reset degli spazi, isolamento e flussi di autenticazione. Mantiene le protezioni 2FA, IAM e approvazione delle azioni già presenti su main.
- **Conversazione vocale e Realtime** — Aggiunge sessioni vocali collegate a tenant, progetto e conversazione, autorizzazione e presentazione delle run. Include eventi, collegamenti persistenti e proiezione dei consumi nel ledger FinOps.
- **Consumi AI, prezzi e diagnostica** — Integra attività e consumi AI per tenant, cataloghi e fonti prezzo, stime attribuite e dashboard. Aggiunge span e trascrizioni diagnostiche, conservando i confini di accesso e la contabilizzazione delle richieste MCP di produzione.
- **Classificazione e campagne email** — Raccoglie profili, campagne, valutazioni e avanzamento del triage email, con job e comandi operativi. Include persistenza e verifiche dei risultati.
- **Interfaccia applicativa e sistema visivo** — Integra navigazione, shell, controlli condivisi e localizzazione dell’applicazione. Riporta il riordino delle pagine amministrative e dei relativi test, lasciando le interfacce specialistiche nei commit della funzione corrispondente.
- **Client desktop e mobile** — Riporta il client Tauri, le viste e le impostazioni di piattaforma, le icone e gli strumenti di build. Conserva nel ramo i metadati e le evidenze di preparazione agli store già versionati in develop.
- **Landing e immagini editoriali** — Integra la landing multilingue, gli stili e le immagini editoriali dell’applicazione. Include le varianti giorno/notte, i formati AVIF/WebP e le dimensioni già versionate nella sorgente.
- **QA, fixture locali e operatività** — Integra campagne QA con prenotazione concorrente del budget, client HTTP riprendibili e corpus fisso. Comprende fixture di integrazione, strumenti di performance e configurazioni operative già versionate.
- **Specifiche, guide e governance** — Raccoglie specifiche, ADR, runbook, guide, changelog e regole del progetto aggiornati in develop. I documenti storici restano cronologia: date e versioni citate al loro interno non attestano una nuova release conclusa.
- **Materiali e fonti marketing** — Riporta analisi, testi, immagini, template e materiali di presentazione già presenti in develop. Include file sorgente ed elaborati già versionati.

## Perimetro e tracciabilità

- Snapshot main: `4048fe996f9ef88f30a196e7738df5a2b06eaf22`.
- Snapshot develop: `2a61388391141bcc459cdd01a8dceb740aa9f08f`.
- 2.343 file applicativi nel delta: 1.726 aggiunti, 611 modificati, 6 eliminati.
- 134 file esclusivi di produzione conservati identici; 14 conflitti riconciliati esplicitamente.
- [Dossier e inventario per area](https://github.com/lopadova/AskMyDocs/blob/codex/feature/develop-main-consolidation-20261105/docs/releases/2026-11-05-develop-main/README.md), con provenienza delle modifiche per file.
- [Scelte di conservazione e risoluzioni](https://github.com/lopadova/AskMyDocs/blob/codex/feature/develop-main-consolidation-20261105/docs/releases/2026-11-05-develop-main/MAIN-PRESERVATION.md).

## Validazione

Gli esiti locali, le dipendenze effettivamente installate e i gate ancora da completare sono registrati in [VALIDATION.md](https://github.com/lopadova/AskMyDocs/blob/codex/feature/develop-main-consolidation-20261105/docs/releases/2026-11-05-develop-main/VALIDATION.md). La preparazione della storia non equivale a una release approvata.

Prima del merge rimangono i gate previsti da `docs/GITFLOW.md`, inclusi CI, Playwright completo, migrazioni su database pulito e ciclo Copilot senza finding aperti.

## Gate aperti rilevati localmente

- Suite PHP: stop dopo 4.293/6.733 test per il contratto Freshdesk mancante nel pacchetto 1.0.0 bloccato già in develop.
- `GraphExecutorNotAdoptedTest`: il tool composto adotta il graph executor mentre il contratto architetturale lo vieta finché non è conclusa la decisione sulla tenancy delle tabelle del motore. Il medesimo errore è riprodotto sul develop originale.
- `verify-e2e-real-data.sh`: cinque rilievi in `mcp-project-connection.spec.ts` e `pact-auth-consent.spec.ts`, già presenti nel develop originale.
- Rollback SQLite: errore della migrazione FinOps invariata; verifica PostgreSQL ancora necessaria.

Questi controlli non sono stati disattivati o attenuati durante il consolidamento. La PR è preparata per la revisione; i gate aperti vanno risolti prima del merge.

## Riallineamento successivo

Main e develop non vengono riscritti da questa PR. Dopo il merge occorre il [riallineamento controllato di develop](https://github.com/lopadova/AskMyDocs/blob/codex/feature/develop-main-consolidation-20261105/docs/releases/2026-11-05-develop-main/REALIGNMENT.md): il solo merge di ritorno conserva i 588 commit storici nella lista dei commit esclusivi.

La PR deve essere aperta verso `main`; push e apertura non sono stati eseguiti durante la preparazione locale.
