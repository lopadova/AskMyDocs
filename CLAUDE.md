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
mutation audit-trails into `kb_canonical_audit` or `admin_command_audits`,
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
- No AI SDKs: every provider is called via `Illuminate\Support\Facades\Http`.

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
| MCP | `app/Mcp/Servers/KnowledgeBaseServer.php`, `app/Mcp/Tools/*` (10 tools: 5 retrieval + 5 canonical/promote) |
| Admin RBAC + auth | `app/Http/Controllers/Api/Admin/*.php`, `app/Services/Admin/*.php`, `app/Http/Requests/Admin/*.php`, `app/Http/Resources/Admin/*.php` |
| Admin metrics + health | `app/Services/Admin/AdminMetricsService.php`, `HealthCheckService.php`, `app/Http/Controllers/Api/Admin/DashboardMetricsController.php` |
| Admin KB surface (tree + detail + editor + graph + PDF) | `app/Services/Admin/KbTreeService.php`, `app/Http/Controllers/Api/Admin/KbTreeController.php`, `KbDocumentController.php`, `app/Services/Admin/Pdf/PdfRenderer*.php` |
| Admin log viewer (H1) | `app/Services/Admin/LogTailService.php`, `app/Http/Controllers/Api/Admin/LogViewerController.php` |
| Admin command runner (H2) | `app/Services/Admin/CommandRunnerService.php`, `app/Http/Controllers/Api/Admin/MaintenanceCommandController.php`, `app/Models/AdminCommandAudit.php`, `AdminCommandNonce.php`, `config/admin.php` (`allowed_commands`) |
| AI insights (Phase I) | `app/Services/Admin/AiInsightsService.php`, `app/Http/Controllers/Api/Admin/AdminInsightsController.php`, `app/Console/Commands/InsightsComputeCommand.php`, `app/Models/AdminInsightsSnapshot.php` |
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
`id`, `text_hash` (SHA-256, UNIQUE), `provider`, `model`,
`embedding vector(N)`, `created_at`, `last_used_at` (LRU pruning).

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
**Composite FK (tenant-scoped)**: `(project_key, from_node_uid)` →
`kb_nodes.(project_key, node_uid)` with ON DELETE CASCADE, same for
`to_node_uid`. Cross-tenant edges are **structurally impossible**.

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
   hybrid search (3× over-retrieval → reranker fusion `0.6·vec + 0.3·kw + 0.1·head`
   + canonical boost + status penalty).
2. `GraphExpander` walks 1 hop of `kb_edges` from canonical seed docs
   (config-gated: `KB_GRAPH_EXPANSION_ENABLED=true` default, no-op when no
   canonical docs exist).
3. `RejectedApproachInjector` vector-correlates the query against
   `rejected-approach` canonical docs and returns up to `KB_REJECTED_INJECTION_MAX_DOCS`
   above `KB_REJECTED_MIN_SIMILARITY`.
4. `SearchResult { primary, expanded, rejected, meta }` → prompt composed
   from `resources/views/prompts/kb_rag.blade.php` (typed blocks: `⚠ REJECTED
   APPROACHES` + `📎 RELATED CONTEXT` + primary `## Context`).
5. `AiManager::chat()` → provider (no SDK, raw `Http::` calls).
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

- **No AI SDKs.** Provider transport is `Http::`. This is intentional: full
  control over auth, retries, timeouts, response parsing, and testability via
  `Http::fake()`.
- **Chat and embeddings providers are separate** (`AI_PROVIDER` vs
  `AI_EMBEDDINGS_PROVIDER`). Anthropic and OpenRouter don't do embeddings.
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
  `(project_key, slug)` and `(project_key, doc_id)`; composite FKs on
  `kb_edges` make cross-tenant edges structurally impossible. Never
  assume global slug uniqueness in new code.

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
3. Tenant-scoped composite FKs on `kb_edges` — never add an edge without
   `project_key`. Cross-tenant edges are structurally impossible;
   FK violations are bugs to fix, not silence.
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
Tenant-aware tables: knowledge_documents, knowledge_chunks,
embedding_cache, chat_logs, conversations, messages, kb_nodes, kb_edges,
kb_canonical_audit, project_memberships, kb_tags,
knowledge_document_tags, knowledge_document_acl, admin_command_audit,
admin_command_nonces, admin_insights_snapshots, chat_filter_presets.
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

**For new repos** (`padosoft/agent-llm`, `padosoft/laravel-flow`, etc.,
created fresh for v4): PRs target `main` directly — no stable code to
preserve; main and develop converge from day 1.

Lorenzo decided this on 2026-04-28 during W1.B PR #78. Existing PR #78
re-targeted from main to feature/v4.0.
→ See `.claude/skills/branching-strategy-feature-vx/SKILL.md`.

### R36 — Copilot review + CI green loop is MANDATORY after EVERY push
After opening or updating a PR, the agent MUST loop on (a) Copilot
review comments and (b) CI status until BOTH conditions hold:
**0 outstanding Copilot must-fix comments** AND **0 failing CI checks**.
Stopping after a single push when CI is red, or "reporting status to
user and waiting" when comments remain unaddressed, is a protocol
violation. Each iteration: read `gh pr view <N> --comments` + `gh api
.../pulls/<N>/comments` for inline reviews + `gh pr checks <N>` for CI
+ `gh run view <run-id> --log-failed` for failed jobs; fix all issues;
run local test gate (phpunit + vitest + playwright + architecture);
commit; push; LOOP. Exit only when reviewDecision is APPROVED (or no
must-fix outstanding) and all checks SUCCESS or expected-SKIPPED. Wait
60-180s after each push before re-checking (CI may not have started).
Anti-pattern: "Push, see red, stop, report" — costs the user a wasted
CI cycle and hands them a half-broken state. Lorenzo flagged this
explicitly on PR #78 (2026-04-28). Applies to all repos under
`lopadova/*` and `padosoft/*` and to any developer/agent working on
this codebase, current and future.
→ See `.claude/skills/copilot-pr-review-loop/SKILL.md`.

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
  `AiManager::resolve()`, add a `providers.<name>` block in `config/ai.php`,
  mirror the OpenAI test for coverage, update `.env.example` and the README
  compatibility matrix.
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
- Follow the **twenty-two rules above (R1–R22)** before opening a PR —
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
