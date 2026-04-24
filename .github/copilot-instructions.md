# Copilot instructions — AskMyDocs

Mirror of `CLAUDE.md` (root) with the same rules. Whichever assistant edits
this repo, the rules are identical. Skills with detailed examples live under
`.claude/skills/`.

---

## 1. Project at a glance

AskMyDocs is an **enterprise RAG + canonical knowledge compilation** system
on **Laravel 13 + PostgreSQL + pgvector**. Markdown in, grounded answers
with citations out — over a **typed knowledge base** with a lightweight
graph, anti-repetition memory, and a human-gated promotion pipeline.
Optional chat history, feedback/few-shot, hybrid (semantic + FTS) search,
MCP server (10 tools), and a GitHub-Action-based cross-repo ingestion
pipeline. A full React SPA admin shell rides alongside at `/app/*`:
dashboard, users + roles + RBAC, canonical KB explorer with inline
editor and graph viewer, five-tab log viewer, whitelisted Artisan
maintenance runner, and a daily AI insights panel. Every admin page is
Spatie-role-gated and every mutation is audit-trailed
(`kb_canonical_audit` for canonical changes, `admin_command_audits` for
commands).

- PHP `^8.3`, Laravel `^13.0`, Sanctum `^4.2`.
- `symfony/yaml ^7.4|^8.0` for canonical YAML frontmatter parsing.
  Section-aware markdown chunking is custom (line-based fence-aware
  FSM in `MarkdownChunker`) — no external markdown parser library.
- `laravel/mcp ^0.7` as a suggest (required only when exposing the
  `enterprise-kb` MCP server).
- PostgreSQL ≥ 15 + `pgvector`. FTS GIN index migration ships pgsql-only.
- No AI SDK — every provider is reached via `Illuminate\Support\Facades\Http`
  (keeps auth/retries/timeouts under our control and makes `Http::fake()`
  trivial).
- Tests: PHPUnit 12 + Orchestra Testbench 11 (SQLite) + Vitest for JS.

---

## 2. Core flows

**Chat** — `KbChatController` → `KbSearchService::searchWithContext()`
(pgvector + optional FTS + `Reranker` fusion `0.6·vec + 0.3·kw + 0.1·head`
+ canonical boost + status penalty) → `GraphExpander` (1-hop walk of
`kb_edges` from canonical seeds, config-gated) → `RejectedApproachInjector`
(cosine-correlates query vs `rejected-approach` canonical docs) →
`SearchResult{ primary, expanded, rejected, meta }` → prompt from
`resources/views/prompts/kb_rag.blade.php` (typed blocks: `⚠ REJECTED
APPROACHES` + `📎 RELATED CONTEXT` + primary `## Context`) →
`AiManager::chat()` → `ChatLogManager::log()` (try/catch, never
propagates). Graph expansion + rejected injection no-op when no canonical
docs exist (zero regression for non-canonical consumers).

**Ingest** — two entrypoints converge on one execution path:

- `php artisan kb:ingest-folder` walks the KB disk, dispatches one job per
  file.
- `POST /api/kb/ingest` (Sanctum, ≤ 100 docs/call) writes to the KB disk,
  dispatches one job per doc.
- Both → `IngestDocumentJob` (`$tries = 3`, backoff `[10,30,60]`) →
  `DocumentIngestor::ingestMarkdown()` (SHA-256 upsert on
  `(project_key, source_path, version_hash)` — idempotent by construction).

**Canonical branch** — when the markdown has a valid YAML frontmatter,
`DocumentIngestor` populates the 8 canonical columns (`doc_id`, `slug`,
`canonical_type`, `canonical_status`, `is_canonical`, `retrieval_priority`,
`source_of_truth`, `frontmatter_json` with `_derived` slugs). Prior
canonical identifiers are vacated before the new version is inserted to
avoid violating the per-project composite uniques. After commit,
`CanonicalIndexerJob` populates `kb_nodes` + `kb_edges` from the
frontmatter `_derived` slug lists and every chunk's `metadata.wikilinks`.
Invalid frontmatter degrades gracefully to non-canonical (R4).

**Promotion pipeline** (ADR 0003, human-gated):
- `POST /api/kb/promotion/suggest` → LLM extracts candidates. Writes nothing.
- `POST /api/kb/promotion/candidates` → validates a draft. Writes nothing.
- `POST /api/kb/promotion/promote` → writes markdown + dispatches ingest.
  Returns 202.

Operator CLI equivalent: `kb:promote {path} --project=…`. Claude skills
stop at `suggest` / `candidates`. Only humans (git commit → GH action →
ingest) and operators (`kb:promote`) commit canonical storage.

**Delete** — `kb:delete` / `DELETE /api/kb/documents` /
`kb:ingest-folder --prune-orphans` / scheduled `kb:prune-deleted` all fan in
to `DocumentDeleter`. Default is soft delete (`KB_SOFT_DELETE_ENABLED=true`,
retention `KB_SOFT_DELETE_RETENTION_DAYS=30`). Hard delete **cascades the
graph**: `kb_nodes` owned by the doc are removed (`source_doc_id` match,
fallback `node_uid = slug`); the composite FK on `kb_edges` cascades both
directions. Every hard delete writes a `kb_canonical_audit` row.

**Scheduler** (`bootstrap/app.php`):

| Time  | Command                    |
| ----- | -------------------------- |
| 03:10 | `kb:prune-embedding-cache` |
| 03:20 | `chat-log:prune`           |
| 03:30 | `kb:prune-deleted`         |
| 03:40 | `kb:rebuild-graph`         |

All with `onOneServer()->withoutOverlapping()`. `--days=N` flag overrides the
env retention for ad-hoc runs; `0` disables. `kb:rebuild-graph` is a no-op
when no canonical docs exist.

---

## 3. Key components

| Area | Path |
|---|---|
| AI abstraction | `app/Ai/AiManager.php`, `app/Ai/Providers/*.php` (OpenAI, Anthropic, Gemini, OpenRouter, Regolo) |
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
| Controllers | `app/Http/Controllers/Api/*.php` |
| Artisan | `app/Console/Commands/*.php` |
| Chat logging | `app/Services/ChatLog/*` |
| MCP | `app/Mcp/Servers/KnowledgeBaseServer.php`, `app/Mcp/Tools/*` (10 tools: 5 retrieval + 5 canonical/promote) |
| Admin RBAC + auth | `app/Http/Controllers/Api/Admin/*.php`, `app/Services/Admin/*.php`, `app/Http/Requests/Admin/*.php`, `app/Http/Resources/Admin/*.php` |
| Admin metrics + health | `app/Services/Admin/AdminMetricsService.php`, `HealthCheckService.php`, `app/Http/Controllers/Api/Admin/DashboardMetricsController.php` |
| Admin KB surface | `app/Services/Admin/KbTreeService.php`, `app/Http/Controllers/Api/Admin/KbTreeController.php`, `KbDocumentController.php`, `app/Services/Admin/Pdf/PdfRenderer*.php` |
| Admin log viewer | `app/Services/Admin/LogTailService.php`, `app/Http/Controllers/Api/Admin/LogViewerController.php` |
| Admin command runner | `app/Services/Admin/CommandRunnerService.php`, `app/Http/Controllers/Api/Admin/MaintenanceCommandController.php`, `app/Models/AdminCommandAudit.php`, `AdminCommandNonce.php`, `config/admin.php` |
| AI insights | `app/Services/Admin/AiInsightsService.php`, `app/Http/Controllers/Api/Admin/AdminInsightsController.php`, `app/Console/Commands/InsightsComputeCommand.php`, `app/Models/AdminInsightsSnapshot.php` |
| SPA entrypoint | `app/Http/Controllers/SpaController.php`, `resources/views/app.blade.php`, `frontend/src/main.tsx`, `frontend/src/routes/index.tsx` |
| GitHub Action | `.github/actions/ingest-to-askmydocs/action.yml` (v2 — canonical-folder aware) |
| Claude skill templates | `.claude/skills/kb-canonical/*` (CONSUMER-SIDE), `.claude/skills/canonical-awareness/` (R10, in-repo) |
| ADRs | `docs/adr/0001..0003.md` |

---

## 4. Schemas to know

- **`knowledge_documents`** — `project_key`, `source_type`, `title`,
  `source_path`, `mime_type`, `language`, `access_scope`, `status`,
  `document_hash`, `version_hash` (both SHA-256), `metadata` JSON,
  `source_updated_at`, `indexed_at`, `deleted_at` (SoftDeletes).
  **Canonical columns**: `doc_id`, `slug`, `canonical_type`,
  `canonical_status`, `is_canonical` (default false),
  `retrieval_priority` (0-100, default 50), `source_of_truth` (default
  true), `frontmatter_json` (parsed YAML + `_derived` pre-validated
  slug lists). UNIQUE `(project_key, source_path, version_hash)` +
  composite uniques `(project_key, doc_id)` and `(project_key, slug)` —
  canonical identifiers are tenant-scoped.
- **`knowledge_chunks`** — `knowledge_document_id` FK ON DELETE CASCADE,
  `project_key`, `chunk_order`, `chunk_hash` (SHA-256), `heading_path`,
  `chunk_text`, `metadata` JSON (includes `wikilinks` array for canonical
  chunks), `embedding vector(N)`. UNIQUE `(knowledge_document_id,
  chunk_hash)`. GIN index on `to_tsvector(<lang>, chunk_text)` (pgsql
  only).
- **`kb_nodes`** — canonical graph node. `node_uid`, `node_type` (9 values),
  `label`, `project_key`, `source_doc_id`, `payload_json` (includes
  `dangling: true` for not-yet-canonicalized targets). UNIQUE
  `(project_key, node_uid)`.
- **`kb_edges`** — typed relation between nodes. `edge_uid`,
  `from_node_uid`, `to_node_uid`, `edge_type` (10 values), `project_key`,
  `source_doc_id`, `weight` (decimal 8,4), `provenance` (wikilink |
  frontmatter_* | inferred). UNIQUE `(project_key, edge_uid)`. **Composite
  FKs** tenant-scoped: `(project_key, from/to_node_uid)` →
  `kb_nodes.(project_key, node_uid)` with ON DELETE CASCADE. Cross-tenant
  edges are **structurally impossible**.
- **`kb_canonical_audit`** — immutable forensic trail. `project_key`,
  `doc_id?`, `slug?`, `event_type` (promoted | updated | deprecated |
  superseded | rejected_injection_used | graph_rebuild), `actor`,
  `before_json`, `after_json`, `metadata_json`, `created_at`. No
  `updated_at`; no FK to `knowledge_documents` so rows survive hard deletes.
- **`embedding_cache`** — `text_hash` UNIQUE (SHA-256), `provider`, `model`,
  `embedding vector(N)`, `last_used_at` (LRU prune). Intentionally NOT
  tenant-scoped — same text across projects reuses the embedding.
- **`chat_logs`** — structured analytics; never the app log.
- **`conversations` / `messages`** — user-scoped history; `messages.metadata`
  stores citations + provider/model telemetry; `messages.rating` feeds
  `FewShotService`.

---

## 5. Non-obvious decisions — do not unwind without asking

- **No AI SDKs**, only `Http::`.
- **Chat and embeddings providers are independent** (`AI_PROVIDER` vs
  `AI_EMBEDDINGS_PROVIDER`). Anthropic and OpenRouter have no embeddings
  endpoint.
- **Embedding dimensions are part of the contract.** Changing the embeddings
  model requires migrating the `vector(N)` column, flushing
  `embedding_cache`, and re-indexing.
- **Soft delete is default.** Read paths inherit the Eloquent global scope;
  write/admin paths opt in via `withTrashed()` / `onlyTrashed()`.
- **Idempotency is guaranteed by the unique tuple** — never bypass the hash
  with `firstOrCreate`.
- **Logging never breaks the user request.** Wrap every chat-log driver in
  try/catch; errors go to the app log, not the client.
- **Two ingestion entrypoints, one execution path.** Never add a third path
  that skips `IngestDocumentJob` or `DocumentIngestor::ingestMarkdown()`.
- **Canonical markdown is source-of-truth; DB is a projection.** The
  canonical `kb/` folders in consumer repos are authoritative; `kb_nodes` +
  `kb_edges` are rebuildable from Git via `kb:rebuild-graph` + re-ingest.
  Never design features that require DB-only state unreconstructible from
  markdown. Only `kb_canonical_audit` is an exception (immutable forensic
  trail).
- **Promotion is always human-gated.** Claude skills and
  `suggest` / `candidates` produce drafts; only humans (via git → GH
  action) and operators (`kb:promote`) commit canonical storage (ADR 0003).
- **Rejected-approach injection is by design.** The prompt surfaces
  rejected options under `⚠` so the LLM stops re-proposing them. Disable
  via `KB_REJECTED_INJECTION_ENABLED=false` only when prompt-token budget
  is critical.
- **Graph expansion + rejected injection degrade to no-op** when a tenant
  has zero canonical docs. Code MUST NOT assume either feature is
  populated.
- **Canonical slug + doc_id are tenant-scoped.** Two projects can share
  `dec-cache-v2`. Composite FKs on `kb_edges` make cross-tenant edges
  impossible. Never assume global uniqueness in new code.

---

## 6. Review rules (R1–R10) — read this before reviewing or coding

These are distilled from actual Copilot comments on PRs #4, #5, #6 and the
canonical compilation series PRs #9–#14. The skills in
`.claude/skills/<name>/SKILL.md` carry worked examples.

### R1 — Use `App\Support\KbPath::normalize()` for every KB source path
Never re-implement path trimming. `KbPath::normalize()` collapses `//`,
converts `\\`, rejects `.` / `..` (traversal guard), throws on empty input.
Ingest and delete **must** produce identical paths.

### R2 — Soft-delete awareness
Default scope (hide trashed) is correct for readers. Any branch that must
act on already-trashed rows — e.g. `--force` hard delete, retention purge,
diagnostics — has to `withTrashed()` / `onlyTrashed()`. The read path
(search, MCP, chat) stays default-scoped.

### R3 — Memory-safe bulk ops
`chunkById(100)` / `cursor()` instead of `->get()` + `foreach` for any sweep
that can exceed a few hundred rows. Push filters into SQL. When the filter
list itself is large, split it with `array_chunk($list, 1000)` and apply one
`whereNotIn()` per chunk so each generated `IN` list stays ≤ 1000 values.
The aim is bounded per-clause lists for portability and readable plans, not
bounded total bindings.

### R4 — Never ignore a return value on a side-effecting call
`Storage::put/delete/copy`, `mkdir`, `file_put_contents`, `copy`, `rename`,
HTTP responses — check or wrap. `202 Accepted` after a failed `put()` is the
cardinal sin (PR #5).

### R5 — `action.yml` hygiene
- Serialise file bodies with `jq --rawfile content "$file"`, never `--arg`
  (ARG_MAX + newline stripping).
- Keep the full-sync `find` and the diff `git diff` extension sets in
  lock-step (`.md` + `.markdown`).
- Ingest set: `--diff-filter=AMR`. Delete set: handle both `D` and the `R…`
  old path. A rename must produce one ingest and one delete.

### R6 — Docs/config coupling
When you introduce or rename an env var, update `.env.example`,
`config/*.php`, **and** the README quick-start snippet in the same PR.
Copilot flagged `KB_DISK_DRIVER=s3` drift on PR #4 — the kind of debt that
ages badly.

### R7 — No `@`-silenced errors, no `0777`
`@mkdir($dir, 0777, true)` is out. Use `0755` and propagate errors.

### R8 — Honour `KB_PATH_PREFIX`
`kb:ingest-folder`'s `{path}` argument is resolved **relative to**
`KB_PATH_PREFIX` because the queued job re-applies the prefix on read. New
CLIs/APIs that walk the disk must honour the prefix or explicitly reject
absolute paths. Whichever you pick, document it in the help text and README.

### R9 — Docs must match code
Column names, env vars, config keys, command flags, and routes quoted in
this file (or in `CLAUDE.md`, `README.md`, any `SKILL.md`) must be copied
from the real source — the migration, the config, the routes file, the
`php artisan <cmd> --help` output. Stale docs are worse than missing docs:
they survive grep and propagate into queries and tests. Copilot caught
`chunk_index` vs `chunk_order` drift on PR #7 — verify before quoting.

### R10 — Canonical awareness
Every query, scope, retrieval step, promotion path, and delete path that
touches `knowledge_documents` or `kb_nodes` / `kb_edges` / `kb_canonical_audit`
MUST handle BOTH states (canonical / non-canonical) deliberately.

Checklist:
1. Use dedicated Eloquent scopes (`canonical()`, `accepted()`, `byType()`,
   `bySlug()`) instead of raw WHERE on canonical columns.
2. `scopeAccepted()` implies `canonical()` — don't re-derive status filters.
3. Tenant-scoped composite FKs on `kb_edges` — cross-tenant edges are
   impossible; FK errors are bugs, not noise.
4. Slug + doc_id are unique PER PROJECT, not globally. Two projects can
   share `dec-cache-v2`.
5. Hard delete cascades via `DocumentDeleter::forceDelete()`; soft delete
   leaves the graph intact.
6. Canonical re-ingest must vacate prior identifiers first (handled by
   `DocumentIngestor::vacateCanonicalIdentifiersOnPreviousVersions()`).
7. `Reranker` applies canonical boost + status penalty; new retrieval
   services honour these knobs or add an ADR.
8. Graph expansion + rejected injection are config-gated.
9. Every canonical mutation writes to `kb_canonical_audit`.
10. Never hard-code global slug uniqueness.

Distilled from the canonical compilation series (PRs #9–#14). See
`.claude/skills/canonical-awareness/`.

### R11 — Frontend testid / ARIA / observable state contract
Every React component under `frontend/src/` exposes stable
`data-testid` values on actionable elements, proper ARIA (`role`,
`aria-label`, `aria-live`), and observable async states
(`data-state="idle|loading|ready|error|empty"`, `aria-busy`).
Validation errors render next to their input with
`data-testid="<field>-error"`. API failures surface in the DOM —
no swallowed `useMutation` failures. See
`.claude/skills/frontend-testid-conventions/`.

### R12 — User-visible UI changes ship Playwright E2E coverage
From PR5 onward, every PR touching `frontend/src/` or a route that
renders into the SPA must include `frontend/e2e/<feature>.spec.ts`
with at least one happy path and one failure path. Selectors use
`getByTestId` or `getByRole` + accessible name. Waits use
`data-state` / `toHaveAttribute`, never `waitForTimeout`. See
`.claude/skills/playwright-e2e/`.

### R13 — E2E scenarios exercise real data; stub only external services
Playwright boots `php artisan serve` with `APP_ENV=testing` via
`playwright.config.ts` webServer block. Tests hit the real DB
(SQLite, reset+seeded via `/testing/reset` + `/testing/seed`).
`page.route(...)` is reserved for external-service boundaries
only — AI providers (OpenRouter, OpenAI, Anthropic, Gemini,
Regolo), email senders, payment rails, remote object storage,
OCR APIs. Intercepting `/api/admin/*`, `/api/kb/*`,
`/api/auth/*`, `/sanctum/csrf-cookie`, `/conversations`, or any
internal route turns E2E into a unit test in E2E clothing. The
only exception is explicit failure injection on an internal
route, which must carry an `R13: failure injection` marker
comment so the intent is auditable.
`scripts/verify-e2e-real-data.sh` is wired into the CI workflow
and fails the build on any unmarked internal interception. See
`.claude/skills/playwright-e2e/` and
`.claude/skills/playwright-e2e-templates/`.

---

## 7. Testing & CI

- `vendor/bin/phpunit` — SQLite in-memory, migrations under
  `tests/database/migrations/` (swap `vector(N)` for JSON text).
- `npm test` — Vitest against `resources/js/*.mjs`.
- CI: `.github/workflows/tests.yml` on push to `main` and on PRs.
- `Storage::fake('kb')` is the standard pattern for exercising ingestion.
- `AiManager` is deliberately non-`final` so Mockery can swap it in tests.
- Any PR touching retrieval/ingestion/deletion must add a **feature** test
  (not just a unit test).

---

## 8. Style / scope

- Prefer editing existing files. The repo already centralises path
  normalisation (`KbPath`), deletion (`DocumentDeleter`), ingestion
  (`DocumentIngestor`) — plug into those instead of cloning logic.
- Don't ship dead compatibility shims or "just in case" abstractions.
- Keep the README, `.env.example`, and `config/*.php` in sync.
- Commits go on the designated feature branch; never force-push `main`.

---

## 9. Copilot review checklist

Before approving a PR, quickly verify:

- [ ] R1: every new `source_path` / `path` consumer calls
      `KbPath::normalize()` (grep for `trim(` / `str_replace('\\'` / inline
      `preg_replace('#/+#'`).
- [ ] R2: every query on `KnowledgeDocument` that handles `--force` or
      retention uses `withTrashed()` / `onlyTrashed()`.
- [ ] R3: every new sweep uses `chunkById()` / `cursor()` and pushes filters
      into SQL.
- [ ] R4: every `Storage::put/delete`, `mkdir`, `file_put_contents` has its
      return value checked or is inside a method that throws on failure.
- [ ] R5: `action.yml` edits keep `jq --rawfile`, lock-step extensions,
      `AMR` / `D+R` filters.
- [ ] R6: env-var additions touch `.env.example` + `config/*.php` + README
      in the same diff.
- [ ] R7: no `@`-silenced calls, no `0777`.
- [ ] R8: any disk walker is explicit about `KB_PATH_PREFIX` handling.
- [ ] R9: every column / env / flag / route quoted in the diff exists in
      the migration / config / routes / `--help` output it claims to mirror.
- [ ] Tests: feature test added when the RAG hot path changed.
