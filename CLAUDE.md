# CLAUDE.md — AskMyDocs

Project brief and working rules for Claude Code. Mirrored for GitHub Copilot in
`.github/copilot-instructions.md`. Skills that codify the recurring review
findings live in `.claude/skills/`.

---

## 1. What this project is

AskMyDocs is an **enterprise-grade RAG** system built on **Laravel 11 +
PostgreSQL/pgvector**. Users ingest markdown, ask questions via a chat UI (or a
stateless JSON API), and get grounded answers with citations.

- **PHP** `^8.2`, **Laravel** `^11.0`, **Sanctum** `^4.0`.
- **PostgreSQL ≥ 15** with the `pgvector` extension (FTS GIN index shipped).
- Tests: PHPUnit 11 + Orchestra Testbench + Vitest. SQLite is used in tests —
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
| Ingestion | `app/Services/Kb/DocumentIngestor.php`, `MarkdownChunker.php`, `EmbeddingCacheService.php` |
| Deletion | `app/Services/Kb/DocumentDeleter.php` |
| Queued pipeline | `app/Jobs/IngestDocumentJob.php` |
| Shared helpers | `app/Support/KbPath.php` |
| HTTP entrypoints | `app/Http/Controllers/Api/*.php` |
| Artisan | `app/Console/Commands/*.php` |
| Chat logging | `app/Services/ChatLog/*` + `app/Models/ChatLog.php` |
| MCP | `app/Mcp/Servers/KnowledgeBaseServer.php`, `app/Mcp/Tools/*` |
| Scheduler | `bootstrap/app.php` |
| GitHub Action | `.github/actions/ingest-to-askmydocs/action.yml` |

---

## 4. Key schemas (condensed)

### `knowledge_documents`
`id`, `project_key`, `source_type`, `title`, `source_path`, `mime_type`,
`language`, `access_scope`, `status`, `document_hash`, `version_hash` (both
SHA-256), `metadata` JSON, `source_updated_at`, `indexed_at`, `created_at`,
`updated_at`, `deleted_at` (soft delete).
**Uniqueness:** `(project_key, source_path, version_hash)` — the idempotency
anchor.

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

---

## 5. Flows you touch most

### Chat turn
1. `KbChatController` validates → embeds query → `KbSearchService` hybrid
   search (3× over-retrieval → reranker fusion `0.6·vec + 0.3·kw + 0.1·head`).
2. Prompt composed from `resources/views/prompts/kb_rag.blade.php`.
3. `AiManager::chat()` → provider (no SDK, raw `Http::` calls).
4. `ChatLogManager::log()` in try/catch — **never** propagate logging failures.

### Ingestion
Idempotency is not optional: `DocumentIngestor` hashes the markdown and upserts
on `(project_key, source_path, version_hash)`. Re-pushing identical bytes is a
no-op; a new version archives the previous one so stale chunks never surface
(see PR #3).

### Deletion
`KB_SOFT_DELETE_ENABLED=true` (default) → `SoftDeletes` trait hides the row
from every read path. `kb:prune-deleted` (03:30) hard-deletes soft rows older
than `KB_SOFT_DELETE_RETENTION_DAYS` (default 30) and wipes the file on disk.

### Scheduler (bootstrap/app.php)

| Time  | Command                    |
| ----- | -------------------------- |
| 03:10 | `kb:prune-embedding-cache` |
| 03:20 | `chat-log:prune`           |
| 03:30 | `kb:prune-deleted`         |

All commands: `onOneServer()->withoutOverlapping()` and accept a `--days=N`
override. `0` disables the corresponding rotation.

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

---

## 7. Recurring review findings — rules to follow

These are distilled from Copilot reviews on PR #4, #5, #6. Each has a
dedicated skill in `.claude/skills/` with examples and counter-examples.

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
- Follow the **eight rules above (R1–R8)** before opening a PR — they exist
  because Copilot caught them the first time.
- Keep the README, `.env.example`, and `config/*.php` in sync whenever a knob
  changes.
- Commits go on the designated feature branch; never force-push `main`.
- Tests first for anything touching retrieval, ingestion, or deletion.
