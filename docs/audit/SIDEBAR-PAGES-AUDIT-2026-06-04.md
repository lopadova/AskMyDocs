# Audit pagine sidebar — 2026-06-04

Audit read-only delle **24 voci di menu** della SPA admin, su 4 dimensioni per pagina:

0. **Utilità** — a cosa serve la voce + ruoli che la vedono.
1. **Mock** — legge dati reali (DB/disk) e non array hard-coded / `fake()` / stub / fixtures FE.
2. **Tenant + Progetto** — query scoped al tenant attivo (`forTenant`/`where('tenant_id')`) **e** filtro
   sul progetto scelto dove la pagina è project-aware.
3. **Funziona** — monta, carica dati reali, stati loading/empty/error, status code corretti.

Metodo: tracciamento codice FE → `api.ts` → route → controller → service → query, con verifica diretta
dei due finding ad alta gravità. Nessuna modifica al codice (audit). I fix sono nel **backlog** in fondo.

---

## Modello tenant/progetto (per leggere i finding)

- **`ResolveTenant`** (globale, gira per primo) imposta il tenant attivo su `TenantContext` da:
  `X-Tenant-Id` header → `tenant_id` dell'utente → `'default'`. `app/Http/Middleware/ResolveTenant.php:76-99`.
  ⚠ L'header ha priorità ed è **spoofabile**: chiunque sia autenticato può inviare `X-Tenant-Id: <vittima>`.
- **`tenant.authorize`** (`AuthorizeTenantHeader`, montato dopo `auth:sanctum` sui gruppi protetti) è il
  guard che **respinge l'header spoofato** salvo permesso `tenant.cross-access`.
  `app/Http/Middleware/AuthorizeTenantHeader.php:43-77`. → Qualunque rotta admin **senza** `tenant.authorize`
  è esposta a IDOR cross-tenant (il gate `can:`/`role:` è Spatie **globale**, non legato al tenant).
- **`forTenant($ctx->current())`** (trait `BelongsToTenant`) è lo scope applicativo che isola le query.
  Le query **raw** `DB::table(...)` NON ereditano alcuno scope: vanno filtrate a mano per `tenant_id`.
- **Progetto** ≠ tenant: un tenant ha più `project_key`; due tenant possono condividere lo stesso
  `project_key`. Il filtro progetto è **opzionale per endpoint** (`?project` / `project_keys[]` / `project_key`)
  e va passato dal FE (store globale `frontend/src/lib/project-store.ts` o `<select>` locale).

---

## Tabella riassuntiva (24 voci)

Legenda: ✅ ok · ⚠ gap/da-verificare · ❌ difetto bloccante · N/A non applicabile (motivato).

| # | Gruppo | Pagina | Mock | Tenant | Progetto | Funziona | Stato |
|---|---|---|---|---|---|---|---|
| 1 | Workspace | Chat | ✅ DB | ✅ forTenant | ✅ active project | ✅ | ✅ |
| 2 | Administration | **Dashboard** | ✅ DB | ❌ **no tenant filter** | ❌ FE non passa progetto | ⚠ dati cross-tenant | ❌ |
| 3 | Administration | AI Insights | ✅ DB | ✅ forTenant | N/A (snapshot tenant) | ✅ | ✅ |
| 4 | Administration | Users | ✅ DB | N/A cross-tenant (by design) | N/A | ✅ | ⚠ (mock list literal) |
| 5 | Administration | Roles | ✅ DB | N/A globale Spatie | N/A | ✅ | ✅ |
| 6 | Knowledge | Knowledge Base | ✅ DB | ✅ forTenant | ✅ `<select>` locale | ✅ | ✅ |
| 7 | Knowledge | Collections | ✅ DB | ✅ forTenant | N/A (tenant-global) | ✅ | ✅ |
| 8 | Knowledge | Synonyms | ✅ DB | ✅ forTenant | ⚠ FE non passa filtro | ✅ | ⚠ |
| 9 | Knowledge | Doc Insights | ✅ DB | ✅ forTenant | ⚠ FE non passa filtro | ✅ | ⚠ |
| 10 | Knowledge | Analysis Gate | ✅ DB | ✅ forTenant | ✅ matrice multi-progetto | ✅ | ✅ |
| 11 | Knowledge | Content Gaps | ✅ DB | ✅ forTenant | ⚠ FE non passa filtro | ✅ | ⚠ |
| 12 | Knowledge | Tabular Reviews | ✅ DB | ✅ forTenant | ⚠ filtro lista assente (noto W3.X) | ✅ | ⚠ |
| 13 | Knowledge | Workflows | ✅ DB | ✅ forTenant | N/A (template tenant) | ✅ | ✅ |
| 14 | Compliance | AI Act | ✅ DB | ✅ `tenant.authorize`+ctx | N/A (tenant) | ✅ flag OFF ok | ✅ |
| 15 | Compliance | Compliance Reports | ✅ DB | ✅ forTenant | N/A (tenant) | ✅ | ⚠ (FE tenant_id morto) |
| 16 | Compliance | **PII Redactor** | ✅ config | ❌ **no `tenant.authorize`** | N/A | ✅ flag OFF ok | ❌ (se flag ON) |
| 17 | Operations | Connectors | ✅ DB | ✅ where tenant_id | N/A (tenant) | ✅ | ✅ |
| 18 | Operations | Flows | ✅ DB | ✅ authorizer+gate | N/A (tenant) | ✅ flag OFF ok | ✅ |
| 19 | Operations | Eval Harness | ✅ DB | ✅ tenant header mw | N/A (tenant) | ✅ flag OFF ok (R43) | ✅ |
| 20 | Operations | MCP Tools | ✅ DB | ✅ where tenant_id | N/A (tenant) | ✅ | ✅ |
| 21 | Operations | MCP Tokens | ✅ DB | ✅ where tenant_id | N/A (tenant) | ✅ | ✅ |
| 22 | Operations | Widget | ✅ DB | ✅ where tenant_id | ✅ project_key per key | ✅ | ✅ |
| 23 | Operations | Logs | ✅ DB/disk | ✅ forTenant (chat/audit) | ✅ `?project` su chat/audit | ✅ R14 | ✅ |
| 24 | Operations | Maintenance | ✅ DB/config | ✅ forTenant + R21 | ✅ `--project` su comandi | ✅ R14 | ✅ |

**Esito**: 1 blocker (Dashboard), 1 major-condizionale (PII Redactor con flag ON), 4 gap progetto FE
(Synonyms, Doc Insights, Content Gaps, Tabular Reviews), 2 minori (Users literal list, Compliance FE dead code).
Le restanti 16 pagine sono pulite sui 4 check.

---

## Schede per pagina

### 1. Chat (`/app/chat`) — ✅
- **Utilità**: chat RAG su KB; tutti gli autenticati. Citazioni per admin.
- **Mock**: REAL DB — `KbChatController` → `ChatRetrievalService` → `KbSearchService`.
- **Tenant**: ✅ `KbSearchService.php:613,726` `->forTenant($tenantId)` su semantic + FTS.
- **Progetto**: ✅ `ChatView.tsx:82` usa active project; `KbChatController.php:77-78` `effectiveProjectKey()`.
- **Funziona**: ✅ stati loading/error/empty, streaming. `ChatView.tsx:61-148`.

### 2. Dashboard (`/app/admin`) — ❌ BLOCKER
- **Utilità**: KPI strip + grafici (chat volume, token burn, ratings, top projects) + activity feed + health. Ruoli admin/super-admin.
- **Mock**: REAL DB — `AdminMetricsService` aggrega via `DB::table(...)`. Nessun dato finto.
- **Tenant**: ❌ **MANCANTE**. `AdminMetricsService.php` interroga `knowledge_documents` (47), `knowledge_chunks` (59), `chat_logs` (73,118,150,222,265), `messages` (180), `kb_canonical_audit` (298) con **solo** `where('project_key', ...)` e **mai** `where('tenant_id', ...)`. I metodi non accettano nemmeno un parametro tenant. Verificato direttamente leggendo il file: zero riferimenti a `TenantContext`. → Su deployment multi-tenant ogni admin vede aggregati di **tutti** i tenant; due tenant che condividono un `project_key` si vedono le metriche a vicenda. Non è spoofing: parte ad ogni load.
- **Progetto**: ❌ il FE non passa progetto: `DashboardView.tsx:53-54` chiama `useAdminOverview({days})`, controller default `project=null` → rollup tenant-wide; lo store `activeProjectKey` non viene consumato.
- **Funziona**: struttura OK (stati loading/error/empty), ma i dati runtime sono cross-tenant.
- **Finding**: BLOCKER (leak tenant) + MAJOR (filtro progetto FE assente).

### 3. AI Insights (`/app/admin/insights`) — ✅
- **Utilità**: snapshot di raccomandazioni (promotion, orphan, auto-tag, gap, stale, quality). Admin/super-admin.
- **Mock**: REAL DB — `AdminInsightsController.php:50-123` legge `AdminInsightsSnapshot`.
- **Tenant**: ✅ `:54,:110` `->forTenant(...)`.
- **Progetto**: N/A — snapshot aggregati tenant.
- **Funziona**: ✅ `InsightsView.tsx:22-148` stati + null-snapshot gestito.

### 4. Users (`/app/admin/users`) — ⚠ (mock list literal)
- **Utilità**: lista utenti paginata (search/filtri/trashed), CRUD + restore. Admin/super-admin.
- **Mock**: REAL DB per la lista — `UserController::index` `:39-79`, LIKE con `LikeEscaper` (R19 ok).
- **Tenant**: N/A by design — `User` è identità cross-tenant (no `BelongsToTenant`).
- **Progetto**: N/A.
- **Funziona**: ✅.
- **Finding**: MINOR — `UsersView.tsx:28` `DEFAULT_PROJECT_KEYS = ['hr-portal','engineering']` hard-coded (anti-pattern R18), in attesa dell'endpoint project picker. Da derivare da DB.

### 5. Roles (`/app/admin/roles`) — ✅
- **Utilità**: gestione ruoli/permessi Spatie. Admin/super-admin. `super-admin`/`admin` non eliminabili.
- **Mock**: REAL DB — `RoleController` + `PermissionController`.
- **Tenant/Progetto**: N/A — ruoli/permessi Spatie globali.
- **Funziona**: ✅.

### 6. Knowledge Base (`/app/admin/kb`) — ✅
- **Utilità**: tree canonical+raw per progetto, editor/preview/graph. Admin/super-admin.
- **Mock**: REAL DB — `KbTreeService` + `KbTreeController:64-81`; dropdown da `/api/admin/kb/projects` (DISTINCT DB, non literal).
- **Tenant**: ✅ `KbTreeService.php:110` `->forTenant(...)`.
- **Progetto**: ✅ `<select>` locale `KbView.tsx:177-196` → filtra il tree via `?project`.
- **Funziona**: ✅ stati + detail pane.

### 7. Collections (`/app/admin/collections`) — ✅
- **Utilità**: CRUD collezioni semantiche/criteri (set riusabili cross-progetto). Admin/super-admin.
- **Mock**: REAL DB — `KbCollectionController:24-50`.
- **Tenant**: ✅ `:24` `forTenant(...)` su ogni azione.
- **Progetto**: N/A by design — collezioni tenant-global (i criteri possono referenziare project_keys).
- **Funziona**: ✅.

### 8. Synonyms (`/app/admin/kb/synonyms`) — ⚠ gap progetto FE
- **Utilità**: gruppi sinonimi per (tenant, progetto). Admin/super-admin.
- **Mock**: REAL DB — `SynonymController:50-56`.
- **Tenant**: ✅ `:50` `forTenant(...)`.
- **Progetto**: ⚠ **MANCANTE lato FE** — l'API supporta `project_keys[]` (`synonyms.api.ts:33-37`) e il BE filtra, ma `SynonymsList.tsx:33` chiama `list()` senza argomenti → mostra tutti i sinonimi del tenant.
- **Funziona**: ✅.
- **Finding**: MAJOR usabilità — aggiungere `<select>` progetto o passare `activeProjectKey`.

### 9. Doc Insights (`/app/admin/kb/insights`) — ⚠ gap progetto FE
- **Utilità**: analisi AI sui cambi documento (suggerimenti, cross-ref, impacted). Admin/super-admin.
- **Mock**: REAL DB — `KbDocAnalysisController:44-92`.
- **Tenant**: ✅ `:44` `forTenant(...)`.
- **Progetto**: ⚠ **MANCANTE lato FE** — API supporta `project_keys[]` (`analyses.api.ts:48-59`), ma `KbInsightsView.tsx:19` passa solo `status`.
- **Funziona**: ✅.
- **Finding**: MAJOR usabilità — stesso pattern di Synonyms.

### 10. Analysis Gate (`/app/admin/kb/analysis-settings`) — ✅
- **Utilità**: flag deep-analysis per (tenant, progetto) con eredità progetto→`*`→config. Admin/super-admin.
- **Mock**: REAL DB — `KbAnalysisSettingController:45-76`; lista progetti da `KnowledgeDocument::forTenant()->distinct()` (R18 ok).
- **Tenant**: ✅ `:46,:53` `forTenant($tenantId)`.
- **Progetto**: ✅ — non è un filtro ma una **matrice multi-progetto** (una riga per progetto reale + wildcard). `AnalysisSettingsView.tsx:104-106`.
- **Funziona**: ✅.

### 11. Content Gaps (`/app/admin/kb/content-gaps`) — ⚠ gap progetto FE
- **Utilità**: domande senza risposta (KB search failures) ordinate per frequenza. Admin/super-admin.
- **Mock**: REAL DB — `KbContentGapController:44`; `available_reasons` da DB (R18 ok, `:76-81`).
- **Tenant**: ✅ `:44` `forTenant(...)`.
- **Progetto**: ⚠ **MANCANTE lato FE** — API supporta `project_keys[]` (`content-gaps.api.ts:43-49`), ma `ContentGapsView.tsx:21` non lo passa.
- **Funziona**: ✅.
- **Finding**: MAJOR usabilità — stesso pattern.

### 12. Tabular Reviews (`/app/admin/tabular-reviews`) — ⚠ minor
- **Utilità**: CRUD review tabellari + estrazione celle. Viewer read-only (BE `denyMutationForViewer`), admin write. Admin/super-admin/viewer.
- **Mock**: REAL DB — `TabularReviewController:58-82`.
- **Tenant**: ✅ `:59` `forTenant(...)`.
- **Progetto**: ⚠ filtro lista assente; create form ha campo libero `projectKey`. Riconosciuto come polish W3.X (`TabularReviewsList.tsx:16-18`).
- **Funziona**: ✅.
- **Finding**: MINOR (noto).

### 13. Workflows (`/app/admin/workflows`) — ✅
- **Utilità**: CRUD workflow template + share + hide per-utente. Viewer read-only+hide, admin write.
- **Mock**: REAL DB — `WorkflowService.php:74` `forTenant(...)`.
- **Tenant**: ✅ ogni metodo del service tenant-scoped (R30).
- **Progetto**: N/A — template tenant-global (campo `project_key` opzionale di riferimento).
- **Funziona**: ✅ tab scope, gating client mirror del BE.

### 14. AI Act (`/app/admin/ai-act-compliance`) — ✅
- **Utilità**: registri AI-Act (incident/DSAR/consent/bias). Admin/super-admin/dpo.
- **Mock**: REAL DB — package padosoft, route `:329-338` in `routes/api.php`.
- **Tenant**: ✅ host **sovrascrive** la middleware del package con stack auth completo + `tenant.authorize` + `ai-act.tenant-context` (`config/ai-act-compliance.php:40-51`). È la pagina ex-incidente R32: ora gate correttamente chiuso.
- **Progetto**: N/A — compliance tenant-wide.
- **Funziona**: ✅ 6 card, empty/error, flag OFF degrada pulito (SP short-circuit).

### 15. Compliance Reports (`/app/admin/compliance/reports`) — ⚠ minor
- **Utilità**: report compliance firmati (SHA256+HMAC) su audit/doc reali. Admin/super-admin.
- **Mock**: REAL DB — `ComplianceReportGenerator.php:14-75`.
- **Tenant**: ✅ binding `forTenant` (`routes/api.php:293-297`) + `store()` ignora `tenant_id` del client e usa tenant attivo (`ComplianceReportController.php:59-68`). No IDOR.
- **Progetto**: N/A.
- **Funziona**: ✅ binding 404 guard.
- **Finding**: MINOR — `ComplianceReportsView.tsx:18,26,34` passa `tenant_id` controllato dal client che il BE **ignora** (dead code, UX confondente). Rimuovere lo stato FE.

### 16. PII Redactor (`/app/admin/pii-redactor`) — ❌ MAJOR (se flag ON)
- **Utilità**: console strategia PII + detokenize. Admin/super-admin/dpo, gate `can:viewPiiRedactorAdmin`.
- **Mock**: CONFIG — `PiiStrategyController.php:62-89` ritorna snapshot config (non DB, non finto).
- **Tenant**: ❌ **MANCA `tenant.authorize`**. Le route del package (`admin/pii-redactor/api/*`) sono gated da `web,auth,can:viewPiiRedactorAdmin` (`config/pii-redactor-admin.php:46-48`). Il docblock assume "ResolveTenant basta", ma ResolveTenant **imposta** il tenant dall'header spoofabile e NON lo valida; senza `AuthorizeTenantHeader` un admin/dpo (ruolo Spatie **globale**) può inviare `X-Tenant-Id: <vittima>` e operare sul tenant altrui. Verificato: `ResolveTenant.php:79-82` (header prima) + `AuthorizeTenantHeader.php:43-77` (il guard assente qui).
- **Progetto**: N/A.
- **Funziona**: ✅ flag `PII_REDACTOR_ADMIN_ENABLED` default **false** → pagina 404 pulita da spenta (blast radius limitato).
- **Finding**: MAJOR (diventa blocker su deployment multi-tenant con flag ON). Fix: aggiungere `tenant.authorize` allo stack `PII_REDACTOR_ADMIN_MIDDLEWARE`/default config (come fatto per AI Act). Nota: l'entità esatta del dato esposto dipende dallo scoping interno del package (vendor, non ispezionato qui) — comunque la rotta va chiusa.

### 17. Connectors (`/app/admin/connectors`) — ✅
- **Utilità**: connettori OAuth (Drive/Notion) per sync KB. Super-admin, `can:manageConnectors`.
- **Mock**: REAL DB — `ConnectorAdminController.php:51-73` su `connector_installations`.
- **Tenant**: ✅ `where('tenant_id', ...)` `:52,103,157,289`.
- **Progetto**: N/A — tenant-level.
- **Funziona**: ✅ OAuth callback scoping `:156-167`, errori loud.

### 18. Flows (`/app/admin/flows`) — ✅
- **Utilità**: cockpit orchestrazione (padosoft/laravel-flow-admin). Super-admin/admin/dpo.
- **Mock**: REAL DB — endpoint `/admin/flows/api/live`.
- **Tenant**: ✅ gate `can:viewFlowAdmin` + `AskMyDocsFlowAuthorizer` verifica `tenant_id` riga vs `TenantContext` (`FlowAdminIntegrationServiceProvider.php:60-67`).
- **Progetto**: N/A.
- **Funziona**: ✅ flag OFF → 404 (`FlowAdminEnabled.php:35`, R43 ok).

### 19. Eval Harness (`/app/admin/eval-harness`) — ✅
- **Utilità**: dashboard valutazione modelli (report/batch/trend). Super-admin/admin/dpo/editor.
- **Mock**: REAL DB — probe `/admin/eval-harness/api/reports`; bootstrap da `config/eval-harness-ui.php`.
- **Tenant**: ✅ header `X-Eval-Harness-Tenant` da `TenantContext` (`eval-harness-ui.tenant-header` mw); gate `can:eval-harness.viewer`.
- **Progetto**: N/A.
- **Funziona**: ✅ OFF a 3 livelli (flag/prod/data-API rotta) → landing pulita (R43, ex-caso canonico). `EvalHarnessView.tsx:192-216`.

### 20. MCP Tools (`/app/admin/mcp-tools`) — ✅
- **Utilità**: registrazione server MCP + whitelist tool + audit chiamate. Super-admin (`can:manageMcpTools`).
- **Mock**: REAL DB — `mcp_servers` + `mcp_tool_call_audit`.
- **Tenant**: ✅ `where('tenant_id', ...)` + modelli `BelongsToTenant`.
- **Progetto**: N/A.
- **Funziona**: ✅ handshake error → status `errored`.

### 21. MCP Tokens (`/app/admin/mcp/tokens`) — ✅
- **Utilità**: token MCP per-tenant. Super-admin (`can:manageMcpTools`).
- **Mock**: REAL DB — `mcp_tenant_tokens`; plaintext mostrato una sola volta, mai loggato.
- **Tenant**: ✅ `where('tenant_id', ...)` index/store/revoke; modello `BelongsToTenant`.
- **Progetto**: N/A.
- **Funziona**: ✅ revoke idempotente (`revoked_at===null`).

### 22. Widget (`/app/admin/widget`) — ✅
- **Utilità**: chiavi widget embeddabile (pk_/sk_) + sessioni. Super-admin.
- **Mock**: REAL DB — `WidgetKey`/`WidgetSession`; secret hash nascosti in serialize.
- **Tenant**: ✅ `WidgetKey` `BelongsToTenant` + `where('tenant_id', ...)`.
- **Progetto**: ✅ ogni chiave ha `project_key` configurabile.
- **Funziona**: ✅ secret mostrato una volta.

### 23. Logs (`/app/admin/logs`) — ✅
- **Utilità**: 5 tab — Chat, Canonical Audit, Application, Activity, Failed Jobs. Admin/super-admin.
- **Mock**: REAL DB/disk — chat/audit Eloquent; application via `LogTailService` (file whitelisted); activity/failed-jobs con fallback graceful se tabella assente.
- **Tenant**: ✅ chat+audit `forTenant` (`LogViewerController.php:65-66,125-126`) con `?project`; application/activity/failed-jobs **globali by design**; detokenize verifica tenant sulla riga (`:336`) + audit trail.
- **Progetto**: ✅ `?project` su chat/audit.
- **Funziona**: ✅ R14 — `LogFileNotFound`→404, `LogFileUnreadable`→500, invalid→422; fallback 200+`note` su tabelle mancanti.

### 24. Maintenance (`/app/admin/maintenance`) — ✅
- **Utilità**: runner comandi Artisan whitelisted + history + scheduler status. Admin/super-admin, run `throttle:10,1`.
- **Mock**: CONFIG+DB — catalogo da `config('admin.allowed_commands')`; history da `admin_command_audits`.
- **Tenant**: ✅ history `forTenant` (`MaintenanceCommandController.php:178-179`); nonce confirm-token `forTenant`; modelli `BelongsToTenant`.
- **Progetto**: ✅ `--project` su comandi KB.
- **Funziona**: ✅ R14 status matrix; **R21** confirm-token consumato atomicamente dentro `DB::transaction`+`lockForUpdate` (`CommandRunnerService.php:548-584`); audit scritto prima dell'esecuzione.

---

## Findings & Fix backlog (per gravità)

### ❌ Blocker
1. **Dashboard — leak cross-tenant nelle metriche.** `app/Services/Admin/AdminMetricsService.php`
   non filtra mai per `tenant_id` (metodi senza parametro tenant; query raw su `knowledge_documents`,
   `chat_logs`, `messages`, `kb_canonical_audit`, `knowledge_chunks`). Ogni admin vede aggregati di tutti
   i tenant. **Fix**: iniettare `TenantContext`, aggiungere `where('tenant_id', $ctx->current())` a ogni
   query (incl. il join chunks→documents) e propagare il tenant da `DashboardMetricsController`. Aggiungere
   un test feature a doppio tenant. *(Verificato direttamente.)*

### ⚠ Major
2. **PII Redactor — IDOR cross-tenant via header (con flag ON).** `config/pii-redactor-admin.php:46-48`
   monta le route package senza `tenant.authorize`; `X-Tenant-Id` è spoofabile e il gate `can:` è globale.
   **Fix**: aggiungere `tenant.authorize` al default `PII_REDACTOR_ADMIN_MIDDLEWARE` (come AI Act) e caricare
   la config sicura in `tests/TestCase.php::getEnvironmentSetUp`; aggiungere la rotta alla matrice R32.
   Blast radius limitato dal flag default-off, ma da chiudere prima di abilitarlo in multi-tenant. *(Verificato.)*
3. **Dashboard — il FE non filtra per progetto.** `DashboardView.tsx:53-54` non consuma `activeProjectKey`.
   **Fix**: leggere `activeProjectKey` dallo store e passarlo a `useAdminOverview`/`useAdminSeries`.
4. **Synonyms / Doc Insights / Content Gaps — filtro progetto non passato dal FE.** BE+API pronti
   (`project_keys[]`), ma il FE chiama senza progetto: `SynonymsList.tsx:33`, `KbInsightsView.tsx:19`,
   `ContentGapsView.tsx:21`. **Fix**: aggiungere un `<select>` progetto (come KB) o consumare `activeProjectKey`.

### · Minor
5. **Users — lista progetti hard-coded.** `UsersView.tsx:28` `['hr-portal','engineering']` (R18). Derivare da DB.
6. **Compliance Reports — `tenant_id` FE morto.** `ComplianceReportsView.tsx:18,26,34` invia un `tenant_id`
   che il BE ignora. Rimuovere lo stato FE (UX).
7. **Tabular Reviews — filtro progetto lista assente** (noto, polish W3.X). `TabularReviewsList.tsx:16-18`.

---

## Verifica consigliata (post-fix)

- BE scoping: `vendor/bin/phpunit --filter Tenant` + `--filter AdminAuthorizationMatrix` verdi; aggiungere
  un test doppio-tenant per le metriche dashboard.
- Live: `php artisan serve` (`APP_ENV=testing`) + login per ruolo; in DevTools controllare che ogni
  `/api/admin/*` porti `tenant`/`project` e ritorni dati reali; ripetere con un secondo tenant/progetto
  seeded e confermare zero leak (in particolare Dashboard e PII Redactor con flag ON).
