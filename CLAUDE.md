# CLAUDE.md — AskMyDocs

Project brief and working rules for Claude Code. Mirrored for GitHub Copilot in
`.github/copilot-instructions.md`. Skills that codify the recurring review
findings live in `.claude/skills/`.

---

## 1. What this project is

AskMyDocs is an **enterprise-grade RAG + canonical knowledge compilation**
system built on **Laravel 13 + PostgreSQL/pgvector**. Users ingest markdown,
ask questions via a chat UI (or a stateless JSON API), and get grounded
answers with citations — over a **typed knowledge base** with a lightweight
graph, anti-repetition memory, and a human-gated promotion pipeline. A
full React SPA admin shell ships alongside the chat: KPI dashboard, users
+ roles + RBAC, canonical KB explorer with inline editor and graph viewer,
log viewer (five tabs), whitelisted Artisan maintenance runner, and a
daily AI-insights panel. Every admin page runs behind Spatie roles, every
mutation audit-trails into `kb_canonical_audit` or `admin_command_audit`,
and every destructive command requires a DB-backed single-use confirm
token.

- **PHP** `^8.3`, **Laravel** `^13.0`, **Sanctum** `^4.2`.
- **symfony/yaml** `^7.4|^8.0` for canonical YAML frontmatter parsing
  (`CanonicalParser`). Section-aware markdown chunking is implemented
  in-house (`MarkdownChunker` — line-based fence-aware FSM); no external
  markdown parser library.
- **laravel/mcp** `^0.7` as a suggest (required only when exposing the
  `enterprise-kb` MCP server with its 10 tools).
- **PostgreSQL ≥ 15** with the `pgvector` extension (FTS GIN index shipped).
- Tests: PHPUnit 12 + Orchestra Testbench 11 + Vitest. SQLite is used in tests —
  `vector(N)` columns swap to JSON text via the migrations under
  `tests/database/migrations/`.
- **All providers run on the `laravel/ai` SDK (since v8.16/W2, ADR 0015).**
  Anthropic + Gemini are FULLY SDK; OpenAI + OpenRouter are HYBRID — no-tools
  chat + embeddings via the SDK, the MCP **with-tools** turn on raw
  `Illuminate\Support\Facades\Http` `/chat/completions` (the SDK cannot host
  AskMyDocs's external-MCP tool loop). Regolo is on the
  `padosoft/laravel-ai-regolo` SDK adapter. `laravel/ai` is pinned `^0.6.8`.
  (This reverses the earlier "No AI SDKs" rule — see ADR 0015 +
  `docs/v4-platform/W2-sdk-migration-findings.md`.)

---

## 2. High-level architecture

```
Client ──► KbChatController ──► KbSearchService (pgvector + FTS + Reranker)
                              ──► AiManager::chat() ──► provider (OpenAI, Anthropic,
                                                        Gemini, OpenRouter, Regolo)
                              ──► ChatLogManager::log()  (optional, try/catch)
                              ──► JSON { answer, citations, meta }
```

Ingestion has **two entry points** converging on one execution path:

```
Flow 1 — CLI:    php artisan kb:ingest-folder docs/ --project=X
                 (walks KB disk, dispatches 1 job per file)

Flow 2 — HTTP:   POST /api/kb/ingest  (Sanctum, batch ≤ 100)
                 (writes markdown to KB disk, dispatches 1 job per doc)

Both ─► IngestDocumentJob ($tries=3, backoff=[10,30,60])
        └─► DocumentIngestor::ingestMarkdown()  (idempotent SHA-256 upsert)
```

Deletion mirrors the same fan-in:

```
kb:delete / DELETE /api/kb/documents / --prune-orphans / kb:prune-deleted
        └─► DocumentDeleter (single service — soft/hard, chunks, file on disk)
```

---

## 3. Critical components (where to look first)

| Area | Path |
|---|---|
| Provider abstraction | `app/Ai/AiManager.php`, `app/Ai/Providers/*.php` |
| DTOs | `app/Ai/AiResponse.php`, `app/Ai/EmbeddingsResponse.php` |
| RAG retrieval | `app/Services/Kb/KbSearchService.php`, `Reranker.php` |
| Graph-aware retrieval | `app/Services/Kb/Retrieval/GraphExpander.php`, `RejectedApproachInjector.php`, `CosineCalculator.php`, `SearchResult.php` |
| Ingestion | `app/Services/Kb/DocumentIngestor.php`, `MarkdownChunker.php`, `EmbeddingCacheService.php` |
| Canonical parsing | `app/Services/Kb/Canonical/CanonicalParser.php`, `WikilinkExtractor.php`, `CanonicalParsedDocument.php`, `ValidationResult.php` |
| Promotion pipeline | `app/Services/Kb/Canonical/CanonicalWriter.php`, `PromotionSuggestService.php`, `app/Http/Controllers/Api/KbPromotionController.php` |
| Canonical enums + audit | `app/Support/Canonical/{CanonicalType,CanonicalStatus,EdgeType}.php`, `app/Models/{KbNode,KbEdge,KbCanonicalAudit}.php` |
| Canonical indexer | `app/Jobs/CanonicalIndexerJob.php` |
| Deletion | `app/Services/Kb/DocumentDeleter.php` |
| Queued pipeline | `app/Jobs/IngestDocumentJob.php` |
| Shared helpers | `app/Support/KbPath.php` |
| HTTP entrypoints | `app/Http/Controllers/Api/*.php` |
| Artisan | `app/Console/Commands/*.php` |
| Chat logging | `app/Services/ChatLog/*` + `app/Models/ChatLog.php` |
| MCP | `app/Mcp/Servers/KnowledgeBaseServer.php`, `app/Mcp/Tools/*` (45 tools: retrieval + canonical/promote + auto-wiki + engagement + the v8.16/W4 AI FinOps read surfaces `FinOps{SpendSummary,TopModels,BudgetStatus}Tool` + the v8.18/W4 AI gamification read surface `KbGamificationInsightsTool` + the v8.19/W4 Agentic Knowledge Reports read surface `KbRunReportTool` + the v8.20 multi-account connectors read surface `ConnectorInstallationsTool` + the v8.21 ingestion/sync observability read surface `KbIngestionStatusTool` + the v8.22 runtime config governance read surface `AppSettingsTool` + the v8.23 PII tri-surface `Kb{PiiPolicy,Detokenize,EraseSubject,ReembedProject}Tool` + the v8.25 connector sync-settings read surface `ConnectorSettingsTool` + the `padosoft/laravel-invitations` tri-surface `Invite{ValidateCode,GenerateCodes,Metrics}Tool` (vendor-namespaced, registered on the host server); count locked by `tests/Unit/Mcp/KnowledgeBaseServerRegistrationTest.php`) |
| Admin RBAC + auth | `app/Http/Controllers/Api/Admin/*.php`, `app/Services/Admin/*.php`, `app/Http/Requests/Admin/*.php`, `app/Http/Resources/Admin/*.php` |
| Invitations (`padosoft/laravel-invitations`) | `config/invitations.php` (host route middleware + `manageInvitations` gate, `INVITE_REQUIRED` default-false R43), `app/Invitations/ProjectMembershipProvisioner.php` (GRANT-never-REVOKE project membership from a `TenantGrant`), `App\Models\User` (implements `InvitedAccount`), `AppServiceProvider::registerInvitationsIntegration()` (TenantResolver→TenantContext + provisioner tag) + `registerInvitationsGates()`; MCP `Invite{ValidateCode,GenerateCodes,Metrics}Tool` on `KnowledgeBaseServer`; routes auto-mount at `/api/{admin/}invitations/*`. The 9 invite tables are package-owned + tenant-aware (R30/R31 in the package's CI). |
| Admin metrics + health | `app/Services/Admin/AdminMetricsService.php`, `HealthCheckService.php`, `app/Http/Controllers/Api/Admin/DashboardMetricsController.php` |
| Admin KB surface (tree + detail + editor + graph + PDF) | `app/Services/Admin/KbTreeService.php`, `app/Http/Controllers/Api/Admin/KbTreeController.php`, `KbDocumentController.php`, `app/Services/Admin/Pdf/PdfRenderer*.php` |
| Admin log viewer (H1) | `app/Services/Admin/LogTailService.php`, `app/Http/Controllers/Api/Admin/LogViewerController.php` |
| Admin command runner (H2) | `app/Services/Admin/CommandRunnerService.php`, `app/Http/Controllers/Api/Admin/MaintenanceCommandController.php`, `app/Models/AdminCommandAudit.php`, `AdminCommandNonce.php`, `config/admin.php` (`allowed_commands`) |
| AI insights (Phase I) | `app/Services/Admin/AiInsightsService.php`, `app/Http/Controllers/Api/Admin/AdminInsightsController.php`, `app/Console/Commands/InsightsComputeCommand.php`, `app/Models/AdminInsightsSnapshot.php` |
| AI FinOps (v8.16, `padosoft/laravel-ai-finops` + `-admin`) | `app/FinOps/AiCallMeter.php` (metering bridge on AiManager; since v8.16/W2 + ADR 0015 it meters ONLY the residual raw-Http OpenAI/OpenRouter with-tools turn — every SDK-path call is metered by the finops lifecycle hook), `app/FinOps/HostTenantResolver.php`, `app/Http/Middleware/FinOpsAuthorize.php` (method-aware gate), `config/ai-finops.php` + `config/ai-finops-admin.php` (secure host overrides, R32), gates `viewAiFinOps`/`manageAiFinOps` in `AppServiceProvider`. **W3 server-side cost authority**: `app/FinOps/ChatTurnCostResolver.php` resolves real per-turn cost at `ChatLogManager` time onto additive `chat_logs.{cost,cost_currency,trace_id}` (`ChatTraceContext` joins the row to the ledger; FE reads the server cost — replaces the old static `config/ai.php cost_rates` client guess), all gated on `AI_FINOPS_METERING`. **W4 MCP read surface (R44)**: `app/Mcp/Tools/FinOps{SpendSummary,TopModels,BudgetStatus}Tool.php` (tenant-scoped R30, OFF-path safe R43). **W4 admin SPA**: package-served Blade SPA at `/admin/ai-finops` (`AI_FINOPS_ADMIN_ENABLED`, default-OFF → clean 404) |
| SPA entrypoint | `app/Http/Controllers/SpaController.php`, `resources/views/app.blade.php`, `frontend/src/main.tsx`, `frontend/src/routes/index.tsx` |
| Scheduler | `bootstrap/app.php` |
| GitHub Action | `.github/actions/ingest-to-askmydocs/action.yml` (v2 — canonical-folder aware) |
| Claude skill templates | `.claude/skills/kb-canonical/*` (5 CONSUMER-SIDE templates), `.claude/skills/canonical-awareness/` (R10 — in-repo) |
| ADRs | `docs/adr/0001..0003.md` |

---

## 4. Key schemas (condensed)

### `knowledge_documents`
`id`, `project_key`, `source_type`, `title`, `source_path`, `mime_type`,
`language`, `access_scope`, `status`, `document_hash`, `version_hash` (both
SHA-256), `metadata` JSON, `source_updated_at`, `indexed_at`, `created_at`,
`updated_at`, `deleted_at` (soft delete).
**Canonical columns** (nullable, added in phase 1): `doc_id`, `slug`,
`canonical_type`, `canonical_status`, `is_canonical` (bool, default false),
`retrieval_priority` (smallint 0–100, default 50), `source_of_truth`
(bool, default true), `frontmatter_json` (full parsed YAML + `_derived`
sub-map with validated slug lists).
**Uniqueness:** `(project_key, source_path, version_hash)` — the idempotency
anchor. Additional composite uniques scoped per project: `(project_key,
doc_id)` = `uq_kb_doc_doc_id`, `(project_key, slug)` = `uq_kb_doc_slug`.
The canonical identifiers are tenant-scoped — two projects can legitimately
share the same slug / doc_id.

### `knowledge_chunks`
`id`, `knowledge_document_id` FK (ON DELETE CASCADE), `project_key`,
`chunk_order`, `chunk_hash` (SHA-256), `heading_path`, `chunk_text`,
`metadata` JSON, `embedding vector(N)`. UNIQUE
`(knowledge_document_id, chunk_hash)` (`uq_kb_chunk_doc_hash`). GIN index on
`to_tsvector(<lang>, chunk_text)` (pgsql only, no-op elsewhere).

### `embedding_cache`
`id`, `text_hash` (SHA-256), `provider`, `model`,
`embedding vector(N)`, `created_at`, `last_used_at` (LRU pruning).
UNIQUE `(text_hash, provider, model)` (`embedding_cache_text_hash_provider_model_unique`
— composite key so different provider/model pairs for the same text coexist
as separate entries; upgraded from `UNIQUE(text_hash)` in migration
`2026_05_03_000001_change_embedding_cache_unique_to_composite.php`).

### `chat_logs`
`session_id`, `user_id?`, `question`, `answer`, `project_key?`, `ai_provider`,
`ai_model`, `chunks_count`, `sources` JSON, token counts, `latency_ms`,
`client_ip?`, `user_agent?`, `extra` JSON, `created_at`.

### `conversations` / `messages`
User-scoped chat history. `messages.metadata` holds citations + provider/model
telemetry. `messages.rating` feeds the `FewShotService` few-shot loop.

### `kb_nodes` (canonical knowledge graph — 9 node types)
`id`, `node_uid`, `node_type` (one of: project, module, decision, runbook,
standard, incident, integration, domain-concept, rejected-approach),
`label`, `project_key`, `source_doc_id` (nullable — points at the
`knowledge_documents.doc_id` that owns this node), `payload_json` (holds
`dangling: true` when the slug is wikilinked-but-not-yet-canonicalized).
**Uniqueness:** `(project_key, node_uid)` = `uq_kb_nodes_project_uid` —
**per-project**, not global.

### `kb_edges` (canonical knowledge graph — 10 edge types)
`id`, `edge_uid`, `from_node_uid`, `to_node_uid`, `edge_type` (one of:
depends_on, uses, implements, related_to, supersedes, invalidated_by,
decision_for, documented_by, affects, owned_by), `project_key`,
`source_doc_id`, `weight` (decimal 8,4 — drives graph-expansion ordering),
`provenance` (wikilink | frontmatter_related | frontmatter_supersedes |
frontmatter_superseded_by | inferred), `payload_json`.
**Uniqueness:** `(project_key, edge_uid)` = `uq_kb_edges_project_uid`.
**Composite FK (project-scoped)**: `(project_key, from_node_uid)` →
`kb_nodes.(project_key, node_uid)` with ON DELETE CASCADE, same for
`to_node_uid` — an edge must resolve to nodes in the **same project**
(intra-project referential integrity). Cross-**tenant** isolation is **not**
enforced by this FK (it is keyed on `project_key`, which is shared across
tenants) but at the application layer via R30 `forTenant()` scoping. (A
tenant-scoped composite-FK rebuild was considered and deferred.)

### `kb_canonical_audit` (immutable editorial trail)
`id`, `project_key`, `doc_id?`, `slug?`, `event_type` (promoted | updated |
deprecated | superseded | rejected_injection_used | graph_rebuild),
`actor` (user id / command name / `system`), `before_json`, `after_json`,
`metadata_json`, `created_at`. **No `updated_at`** — rows are never
mutated; this is the compliance record. **No FK to `knowledge_documents`**
by design, so rows survive hard deletes for forensic access.

---

## 5. Flows you touch most

### Chat turn
1. `KbChatController` validates → embeds query → `KbSearchService::searchWithContext()`
   hybrid search (3× over-retrieval → reranker fusion `0.55·vec + 0.25·kw + 0.05·head`
   shipped defaults, configurable via `kb.reranking.*` + canonical boost + status penalty).
2. `GraphExpander` walks 1 hop of `kb_edges` from canonical seed docs
   (config-gated: `KB_GRAPH_EXPANSION_ENABLED=true` default, no-op when no
   canonical docs exist).
3. `RejectedApproachInjector` vector-correlates the query against
   `rejected-approach` canonical docs and returns up to `KB_REJECTED_INJECTION_MAX_DOCS`
   above `KB_REJECTED_MIN_SIMILARITY`.
4. `SearchResult { primary, expanded, rejected, meta }` → prompt composed
   from `resources/views/prompts/kb_rag.blade.php` (typed blocks: `⚠ REJECTED
   APPROACHES` + `📎 RELATED CONTEXT` + primary `## Context`).
5. `AiManager::chat()` → provider, all on the `laravel/ai` SDK (ADR 0015):
   Anthropic/Gemini fully SDK; OpenAI/OpenRouter SDK for no-tools chat +
   embeddings, raw `Http::` `/chat/completions` for the MCP with-tools turn;
   Regolo via the `padosoft/laravel-ai-regolo` SDK adapter.
6. `ChatLogManager::log()` in try/catch — **never** propagate logging failures.

### Ingestion
Idempotency is not optional: `DocumentIngestor` hashes the markdown and upserts
on `(project_key, source_path, version_hash)`. Re-pushing identical bytes is a
no-op; a new version archives the previous one so stale chunks never surface.

**Canonical branch** (when frontmatter is present and valid): `CanonicalParser::parse()`
extracts YAML frontmatter, validates against the 9 types / 6 statuses,
populates the 8 canonical columns + `frontmatter_json` with a `_derived`
sub-map (pre-validated slug lists). Before the row is inserted, prior
versions' canonical identifiers are nulled so the composite uniques accept
the new version. After commit, `CanonicalIndexerJob` is dispatched to populate
`kb_nodes` + `kb_edges`. Invalid frontmatter degrades gracefully to non-canonical
ingestion (R4).

### Deletion
`KB_SOFT_DELETE_ENABLED=true` (default) → `SoftDeletes` trait hides the row
from every read path. `kb:prune-deleted` (03:30) hard-deletes soft rows older
than `KB_SOFT_DELETE_RETENTION_DAYS` (default 30) and wipes the file on disk.

**Canonical cascade on hard delete**: `DocumentDeleter::forceDelete()` also
removes `kb_nodes` owned by the doc (by `source_doc_id`, falling back to
`node_uid = slug`). The composite FK on `kb_edges` cascades both outgoing AND
incoming edges automatically. Soft delete leaves the graph intact —
retention reversibility is preserved; cascade fires only on final hard delete.
Every hard delete writes a `kb_canonical_audit` row with
`event_type='deprecated'`.

### Promotion (human-gated, ADR 0003)
Three-stage API, all Sanctum-protected:
- `POST /api/kb/promotion/suggest` → LLM extracts candidate artifacts from a
  transcript via `PromotionSuggestService`. **Writes nothing.**
- `POST /api/kb/promotion/candidates` → validates a markdown draft against
  `CanonicalParser`. Returns `{valid, errors}`. **Writes nothing.**
- `POST /api/kb/promotion/promote` → `CanonicalWriter` writes MD to KB disk
  (R4: `Storage::put()` return checked, throws on failure), dispatches
  `IngestDocumentJob`. HTTP 202.

Claude skills stop at `suggest` / `candidates`. Only humans (via git push →
GH action → ingest) or operators (via `kb:promote` CLI) commit to canonical
storage.

### Scheduler (bootstrap/app.php)

| Time  | Command                    |
| ----- | -------------------------- |
| 03:10 | `kb:prune-embedding-cache` |
| 03:20 | `chat-log:prune`           |
| 03:30 | `kb:prune-deleted`         |
| 03:40 | `kb:rebuild-graph`         |

All commands: `onOneServer()->withoutOverlapping()` and accept a `--days=N`
override (or `--project=` for `kb:rebuild-graph`). `0` disables the corresponding
rotation. `kb:rebuild-graph` is a no-op when no canonical docs exist.

---

## 6. Non-obvious decisions (do not unwind without asking)

- **All providers run on the `laravel/ai` SDK (v8.16/W2, ADR 0015 — reverses
  the earlier "No AI SDKs" rule).** This was done so `padosoft/laravel-ai-finops`
  meters every provider natively via the SDK lifecycle events (no host bridge)
  and so OpenRouter's real `usage.cost` is capturable. Anthropic + Gemini are
  FULLY SDK; OpenAI + OpenRouter are HYBRID — the SDK serves no-tools chat +
  embeddings, but the MCP **with-tools** turn stays on raw `Http::`
  `/chat/completions` because the SDK owns its own tool loop and cannot host
  AskMyDocs's external-MCP loop (`McpToolCallingService` — dynamic JSON tools +
  `role:'tool'` replay). The interim `App\FinOps\AiCallMeter` bridge now meters
  ONLY that residual with-tools turn (`AiManager` gate). Do NOT re-route the
  with-tools turn onto the SDK without a dedicated `Tool`-adapter ADR. Regolo
  stays on the in-house `padosoft/laravel-ai-regolo` SDK adapter.
- **Chat and embeddings providers are separate** (`AI_PROVIDER` vs
  `AI_EMBEDDINGS_PROVIDER`). Anthropic does not offer embeddings.
  OpenRouter exposes an OpenAI-compatible `/v1/embeddings` endpoint and
  routes both `openai/text-embedding-3-small` (default, 1536 dims, matches
  the schema default — no DB migration needed) and `qwen/qwen3-embedding-4b`
  (2560 dims, requires resizing the pgvector columns). When
  `AI_PROVIDER=anthropic` and `AI_EMBEDDINGS_PROVIDER` is empty, AiManager
  auto-selects the first embeddings-capable provider with a configured
  API key in this order: openai → openrouter → regolo → gemini. The order
  is dimension-safety first (R14): openai + openrouter both default to a
  1536-dim model matching the stock pgvector schema, so a deployment that
  happens to have `REGOLO_API_KEY` or `GEMINI_API_KEY` set but never
  resized the column does not silently corrupt ingest writes.
- **Embedding dimensions are part of the contract.** Switching provider/model
  requires migrating the `vector(N)` column **and** flushing the cache **and**
  re-indexing. See the "Embedding dimension gotcha" section in the README.
- **Soft delete is the default.** Any read query that bypasses Eloquent's
  global scope must explicitly opt in with `withTrashed()` / `onlyTrashed()`.
- **Idempotency is guaranteed by the unique tuple** — do not add `firstOrCreate`
  logic that bypasses the hash.
- **Logging never breaks the user path.** `ChatLogManager::log()` is wrapped in
  try/catch; do not hoist logging into the hot path.
- **Two ingestion entrypoints, one execution path.** Never add a third path
  that skips `IngestDocumentJob` / `DocumentIngestor::ingestMarkdown()`.
- **Canonical markdown is the source-of-truth; the DB is a projection.**
  The canonical `kb/` folders in consumer repos are authoritative; the
  `knowledge_documents` + `kb_nodes` + `kb_edges` rows are rebuildable
  from Git at any moment via `kb:rebuild-graph` + re-ingest. Never design
  a feature that requires DB-only state that can't be reconstructed from
  the markdown. `kb_canonical_audit` is the one exception — it's an
  immutable forensic trail that survives hard deletes.
- **Promotion is always human-gated.** Claude skills and the `suggest` /
  `candidates` endpoints produce drafts; only humans (via git commit →
  GH action) and operators (via `kb:promote`) commit canonical storage.
  ADR 0003 — do not add an "automatic promotion" shortcut without an ADR
  overriding this boundary.
- **Rejected-approach injection is by design, not a hack.** The prompt
  explicitly surfaces rejected approaches under a ⚠ marker so the LLM
  stops re-proposing dismissed options. `KB_REJECTED_INJECTION_ENABLED=false`
  turns it off, but the default is on. Tune `KB_REJECTED_MIN_SIMILARITY`
  before disabling — the prompt-token budget of rejected injection is
  typically <300 tokens per turn.
- **Graph expansion + rejected injection degrade to no-op.** When a
  tenant has zero canonical docs, `GraphExpander::expand()` returns empty
  and `RejectedApproachInjector::pick()` returns empty. Existing consumers
  see identical retrieval behaviour until they canonicalize. Never write
  code that assumes either feature is "always populated".
- **Canonical slug + doc_id are tenant-scoped, NOT global.** Two projects
  can legitimately share `dec-cache-v2`. The composite uniques are
  `(project_key, slug)` and `(project_key, doc_id)`; the composite FKs on
  `kb_edges` are **project-scoped** (intra-project referential integrity) —
  cross-tenant isolation is the application-layer R30 `forTenant()` scope, not
  the FK. Never assume global slug uniqueness in new code.

---

## 7. Recurring review findings — rules to follow

These are distilled from Copilot reviews on PR #4, #5, #6, and from the
canonical compilation series PRs #9 — #14. Each has a dedicated skill in
`.claude/skills/` with examples and counter-examples.

### R1 — Use `App\Support\KbPath::normalize()` for every KB source path
Never re-implement trim/collapse logic in a new controller, command, or job.
`KbPath::normalize()` collapses `//`, converts `\\`, rejects `.` and `..`
(path-traversal guard), and throws `InvalidArgumentException` on empty input.
The ingest and delete entry points **must** produce identical paths or delete
calls emit spurious "not found".
→ See `.claude/skills/kb-path-normalization/`.

### R2 — Soft-delete awareness on every query against `knowledge_documents`
Eloquent hides `deleted_at != null` rows by default. That is correct for the
read path (search, MCP, chat). **Any** write-side or CLI operation that is
expected to work on already-deleted rows (hard delete via `--force`, retention
purge, diagnostics) must query `withTrashed()` / `onlyTrashed()`. Forgetting
this is why "force delete on a previously soft-deleted doc" silently became a
no-op — see Copilot PR #6.
→ See `.claude/skills/soft-delete-aware-queries/`.

### R3 — Memory-safe bulk operations
Any code path that can see more than a few hundred rows at once
(`DocumentDeleter::deleteOrphans`, `pruneSoftDeleted`, full-corpus sweeps, CI
batches) **must** use `chunkById(100)` or `cursor()` and **push filters into
SQL** rather than loading everything into memory and filtering in PHP. When
the filter list itself is large, split it with `array_chunk($existing, 1000)`
and apply one `whereNotIn()` clause per chunk so each generated `IN` list
stays ≤ 1000 values (parser-friendly across drivers, readable EXPLAIN plans).
→ See `.claude/skills/memory-safe-bulk-ops/`.

### R4 — Never ignore a return value on a side-effecting call
Copilot flagged `Storage::disk($disk)->put(...)` returning `false` while the
controller still answered `202 Accepted` and dispatched a job that later
crashed with "file not found". Always check the boolean return of
`Storage::put/delete/copy`, `@mkdir`, `file_put_contents`, `copy`, `rename`.
If the write is load-bearing, surface the failure as a proper HTTP response
or exception.
→ See `.claude/skills/no-silent-failures/`.

### R5 — GitHub Action (`action.yml`) hygiene
Three recurring issues:
1. **Large files via `jq --arg`** hit `ARG_MAX`. Use `jq --rawfile content "$file"`.
2. **Pattern drift** between full-sync (`find -name "*.md"`) and diff mode
   (which includes `.markdown`). Keep both branches in lock-step.
3. **`git diff --diff-filter=AM` misses renames.** Use `AMR` for the ingest
   set and keep `D` + `R` for the delete set — otherwise a rename removes the
   old doc but never ingests the new path.
→ See `.claude/skills/github-action-hygiene/`.

### R6 — Docs and config must stay coupled
Copilot flagged `KB_DISK_DRIVER=s3` instructions that didn't match the actual
`config/filesystems.php` shape. When you introduce or rename an env var:
update `.env.example`, the relevant `config/*.php`, **and** the README quick-
start snippet in the same PR.

### R7 — No world-writable fallbacks, no `@`-silenced errors
`@mkdir($dir, 0777, true)` was flagged (PR #4). Use `0755` and `throw` (or
return cleanly) on failure. This applies equally to test bootstrap code.

### R8 — Honour `KB_PATH_PREFIX` consistently
`kb:ingest-folder` resolves the `{path}` argument **relative to**
`KB_PATH_PREFIX`, because the queued job re-applies the prefix when reading.
Any new CLI/API that walks the disk must either honour the prefix or reject
absolute paths. Document whichever you choose.

### R9 — Docs must match code
Every column name, env var, config key, command flag and route quoted in
`CLAUDE.md`, `.github/copilot-instructions.md`, `README.md`, or any
`SKILL.md` must be copied from the source of truth — the migration, the
config file, the `php artisan <cmd> --help` output, the routes file. A
wrong schema in `CLAUDE.md` is load-bearing damage: future PRs trust the
file as a quick reference and propagate the mistake into queries and tests.
Copilot caught the `chunk_index` vs `chunk_order` drift on PR #7 — it
should never have shipped.
→ See `.claude/skills/docs-match-code/`.

### R10 — Canonical awareness on every KB subsystem change
The canonical layer is NOT optional metadata. Every query, scope,
retrieval step, promotion path, and delete path that touches
`knowledge_documents` or the graph tables (`kb_nodes`, `kb_edges`,
`kb_canonical_audit`) MUST handle BOTH states (canonical / non-canonical)
deliberately. 10-point operational checklist:

1. Queries against `knowledge_documents` — use the dedicated scopes
   (`canonical()`, `accepted()`, `byType()`, `bySlug()`) instead of raw
   WHERE clauses. A bare `where('project_key', $x)` returns a mix of
   both states; wrong for retrieval grounding.
2. `scopeAccepted()` implies `canonical()` — don't re-implement status
   filtering by hand.
3. Project-scoped composite FKs on `kb_edges` — never add an edge without
   `project_key` (the FK is `(project_key, node_uid)`; tenant isolation is the
   application-layer R30 `forTenant()` scope, not the FK). FK violations are
   bugs to fix, not silence.
4. Slug + doc_id uniqueness is scoped per project. Two different projects
   CAN and SHOULD share `dec-cache-v2`.
5. Hard delete cascades the graph via `DocumentDeleter::forceDelete()`;
   soft delete leaves it intact. Never replicate either path manually.
6. Canonical re-ingest must vacate prior identifiers first or the
   composite uniques reject the insert. `DocumentIngestor::vacateCanonicalIdentifiersOnPreviousVersions()`
   handles it; any new ingestion path must too.
7. Reranker applies canonical boost + status penalty. New retrieval
   services must honour these knobs or add an ADR explaining the
   deviation.
8. Graph expansion + rejected injection are config-gated. Never
   hard-code "always on".
9. Every canonical mutation writes to `kb_canonical_audit` via the
   dedicated path. Bypassing it is a defect even if the change works.
10. Never hard-code global slug uniqueness. Queries that assume it
    will break the first time two projects share a slug.

→ See `.claude/skills/canonical-awareness/`.

### R11 — Frontend components must be test-friendly (from PR5 onward)
Every React component in `frontend/src/` must expose stable
`data-testid` attributes on actionable elements, meaningful ARIA
semantics (`role`, `aria-label`, `aria-live`), and observable async
states (`data-state="idle|loading|ready|error|empty"`, `aria-busy`).
API errors MUST surface in the DOM (no silent `useMutation` failures);
validation errors MUST appear next to each input with
`data-testid="<field>-error"`. Naming: `<feature>-<role>-<id?>`,
kebab case. Applied from PR5 (Chat React) onward. Copilot cannot
enforce this post-hoc — it starts at the component writing.
→ See `.claude/skills/frontend-testid-conventions/`.

### R12 — Every user-visible UI change ships Playwright E2E coverage
From PR5 onward, a PR that touches any file under `frontend/src/` or
any route/controller that renders into the SPA must include at least
one `*.spec.ts` file under `frontend/e2e/` covering the happy path and
at least one failure path (validation / 422 / 429 / network error /
empty state) for the changed feature. Scenarios use `getByTestId` or
`getByRole` + accessible name — never CSS selectors. They wait on
`data-state`, never on `waitForTimeout`. CI gate: `npm run e2e` green.
Authed tests reuse `playwright/.auth/admin.json` storage state — no
per-test login.
→ See `.claude/skills/playwright-e2e/`.

### R13 — E2E scenarios run against real data; mock ONLY external services
E2E tests are end-to-end on purpose: they must exercise the real
Laravel app, the real database (SQLite in CI, seeded via the
`TestingControllerSpy`/`DemoSeeder` pair), real Eloquent queries,
real Sanctum cookies, real controllers. `page.route(...)` is
**only** allowed to intercept calls that leave the application
boundary — the AI provider (OpenRouter / OpenAI / Anthropic /
Gemini / Regolo), email sending (Mailgun / SES / Mailersend),
remote object storage, payment rails, OCR APIs, or any other
third-party service that costs money or requires production
credentials. Intercepting `/api/admin/*`, `/api/kb/*`,
`/api/auth/*`, `/sanctum/csrf-cookie`, `/conversations`, or any
other internal route is a bug — the scenario becomes a unit test
in E2E clothing and stops catching the kind of integration
regressions E2E exists for. **Exception:** failure-mode injection
against an internal route is permitted when the happy-path
variant in the same file already covers the real-data flow; the
injection test must carry an `R13: failure injection` marker
comment so the intent is auditable. `playwright.config.ts` boots
`php artisan serve` via the `webServer` block with
`APP_ENV=testing` so every scenario has a working back-end
automatically. Per-scenario seeding goes through `/testing/reset`
+ `/testing/seed` (the `seeded` auto-fixture). A pre-commit /
pre-merge gate — `scripts/verify-e2e-real-data.sh` — greps
`page.route(` across `frontend/e2e/` and fails the build on any
unallowlisted internal interception without the marker. The
script is wired into `.github/workflows/tests.yml` so CI blocks
regressions.
→ See `.claude/skills/playwright-e2e/`,
  `.claude/skills/playwright-e2e-templates/`,
  `scripts/verify-e2e-real-data.sh`.

### R14 — Surface failures loudly; never answer 200 with empty/null/NaN
Every HTTP endpoint, renderer, log reader, and preview path must map
failure → the correct status code (404 missing, 500 unreadable, 503
downstream outage). Empty body on 200, `""` PDF on 200, `null` JSON on
500-underneath, and `-Infinity` / NaN in chart coordinates are all the
same bug: the caller cannot tell success from silent failure. PR #25
`KbDocumentController::printable` returned 200 on missing file. PR #27
`DompdfPdfRenderer::render` / `BrowsershotPdfRenderer::render` fell back
to `''` when `$dompdf->output()` / `Browsershot::pdf()` returned a
non-string, shipping zero-byte PDFs under 200. PR #28
`LogTailService::readTail` treated `key() === 0` as empty and dropped
single-line files. PR #20 `WikilinkHover.fetchWikilink` caught-all and
returned `null`, so React Query saw `isError=false` on a real 500.
PR #19 `AreaChart` / `BarStack` computed `Math.max(...[])` = `-Infinity`
and rendered broken SVG. PR #28 `LogViewerController::application`
chose 404 vs 500 by matching a message-prefix string. Check:

- [ ] Grep the diff for `return response()->json([...], 200)` in error
      branches; replace with 404 / 500 / 503 + error payload.
- [ ] Grep the diff for `return ''`, `return '[]'`, `return null` in any
      service/controller that a caller treats as success; throw or
      return a proper failure value.
- [ ] Grep FE for `try { ... } catch { return null }` — the UI must
      distinguish error from success.
- [ ] Any `Math.max(...arr)` / `Math.min(...arr)` guards the `arr.length
      === 0` case.
- [ ] Choose exception TYPE, not exception MESSAGE, to drive status code.

→ See `.claude/skills/surface-failures-loudly/SKILL.md`.

### R15 — Frontend a11y checklist: every actionable element announced + keyboard-reachable
Every interactive element in the React SPA must carry a programmatic
label (`<label htmlFor>`, `aria-label`, or `aria-labelledby`), be
reachable via keyboard (tab order, `:focus` style, not `display:none`),
and announce its role on the FOCUSABLE node — not on a wrapper. PR #19
`Tooltip` was mouse-only (no focus/blur). PR #23 `RoleDialog` hid the
real `<input type="checkbox">` via `style={{display:'none'}}` —
screen-readers cannot perceive display-none inputs. PR #23 `UserForm`
rendered visible `<div>` labels that weren't bound to their inputs.
PR #24 `TreeView` search input had placeholder-only, mode `<select>`
had no accessible name, and `role="treeitem"` + `aria-expanded` were
attached to the `<li>` wrapper while the interactive element was the
nested `<button>`. Check:

- [ ] Every `<input>` / `<select>` / `<textarea>` has either `<label
      htmlFor=id>` or `aria-label`. Placeholder is NOT a label.
- [ ] Visually-hidden but semantically-real inputs use the CSS
      visually-hidden pattern (`position:absolute; width:1px; ...`),
      never `display:none` or `visibility:hidden`.
- [ ] `role="…"` + `aria-expanded` / `aria-selected` / `aria-pressed`
      live on the element that receives focus (the `<button>`, not the
      `<li>` wrapper).
- [ ] Tooltips / popovers respond to focus/blur, not only
      mouseenter/mouseleave.
- [ ] Icon-only buttons have `aria-label`.

→ See `.claude/skills/frontend-a11y-checklist/SKILL.md`.

### R16 — Tests must actually exercise the behaviour they claim
A test's body must drive the state transition the test name promises.
Named "enables Save/Cancel after edit"? Then it must dispatch an edit
AND assert the buttons flip enabled — not just render and assert
disabled. Named "history is sorted desc"? Then the fixture must contain
rows that would FAIL under asc order, and the assertion must be
strictly `$first > $last`, not `$last >= $first` (which is trivially
true under either order). Named "failure path"? Then the failure must
actually fire. PR #26 `SourceTab.test` "enables Save/Cancel" never
dispatched a CodeMirror edit; the "422 frontmatter error" case never
clicked Save. PR #25 `KbDocumentControllerTest` history-ordering
reversed the comparison and passed under both orderings. PR #27
`admin-kb-graph` "empty state" test asserted `data-state="ready"` with
a center node — the scenario body proved the non-empty branch. PR #20
`TestingControllerTest` mutated `app()->detectEnvironment(...)` without
restoring. PR #30 `PromotionSuggestionsCard.test` overrode
`window.location` in `beforeEach` without restoring. Check:

- [ ] Test NAME and test BODY match: `grep -n "it('" | grep -n "expect("`
      and read the assertion against the name.
- [ ] Ordering / sorting tests use strictly-monotonic fixtures and
      `>` / `<`, not `>=` / `<=`.
- [ ] Any test that asserts "empty state" inspects for an empty-state
      testid, not for the "ready" state.
- [ ] Any test that mutates global state (env, DI, `window.location`,
      `Date.now`) restores it in `afterEach` / `tearDown`.
- [ ] "Failure path" tests actually provoke the failure (click Save,
      POST invalid body, unplug network).

→ See `.claude/skills/test-actually-tests-what-it-claims/SKILL.md`.

### R17 — React effects must sync cached / imperative state
When a component owns cached state inside an imperative library
(CodeMirror `EditorView`, canvas, D3, IMask, monaco) or in a ref that
mirrors server state, every effect branch that changes the underlying
source must ALSO sync the cached copy. PR #26 `SourceTab` updated
`savedRef` / `bufferRef` on refetch but never dispatched the new
content into the CodeMirror `EditorView` — the visible editor showed
stale content after every save. PR #20 `use-chat-mutation` filtered
cached messages to `m.id > 0` on success, dropping the optimistic
placeholder BEFORE the refetch completed — user's message vanished
briefly. PR #20 `ChatView` computed `fromUrl` as `NaN` on non-numeric
param; `NaN !== activeId` is always true → `setActive(null)` ran on
every render, looping forever. Same family: PR #28 `AuditTab` +
`FailedJobsTab`, PR #29 `CommandHistoryTable` all returned unkeyed
`<>...</>` fragments from `.map()` where React needs the key on the
list ELEMENT, not on a child inside the fragment — the inner `<tr
key={…}>` does not satisfy the requirement and reconciliation drifts.
Check:

- [ ] After any effect that re-reads server state, verify every
      imperative cache (EditorView, canvas, ref-of-server-state) was
      synced in the SAME branch.
- [ ] Optimistic updates stay in the cache UNTIL the refetch resolves
      (guard the filter on `isFetching`, not on `isSuccess`).
- [ ] Any derived value that can be `NaN` is guarded before the
      equality check (`Number.isFinite(x) && x !== activeId`).
- [ ] `.map()` of multi-element rows wraps in a keyed `<Fragment
      key={id}>` (not `<>`), not inside-`<tr key={id}>`.

→ See `.claude/skills/react-effect-sync-cached-state/SKILL.md`.

### R18 — Derive options from the DB, not from a literal subset
Any UI dropdown / filter / dataset that maps to a DB-derived domain
must fetch the actual domain, not hard-code a subset. PR #24 `KbView`
project filter was literally `['hr-portal', 'engineering']` — two
tenants the seeder happened to create. PR #22 `topProjects()` was
hard-coded to 7 days while the cache key encoded `(project, days)`
generally. PR #18 `RbacSeeder::backfillUser()` granted EVERY user
access to EVERY project_key — defeating tenant isolation. PR #27
`exportPdf` did `basename($sourcePath, '.md')` while KB ingest accepts
both `.md` AND `.markdown` — a `.markdown` doc exported as
`foo.markdown.pdf`. Check:

- [ ] UI select options derive from an API that returns the real
      domain (`GET /api/admin/projects/keys` / `distinct` query).
- [ ] No literal array of domain values in FE except for UI-only
      aesthetics (colour palettes, etc.).
- [ ] Backend filters accept the same parameter surface the cache key
      encodes — no "7 days silently fixed" while the rest is generic.
- [ ] File-extension handling covers every extension the ingest
      pipeline accepts (`.md` AND `.markdown`).

→ See `.claude/skills/derive-from-db-not-literal/SKILL.md`.

### R19 — Input escaping is complete, not partial
Every string passed to an operator with meta-characters (LIKE, regex,
fnmatch, shell) must be escaped for EVERY meta-char, not just the
obvious one. `%` in LIKE is obvious; `_` and `\\` also match. `*` in
fnmatch with default flags matches `/`; use `FNM_PATHNAME`. A literal
domain like `api.openai.com` is NOT a regex — in `grep -Eq` the `.`
matches any char. PR #23 `UserController` escaped `%` but not `_` —
queries with `a_b` matched `acb`. PR #18 `User::matchesAnyGlob` called
`fnmatch` without `FNM_PATHNAME` so `hr/policies/*` matched
`hr/policies/x/y`. PR #21 `verify-e2e-real-data.sh` treated
EXTERNAL_PATTERNS as regex via `grep -Eq` — unescaped `.` weakened the
gate. PR #17 `cors` / `sanctum` raw-`explode`'d whitespace-bearing CSV
env vars into stateful lists. Check:

- [ ] LIKE: escape `%`, `_`, and `\\`. Use `ESCAPE '\\'` explicitly.
- [ ] fnmatch: always pass `FNM_PATHNAME` when the input is a path.
- [ ] regex literals: escape `.`, `+`, `*`, `?`, `(`, `)`, `[`, `]`,
      `{`, `}`, `|`, `^`, `$`, `\\` when the pattern is meant as
      literal substring — or use `grep -Fq` (fixed string).
- [ ] CSV env vars: `array_filter(array_map('trim', explode(',', $raw)))`
      — never bare `explode`.

→ See `.claude/skills/input-escape-complete/SKILL.md`.

### R20 — Route contracts match the FE payload shape
When the FE expects `?token=X&email=Y`, the BE route must match
`GET /reset-password` with those query params, NOT
`GET /reset-password/{token}` (path param) — and vice versa. When an
E2E spec posts `{ project, markdown }`, the controller must accept
that shape; `{ project_key, content }` will 422. When `CommandRunner`
prepends `--` to every arg, commands with positional signatures
(`kb:delete {path}`, `kb:ingest-folder {path?}`) never populate.
PR #19 reset-password mismatch; PR #20 `chatRoute` had `$conversationId`
nested under a parent without rendering `<Outlet />`, so the child
never mounted. PR #24 `admin-kb.spec` sent the wrong ingest payload
(`{ project, markdown }` vs `{ documents.*.project_key +
documents.*.content }`). PR #29 `CommandRunnerService::invokeArtisan`
treated positional args as options. Check:

- [ ] Every FE `api.ts` call has a BE controller whose request
      validation mirrors the exact shape (open both files side-by-side).
- [ ] TanStack Router layouts that host child routes render
      `<Outlet />` (or the child never mounts).
- [ ] Artisan wrappers distinguish positional from option by signature
      (`{name}` vs `{--name=}`), not by blanket `--` prefix.
- [ ] Laravel implicit bindings that need `withTrashed()` declare it
      in the route definition.

→ See `.claude/skills/route-contracts-match-fe-shape/SKILL.md`.

### R21 — Security invariants are atomic or absent
Any single-use / rate-limit / auth check that crosses a concurrency
boundary must hold the lock until the invariant is recorded — or you
don't have the invariant. `lockForUpdate()` inside a transaction,
`update('used_at')` OUTSIDE, means two concurrent requests see
`used_at = null` and both succeed. That's not "rare" — it's the
textbook race window, and for a DESTRUCTIVE-command confirm token it
is RCE-class. PR #29 `CommandRunnerService::consumeConfirmToken`
(`59d95bc`) was exactly this shape: `DB::transaction(fn() =>
$row->lockForUpdate()->first())` then `$row->update([...])` after the
closure returned. Fixed by moving the update INSIDE the transaction.
**This rule is graded on blast radius, not frequency.** One
occurrence mints a dedicated rule + skill because the next instance
ships a public RCE. Check:

- [ ] Every `lockForUpdate()` read is followed by the write inside
      the SAME `DB::transaction` closure.
- [ ] Single-use tokens, nonces, rate counters mutate state INSIDE
      the lock, never after.
- [ ] `updateOrCreate` / `firstOrCreate` on a single-use resource
      carries `->where('used_at', null)` or its equivalent.
- [ ] `used_at` / `consumed_at` / `revoked_at` columns have a DB-level
      `UNIQUE` or `PARTIAL UNIQUE` constraint where the business rule
      demands it, not just code-path discipline.
- [ ] Concurrency-sensitive services have a test that fires two
      threads/processes at the same resource (or at minimum documents
      why a unit-level concurrency test is not feasible).

→ See `.claude/skills/security-invariants-atomic-or-absent/SKILL.md`.

### R22 — CI failure investigation: read the artefacts BEFORE iterating
When `gh pr checks` shows Playwright (or any E2E) job red, NEVER guess
fixes from the test name alone. The cost of a wrong commit is one CI
cycle (4–8 min) AND a misleading next-iteration baseline (the new
failure becomes "different" but you don't know why). Always pull the
full failure context first. Four sources, in order:

1. **Failed-job log** — `gh run view --job <id> --log-failed` to surface
   the `✘` lines, the spec:line that failed, and the error excerpt.
   60% of the time this clusters the failures by root cause already.
2. **Playwright HTML report artefact** — `tests.yml` uploads
   `playwright-report/` on failure (retention 7d). Either:
   - GitHub UI: PR → failed job → Artifacts → `playwright-report.zip`
   - Or `gh run download <run-id> --name playwright-report --dir /tmp/...`
   Inside the zip, `data/<hash>.md` files are the per-test error
   contexts (locator, timeout, page snapshot URL, screenshot path).
   Read them BEFORE diffing code — the snapshot often shows the page
   in a state that explains the failure (stale modal, unresolved
   spinner, error banner you missed).
3. **Laravel log tail** — the workflow's "Dump Laravel log on failure"
   step prints the last 200 lines of `storage/logs/laravel.log` inline
   in the failed-job log. Read it before assuming the failure is FE-only
   — a 500 from `/api/admin/...` surfaces as a Playwright
   "element not visible" while the actual stack trace lives in the
   laravel log.
4. **Diagnostic throws in tests** — when a non-2xx response masks
   itself as a generic timeout, add a temporary `waitForResponse` +
   throw so the next CI run prints the real status + JSON body in the
   failed-job log:
   ```ts
   const respPromise = page.waitForResponse(
       (r) => r.url().includes('/api/admin/.../raw')
           && r.request().method() === 'PATCH',
       { timeout: 15_000 },
   );
   await save.click();
   const resp = await respPromise;
   if (!resp.ok()) {
       throw new Error(`PATCH /raw returned non-OK: ${resp.status()} ${await resp.text()}`);
   }
   ```
   PR #33 caught the DemoSeeder frontmatter regression this way: the
   "toast not visible" timeout was actually a 422 with
   `{"errors":{"frontmatter":{"slug":["Missing required field 'slug'."]}}}`.
   Without the throw, the timeout was indistinguishable from a slow
   render. Leave the throws in until green; remove them in a polish
   commit only after the fix is verified.

This rule is operational, not a code rule — but skipping it costs 30+
min per false-iteration loop. Always artefact-first, then code.

→ See `.claude/skills/ci-failure-investigation/SKILL.md`.

### R23 — Pluggable pipeline: validate FQCN at boot + `supports()` mutex
Every interface registry (`PipelineRegistry`, future MCP-tool registry,
future provider registry) MUST validate at boot that each registered
FQCN actually implements the expected interface, AND its `supports()`
predicates MUST NOT overlap with another registered class. First-match-
wins resolution silently picks the wrong handler when overlap exists
(caught by T1.7's PdfPageChunker re-routing test). Test the overlap
detection explicitly.
→ See `.claude/skills/pluggable-pipeline-registry/SKILL.md`.

### R24 — Per-reason i18n with generic fallback; machine-readable tag stays English
Growing user-visible taxonomies (refusal reasons, validation errors,
audit-event labels) use a hierarchical `<namespace>.<category>.<reason>`
key with a generic fallback at the parent path. The machine-readable
identifier (e.g. `refusal_reason: 'no_relevant_context'`) NEVER
localizes — only the human-visible body does. FE renders BE-localized
strings verbatim; never introduce a parallel FE i18n surface for the
same content. Two translation surfaces drift over deploy windows.

### R25 — Optimistic mutations: dedupe by id when merging server response
Any TanStack Query (or Redux/Zustand equivalent) `onSuccess` that
merges optimistic placeholder + server-confirmed payload MUST filter
the cache by BOTH the optimistic id AND the server response's id
before appending. The merge is idempotent: same id appears AT MOST
once after the call. Without the dedupe, a cache that already
contains the server-id (prior refetch race, fixture seed, fast-typist
double-mutation) duplicates → React renders two same-id components
for ~100ms until reconciliation. Test posture: strict-mode locators,
NO `.first()`. If two same-testid elements ever appear, that's a real
regression, not flakiness.
→ See `.claude/skills/optimistic-mutation-dedupe/SKILL.md`.

### R26 — External-call short-circuits: prove no-call with `shouldNotReceive`
Any controller path that should skip an expensive external call when
local conditions don't warrant it (refusal threshold, cost guard, rate
limit, idempotency hit) MUST be proved by Mockery's
`shouldNotReceive('chat')` (or equivalent). Transport-agnostic; fails
loudly on regression. `Http::assertNothingSent()` only catches calls
that go through `Http::` — silently misses provider implementations
using a different HTTP client. Mirror the short-circuit across every
controller hitting the same external (e.g. `KbChatController` +
`MessageController` both refuse on missing context).
→ See `.claude/skills/refusal-not-error-ux/SKILL.md`.

### R27 — Response-shape extensions are ADDITIVE only
Extending a JSON response with new data: ADD new keys with sensible
defaults; NEVER rename or sub-objectify a primitive callers may
already read. New sub-structure goes under `<key>_breakdown` (or
`<key>_details`) as a sibling. Refusal/error paths emit the same
shape with sentinel values; never strip keys based on path. Test the
additive contract explicitly (e.g. `assertIsInt('meta.latency_ms')`
after the extension lands). Sub-objectifying after ship is a one-way
door — every existing client breaks silently.

### R28 — Per-project unique slugs + ALWAYS cascade m2m pivot delete
Per-project taxonomies (tags, categories, custom labels) backed by
m2m pivot tables MUST: (a) declare composite UNIQUE on
`(project_key, slug_or_name)` — never global; (b) declare FK with
`cascadeOnDelete()` on the pivot; (c) reject `project_key` change on
update with 422 (orphan-pivot guard); (d) test the cascade explicitly
(`assertDatabaseMissing` on pivot rows after parent delete). Global
slug uniqueness blocks two tenants picking the same intuitive name.
Pivot orphan rows make the FE crash on undefined relationships.

### R30 — Cross-tenant isolation on every tenant-aware query
Every Eloquent query against a tenant-aware table MUST be scoped to the
active tenant via `forTenant($ctx->current())` (provided by the
`BelongsToTenant` trait) or an explicit `where('tenant_id', ...)`. Two
different customers can legitimately share the same `project_key` — tenant
boundary is the only safe scope. Cross-tenant leak = GDPR catastrophe.
Tenant-aware tables (authoritative list: `TenantIdMandatoryTest::TENANT_AWARE_MODELS`):
knowledge_documents, knowledge_chunks,
chat_logs, conversations, messages, kb_nodes, kb_edges,
kb_canonical_audit, project_memberships, kb_tags,
knowledge_document_tags, knowledge_document_acl, admin_command_audit,
admin_command_nonces, admin_insights_snapshots, chat_filter_presets.
**`embedding_cache` is intentionally NOT tenant-aware** — a cross-tenant reuse
layer keyed `UNIQUE(text_hash, provider, model)` (same text+provider+model embeds once
across tenants); `TenantIdMandatoryTest` documents the exclusion. (`User` is likewise
excluded as cross-tenant identity.)
**Package-owned tenant-aware tables** (NOT in the host `TenantIdMandatoryTest`
enumeration because their models live in `vendor/`, not `app/Models/` — the
package enforces R30/R31 in its own CI; same posture as the
`padosoft/askmydocs-connector-base` tables): the 9
`padosoft/laravel-invitations` tables — `invite_campaigns`, `invite_codes`,
`invitations`, `invite_redemptions`, `invite_referrals`, `invite_rewards`,
`invite_waitlist`, `invite_abuse_signals`, `invite_analytics_events` — each
carry `tenant_id` and are scoped through the host `TenantContext` (bound to the
package's `Padosoft\Invitations\Contracts\TenantResolver` in `AppServiceProvider`).
→ See `.claude/skills/cross-tenant-isolation/SKILL.md`.

### R31 — `tenant_id` mandatory on every tenant-aware Model + migration
Every Eloquent model under `app/Models/` representing a tenant-scoped
domain entity MUST `use BelongsToTenant;` (auto-fills tenant_id on
creating from `TenantContext`) and list `'tenant_id'` in `$fillable`
(or use `$guarded = ['id']`). Every new migration creating a tenant-aware
table MUST add `string('tenant_id', 50)->default('default')->index()`
and start composite uniques with `tenant_id`. Architecture test
`tests/Architecture/TenantIdMandatoryTest.php` enumerates the model list
and gates new entries.
→ See `.claude/skills/tenant-id-mandatory/SKILL.md`.

### R32 — RBAC access-control is a regression gate (authorization matrix)
Every new **protected route / API / admin SPA screen / `Gate::define` /
role / permission / feature-flagged route group** MUST be added, in the
SAME PR, to the canonical access-control matrix:

- **API:** `tests/Feature/Security/AdminAuthorizationMatrixTest.php` — the
  `matrix()` array maps a representative no-path-param endpoint per group
  → the EXACT allow-set of roles. The test asserts, for all five roles
  (super-admin / admin / dpo / editor / viewer) + the guest:
  role-NOT-in-set → **exactly 403**; role-in-set → **anything-but-403**
  (authz passed; the controller may still 200/404/422/500 on data);
  guest → **401**.
- **UI:** `frontend/e2e/role-access.spec.ts` — per-role nav/screen
  visibility + reachability.

Per-controller tests each cover one endpoint for one or two roles, so a
new route that forgets its `role:` / `can:` gate — or a vendor package
that mounts routes with a permissive `middleware => ['api']` default —
ships **green**. This rule is graded on **blast radius, not frequency**:
the matrix's first run (v8.4) caught `api/admin/ai-act-compliance/*`
mounted with NO auth, exposing DSAR / incidents / bias / risk-register /
consent data **unauthenticated** — one missing config key = a public data
breach. Package-registered admin routes MUST be gated by overriding the
host `config/<pkg>.php` `routes.middleware` with the authenticated admin
stack (`auth:sanctum` + `tenant.authorize` + `can:<gate>`), and that host
config MUST be loaded in `tests/TestCase.php::getEnvironmentSetUp`
(Testbench does NOT auto-load host `config/`) so the matrix verifies the
SECURE config, not the package default.
→ See `.claude/skills/rbac-authorization-matrix/SKILL.md`.

### R37 — Branching strategy: `feature/v4.x` integration branches → main
For AskMyDocs, `main` holds the **stable production release** (v3 today,
v4.0 when v4.0 RC ships, v4.1 when v4.1 ships, etc.). Each major
release works in its own integration branch:

- `main` ← stable production
- `feature/v4.0` ← integration branch for entire v4.0 cycle (8 weeks)
- `feature/v4.0/W1.B` ← sub-branch per sottotask, PR target = `feature/v4.0`
- `feature/v4.1` ← integration branch for v4.1, PR sub-branches target it
- ... and so on for v4.2, v4.3, v4.4

**Merge to main happens ONCE per major release**, when:
- All sub-branches merged into `feature/v4.x`
- All tests + CI green on `feature/v4.x`
- RC1/RC2 acceptance criteria passed
- Then: `feature/v4.x` → `main` → tag `v4.x.0`

**Why not merge sub-branches direct to main**:
- v3 must stay stable on main for hotfixes during 6-month v4 development
- Half-merged v4 features on main would break v3 production users
- Single merge per release = single review surface, single deploy event

**For new repos** (`padosoft/laravel-ai-regolo`, `padosoft/laravel-flow`, etc.,
created fresh for v4): PRs target `main` directly — no stable code to
preserve; main and develop converge from day 1.

Lorenzo decided this on 2026-04-28 during W1.B PR #78. Existing PR #78
re-targeted from main to feature/v4.0.
→ See `.claude/skills/branching-strategy-feature-vx/SKILL.md`.

### R36 — Copilot review + CI green loop is MANDATORY after EVERY push
**The 9-step canonical flow** for every PR on every Lorenzo / Padosoft repo:

1. Fine task — implementation complete.
2. Test tutti verdi in **locale** (phpunit + vitest + playwright + architecture).
3. Apri PR with `gh pr create --reviewer copilot-pull-request-reviewer ...` —
   the flag is **mandatory** on every PR. Two knobs interact:

   - **The short alias `--reviewer copilot`** only resolves when the
     repo / org has **GitHub Copilot Code Review enabled** (Settings →
     Copilot → Code review → "Enable for this repository"). On a
     fresh repo where the feature is disabled, `gh` reports "could
     not request reviewer" and opens the PR without a reviewer
     assigned.
   - **The canonical login `copilot-pull-request-reviewer`** is the
     bot's actual GitHub username. The `gh` CLI accepts it as a
     reviewer **regardless of whether Copilot Code Review is enabled**
     — i.e. the assignment itself succeeds in both states (CR
     enabled → bot reviews automatically; CR disabled → bot is
     listed as a reviewer but no automated review fires; enabling
     CR later is a one-time setting toggle). Always pass the full
     username so the assignment never silently fails.

   Same login goes into `gh pr edit <N> --add-reviewer copilot-pull-request-reviewer`
   when Copilot is re-requested after each push.
4. Attendi CI GitHub verde (typically 60–180 s).
5. **Attendi Copilot review commenti** (typically 2–15 min after PR open).
   Skipping this wait — even when CI is already green — is a protocol
   violation.
6. Leggi commenti (`gh pr view <N> --comments` + inline via
   `gh api .../comments`) e fix locale.
7. Ri-attendi CI tutta verde dopo il push del fix.
8. Se Copilot ri-review trova nuovi commenti → GOTO step 5.
9. Merge solo quando ENTRAMBI:
   - `reviewDecision = APPROVED` **oppure** zero outstanding must-fix
     Copilot comments;
   - all CI checks `status COMPLETED + conclusion SUCCESS` (or expected
     SKIPPED).

Exit conditions are conjunctive: green CI alone is **not enough**.
Anti-pattern: "Push, see green CI, merge now" — costs the user a code
review pass that Copilot would have caught. Lorenzo flagged this
explicitly on PR #78 (2026-04-28) and reinforced it on padosoft
PR #1/#2/#3 (2026-04-29) — they were merged without
`--reviewer copilot-pull-request-reviewer`
and without waiting for Copilot review, which is a protocol violation
even though the code shipped clean.

**Scope**: applies to all repos under `lopadova/*` and `padosoft/*`
(current and future), to every developer and every AI agent working on
the codebase, and to **every** PR — including docs-only PRs and CI-fix
PRs. The only acceptable exception is a documented hotfix where every
minute of delay is operationally costly; even then the post-merge
review must run retroactively.

**Review-provider fallback — Copilot first, Codex on out-of-budget
(2026-06-14, Lorenzo):** the **first** cloud reviewer is ALWAYS GitHub
Copilot (steps 3/5 above). But when Copilot is **out of budget for a
prolonged period** — the symptom is HTTP **402** `additional_spend_limit_reached`
on the copilot-cli critic (R40) AND no cloud review fires after
requesting it (seen across PRs #272–#274) — do **NOT** stop or merge
review-less: **automatically switch to the ChatGPT Codex connector** and
run the SAME loop on it.
- Codex = the GitHub App **chatgpt-codex-connector**
  (https://github.com/apps/chatgpt-codex-connector), installed on the
  repo. Trigger it by posting a PR comment whose body is **`@codex
  review`** (`gh pr comment <N> --body "@codex review"`); it posts a
  review (state `COMMENTED`) with inline findings like Copilot.
- Re-trigger after every fix: end the fix-reply comment with `@codex
  review` (proven on `padosoft/scalar-openapi-doc` PR #16, ~20 rounds).
- Loop the same way: read findings → fix → `@codex review` → repeat until
  0 must-fix, then merge (CI green + 0 outstanding must-fix).
- **Always-on local gate:** regardless of which cloud bot is live, run an
  independent **code-reviewer SUBAGENT** (Task tool) as the pre-merge
  safety net — fast, billing-free, and it has caught real must-fix issues
  while the Copilot budget was out. When BOTH cloud bots are unavailable,
  the subagent review carries the merge (CI green + subagent 0 must-fix),
  and a retroactive cloud review runs once budget returns.
→ See `.claude/skills/copilot-pr-review-loop/SKILL.md` +
  [[feedback_review_escalation_copilot_then_codex]].

### R38 — Heavy work belongs in CLI workflow steps, not behind `php artisan serve`
**The architectural rule for any CI flake of the form
"Playwright auth.setup ECONNREFUSED on `/testing/reset` after
`/healthz` answered green":**

- Do **NOT** start the investigation by detaching the artisan-serve
  process (`setsid -f`, `nohup`, `disown`, PGID kills, `lsof`-based
  PID capture, stability-gate healthz polling). PHP's built-in dev
  server has a single-threaded accept loop per worker; when one
  handler runs `migrate:fresh` or any multi-second blocking task the
  loop stalls and every subsequent connection ECONNREFUSEs. That is
  the dev server doing its job, not a process-management bug.
- **DO** ask: "what does the failing endpoint actually run? Could that
  work happen via a CLI artisan step BEFORE Playwright starts?"
  If yes, that is the fix.

The structural fix that landed on PR #85 is the canonical example:

1. Workflow step before Playwright runs `migrate:fresh` from the CLI.
   `key:generate --force` runs FIRST so it REPLACES the empty
   `APP_KEY=` line copied from `.env.example` in-place — the earlier
   "Prepare .env for testing" step deliberately leaves the line empty
   (using `sed -i 's|^APP_ENV=.*|APP_ENV=testing|' .env` for APP_ENV
   in-place replacement, no `echo "APP_KEY=..." >> .env` for APP_KEY)
   so `key:generate` has nothing to duplicate. Net effect: exactly
   one `APP_ENV=` and one `APP_KEY=base64:…` definition in .env, no
   shell-escaping hazard from piping openssl-generated base64
   (which can contain `/`) through sed.
   ```yaml
   - name: Migrate test database (CLI)
     env: { APP_ENV: testing }
     run: |
       php artisan key:generate --force      # replaces empty APP_KEY=
       php artisan migrate:fresh --force
   ```
2. Every E2E call site that needs to wipe the DB goes through a
   single `resetDb(target)` helper in `frontend/e2e/setup-helpers.ts`.
   `resetDb()` ALWAYS posts to `/testing/reset` — it does NOT honour
   `E2E_SKIP_HTTP_RESET`. The flag is intentionally narrow: it only
   short-circuits the redundant initial reset inside the setup-time
   `resetAndSeed(target)` helper (which is what auth.setup /
   viewer.setup / super-admin.setup call during the boot-race
   window). Per-scenario reseeding — `admin-dashboard.spec.ts`
   switching to `EmptyAdminSeeder`, `admin-insights.spec.ts`
   wiping snapshots before `DemoSeeder`, the `seeded` auto-fixture
   in `fixtures.ts` running before every test — needs an actual DB
   wipe. `/testing/seed` only INSERTS rows; it does not truncate, so
   skipping `/testing/reset` mid-suite would leave cross-scenario
   state and produce order-dependent assertions. Direct
   `request.post('/testing/reset')` calls in spec / fixture code are
   forbidden anyway: they bypass the (future-extensible) helper and
   make wipe semantics impossible to refactor in one place.
3. The Playwright step sets `E2E_SKIP_HTTP_RESET: '1'`.

The remaining HTTP traffic is light enough that `php artisan serve`
handles it without breaking a sweat. The flake disappears.

**Trigger conditions for the CLI move** (all four must be true):
- The failing endpoint runs `migrate:fresh`, big seeders, or any
  multi-second blocking artisan command.
- The endpoint is hit ONCE at suite startup (not per-test).
- The work is idempotent (CLI can run it once per workflow start).
- The test environment is disposable (CI runner / local docker, not
  shared with humans).

**Anti-pattern reference**: PR #83 commits `6071b81 → 4cde177` —
seven process-management iterations (setsid, lsof, PGID, defensive
sweep) without converging. Read those commits when you feel tempted
to detach a child process from a bash session in a GitHub Actions
step. The fix Copilot landed on PR #85 (`e2c87c29`) is ~58 lines
across two files and was correct on the first try.

→ See `.claude/skills/ci-failure-investigation/SKILL.md` (R22) for
the artefact-first investigation flow that should surface this rule
before the rabbit hole opens.

### R29 — testid hierarchy: `feature-resource-{id}-{action[-substep]}`
Every interactive admin or chat surface uses the testid hierarchy
`feature-resource-{id}-{action[-substep]}` for stable, hierarchical,
grepable Playwright + Vitest selectors. Examples:
`admin-tag-row-42-delete-confirm`, `chat-filter-preset-7-load`,
`filter-chip-source-pdf-remove`, `filter-popover-close`. Trigger
buttons follow `feature-action`: `chat-filter-bar-add`,
`admin-tags-create`. Predictable selectors survive DOM refactors;
cross-feature memorisation isn't required when convention holds.
Stateless components (`FilterBar`, `TagsList`) take `(value, onChange)`
controlled props — state lifts to the lowest common parent.

### R39 — Tag `vX.Y.0-rcN` at the end of every Wn milestone
Standing convention from 2026-05-02. R37 says "merge to main once per
major release"; R39 fills the gap by giving every weekly milestone a
visible release-candidate tag. After each Wn closure on
`feature/vX.Y` (every sub-task PR merged + CI green + closure status
doc shipped under `docs/v4-platform/STATUS-{date}-week{N}.md`):

1. Open a small docs PR refreshing **`README.md`** — specifically the
   `### Key Features` and `## Changelog` sections (AskMyDocs keeps the
   changelog inline in the main README; there is no separate
   `CHANGELOG.md` file). Add a new entry under `## Changelog` with the
   `vX.Y.0-rcN` heading + bullet list of Wn deliverables, and refresh
   `### Key Features` so the freshly-shipped capabilities surface above
   the fold for prospective consumers.
2. **Capture the closure-commit SHA before the docs PR merges**, then
   tag at that exact SHA — never against the moving `feature/vX.Y` ref,
   because another PR landing between `gh release create` and the docs
   PR merge would silently shift the rc to the new HEAD:
   ```bash
   CLOSURE_SHA=$(git rev-parse origin/feature/vX.Y)
   gh release create vX.Y.0-rcN \
     --repo lopadova/AskMyDocs \
     --target "$CLOSURE_SHA" \
     --title "vX.Y.0-rcN — Wn milestone" \
     --prerelease \
     --notes "..."
   ```
3. Increment `N` once per Wn closure: rc1 after W4, rc2 after W5, etc.
   The final `vX.Y.0` GA tag fires only when the LAST Wn closes (W8
   for v4.0) and the integration branch merges into `main` per R37.

Why a release-candidate and not a final tag at every Wn:
- Composer / Packagist semver: `^X.Y` resolution skips RC builds by
  default. Consumers explicitly opt in via `^X.Y@beta` or
  `^X.Y.0-rcN` if they want the milestone preview. The stable channel
  remains the previous major until the GA ships.
- Each rc is a checkpoint. If something regresses between Wn and
  Wn+1, the rc tag is a known-good rollback target.
- Audit + community visibility: tagging publicly demonstrates progress
  every week without committing to a final API contract — and gives
  Patent Box auditors a clean per-week artefact to point at.

Anti-patterns:
- ❌ Tagging the rc on `main` — rejected by R37.
- ❌ Skipping the README + CHANGELOG refresh — leaves consumers staring
  at a stale "Roadmap" claiming the freshly-shipped feature is still
  pending.
- ❌ Tagging mid-Wn (between sub-task merges) — wait for the closure
  status doc to land first.
- ❌ Re-tagging the same `rcN` after subsequent commits — bump to
  `rcN+1` instead.

Scope: applies to AskMyDocs (`lopadova/AskMyDocs`) integration-branch
cycles. Standalone `padosoft/*` packages tag their own normal-semver
`v0.1.0` final at the end of their respective Wn (already established
for `padosoft/laravel-patent-box-tracker` after W4). Those follow
plain SemVer, NOT the RC convention.

→ See `.claude/skills/rc-tag-per-week-milestone/SKILL.md`.

### R40 — Local critic loop (copilot-cli) BEFORE every push (v8.0+)

Standing convention from **2026-05-18** (v8.0/W1.1 — Lorenzo decision
post round-2 of PR #188). Every PR push from this point forward on
`lopadova/AskMyDocs` and every `padosoft/*` repo MUST run a local
copilot-cli pre-flight review BEFORE the push leaves the laptop.
The R36 cloud loop stays mandatory but converges in 1-2 rounds
instead of 5-15.

**Tool**: GitHub Copilot CLI
(`copilot --autopilot --yolo --add-dir "$(pwd)" -p "<prompt>"`).
`--autopilot` lets the agent run multi-step research autonomously;
`--yolo` is shorthand for `--allow-all-tools --allow-all-paths
--allow-all-urls`; `--add-dir "$(pwd)"` whitelists the current repo
root for file access (otherwise the agent's allowed-paths default
is the home directory only and grep across the codebase comes back
empty); `-p` is non-interactive prompt mode (single-shot). Keep
this canonical command in lockstep with
`.github/copilot-instructions.md` section §R40.

**Slash-command invocation**: copilot-cli ships a built-in
`/review` slash command (visible in interactive `/help` since
v1.0.49 — "Run code review agent to analyze changes"). It can be
invoked from `-p` non-interactive mode by passing it as the **first
line** of the prompt:
```bash
copilot --autopilot --yolo --add-dir "$(pwd)" -p "$(cat <<'EOF'
/review

<context, diff file paths, R-rule reminders, SUMMARY contract>
EOF
)"
```
This routes the prompt through the dedicated code-review pipeline
on the Copilot side rather than the general agentic loop, with
sharper findings on diff hunks.

**Diff-passing pattern**: pass the actual PR diff to the agent so
findings are anchored to real hunks, not the agent re-deriving
context from `git log`:
```bash
git diff "origin/${BASE_BRANCH}...HEAD" >/tmp/pr-diff.patch
gh pr view --json number,title,body >/tmp/pr-meta.json
# then reference both files in the prompt and let copilot read them
```
The wrapper script `scripts/local-critic-loop.sh` encodes this
pattern + the SUMMARY-line contract (see below).

**Path-scoped R-rule instructions**:
`.github/instructions/r-rules.instructions.md` carries the critical
R-rule subset with `applyTo: "**/*.{php,ts,tsx,js,jsx,yml,yaml}"`
frontmatter. Both copilot-cli (via the
`.github/instructions/` directory convention — Copilot CLI auto-
discovers these under git root) and GitHub Copilot Code Review on
the cloud side load this file when the diff touches matching
extensions, so the reviewer sees the same rule set in both loops.

**SUMMARY-line contract**: the prompt MUST end with a directive
asking the agent to close its review with EXACTLY one line in the
form `SUMMARY: <N> must-fix, <M> nit`. The wrapper script greps
that line and exits non-zero when `N > 0`, which makes the wrapper
usable as a `pre-push` git hook or a manual gate before
`gh pr create`.

**Canonical wrapper**: `scripts/local-critic-loop.sh [base-branch]`
encodes all of the above (diff capture + meta capture + prompt
assembly + SUMMARY parsing + exit-code). Invoke it after
local tests pass, before `git push`. The full rule with examples
lives in
[`.claude/skills/copilot-pr-review-loop/`](.claude/skills/copilot-pr-review-loop/)
and the R-rule subset the agent enforces lives in
[`.github/instructions/r-rules.instructions.md`](.github/instructions/r-rules.instructions.md).

**Mandatory workflow per sub-PR**:

1. **Local tests green first** (`vendor/bin/phpunit` + relevant
   targeted suites). Standard pre-existing gate.
2. **Settle the working tree before review.** Stop editing,
   save every open buffer, run tests once more so phpunit
   confirms the WIP compiles + behaves as intended. The working
   tree can stay uncommitted at this point — copilot-cli reads
   it via `git diff HEAD` plus direct file reads — but it MUST
   NOT be mid-edit (half-typed methods, broken syntax, etc.).
3. **`copilot --autopilot --yolo -p <review-prompt>` against the
   settled working tree** (or the last N commits on the branch
   when the diff is already staged/committed). The prompt MUST
   ask for: must-fix bugs / R-rule compliance violations /
   contract drift between schema-models-tests-docs / security
   issues (R21 + R30 + R31) / missing edge coverage / migration
   safety. Skip nitpicks (formatting / comment style — Copilot
   Code Review on GitHub will catch those if they matter).
4. **Fix every finding locally** (must-fix + should-fix). Re-run
   tests after each fix.
5. **Re-run `copilot --autopilot --yolo --add-dir "$(pwd)" -p`**
   to verify the fixes landed and to catch any new issues
   introduced by the fixes. Loop until copilot-cli reports
   `0 must-fix, 0 should-fix`.
6. **Only then push.** First push of a new sub-branch creates the
   PR with `gh pr create --reviewer copilot-pull-request-reviewer`
   per R36. Subsequent pushes re-request review via `gh pr edit
   <N> --add-reviewer copilot-pull-request-reviewer` per R36.
7. **R36 cloud loop runs as documented** on the now-much-cleaner
   commits. Expected: 0-1 round of GitHub Copilot findings; rarely
   2. If GitHub Copilot finds NEW issues that copilot-cli missed,
   note the gap as a calibration signal (the local prompt needs to
   ask harder questions for that class of finding).

**Why mandatory**:
- Wall-time win: ~6-10 min CI cycle × N fix rounds becomes 1-3 min
  copilot-cli call × N (no CI burn, no waiting for the formal
  Copilot review lag of 1-7 min). Established empirically on
  PR #188: 6 findings caught locally that would have been 1-2
  extra cloud rounds.
- Quality win: multi-LLM diversity (the Anthropic-side coder doing
  the work + the Copilot-side reviewer + the GitHub Copilot Code
  Review bot) catches more than any single reviewer.
- Cost win: ~1 copilot-cli premium request per round vs ~30 min of
  human attention waiting on cloud cycles.

**Anti-patterns**:
- ❌ Push first, then run copilot-cli post-hoc on the cloud-mirrored
  branch (defeats the wall-time saving; doesn't reduce GitHub
  Copilot findings).
- ❌ Skip copilot-cli because "the diff is small" — the discipline
  is uniform; small diffs converge in seconds anyway.
- ❌ Accept copilot-cli findings without fix and push regardless —
  if copilot-cli finds it, GitHub Copilot will likely find it too,
  and re-pushing fixes is more expensive than fixing pre-flight.
- ❌ Run copilot-cli mid-edit while the working tree is still in
  flux (half-typed methods, broken syntax, unsaved buffers).
  Pause edits, save files, run tests once for sanity, THEN
  invoke copilot-cli. Uncommitted edits are fine — in-flux
  edits are not.

**Calibration**: keep `feedback_local_critic_loop_before_push` memory
file updated with examples of (issue found locally → would have hit
cloud → counterfactual minutes saved) so the rule's ROI stays
measurable session-over-session.

**Scope**: every PR on `lopadova/AskMyDocs` from 2026-05-18 onward,
and every PR on `padosoft/*` (current + future). Applies to
docs-only PRs and CI-fix PRs too. Same exception clause as R36:
the only acceptable skip is a documented hotfix where every minute
of delay is operationally costly — and even then the local critic
must run retroactively.

### R41 — Test teardown: roll the DB back BEFORE `Mockery::close()`

A test's `tearDown()` MUST call `parent::tearDown()` (which runs the
`RefreshDatabase` rollback) **before** any code that can throw —
specifically `Mockery::close()`. The framework already closes Mockery
safely (after the rollback, wrapped in try/catch); a MANUAL
`Mockery::close()` placed BEFORE `parent::tearDown()` is the bug:

```php
// ❌ WRONG — close-before-parent. If a `->once()` expectation is
//    unmet, Mockery::close() THROWS, parent::tearDown() never runs,
//    the RefreshDatabase transaction is never rolled back, and the
//    NEXT test errors "PDOException: There is already an active
//    transaction" — a cascade that turns ONE real failure into a
//    suite-wide red, masking the true culprit and reading as flake.
protected function tearDown(): void
{
    Mockery::close();      // throws here on unmet mock
    parent::tearDown();    // rollback SKIPPED
}

// ✅ RIGHT — rollback first, then verify mocks. A failed expectation
//    fails only ITS OWN test; the transaction is already clean.
protected function tearDown(): void
{
    parent::tearDown();    // rollback ALWAYS happens
    Mockery::close();      // safe to throw now; DB already clean
}
```

This is graded on **blast radius, not frequency**: one fragile teardown
poisons every test that runs after it in random order, so a single
unmet mock surfaces as a non-deterministic "active transaction"
cascade that wastes a full CI cycle chasing the wrong test. v8.8/W1
reordered 35 such files in one sweep and added a `TenantContext` reset
to the base `tests/TestCase.php::setUp()` so no test can leak
tenant-scoped state into a sibling and trigger the unmet expectation in
the first place. Check:

- [ ] Every custom `tearDown()` runs `parent::tearDown()` (the rollback)
      BEFORE any THROWING cleanup (`Mockery::close()`). Non-throwing
      cleanup that needs `$this->app` (e.g. a `TenantContext` reset) may
      run before `parent::tearDown()`; only throwing calls must come
      after the rollback.
- [ ] Prefer dropping the manual `Mockery::close()` entirely — the
      framework's `tearDown()` already closes it safely.
- [ ] Base `TestCase::setUp()` resets request-scoped singletons
      (`TenantContext`) so a tenant-switching test cannot contaminate
      the next one.
- [ ] A "flaky" suite that fails with "active transaction" or "did not
      remove its own error/exception handlers" is almost always a
      teardown that threw before its rollback — fix the teardown, do
      NOT just re-run CI.

→ See `.claude/skills/test-teardown-rollback-before-mockery/SKILL.md`.

### R42 — On transient API failure: never stop, wait, retry in a loop

Operational rule (like R22), standing from **2026-06-03** (Lorenzo). When an
agent action hits a **transient, recoverable** failure talking to an external
service — HTTP 429 rate-limit, a 5xx / transport error from the AI provider,
`Stream idle timeout`, a dropped/again-up network, a "no connection" blip —
the agent MUST NOT stop, abandon the task, or ask the user what to do. Instead:

1. Wait ~60 seconds.
2. Retry the same operation.
3. Repeat the wait-then-retry loop **indefinitely** until access is restored.

Do NOT drop out of an unattended `/loop` / auto-mode run on a transient error —
that is exactly when the loop must keep itself alive. This is the same posture
as [[feedback_stream_idle_timeout_retry]] generalised to every recoverable
external-call failure (live verification, benchmark runs, copilot-cli calls,
PR/CI polling, the live AI provider during ingest/chat tests).

**Only** surface to the user (instead of looping) when the failure is clearly
**non-transient**: a `401` on a key known to be valid, a `403`/permission
denial, a `4xx` contract/validation error that a retry cannot fix, or a
permanent quota exhaustion. Those are real signals; rate-limits and timeouts
are not.

Scope: every agent/session on `lopadova/*` and `padosoft/*`, attended or
unattended. Mirrors the private memory [[feedback_retry_on_api_error_never_stop]].

### R43 — A boolean feature flag is tested in BOTH states, never just enabled

Inalienable rule, standing from **2026-06-03** (Lorenzo, after the eval-harness
500). Any `true/false` feature flag (env knob, `config('…enabled')`, a
package-mount toggle like `EVAL_HARNESS_UI_ENABLED` / `FLOW_ADMIN_ENABLED` /
`PII_REDACTOR_ADMIN_ENABLED`, a chat/retrieval gate, an admin-panel switch)
MUST be verified to leave the app **healthy in BOTH states — OFF and ON — not
only when enabled**. "It works when I turn it on" is half a test.

The eval-harness failure is the canonical example: enabling the flag mounted a
package Blade route that `@vite`'d an asset absent from the host manifest →
**500** on every data call; the cross-mount surfaced "API returned 500". It had
only ever been exercised enabled-and-assumed-working — the OFF path (clean 404
degrade) and the ON-but-backend-unwired path (the 500) were never checked. The
fix made the host probe the data API and show a single clean "unavailable"
landing, so the feature is safe **on or off**.

Concretely, for every flag added or touched:

- [ ] **OFF path**: with the flag false, the feature's routes/UI degrade
      cleanly — a 404 / disabled state / "unavailable" panel, **never** a 500,
      a blank crash, an unhandled exception, or a `page.route`-less error storm.
      A consumer that hits the now-absent backend must handle the 4xx, not throw.
- [ ] **ON path**: with the flag true, the feature works **or**, if its backend
      isn't wired in this deployment, still degrades to a clean state (no raw
      500 / stack trace reaching the user).
- [ ] **Tests cover both**: a unit/e2e asserting the OFF (disabled/unavailable)
      branch AND the ON branch — not just the happy enabled path. Toggling the
      flag in the future must hold no surprises.
- [ ] **Default-OFF flags get extra scrutiny on the OFF path** — that is the
      state every fresh deploy ships, and the one most likely to be skipped in
      manual testing precisely because "I enabled it to try it".

Graded on blast radius: one un-tested OFF path that 500s is a public incident
the first time an operator flips a knob. Mirrors the private memory
[[feedback_test_feature_flags_both_states]].

### R44 — Every capability is tri-surface: PHP + HTTP API + MCP, over ONE core

Iron rule, standing from **2026-06-13** (Lorenzo, during the Auto-Wiki epic).
Every feature/capability we introduce — and every later modification of an
existing one — MUST be **exposed AND consumable across all three surfaces**:

1. **PHP** — an Artisan command and/or a service/facade callable from app code.
2. **HTTP API** — a RESTful endpoint, auth + RBAC-gated (R32 matrix entry), with
   the same request/response contract discipline as every other admin route.
3. **MCP** — a `Laravel\Mcp\Server\Tool` (read or write) registered on
   `KnowledgeBaseServer::$tools`, with a `schema()` and `handle()`.

All three are **thin layers over ONE shared core service** — never three
parallel implementations. The service holds the logic, the audit, the
tenant-scoping (R30); the command/controller/tool only adapt input → core →
output. A capability that lands on only one or two surfaces is a **gap, not a
smaller feature** — close it in the same PR or file the follow-up explicitly.

When you DESIGN a Px / feature / package integration, plan the three surfaces
up front (the plan's "tri-surface exposure" line). When you MODIFY a capability
(new field, new option, changed contract), propagate the change to all three
surfaces + their tests in the same PR — so the surfaces never drift.

Check:

- [ ] New/changed capability has a PHP entry point (command or service method).
- [ ] New/changed capability has an HTTP endpoint + an R32 authorization-matrix
      row (representative endpoint → exact allow-set of roles).
- [ ] New/changed capability has an MCP tool registered on
      `KnowledgeBaseServer::$tools` (+ the registration-count test bumped).
- [ ] All three delegate to the SAME core service — no duplicated logic.
- [ ] Each surface is tested at its layer (service PHPUnit + HTTP feature test +
      MCP registration/contract test); UI surfaces add Vitest + Playwright.

The exception is a capability that is intrinsically single-surface (e.g. a
scheduler-only maintenance sweep with no caller-facing read) — state WHY in the
PR; absence of a surface is a deliberate, documented choice, never an omission.
Mirrors the private memory [[feedback_tri_surface_php_api_mcp]].

### R45 — Doc-site parity: every feature/release/README change ships its Mintlify deep-doc

Standing convention from **2026-06-15** (Lorenzo). The public documentation site
lives under **`/docs-site/`** (Mintlify, groups-based, deployed to
`padosoft.mintlify.app` via the GitHub App on every push touching `docs-site/`).
It is **separate** from the 162 internal dev docs in `/docs/` and is authored
from scratch at senior-architect / academic depth — NOT a condensed README paste.

When a PR adds or changes a capability — or edits `README.md` feature tables /
changelog / roadmap — it MUST also add/update the corresponding **deep standalone
page** under `/docs-site/` and register it in `docs.json`. The README is the
above-the-fold pitch; the doc-site is the authoritative, argued, diagrammed
reference. Shipping a feature with a README bump but no doc-site page is an
**incomplete PR**.

Each page follows the deep-doc template (motivation → theory → design **with a
Mermaid diagram** → data model/contract → **ADR-style decision rationale**
cross-linking `/docs/adr/*` → worked example → gotchas). The structural + depth
model is the claude-mem docs
(`github.com/thedotmack/claude-mem/tree/main/docs/public`): a deep **Architecture**
group (one page per subsystem + a decisions narrative) + a conceptual **Best
Practices** group. Every quoted column / env var / command / route must be
accurate to the code (R9).

Check:

- [ ] New/changed capability has a deep `/docs-site/**.mdx` page registered in
      `docs.json` under the right group.
- [ ] Concept/architecture pages carry a Mermaid diagram + an ADR-rationale
      section + a worked example.
- [ ] README change → matching doc-site change in the SAME PR (or an explicit,
      filed follow-up).
- [ ] `docs.json` is valid JSON and every listed page file exists (Mintlify
      errors on a nav entry without a file); `cd docs-site && mint dev` clean when
      the CLI is available.

→ See `.claude/skills/mintlify-doc-authoring/SKILL.md`.

### R46 — Deferred-E2E fast loop: run Playwright LAST, never inside the Copilot rounds

Standing convention from **2026-06-22** (Lorenzo). The expensive gate is
**Playwright E2E** (~18-20 min × matrix); Copilot reviews the **diff**, not
test results — so running E2E on every test/CI/Copilot round is pure waste.
The fix: run the fast unit gates (PHPUnit + Vitest) on every round and **defer
E2E to two phases** — one local pre-PR, one CI at the very end (at least twice;
the CI phase legitimately re-runs on each fix-push while the `run-e2e` label is
on, plus any flake reruns) — while keeping **two hard E2E gates before merge**.
This collapses days of
mostly-waiting into hours with **zero loss of quality or robustness** (both E2E
gates still block the merge).

**The canonical per-PR order (supersedes the "tests run everything every round"
assumption in R36/R40/R22 — the review *loops* of R36/R40 still apply, only the
test-execution ordering changes):**

1. **Implement** the task.
2. **Local unit gate (FAST only).** Run PHPUnit + Vitest (`vendor/bin/phpunit`
   + `npm test` + `npm run test:legacy`). **NO Playwright.** Fix until green.
3. **Local copilot-cli critic loop (R40)** until `0 must-fix`. Between rounds
   re-run **only** php+vite — **never** Playwright.
4. **Local Playwright E2E** (`npm run e2e`) — run it now that php+vite is green
   and copilot-cli is clean. Fix until green. **No Copilot for spec-only fixes**
   — but if an E2E fix touches non-trivial **app** code (not just `*.spec.ts`),
   re-run the local copilot-cli loop (R40) on that delta before moving on.
5. **Open the PR.** CI runs **unit-only** (PHPUnit + Vitest); the `playwright`
   job is gated OFF because the PR has no `run-e2e` label. Fix until php+vite CI
   green. *(md-only PRs: see the exception below.)*
6. **Cloud Copilot review loop (R36)** until `0 outstanding must-fix`. CI during
   this phase still runs **php+vite only** (no label yet) — each round returns
   in ~3 min, not ~25.
7. **Final E2E gate.** ONLY when cloud Copilot has nothing left:
   `gh pr edit <N> --repo lopadova/AskMyDocs --add-label run-e2e`. The `labeled`
   event re-fires CI with the label present → the `playwright` job runs. Fix
   until E2E green. **Do NOT engage Copilot for E2E-only fixes.**
8. **Merge** when BOTH gates hold (conjunctive): `0` outstanding Copilot must-fix
   **AND** all CI green — including the labelled Playwright run.

**md-only exception (Lorenzo, 2026-06-22):** when a change touches **only `.md`
files**, do NOT engage Copilot at all (skip both the local copilot-cli loop AND
the cloud Copilot review). This narrows the R36/R40 "applies to docs-only PRs"
clause: prose-only diffs don't need a code reviewer. (`.mdx` doc-site pages are
*not* covered by this exception — they ship with feature code under R45 and go
through the normal flow.)

**CI mechanism.** `.github/workflows/tests.yml`: the `playwright` job carries
`if: (github.event_name == 'push' && github.ref == 'refs/heads/main') ||
(github.event_name == 'pull_request' && contains(github.event.pull_request.labels.*.name, 'run-e2e'))`
(the PR-field read is guarded behind the `pull_request` event so push runs never
dereference PR-only fields), and the
`pull_request` trigger lists `types: [opened, synchronize, reopened, labeled]`
so adding the label re-triggers the run. `main` pushes always run E2E
(post-merge safety net). The `run-e2e` label must exist in the repo
(`gh label create run-e2e`).

**Residual risk (accepted):** a late Copilot fix made with no E2E running could
break an E2E test, surfaced only at step 7 — cost is one extra E2E cycle, and
the step-4 local E2E run already de-risks it. If a step-7 E2E fix touches
non-trivial **app** code (not just `*.spec.ts`), re-run the local copilot-cli
loop (R40) on that delta before merge.

**Anti-patterns:**
- ❌ Running Playwright (local or CI) inside the Copilot review rounds.
- ❌ Adding `run-e2e` before the cloud Copilot loop is at `0 must-fix`.
- ❌ Engaging Copilot on an E2E-only fix (step 4 or step 7).
- ❌ Merging on php+vite-green alone without the labelled E2E run passing.
- ❌ Running the Copilot loop on an md-only PR.

→ See `.claude/skills/copilot-pr-review-loop/SKILL.md`.

---

## 8. Testing & CI

- `vendor/bin/phpunit` — full suite; SQLite in-memory, migrations live in
  `tests/database/migrations/`.
- `npm test` — Vitest against pure modules (`resources/js/*.mjs`).
- CI: `.github/workflows/tests.yml` runs both suites on push to `main` and on
  PRs.
- Mockery-friendly: `AiManager` is intentionally not `final`.
- `Storage::fake('kb')` is the standard way to exercise the ingestion
  pipeline without a real disk.
- When a PR changes the RAG hot path, add a feature test in
  `tests/Feature/Kb/` or `tests/Feature/Api/` — do not settle for a unit test.

---

## 9. Extension points

- **New AI provider:** implement `AiProviderInterface`, add a `match` case in
  `AiManager::resolve()`, add a `providers.<name>` block in `config/ai.php`
  (SDK shape — `driver`/`key`/`url`/`models` — if the provider has a native
  `laravel/ai` driver; use the `Concerns\SdkChat` trait), mirror the
  `AnthropicProviderTest` (fully-SDK) or `OpenAiProviderTest` (hybrid) for
  coverage, update `.env.example` and the README compatibility matrix. If the
  provider is metered by the SDK lifecycle hook, leave it OUT of
  `AiCallMeter::SDK_METERED_PROVIDERS`-bridging unless it serves a raw-Http
  tool turn (see ADR 0015 + the `AiManager` metering gate).
- **New chat-log driver:** implement `ChatLogDriverInterface`, register in
  `ChatLogManager::resolveDriver()`, add config in `config/chat-log.php`.
- **New MCP tool:** extend `Laravel\Mcp\Server\Tool`, register on
  `KnowledgeBaseServer::$tools`.

---

## 10. How Claude should work in this repo

- Prefer editing existing files over creating new ones. The repo already has a
  single helper for path normalization (`KbPath`), a single deletion service
  (`DocumentDeleter`), a single ingestion path (`DocumentIngestor`). Plug
  into those instead of cloning logic.
- Follow **every R-rule above (R1–R32 + R36–R46 are the populated set; R33–R35 are intentionally unallocated)** before opening a PR —
  R1..R21 exist because Copilot caught them the first time. R14..R21
  were distilled at PR16 from ~110 live Copilot findings across
  PRs #16..#31; see `docs/enhancement-plan/COPILOT-FINDINGS.md` for the
  frequency matrix and `.claude/agents/copilot-review-anticipator.md`
  for the pre-push review sub-agent. R22 is the operational protocol
  for investigating CI failures (PR #33 lesson).
- Keep the README, `.env.example`, and `config/*.php` in sync whenever a knob
  changes.
- Commits go on the designated feature branch; never force-push `main`.
- Tests first for anything touching retrieval, ingestion, or deletion.
