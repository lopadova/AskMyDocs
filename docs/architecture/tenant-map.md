# E00.03 — tenant map e partizionamento

## Decisione

AskMyDocs separa dati tenant-owned da infrastruttura condivisa. Ogni dato che contiene payload, stato operativo, ACL, audit o identificatori di business porta `tenant_id` e viene letto con il tenant esplicito del contesto/flow. Le sole condivisioni ammesse sono artefatti derivati dichiarati pubblici o strutture statiche senza payload tenant.

| Superficie | Stato corrente | Partizionamento | Gate |
|---|---|---|---|
| documenti, chunk, chat, conversazioni, messaggi | tenant-aware | `tenant_id` + project/ACL | E00.03 |
| nodi, archi, canonical audit | tenant-aware | `tenant_id` + project | E00.03 |
| Flow run/steps/audit/approval/outbox | tenant-aware host adapter | `tenant_id`; idempotency scoped | E00.03 |
| queue job ingest | tenant catturato nel payload | `tenantId` serializzato; restore in `finally` | E00.03 |
| `embedding_cache` | condivisa deliberatamente | `(text_hash, provider, model)`; solo vettore derivato, nessun testo/payload | condivisione pubblica esplicita |
| `flow_definitions` | globale | definizioni statiche/versionate, nessun payload tenant | E03.01 |
| `flow_node_children` | globale ma non popolata | contiene input/output: vietata finché non tenantizzata | E03.01 |
| `flow_node_cache` | globale ma non popolata | cache contenuto senza tenant: vietata per output privati | E03.01 |
| `users`, queue plumbing, failed jobs | infrastrutturale | non tenant-owned; autorizzazione su membership/context | E01 |

## Chiavi obbligatorie

- Persistenza tenant-owned: `tenant_id` prima di `project_key`, `document_id`, ACL e identificatori locali.
- Flow: `tenant_id` nell'input, `TenantContext`, correlation/idempotency key e righe persistite.
- Cache embedding condivisa: hash del testo già trasformato secondo policy PII, provider e modello; non memorizza testo sorgente.
- Cache privata futura: `tenant_id + policy_digest + prompt_digest + model + ACL_digest + input_digest`; sharing solo con flag/contratto pubblico esplicito.
- Queue: il worker non eredita implicitamente il tenant precedente; ogni job deve catturare, impostare e ripristinare il contesto.

## Evidenze locali

- `TenantIdMandatoryTest`: modelli tenant-aware e `tenant_id` verificati.
- `StepTenantBinderTest`: 6 test, 10 assertion; input mancante/malformato negato, input valido ribinda il contesto.
- `IngestDocumentJobIdempotencyKeyTest`: chiave separata per tenant, progetto, path e retry.
- Compensator tests: rollback cross-tenant negato per documenti, chunk e nodi.
- `GraphExecutorNotAdoptedTest`: DAG host non invocato; il gate resta attivo.

## Gap e gate successivi

Manca una prova con PostgreSQL e processi/connessioni separati che dimostri A→B dopo eccezione su worker persistente e osservatore DB indipendente. Non popolare o migrare le tabelle graph prima di E03.01; E00.03 produce il contratto e la matrice, non abilita il DAG.
