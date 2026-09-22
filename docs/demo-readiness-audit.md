# Demo readiness audit — G1

**Data:** 22 settembre 2026<br>
**Ambiente verificato:** locale Herd `https://askmydocsdev.test`<br>
**Tenant e progetto di certificazione:** `autry`<br>
**Scope esterno:** nessuna sincronizzazione IMAP reale, nessun push o PR.

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
| GitFlow remoto | IN ATTESA | branch di sincronizzazione e merge commit locali pronti; push/PR restano al maintainer |

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
`42ceafc1`; il controllo di antenato `origin/main` e `git diff --check` sono
verdi, così come il test architetturale isolato. Il push e la PR con merge
commit verso `develop` restano intenzionalmente non eseguiti.

La ricreazione di `feature/demo-g1-audit-baseline` dal `develop` remoto
aggiornato dipende dal merge di quella PR. A quel punto vanno riportati i due
commit locali storici (export transcript e audit) insieme ai commit G1 di
questa worktree, senza riscrivere rami condivisi.

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

herd php artisan migrate:status
herd php artisan queue:failed
curl --fail https://askmydocsdev.test/login
# nessuna migrazione pending; nessun failed job; HTTP 200
```

## Ownership e residui

| Priorità | Azione | Owner |
| --- | --- | --- |
| P0 | push e PR del branch `chore/sync-main-into-develop-20260922` | maintainer Git |
| P1 | configurare host IMAP Autry e autorizzare esplicitamente una sync reale | owner integrazione |
| P1 | configurare `OPENROUTER_API_KEY` e ripetere la sintesi agentica live citata | owner AI/segreti |
| P1 | liberare spazio sul volume locale, vicino alla saturazione | owner ambiente |
| P1 | certificare smoke live Gescat e Oktodora, fuori dallo scope G1 | owner integrazioni |

Nessun altro P0 tecnico locale resta aperto. Le azioni esterne sono state
deliberatamente lasciate ferme per rispettare i limiti di autorizzazione.
