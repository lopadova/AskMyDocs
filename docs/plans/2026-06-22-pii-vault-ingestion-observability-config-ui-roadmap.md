# Roadmap enterprise — Connettori multi-account, Ingestion/Sync Observability, Runtime Config UI & PII reversible-vault

> **Stato:** proposta approvata (roadmap), NON ancora implementata
> **Data:** 2026-06-22 (riprogettata 2026-06-22 dopo verifica connettori + nuove esigenze)
> **Trigger:** verifica del connettore IMAP (`padosoft/askmydocs-connector-imap`) → emergono limiti che
> valgono per **tutti** i connettori (multi-credenziale, binding a progetto, parallelismo/osservabilità code)
> **Decisione di consegna:** roadmap unica multi-ciclo (v8.20 → v8.21 → v8.22 → v8.23), eseguita ciclo per
> ciclo su via libera esplicita
> **Documenti collegati:** piano operativo in `~/.claude/plans/wise-rolling-volcano.md`;
> ADR da redigere per ciascun ciclo; pagina deep doc-site (R45) per ciascun ciclo

---

## 1. Executive summary

Provando il connettore IMAP sono emerse esigenze di livello enterprise/helpdesk che oggi AskMyDocs copre solo
parzialmente. Tre nuove (emerse 2026-06-22) riguardano **tutti** i connettori, non solo IMAP:

1. **Connettori mono-account, non legabili a un progetto** — ogni connettore ammette **una sola credenziale
   per tenant** (`connector_installations.unique(tenant_id, connector_name)`, vault con un secret per
   installazione). Non si possono collegare più caselle IMAP, più account Drive/OneDrive, più workspace
   Notion. E il documento ingestionato non è legabile (facoltativamente) a uno specifico progetto: oggi va
   in un progetto sintetico `connector-<key>`, non al progetto reale dell'operatore né al default tenant.
2. **Code di sync non isolate né osservabili** — sync e ingestion sono già su code diverse, ma **tutti i
   connettori condividono UNA sola coda di sync** e l'operatore non vede profondità code, storico sync
   per-account, né stato per-documento.
3. **Configurazione e privacy/PII solo da env/deploy** — cadenza sync, provider/modello AI, master-switch
   package non sono configurabili da UI; e con la config di default **nulla viene offuscato** in ingestion.

Le altre due esigenze storiche restano: **osservabilità dell'ingestion** e **PII/AI-Act in ingestion**
(la KB deve essere PII-safe per default con re-identificazione gated per l'helpdesk autorizzato).

**Ordine di consegna (deciso 2026-06-22):** prima si potenziano i connettori (multi-account + binding a
progetto), poi l'osservabilità + baseline code, poi la config da UI, **infine il PII** — perché non sono
ancora state importate mail reali (solo test interni), quindi la finestra è ideale per costruire il vault
PII senza esposizione attiva.

---

## 2. Stato dell'arte OGGI (cosa fa il codice, con riferimenti verificati)

### 2.1 Connettori: account singolo + binding progetto

| Cosa | Stato reale | Dove |
|---|---|---|
| Installazioni per connettore | **una sola per tenant** (`unique(tenant_id, connector_name)`) | `vendor/padosoft/askmydocs-connector-base/database/migrations/0001_01_01_000028_create_connector_installations_table.php:52` |
| Credenziale | un secret per `installationId` (vault) → multi-account = multi-install obbligato | `OAuthCredentialVault`, `vendor/.../askmydocs-connector-imap/src/ImapConnector.php:507` |
| `project_key` | letto da `config_json['project_key']` con fallback `connector-<key>`, **identico su tutti gli 8 connettori** | grep verificato: confluence:248, evernote:238, fabric:144, google-drive:210, imap:258, jira:256, notion:189, onedrive:264 |
| Host configure | upsert **single-row** su `(tenant, connector_name)`; accetta già `project_key`→config_json ma **nessuna UI**, nessuna validazione sui progetti reali | `app/Services/Admin/Connectors/ConfigureConnectorService.php:71,184` |
| Logica sync | già **per-`installationId`** in ogni connettore; lo scheduler itera **tutte** le installazioni attive (chunkById) | `vendor/.../askmydocs-connector-imap/src/ImapConnector.php:254`, `vendor/.../Scheduling/SyncScheduler.php:88` |
| Path su disco | già include `installation-%d` → installazioni multiple non collidono | `vendor/.../askmydocs-connector-imap/src/ImapConnector.php:371,397` |

**Repo connettori (verificati):** `-base` + `-template` (scaffold) + 8 funzionali installati
(`google-drive`, `notion`, `onedrive`, `evernote`, `fabric`, `confluence`, `jira`, `imap`).

> **Conclusione architetturale.** Il multi-account è **principalmente un cambio di data-model nel package
> `askmydocs-connector-base`** (rilascio del vincolo unique + colonne `label`/`project_key`) più adozione
> host (service/UI/API da single-row a multi-row). La logica di sync dei connettori è **già** per-installazione
> e lo scheduler **già** itera N installazioni: il blocco è il vincolo unique + il vault per-installazione +
> l'assunzione "una installazione per connettore" della UI/service host.

### 2.2 Code di sync e ingestion

| Fase | Job | Coda (default) | Config |
|---|---|---|---|
| Sync connettore | `ConnectorSyncJob` | `default` | `connectors.sync_job_queue` ← `CONNECTOR_SYNC_JOB_QUEUE` (`config/connectors.php:114`) |
| Ingestion del singolo doc | `IngestDocumentJob` | `kb-ingest` | `kb.ingest.queue` ← `KB_INGEST_QUEUE` (`config/kb.php:608`) |

- **Sync e ingestion sono già disaccoppiati:** il `ConnectorSyncJob` chiama
  `HostIngestionBridge::dispatchIngestion()` che fa `IngestDocumentJob::dispatch()` sulla coda `kb-ingest`
  **e ritorna subito** — l'ingestion **non** si accoda "dopo" il sync (`app/Connectors/HostIngestionBridge.php:81`).
- **Il vero limite è la coda sync condivisa:** tutti i connettori mettono il `ConnectorSyncJob` sulla
  **stessa** coda (`connectors.sync_job_queue`, default `default`, che porta anche autowiki + change-analysis).
  Non esiste override di coda per-connettore. Il parallelismo dipende solo dal numero di worker.

### 2.3 Osservabilità ingestion

**Esiste:** KPI globale `pending_jobs`/`failed_jobs` (`AdminMetricsService::kpiOverview()`,
`GET /api/admin/metrics/overview`, `KpiStrip.tsx`), tab Failed Jobs (`FailedJobsTab.tsx`,
`LogViewerController`), tracking saga interno `flow_runs` / `flow_steps` / `flow_audit`
(`app/Flow/Definitions/IngestDocumentFlow.php`).

**Manca:** tabella "sync run" per-account/installazione (scaricate/ingestionate/fallite/durata); stato
osservabile per-documento (scaricato → in coda → embedding → indicizzato); esposizione API/UI di `flow_runs`;
screen di monitoraggio coda/sync; profondità code. Oggi solo `connector_installations.status` + `error_json`
+ audit `connector_*` senza metriche.

### 2.4 Configurazione runtime

| Cosa | Stato | Dove |
|---|---|---|
| Credenziali connettore | ✅ **UI-editabile** (form schema-driven, secret cifrato) | `frontend/src/features/admin/connectors/*`, `ConnectorAdminController`, `ConfigureConnectorService` |
| Cadenza sync per connettore | ❌ solo env | `config/connectors.php` → `CONNECTOR_DEFAULT_SYNC_CADENCE_MINUTES`, `per_connector_cadence` |
| Provider / modelli AI | ❌ solo env | `config/ai.php` → `AI_PROVIDER`, `*_CHAT_MODEL` |
| Master-switch package | ❌ solo env | `AI_GUARDRAILS_*`, `AI_FINOPS_*`, `FLOW_ADMIN_ENABLED`, `EVAL_HARNESS_UI_ENABLED`, `KB_PII_*` |

Pattern da riusare per il per-tenant/progetto: **`app/Models/KbAnalysisSetting.php`** (riga `project_key='*'`
= default tenant, righe per-progetto override, `null` eredita; resolver `ChangeAnalysisGate`). Guardrails ha
già una tabella runtime `ai_guardrails_settings` (API `PUT /api/admin/ai-guardrails/settings`) ma **senza SPA**.

### 2.5 PII / AI-Act in ingestion

- **Default = tutto RAW.** 11 knob PII tutti OFF; ingestion HTTP/CLI salva raw
  (`KbIngestController` `Storage::put($bytes)`, `DocumentIngestor::persistChunks()` raw `chunk_text`).
- **Gancio opzionale, non inline:** `HostIngestionBridge::redactContent()`
  (`app/Connectors/HostIngestionBridge.php:115`) — usa **solo `MaskStrategy`** (distruttiva, irreversibile),
  gated `kb.pii_redactor.enabled` + `kb.pii_redactor.redact_before_ingest`, da invocare dal connettore.
- **Embedding:** `EmbeddingCacheService::maskPiiIfEnabled()` redige solo il testo transitorio inviato al
  provider; `chunk_text` resta raw.
- **Vault reversibile già nel package:** `TokeniseStrategy` (token deterministici `[tok:<detector>:<id>]`,
  salt-based, stabili cross-process → ricerca/join funzionano), `TokenStore/{DatabaseTokenStore,
  TokenResolutionService,DetokeniseResult}`, tabella `pii_token_maps`.
- **Detokenize già parziale:** `POST /api/admin/logs/chat/{id}/detokenize`
  (`LogViewerController::chatDetokenize`), gate `detokenisePiiRedactor` (dpo/super-admin).
- **Gap critici:** `pii_token_maps` **NON tenant-scoped** (viola R30/R31, salt globale → correlazione
  cross-tenant); il path di ingestion **non tokenizza**; detokenize **non tri-surface** (R44); **nessun config
  PII per-tenant/progetto**; AI-Act è solo DSAR/consent/bias/incidents su chat, **nessun hook ingestion**.

**Verdetto su 1000 email IMAP con config di default:** PII in chiaro in (a) markdown su disco,
(b) `chunk_text` in DB, (c) embedding, (d) contesto LLM. La redazione è opt-in, mai attiva di default.

---

## 3. Roadmap (4 cicli, nuovo ordine deciso 2026-06-22)

Sequenza: **connettori → observability+code → config-UI → PII (ultimo)**. Ogni ciclo = milestone Wn su
`feature/v8.x`, tag `vX.Y.0-rcN` (R39), merge unico a main a fine ciclo (R37).

### Ciclo 1 — v8.20 "Connettori multi-account & project-scoped"

**Obiettivo:** più credenziali per lo stesso connettore (es. N caselle IMAP, N account Drive/OneDrive,
N workspace Notion) e binding **facoltativo** di ciascun account a un progetto (vuoto = default tenant),
per **tutti** gli 8 connettori.

**Package `askmydocs-connector-base` (bump release, poi update dipendenza in AskMyDocs):**
- Migration su `connector_installations`: aggiunge `label` (string, default `'default'`) e
  `project_key` (string nullable, indicizzato); **rilassa** `unique(tenant_id, connector_name)` →
  `unique(tenant_id, connector_name, label)`; backfill righe esistenti a `label='default'`.
  R30/R31: la composite unique inizia con `tenant_id`; il modello è già `BelongsToTenant`. Aggiornare
  `$fillable`.
- `BaseConnector::resolveProjectKey(ConnectorInstallation $i): string` →
  `$i->project_key ?: config('kb.ingest.default_project','default')`. **Single source** della semantica
  fallback (sostituisce il `connector-<key>` sparso).

**8 package connettore (bump uniforme, one-liner ciascuno):**
- Sostituire `$config['project_key'] ?? ('connector-'.$this->key())` con
  `$this->resolveProjectKey($installation)`. Nessun'altra modifica (logica già per-installazione).
- *Alternativa low-churn (se si vuole evitare 8 release):* l'host scrive sempre `project_key` anche in
  `config_json` con il default tenant → i connettori restano invariati e il fallback `connector-<key>` diventa
  codice morto. **Default consigliato:** bump pulito di tutti (semantica unica, niente codice morto).

**Host AskMyDocs:**
- `ConfigureConnectorService`: da upsert single-row a **gestione multi-installazione** keyed su
  `(tenant, connector_name, label)`; flusso create-new vs edit-by-id; persiste `project_key` come **colonna**,
  validato contro i progetti reali del tenant.
- `ConfigureConnectorRequest`: aggiunge `label` (required, unico per connettore/tenant) e `project_key`
  (nullable, `exists` sui progetti reali — R18; vuoto = default tenant).
- API: estendere `ConnectorAdminController` per **listare/creare/editare/eliminare installazioni** per
  connettore. Righe matrice R32 per ogni endpoint nuovo.
- UI `frontend/src/features/admin/connectors/`: da "lista connettori" a "lista **account** per connettore"
  con campo `label`, **dropdown progetto** (opzioni da endpoint progetti reali, R18) + opzione
  "Globale (default tenant)", add/edit/remove account. R11/R12/R13/R29 + a11y R15.
- Verifica: la rotta `oauth/callback` è già per-`installationId`; scheduler/SyncJob già multi-install.

**Tri-surface R44:** PHP (comando `connectors:install` / `connectors:list` o service) + HTTP (endpoint sopra)
+ MCP (tool read installazioni/stato; bump conteggio in `KnowledgeBaseServerRegistrationTest`), su UN core.
**R43:** comportamento testato con binding impostato E vuoto (default tenant) — entrambi puliti.
**R28-style:** unique per-`(tenant, connector, label)` corretto + cascade `connector_credentials` su delete
installazione. **R45/R39:** pagina deep doc-site + README (multi-account + project scoping).

### Ciclo 2 — v8.21 "Ingestion & Sync observability + queue baseline"

**Obiettivo:** l'operatore vede code, storico sync per-account e stato per-documento; le code sync sono
isolate dal resto e scalabili in parallelo.

- **Queue baseline (Opzione A — niente routing per-connettore):** isolamento
  `CONNECTOR_SYNC_JOB_QUEUE=connectors` (toglie il sync da `default`, che porta autowiki/change-analysis),
  `KB_INGEST_QUEUE=kb-ingest`; topologia worker dedicati per coda (`queue:work --queue=connectors`,
  `--queue=kb-ingest`, `--queue=default`) + nota Horizon per autoscaling. Solo config + doc + (eventuale)
  wiring scheduler. *(Il routing per-connettore/per-progetto è rimandato: si aggiunge solo se un connettore
  diventa "noisy neighbor".)*
- **`connector_sync_runs`** (tabella tenant-scoped): per run di `ConnectorSyncJob` → `installation_id`,
  `started_at`, `finished_at`, `items_discovered`, `items_ingested`, `items_failed`, `status`, `error_json`.
  Popolata in `ConnectorSyncJob`/`SyncResult`.
- **Stato per-documento = derivazione da `flow_runs`** (no colonna dedicata), esposto read-only via API.
- **API:** `GET /api/admin/ingestion/queue` (profondità code: `connectors`/`kb-ingest`/`default`),
  `GET /api/admin/connectors/{installationId}/sync-runs`, `GET /api/admin/ingestion/flow-runs`. Righe R32.
- **UI:** screen "Ingestion & Sync" (`frontend/src/features/admin/ingestion/`): profondità coda per nome,
  storico sync per-account/installazione, progress per-documento, empty/loading/error, polling. R11/R12/R13/R29.
- **MCP (R44):** `KbIngestionStatusTool` read-only (bump conteggio). **R43/R45/R39** da governance.

### Ciclo 3 — v8.22 "Runtime configuration governance"

**Obiettivo:** configurare connettori e package da UI per-tenant/progetto, senza deploy.

- **`app_settings` per-tenant/progetto** (pattern `KbAnalysisSetting`: riga `*`=default, righe per-progetto
  override, resolver tipo `ChangeAnalysisGate`): cadenza sync per connettore/account, provider/modello AI per
  tenant, master-switch package flippabili a runtime in sicurezza. Secret sempre nel vault, mai in UI.
- **Resolver di config** env (default) ← tenant `*` ← progetto, con i knob di sicurezza censiti come
  **deploy-only** (enforcement FinOps, master security switch).
- **UI:** estendere `analysis-settings/` con sezioni Connettori / AI / PII, e **portare la SPA Guardrails**
  (oggi solo API). R32 + R43.

### Ciclo 4 — v8.23 "PII-safe ingestion & reversible vault" (posticipato per ultimo)

**Obiettivo:** la KB è PII-safe per default; l'helpdesk autorizzato re-identifica on-demand. *(Posticipato:
nessuna mail reale ancora importata — finestra utile per costruirlo senza esposizione attiva. Si innesta sul
binding-progetto del Ciclo 1 per le policy per-progetto.)*

- **Bump del package `laravel-pii-redactor`** con `TokenStore` **tenant-aware** (tabella `pii_token_maps`
  estesa a `tenant_id` + `UNIQUE(tenant_id, token)` + salt per-tenant); poi update dipendenza in AskMyDocs.
- **Tokenizzazione in ingestion:** `TokeniseStrategy` (al posto/accanto a `MaskStrategy`) nel boundary
  connettore (`HostIngestionBridge::redactContent()`) e nel path inline (`DocumentIngestor` prima del
  chunking). Markdown su disco + `chunk_text` tokenizzati; il vault tiene l'originale. **Format-preserving
  tokens** (email→`x@y.z`, telefono→E.164).
- **Policy PII per-tenant/progetto** (`kb_pii_settings`, pattern `KbAnalysisSetting`, ereditarietà
  `progetto → tenant '*' → config`): per profilo/scopo, quali detector, quale strategia, quali entity-type
  detokenizzabili per ruolo+scopo. Resolver dedicato.
- **Detokenize tri-surface R44 gated per ruolo+scopo:** `DetokenizeService` (PHP) + endpoint HTTP generico
  (+ riga matrice R32) + `KbDetokenizeTool` MCP. Ogni unmask audit-trailato con reason code.
- **Right-to-erasure + DSAR via vault**, tri-surface: comando `kb:erase-subject` (crypto-shred) + endpoint +
  tool MCP; wiring DSAR-lookup in `laravel-ai-act-compliance`.
- **Anti-regressione embedding:** re-embed completo a cambio policy + gate CI di recall su gold-set.
- **EU AI Act Art. 50(1):** disclosure "stai interagendo con un'AI" nel chat UI.
- **R43/R45/R39:** flag testato OFF/ON; pagina deep doc-site Mintlify con la teoria (pseudonimizzazione
  reversibile vs anonimizzazione, tokenizzazione deterministica + format-preserving, vault per-tenant,
  redact-before-embed, re-id JIT gated, crypto-shred, DSAR, GDPR Art. 4(5)/15/17, AI Act Art. 50) +
  diagramma Mermaid + ADR-rationale + esempio worked; README.

---

## 4. Il dilemma helpdesk e la risposta allo stato dell'arte (supporto al Ciclo 4)

**Tensione:** modalità (a) privacy-max GDPR (tutto offuscato) vs (b) helpdesk-utile (devi sapere
«Mario Rossi, ticket #123» o non puoi aiutare).

**Lo stato dell'arte non sceglie.** Pattern convergente (Microsoft Presidio, Skyflow Data Privacy Vault,
Google Cloud DLP, AWS Bedrock RAG, Private AI):

1. **Detect → tokenizza prima dell'embedding.** Nell'indice/embedding entra solo un surrogato tipizzato e
   **deterministico** (`[NAME_1]`, `[TICKET_1]`, `x@y.z` format-preserving). Mai PII grezza nel vector store.
2. **Vault reversibile per-tenant fuori dal percorso AI** tiene la mappa token↔originale, cifrata con chiave
   per-tenant. Deterministico ⇒ «trova il ticket di Mario Rossi» continua a funzionare (stessa stringa →
   stesso token → match in ricerca).
3. **Re-identificazione JIT gated per ruolo+scopo a runtime.** L'agente helpdesk autorizzato vede l'identità
   reale; il self-service pubblico no. Esposto come tool/funzione (MCP) chiamato on-demand **solo** se il
   chiamante è autorizzato. Ogni unmask loggato con reason code.
4. **Policy-as-config per tenant × scopo** (`agent-assist` vs `public-self-service`): quali detector, quale
   strategia, quali entity-type detokenizzabili per ruolo.
5. **GDPR/EU AI Act:** la tokenizzazione è **pseudonimizzazione** (Art. 4(5)) — l'indice resta dato personale,
   ma il vault abilita **right-to-erasure via crypto-shred** (Art. 17) e **DSAR via lookup nel vault**
   (Art. 15). Per un chatbot di customer-service vale la **disclosure AI Act Art. 50(1)** in vigore dal
   **2 ago 2026**.

**Pitfall noti:** token deterministici espongono frequenza/uguaglianza → vault per-tenant-keyed (no
correlazione cross-tenant); la tokenizzazione **sposta i vettori** (embedding drift) → token tipizzati puliti
+ re-embed completo a ogni cambio policy + gate CI di recall su gold-set; collisione token →
`UNIQUE(tenant_id, token)`; latenza detokenize → batch in una sola call per risposta, nessuna cache del
detokenizzato; citazioni → renderizzate detokenizzate solo per chiamanti autorizzati.

**Fonti principali:** Microsoft Presidio (pseudonymization sample); Skyflow LLM Privacy Vault + tokenization
+ governance PBAC; Google Cloud DLP (deterministic/FPE/HMAC, reidentifyContent); AWS Bedrock RAG PII
(redact-before-embed con token tipizzati); Descope+Skyflow MCP (OAuth token-exchange → ruolo nel token →
policy PII a query-time); GDPR Art. 4(5)/15/17 + EDPB pseudonymisation guidelines 2025; EU AI Act Art. 50 +
timeline ufficiale (transparency 2 ago 2026).

---

## 5. Governance trasversale (tutti i cicli)

- **R44 tri-surface:** ogni capability = PHP + HTTP (+ riga R32) + MCP (tool su `KnowledgeBaseServer::$tools`,
  bump test di conteggio), sopra UN solo core service.
- **R30/R31 tenant isolation:** ogni nuova tabella tenant-aware ha `tenant_id` + composite unique che inizia
  con `tenant_id`; aggiornare le DUE liste (`TenantIdMandatoryTest` + `TenantReadScopeTest`). Tabelle nuove
  o estese: `connector_installations` (esteso, Ciclo 1), `connector_sync_runs` (Ciclo 2), `app_settings`
  (Ciclo 3), `kb_pii_settings` + `pii_token_maps` tenant-aware (Ciclo 4).
- **R18:** dropdown/filtri derivati dal dominio reale (progetti) via API, mai liste hard-coded.
- **R43:** ogni flag testato OFF e ON.
- **R45 doc-site + R39 README:** ogni ciclo aggiorna `/docs-site/` (pagina deep + `docs.json`) e README.
- **R40/R36:** local critic loop prima del push, poi loop Copilot/Codex + CI verde.

---

## 6. Decisioni risolte

1. **Ordine cicli:** connettori → observability+code → config-UI → **PII per ultimo** (nessuna mail reale
   importata, solo test interni).
2. **Multi-account per tutti i connettori = rilascio del vincolo unique a `(tenant, connector_name, label)`
   nel package `askmydocs-connector-base`** + bump dei connettori; logica sync già per-installazione.
3. **`project_key` = colonna reale** su `connector_installations`, selezionabile da **dropdown progetti
   reali** (R18); vuoto → **default tenant** (`kb.ingest.default_project`), sostituendo il fallback
   `connector-<key>`.
4. **Code = solo baseline** (isolamento coda `connectors` + worker/Horizon documentati + monitor nello
   screen observability); routing per-connettore/per-progetto **rimandato**.
5. **Vault PII tenant-scoped = bump del package `laravel-pii-redactor`** con `TokenStore` tenant-aware
   (NON migration solo-host).
6. **Stato per-documento = derivazione da `flow_runs`** (no verità duplicata).
7. **Format-preserving tokens = SÌ.** Documentazione Ciclo 4 approfondita anche nel doc-site Mintlify.

---

## 7. Verifica (per ciclo)

PHPUnit mirati + architettura (`TenantIdMandatoryTest`/`TenantReadScopeTest`); feature test HTTP + matrice
R32; MCP registration test aggiornato; Vitest + Playwright (happy + failure, real-data R13, testid R29);
gate CI di recall (Ciclo 4); suite R43 OFF/ON.

- **E2E Ciclo 1:** configura 2 account IMAP (label "Support", "Sales") sullo stesso tenant, uno legato al
  progetto `acme-hr` e uno senza progetto (→ default tenant); entrambi sincronizzano in parallelo, i
  documenti finiscono nei progetti corretti, l'unique `(tenant, imap, label)` rifiuta un terzo account con
  label "Support".
- **E2E Ciclo 2:** lo screen "Ingestion & Sync" mostra profondità code (`connectors`/`kb-ingest`), storico
  sync per i 2 account IMAP e progress per-documento; con `CONNECTOR_SYNC_JOB_QUEUE=connectors` il sync non
  compete con `default`.
- **E2E Ciclo 3:** cambio cadenza sync di un account da UI senza deploy; master-switch package flippato a
  runtime; resolver env←tenant←progetto verificato.
- **E2E Ciclo 4:** ingestiona N email IMAP con profilo `agent-assist` → markdown + `chunk_text` tokenizzati,
  ricerca trova ancora «ticket di Mario Rossi», agente autorizzato vede l'identità reale (API + MCP) e
  self-service no, `kb:erase-subject` rende i token inerti.
