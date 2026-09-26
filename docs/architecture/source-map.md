# Enterprise source-map

Questo indice separa fonti, codice e owner. Il piano non autorizza a trattare un documento come implementazione.

| Source | Autorità | Owner operativo | Output |
|---|---|---|---|
| `00-LEGGIMI-E-ORDINE-ESECUTIVO.md` | ordine e dipendenze | AskMyDocs lead | sequenza E/W/V |
| `04-CONTRATTI-INVARIANTI-E-MODELLO-DATI.md` | invarianti condivisi | AskMyDocs + package owners | contratti e gate |
| `05-DELIVERY-TEST-E-HANDOFF-AGENTI.md` | evidenza e stati task | tutti gli owner | prove complete e handoff |
| `06-EVIDENZE-INVENTARIO-E-TRACCIABILITA.md` | finding del 26-09 | AskMyDocs lead | finding ricalcolati in E00.01 |
| `01-…`, `02-…`, `03-…` | roadmap addon, competitiva, ecosistema | rispettivi owner | task V, W, E |
| `fonti/vocentra/*` | specifiche e roadmap Vocentra | agente Vocentra | mapping contratti; nessun edit da questo lavoro |
| `TASKS.json` | grafo puntuale dei 75 task | coordinamento | unica fonte per ID/deps |
| `REPOSITORY-SNAPSHOTS.json` | SHA dichiarati | coordinamento | confronto con HEAD reale |
| `MANIFEST-SHA256.json` | integrità del corpus | coordinamento | verifica documentale |

## Repository e confini

- AskMyDocs: repository corrente; implementazione E/W e adapter host autorizzati.
- Package Padosoft: verificare lock, vendor, API e release prima di usarli come capability; il lock non equivale a wiring.
- WeKnora: baseline comparativa; non modificato dal task E00.01.
- Vocentra: repository e app già assegnati a un altro agente; questo workspace conserva soltanto i contratti di integrazione e le dipendenze `V00–V06`.

## Codice host da riconciliare

| Capability | Percorsi rilevati | Task |
|---|---|---|
| Tenant context e scope | `app/Support`, middleware, `app/Flow`, migration e test Architecture/Flow | E00.03, E01.01 |
| RAG/retrieval | `app/Services/Kb/Reranker.php`, `Grounding/ConfidenceCalculator.php`, `Retrieval/GraphExpander.php` | W01–W03 |
| Flow | `app/Flow`, `tests/Unit/Flow`, `padosoft/laravel-flow` in lock | E03.01 |
| Routines | `app/Routines/*`, `AppServiceProvider`, composer/lock; vendor mancante | E00.02, E03.03 |
| AI/FinOps/guardrails | `app/Ai`, config e package lock | E02, E04, E06 |
| HTTP surfaces | `routes/api.php`, `routes/web.php`, controller API/admin | E01, V01–V04, W08 |
| Verification | PHPUnit, Vitest, Playwright, `scripts/verify-e2e-real-data.sh` | ogni task; complete journeys ai gate |
