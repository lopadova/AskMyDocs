# Demo readiness audit — G1

**Data:** 22 settembre 2026, 11:34 CEST<br>
**Ambiente verificato:** locale Herd `https://askmydocsdev.test`<br>
**Branch operativo:** `feature/demo-g1-audit-baseline`, da `origin/develop`<br>
**Commit applicativo:** `c96af4b4` (`feat(chat): add super-admin debug transcript export`)

## Esito sintetico

La baseline locale e la suite mirata sono ripetibili, l'applicazione e le
dipendenze principali sono raggiungibili, ma la demo **non è pronta per una
certificazione live**. Restano blocchi sulla coerenza GitFlow, sulla salute
delle code/worker, sulle migrazioni pendenti e sulle integrazioni live.

| Area | Stato | Evidenza |
| --- | --- | --- |
| Applicazione Herd | PASS | `https://askmydocsdev.test/login` restituisce HTTP 200 |
| PostgreSQL / pgvector | PASS | driver `pgsql`; estensione `vector` presente |
| Redis | PASS | cache, sessione e queue configurate su Redis; store raggiungibile |
| Storage KB | PASS con rischio | disco `kb` presente e scrivibile; 15 GiB liberi, volume al 99% |
| Migrazioni | FAIL | tre migrazioni `2026_10_03_*` sono pendenti |
| Queue / worker | FAIL | tre failed job e nessun worker persistente osservato |
| IMAP | FAIL | installazioni storiche in stato `errored`; nessuno smoke live eseguito |
| API Oktodora | BLOCCATO | contratto/mock presente, endpoint demo e credenziali live non attestati |
| MCP Gescat | BLOCCATO | contratti e test presenti, ma discovery/`tools/list` live non eseguita |
| Baseline mirata | PASS | 28 test PHP, 219 assertion; 3 test frontend; typecheck verde |
| Suite PHP completa | FAIL | contratto risposta agent non più allineato al test |

## Git e GitFlow

È stato eseguito `git fetch --all --prune --tags`. Il checkout iniziale
`develop` era pulito e due commit avanti rispetto a `origin/develop`:

- `b8c876e289f3a0d5ac7926a2d79729dcc42bf2fc` — export transcript debug;
- `7e215720` — specifica esecutiva demo.

Per rispettare la specifica G1, il primo commit è stato riposizionato tramite
cherry-pick su `feature/demo-g1-audit-baseline`, creato direttamente da
`origin/develop`; il commit risultante è `c96af4b4`. Il diff è di 9 file,
773 inserimenti, e comprende controller, route, UI e relativi test.

Il controllo esplicito `git merge-base --is-ancestor origin/main
origin/develop` termina con codice 1: l'invariante `main → develop` non è
soddisfatta dopo il fetch. `git diff --check` è verde.

## Runtime e dati

`php artisan about` ha confermato Laravel 13.30.1, PHP 8.4.23, ambiente
`local`, URL Herd corretta, PostgreSQL, Redis per cache/sessioni/code e mail
SMTP. Il link `public/storage` non è presente; non blocca il disco KB privato,
ma blocca qualunque prova demo che dipenda da asset pubblici.

Tutte le migrazioni fino al 2 ottobre risultano applicate. Sono pendenti:

- `2026_10_03_000001_create_chat_folders_table`;
- `2026_10_03_000002_add_organisation_to_conversations_table`;
- `2026_10_03_000003_add_session_recap_to_conversations_table`.

La queue predefinita Redis usa `kb-ingest`. `queue:failed` espone tre errori:
due `Padosoft\\Invitations\\Mail\\InvitationMail` e un
`App\\Jobs\\IngestDocumentJob`, tutti sulla coda `kb-ingest`. Lo scheduler
registra sia `connectors.dispatch-due-syncs` sia
`connectors.imap.pump-backfills`, ma al controllo non è rimasto un processo
`queue:work`, `queue:listen`, `schedule:work` o Horizon persistente.

## Integrazioni e segreti

La verifica è stata volutamente secret-free: sono stati rilevati solo nomi di
variabili/configurazioni e metadati delle installazioni, senza leggere o
stampare valori sensibili.

- provider AI e embedding: configurati; la chiave OpenRouter risulta presente;
  `OPENROUTER_SITE_URL` è assente;
- IMAP: quattro record di credenziali cifrate; due installazioni `default` sono
  `errored` (ultimo sync 13 agosto), mentre altre installazioni sono `active`
  ma senza `last_sync_at`;
- MCP: installazioni locali `active` e `pending`, non collegate in questa
  sessione a uno smoke Gescat;
- Gescat: widget/host-tool e contratti MCP presenti in codice; il reale server
  di Gescat deve ancora esporre una discovery verificata;
- Oktodora: il runbook contiene il percorso con server mock, ma l'endpoint HTTPS
  demo reale e le credenziali non sono state individuate nella configurazione
  disponibile.

## Baseline eseguita

```text
php artisan test \
  tests/Feature/Admin/ConversationDebugTranscriptTest.php \
  tests/Feature/Api/Admin/KbUploadCommitIntegrationTest.php \
  tests/Feature/Connectors/ImapBackfillAlgorithmsTest.php \
  tests/Feature/Mcp/McpConnectorAuditRecorderTest.php \
  tests/Feature/Agent/AgentServerToolRunnerTest.php \
  tests/Feature/Eval/EvalNightlyCommandTest.php
# 28 passed, 219 assertions

npm test -- --run frontend/src/features/chat/ConversationDebugDownloadButton.test.tsx
# 3 passed

npm run typecheck
# passed
```

La richiesta HTTP Herd e le verifiche pgvector/Redis/disco KB sono PASS. La
suite PHP completa è stata avviata come controllo esplorativo; è stata fermata
quando ha esposto il primo errore riproducibile, quindi non costituisce una
certificazione complessiva. Il test isolato
`tests/Feature/Agent/DefaultAgentRunHandlerTest.php` termina con 1 errore su
2: alla riga 203 si attende `Non risultano ordini disponibili per il cliente
richiesto.`, mentre il codice restituisce `Non trovo ‘Tizio’ nelle fonti
disponibili. Puoi indicare lo spelling corretto o una fonte?`.

## Blocchi da risolvere

| Priorità | Blocco | Owner proposto | Azione prima della demo |
| --- | --- | --- | --- |
| P0 | `origin/main` non è antenato di `origin/develop` | maintainer Git | riallineare con PR `main → develop`, senza riscritture |
| P0 | failed `IngestDocumentJob` su `kb-ingest` | backend | diagnosticare payload/redrive e fissare test di regressione |
| P0 | nessun worker persistente osservato | ambiente | avviare/supervisionare worker per `kb-ingest` e `agent` |
| P0 | migrazioni locali pendenti | backend/maintainer | verificare compatibilità e applicare solo con piano rollback |
| P0 | suite PHP non verde: contratto agent divergente | backend agent | decidere il contratto desiderato e aggiornare implementazione o test |
| P1 | IMAP storico in errore | integrazioni | creare mailbox demo dedicata, testare sync e checkpoint |
| P1 | Gescat e Oktodora non certificati live | integrazioni | definire tenant demo, eseguire smoke redatti e registrare audit |
| P1 | disco locale quasi saturo | ambiente | liberare spazio o spostare lo storage demo prima di ingestion |
| P2 | `public/storage` non collegato | frontend/ops | creare il link se la demo deve servire asset pubblici |

## Prossima azione

Prima di passare al Giorno 2, ripetere la baseline una volta risolti i blocchi
P0 e registrare un tenant/progetto demo esplicito. La prima verifica live deve
essere una ingestion di file innocuo e noto, con worker attivo e tracciabilità
di batch, job, chunk ed embedding.
