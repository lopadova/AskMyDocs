# Demo readiness audit — G1

**Data:** 22 settembre 2026<br>
**Ambiente verificato:** locale Herd `https://askmydocsdev.test`<br>
**Tenant e progetto di certificazione:** `autry`<br>
**Scope esterno:** nessuna sincronizzazione IMAP reale. Le attività GitFlow
descritte sotto sono state eseguite con autorizzazione esplicita.

## Esito sintetico

I P0 tecnici locali sono chiusi: contratto agentico prudente, rifiuto dei PDF
dichiarati ma non validi, migrazioni applicate e provate in clone, runtime
persistente e ingestion Autry completata fino alla citazione e al cleanup.

| Area | Stato | Evidenza |
| --- | --- | --- |
| Contratto Agent API | PASS | fallback senza claim: `insufficient`, nessuna citazione o fonte strumento; payload pubblico invariato |
| Ingestion IMAP PDF | PASS | firma `%PDF-` richiesta per MIME `application/pdf`; mismatch auditato e non accodato |
| Failed jobs storici | PASS | tre job diagnosticati, archiviati nel backup e rimossi puntualmente senza retry/flush |
| Migrazioni locali | PASS | tre migrazioni applicate; prova up/down in clone riuscita |
| Worker e scheduler | PASS | tre agenti `launchd` attivi e riavviabili; log senza nuovi errori |
| Ingestion Autry | PASS | upload → ingest → chunk/embedding → ricerca → citazione → cancellazione fixture |
| Sintesi agentica live Autry | BLOCCATO esterno | chiave `OPENROUTER_API_KEY` non configurata; il contratto e la citazione di retrieval sono verificati localmente |
| Probe IMAP Autry | BLOCCATO esterno | configurazione priva di host; nessuna sincronizzazione reale avviata |
| GitFlow remoto | PASS | PR [#504](https://github.com/lopadova/AskMyDocs/pull/504) fusa con merge commit `2419f330`; `origin/main` è antenato di `origin/develop` |

## Contratto agentico e ingestion

`DefaultAgentRunHandlerTest` ora certifica il fallback prudente per una
risposta senza claim verificabili: `completeness=insufficient`, nessuna
citazione né `tool_sources`, limitazione `missing_claims` e richiesta di una
fonte o della grafia corretta. I casi con evidenze fondate restano separati e
conservano le citazioni. Non è stato modificato alcun campo del payload Agent
API (`answer`, `completeness`, `citations`, `tool_sources`, `limitations`).

Il bridge host rifiuta una sorgente IMAP dichiarata `application/pdf` se i
primi byte non corrispondono alla firma PDF. Scrive un audit secret-free con
ragione `declared_mime_signature_mismatch`, quindi solleva
`UnsupportedIngestionSourceException`: il job non viene accodato e il caller
non può confermare il messaggio, perciò il checkpoint IMAP non avanza. I test
coprono sia il rifiuto sia il PDF valido.

I tre failed job preesistenti sono stati prima registrati nel manifesto di
backup: due inviti riferivano record ormai assenti e
`10661-2026-31.pdf` non aveva una firma PDF valida. Sono stati rimossi solo i
tre UUID identificati con `queue:forget`; non sono stati né ritentati né
svuotati altri job. `queue:failed` risulta vuota.

## Migrazioni e rollback

Prima dell'intervento il volume disponeva di oltre 5 GiB liberi. È stato
salvato fuori dal repository un dump PostgreSQL recuperabile, il manifesto e
uno snapshot redatto di `migrate:status`.

Le migrazioni applicate a step sono:

- `2026_10_03_000001_create_chat_folders_table`;
- `2026_10_03_000002_add_organisation_to_conversations_table`;
- `2026_10_03_000003_add_session_recap_to_conversations_table`.

`migrate:status` non riporta più migrazioni pendenti. Nel clone temporaneo del
database il ciclo applicazione completa → rollback di tre step ha rimosso
tabella `chat_folders` e colonne `chat_folder_id`, `pinned_at`, `archived_at`,
`importance`, `session_recap`; il database locale operativo è rimasto
aggiornato. Gli smoke test di cartelle, organizzazione conversazioni e recap
sono verdi.

## Runtime locale persistente

Le configurazioni riproducibili sono in [`ops/launchd`](../ops/launchd):

- `com.askmydocsdev.queue-core`: code `agent,kb-ingest,default`;
- `com.askmydocsdev.queue-connectors`: coda `connectors`;
- `com.askmydocsdev.scheduler`: `schedule:work`.

Lo script `ops/launchd/install-local.sh` usa PHP Herd, imposta il PATH
necessario ai wrapper Herd e riavvia gli agenti. Tutti e tre sono stati
caricati e verificati in stato `running` dopo una reinstallazione; i log dedicati in
`storage/logs/` non riportano errori. Lo scheduler ha
`CONNECTOR_SCHEDULED_SYNC_ENABLED=false`: gli altri task pianificati restano
attivi, ma non possono avviare sync o backfill automatici dei connector.

## Certificazione Autry

Il file benigno `g1-readiness-20260922` è stato caricato attraverso il normale
servizio di staging/commit dell'admin. Il batch
`01a0c8a8-5d58-7214-be6c-dbbbba7013c8` ha prodotto il documento `13002`, un
chunk e un embedding; la ricerca semantica lo ha restituito come risultato
primario e il builder ha generato una citazione riferita al documento. Non si
sono creati failed job.

Il fixture è stato poi rimosso solo attraverso il flow `kb.delete`, run
`ca8797fb-3f5e-4031-970a-e22a040f40df`; documento, chunk e file sorgente non
esistono più. La verifica di una risposta testuale live con quella citazione è
rinviata finché non sarà configurata una chiave OpenRouter: non è stata
inventata né stampata alcuna credenziale.

È stato eseguito esclusivamente un probe IMAP in sola lettura. Ha rilevato
l'assenza dell'host di connessione e ha restituito un errore tipizzato; non ha
archiviato messaggi, non ha accodato job e non ha fatto avanzare checkpoint.

## GitFlow

In una worktree isolata è stato creato
`chore/sync-main-into-develop-20260922` da `origin/develop`. Il merge
`origin/main → branch` è stato risolto esplicitamente e committato come
`42ceafc1`. La PR [#504](https://github.com/lopadova/AskMyDocs/pull/504) è
stata fusa con merge commit `2419f330`; dopo il fetch,
`git merge-base --is-ancestor origin/main origin/develop` è verde.

La prima CI ha evidenziato un test di contratto rimasto sul comportamento
precedente. È stato allineato al fallback prudente e verificato localmente;
la seconda CI ha superato RAG regression gate, dependency audit, Vitest e la
suite PHPUnit completa. Il job Vitest installa ora le dipendenze Composer
prima di `npm ci`, perché il client realtime è una dipendenza locale fornita
dal package PHP.

`feature/demo-g1-audit-baseline` è stata ricreata da `origin/develop`. Prima
della ricostruzione sono stati creati il ref di backup
`backup/feature-demo-g1-audit-baseline-pre-rebuild-20260922` e un bundle
verificato fuori dal repository. Sono stati riportati export transcript,
audit e modifiche G1; il commit del fallback agentico è stato saltato perché
già incluso dal merge di sincronizzazione.

## Gate eseguiti

```text
herd php artisan test tests/Feature/Agent/DefaultAgentRunHandlerTest.php \
  tests/Feature/Connectors/HostIngestionBridgeTest.php \
  tests/Feature/Connectors/ImapSyncProgressTest.php
# 45 passed, 130 assertions

herd php artisan test tests/Feature/Connectors/SerializedSyncSchedulerTest.php \
  tests/Feature/Connectors/ImapBackfillTest.php \
  tests/Feature/Api/ChatFolderControllerTest.php \
  tests/Feature/Api/ConversationOrganizationTest.php \
  tests/Feature/Chat/ConversationRecapServiceTest.php
# 67 passed, 255 assertions

plutil -lint ops/launchd/*.plist.template
zsh -n ops/launchd/install-local.sh
# passed

herd php artisan test
# 4,310 passed, 1 skipped (vincolo Windows), 19,732 assertions

npm run typecheck
# passed

npm test -- --run frontend/src/features/chat/ConversationDebugDownloadButton.test.tsx
# 3 passed

GitHub Actions, PR #504
# RAG regression gate, dependency audit, Vitest e PHPUnit verdi

herd php artisan migrate:status
herd php artisan queue:failed
curl --fail https://askmydocsdev.test/login
# nessuna migrazione pending; nessun failed job; HTTP 200
```

## Ownership e residui

| Priorità | Azione | Owner |
| --- | --- | --- |
| P1 | configurare host IMAP Autry e autorizzare esplicitamente una sync reale | owner integrazione |
| P1 | configurare `OPENROUTER_API_KEY` e ripetere la sintesi agentica live citata | owner AI/segreti |
| P1 | liberare spazio sul volume locale, vicino alla saturazione | owner ambiente |
| P1 | certificare smoke live Gescat e Oktodora, fuori dallo scope G1 | owner integrazioni |

Nessun P0 tecnico locale resta aperto. Le sole attività bloccate richiedono
segreti o configurazione esterna esplicita; la release della feature G1 resta
disciplinata dai normali gate della relativa PR.

## G2 — upload e ingestion file (23 settembre 2026)

La baseline schema locale è stata riallineata applicando le cinque migrazioni
pendenti `2026_10_02_000009`–`000013`: provenienza della versione, indice di
`markdown_path`, univocità tenant-scoped di documenti, page review e correction
candidates. `migrate:status` non riporta più migrazioni pendenti.

L'accettazione live ha usato un file Markdown temporaneo nel tenant e progetto
`autry`, path `g2-acceptance/askmydocs-g2-ingestion-20260923.md`. Il primo
batch ha completato staging → storage → job `kb-ingest` → conversione → tre
chunk ordinati → embedding a 1536 dimensioni; la ricerca pgvector ha restituito
il documento fra i primi risultati. Un secondo commit degli stessi byte ha
mantenuto una sola versione attiva, senza duplicare chunk. Un terzo commit,
con contenuto diverso alla stessa path, ha archiviato la versione precedente e
reso attiva una nuova versione con tre chunk; il nuovo marcatore è stato
recuperato dalla ricerca. Le due versioni e il file di fixture sono poi stati
hard-deleted con `DocumentDeleter`: nessun documento di accettazione resta nel
tenant.

La suite browser `frontend/e2e/kb-upload.spec.ts` è verde (7 scenari). Il
problema iniziale non era nel contratto upload: i worker Playwright eseguivano
in parallelo `/testing/reset`, che usa `migrate:fresh` sul singolo database di
test condiviso. La configurazione ora esegue la suite con un worker fino a
quando non sarà introdotto un database isolato per worker. È stato inoltre
ricompilato il bundle frontend prima della prova: il bundle presente era
anteriore alla riga UI della stima OCR.

I test PHP mirati di staging, commit, magic-byte, progress, OCR, parser,
chunking, PDF/DOCX e cache embedding sono verdi. Le failure UI per tipo non
supportato e immagine con OCR disattivato mostrano entrambe una risposta 422
esplicita.

### Riesecuzione UI e correzioni di chiusura

Lo smoke manuale su `https://askmydocsdev.test` ha usato il solo Markdown
temporaneo `askmydocs-g2-ui-smoke-20260923.md`, senza dati reali, nel tenant
`acme` e progetto `acme-kb`. I batch `01a0ce93-5cdf-70af-86bf-a0fdade6b281`,
`01a0ce94-9172-729d-964c-a893c3d33a38` e
`01a0ce95-2747-7361-9114-15773becdf82` hanno tutti raggiunto lo stato UI
`SUCCEEDED`; sono stati acquisiti screenshot sia della review di staging sia
del risultato del batch. Il primo upload ha creato due chunk, entrambi con
embedding; la ricerca del marcatore `violet compass ledger 4a8e` ha restituito
la fonte corretta. Il secondo upload, a byte invariati, ha riusato il documento
`16811` e i suoi due chunk. Il terzo, modificato alla stessa path, ha creato il
documento `16812`, archiviato `16811` e mantenuto una sola versione attiva; la
ricerca di `jade orbit 7c51` ha restituito il nuovo testo e la stessa fonte.

La riesecuzione ha evidenziato due casi limite coperti ora dal prodotto e dai
test: ogni item di un batch UI riceve un `runKey` Flow distinto, così byte
modificati alla stessa path attraversano nuovamente l'ingestor; quando un
re-ingest identico è un no-op, il listener collega l'item completato al
documento attivo già esistente. Il contratto pubblico non cambia: il job
`IngestDocumentJob` usa i tre tentativi automatici con backoff `10, 30, 60`,
e il listener porta l'ultimo errore a item `failed` e batch
`completed_with_errors`, senza endpoint manuali di retry o reset.

Verifiche ripetute:

```text
npm run build
# completato
npm run e2e -- frontend/e2e/kb-upload.spec.ts
# 7 passed (22.1s), un solo worker
herd php artisan test [upload, progress, ingest, parser, chunk, embedding]
# verde: staging/magic byte/dimensione/duplicati/path, failure parziali,
# retry, versioning, PDF/DOCX e retrieval pipeline
```

Dopo la verifica, `DocumentDeleter` ha hard-delete entrambe le versioni e il
file sul disk KB. Il controllo finale rileva `0` documenti, `0` chunk e file
sorgente assente per la fixture; la copia locale temporanea è nel Cestino,
quindi non è più in `/tmp` ed è recuperabile se dovesse servire un riesame.

Resta un rischio operativo preesistente: al controllo finale `failed_jobs`
contiene 2.526 record storici (in prevalenza `kb-ingest` da precedente backfill
IMAP). Non sono stati ritentati, eliminati o svuotati. Per non alterare più il
backlog in modo autonomo, `CONNECTOR_SCHEDULED_SYNC_ENABLED=false` resta
nell'ambiente locale e il servizio launchd `com.askmydocsdev.queue-connectors`
è disabilitato; `com.askmydocsdev.queue-core` resta abilitato e non c'erano job
`kb-ingest` in attesa al controllo finale.
