# Copilot instructions — AskMyDocs

Mirror of `CLAUDE.md` (root) with the same rules. Whichever assistant edits
this repo, the rules are identical. Skills with detailed examples live under
`.claude/skills/`.

---

## 1. Project at a glance

AskMyDocs is an **enterprise RAG system** on **Laravel 11 + PostgreSQL +
pgvector**. Markdown in, grounded answers with citations out. Optional chat
history, feedback/few-shot, hybrid (semantic + FTS) search, MCP server, and a
GitHub-Action-based cross-repo ingestion pipeline.

- PHP `^8.2`, Laravel `^11.0`, Sanctum `^4.0`.
- PostgreSQL ≥ 15 + `pgvector`. FTS GIN index migration ships pgsql-only.
- No AI SDK — every provider is reached via `Illuminate\Support\Facades\Http`
  (keeps auth/retries/timeouts under our control and makes `Http::fake()`
  trivial).
- Tests: PHPUnit 11 + Orchestra Testbench (SQLite) + Vitest for JS.

---

## 2. Core flows

**Chat** — `KbChatController` → `KbSearchService` (pgvector + optional FTS +
`Reranker` fusion `0.6·vec + 0.3·kw + 0.1·head`) → prompt from
`resources/views/prompts/kb_rag.blade.php` → `AiManager::chat()` →
`ChatLogManager::log()` (try/catch, never propagates).

**Ingest** — two entrypoints converge on one execution path:

- `php artisan kb:ingest-folder` walks the KB disk, dispatches one job per
  file.
- `POST /api/kb/ingest` (Sanctum, ≤ 100 docs/call) writes to the KB disk,
  dispatches one job per doc.
- Both → `IngestDocumentJob` (`$tries = 3`, backoff `[10,30,60]`) →
  `DocumentIngestor::ingestMarkdown()` (SHA-256 upsert on
  `(project_key, source_path, version_hash)` — idempotent by construction).

**Delete** — `kb:delete` / `DELETE /api/kb/documents` /
`kb:ingest-folder --prune-orphans` / scheduled `kb:prune-deleted` all fan in
to `DocumentDeleter`. Default is soft delete (`KB_SOFT_DELETE_ENABLED=true`,
retention `KB_SOFT_DELETE_RETENTION_DAYS=30`).

**Scheduler** (`bootstrap/app.php`):

| 03:10 | `kb:prune-embedding-cache` |
| 03:20 | `chat-log:prune` |
| 03:30 | `kb:prune-deleted` |

All with `onOneServer()->withoutOverlapping()`. `--days=N` flag overrides the
env retention for ad-hoc runs; `0` disables.

---

## 3. Key components

| Area | Path |
|---|---|
| AI abstraction | `app/Ai/AiManager.php`, `app/Ai/Providers/*.php` (OpenAI, Anthropic, Gemini, OpenRouter, Regolo) |
| DTOs | `app/Ai/AiResponse.php`, `app/Ai/EmbeddingsResponse.php` |
| RAG retrieval | `app/Services/Kb/KbSearchService.php`, `Reranker.php` |
| Ingestion | `app/Services/Kb/DocumentIngestor.php`, `MarkdownChunker.php`, `EmbeddingCacheService.php` |
| Deletion | `app/Services/Kb/DocumentDeleter.php` |
| Queued pipeline | `app/Jobs/IngestDocumentJob.php` |
| Shared helpers | `app/Support/KbPath.php` |
| Controllers | `app/Http/Controllers/Api/*.php` |
| Artisan | `app/Console/Commands/*.php` |
| Chat logging | `app/Services/ChatLog/*` |
| MCP | `app/Mcp/Servers/KnowledgeBaseServer.php`, `app/Mcp/Tools/*` |
| GitHub Action | `.github/actions/ingest-to-askmydocs/action.yml` |

---

## 4. Schemas to know

- **`knowledge_documents`** — `project_key`, `source_path`, `title`,
  `version_hash` (SHA-256), `metadata` JSON, `indexed_at`, `deleted_at`
  (SoftDeletes). UNIQUE `(project_key, source_path, version_hash)`.
- **`knowledge_chunks`** — `knowledge_document_id` FK ON DELETE CASCADE,
  `chunk_index`, `chunk_text`, `heading_path`, `embedding vector(N)`. GIN
  index on `to_tsvector(<lang>, chunk_text)` (pgsql only).
- **`embedding_cache`** — `text_hash` UNIQUE (SHA-256), `provider`, `model`,
  `embedding vector(N)`, `last_used_at` (LRU prune).
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

---

## 6. Review rules (R1–R8) — read this before reviewing or coding

These are distilled from actual Copilot comments on PRs #4, #5, #6. The
skills in `.claude/skills/<name>/SKILL.md` carry worked examples.

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
that can exceed a few hundred rows. Push filters into SQL. Chunk large
binding lists with `array_chunk($list, 1000)` before `whereNotIn()`.

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
- [ ] Tests: feature test added when the RAG hot path changed.
