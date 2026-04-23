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
graph, anti-repetition memory, and a human-gated promotion pipeline.

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
- Follow the **twelve rules above (R1–R12)** before opening a PR — they
  exist because Copilot caught them the first time.
- Keep the README, `.env.example`, and `config/*.php` in sync whenever a knob
  changes.
- Commits go on the designated feature branch; never force-push `main`.
- Tests first for anything touching retrieval, ingestion, or deletion.
