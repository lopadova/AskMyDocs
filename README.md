# AskMyDocs — Enterprise AI Knowledge Base on Laravel

<p align="center">
  <img src="resources/cover-AskMyDocs.png" alt="AskMyDocs" width="100%" />
</p>

<p align="center">
  <a href="#installation"><img src="https://img.shields.io/badge/Laravel-13+-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel"></a>
  <a href="#ai-provider"><img src="https://img.shields.io/badge/Claude-Compatible-cc785c?style=flat-square&logo=anthropic&logoColor=white" alt="Claude"></a>
  <a href="#ai-provider"><img src="https://img.shields.io/badge/OpenAI-Compatible-412991?style=flat-square&logo=openai&logoColor=white" alt="OpenAI"></a>
  <a href="#ai-provider"><img src="https://img.shields.io/badge/Gemini-Compatible-4285F4?style=flat-square&logo=google&logoColor=white" alt="Gemini"></a>
  <a href="#ai-provider"><img src="https://img.shields.io/badge/OpenRouter-Multi--Model-6366f1?style=flat-square" alt="OpenRouter"></a>
  <a href="#ai-provider"><img src="https://img.shields.io/badge/Regolo.ai-EU-10b981?style=flat-square" alt="Regolo.ai"></a>
  <a href="#mcp-server"><img src="https://img.shields.io/badge/MCP-10%20tools-0ea5e9?style=flat-square" alt="MCP Server"></a>
  <a href="#canonical-knowledge-compilation-knowledge-graph--anti-repetition-memory"><img src="https://img.shields.io/badge/Canonical--KB-9%20types-ff7a00?style=flat-square" alt="Canonical KB"></a>
  <a href="#canonical-knowledge-compilation-knowledge-graph--anti-repetition-memory"><img src="https://img.shields.io/badge/Knowledge%20Graph-10%20relations-7c3aed?style=flat-square" alt="Knowledge Graph"></a>
  <a href="#canonical-knowledge-compilation-knowledge-graph--anti-repetition-memory"><img src="https://img.shields.io/badge/Anti--Repetition-%E2%9A%A0%EF%B8%8F%20built--in-dc2626?style=flat-square" alt="Anti-Repetition Memory"></a>
  <a href="#requirements"><img src="https://img.shields.io/badge/PostgreSQL-pgvector-336791?style=flat-square&logo=postgresql&logoColor=white" alt="PostgreSQL + pgvector"></a>
  <a href="#license"><img src="https://img.shields.io/badge/License-MIT-green?style=flat-square" alt="MIT License"></a>
  <a href="#requirements"><img src="https://img.shields.io/badge/PHP-8.3+-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.3+"></a>
</p>

<p align="center">
  <strong>Ask your docs. Get grounded answers. See the sources.</strong>
</p>

---

An enterprise-grade RAG system built on Laravel and PostgreSQL. Ingest your documents, ask questions in natural language, and get AI-powered answers grounded in your actual knowledge base — with full source citations, visual artifacts, and a ChatGPT-like interface.

### Key Features

#### RAG core

| Feature | Description |
|---|---|
| **Multi-Provider AI** | Swap between OpenAI, Anthropic Claude, Google Gemini, OpenRouter, or Regolo.ai with a single `.env` change |
| **Hybrid Search** | Semantic vector search (pgvector) + full-text keyword search fused via Reciprocal Rank Fusion |
| **Smart Reranking** | Over-retrieval + keyword/heading + canonical boost + status penalty to surface the most relevant chunks |
| **Embedding Cache** | DB-backed cache eliminates redundant API calls on re-ingestion and repeated queries |
| **Citations** | Every answer shows exactly which documents and sections were used — verify at the source |
| **Visual Artifacts** | The AI generates charts (recharts), enhanced tables, and action buttons (copy, download) when the data justifies it |
| **Feedback Learning** | Thumbs up/down on answers; positive examples are injected as few-shot context to improve future responses |
| **Chat History** | Full conversation persistence with sidebar, rename, delete, auto-generated titles — ChatGPT-style |
| **Speech-to-Text** | Browser-native microphone input via Web Speech API — zero external services |

#### Canonical Knowledge Compilation (OmegaWiki-inspired)

| Feature | Description |
|---|---|
| **9 Canonical Document Types** | `decision`, `runbook`, `standard`, `incident`, `integration`, `domain-concept`, `module-kb`, `rejected-approach`, `project-index` — each with YAML frontmatter validated by `CanonicalParser` |
| **Knowledge Graph** | `kb_nodes` + `kb_edges` with 10 typed relations (`depends_on`, `uses`, `implements`, `related_to`, `supersedes`, `invalidated_by`, `decision_for`, `documented_by`, `affects`, `owned_by`); 1-hop graph expansion at retrieval time, tenant-scoped composite FKs make cross-tenant edges structurally impossible |
| **Anti-Repetition Memory** | The prompt explicitly surfaces `rejected-approach` docs under a `⚠ REJECTED APPROACHES` block so the LLM stops re-proposing dismissed options — config-gated via `KB_REJECTED_INJECTION_ENABLED` |
| **Promotion Pipeline** | Three-stage human-gated API (`/suggest` → LLM extract candidates / `/candidates` → validate draft / `/promote` → write + ingest); only humans (git push → GH action) and operators (`kb:promote` CLI) commit canonical storage — never the LLM |
| **Audit Trail** | `kb_canonical_audit` is immutable (no `updated_at`, no FK to docs) — survives hard deletes for forensic access |
| **5 Claude Skill Templates** | `.claude/skills/kb-canonical/*` — copy-paste templates for consumer repos to scaffold canonical drafts |

#### Enterprise React SPA + RBAC

| Feature | Description |
|---|---|
| **Single-Page Admin Shell** | React 18 + TypeScript + Vite + TanStack Router/Query + shadcn/ui under `/app/*` — dark-first glassmorphism UI with code-split routes (~400 KB initial gzipped) |
| **RBAC Foundation** | Spatie roles (`admin`, `super-admin`, `editor`, `viewer`) + `project_memberships` (tenant scope with folder/tag allowlist JSON) + `knowledge_document_acl` (row-level ACL); global Eloquent scope filters every query against `knowledge_documents` to the user's permitted projects |
| **Auth: Sanctum stateful SPA + Bearer** | Cookie-based stateful auth for the SPA (`/sanctum/csrf-cookie` + `X-XSRF-TOKEN`) AND personal access tokens for API clients / MCP / GitHub Action — same guard, same RBAC scopes |
| **2FA stub** | `TwoFactorController` skeleton behind `AUTH_2FA_ENABLED=false` for future TOTP rollout |

#### Admin pages (every page under `/app/admin/*`)

| Page | Description |
|---|---|
| **Dashboard** (`/admin`) | KPI strip (docs, chunks, chats, p95 latency, cache hit rate, canonical coverage) + health strip (db, pgvector, queue, kb-disk, embeddings, chat) + 3 code-split recharts cards (chat volume, token burn, rating donut) + top projects + activity feed; 30-second cache layer |
| **Users & Roles** (`/admin/users` + `/admin/roles`) | Filterable users table with soft-delete + restore, 3-tab edit drawer (Details / Roles / Memberships with `scope_allowlist` JSON editor), Spatie-backed role CRUD with grouped permission matrix |
| **KB Explorer** (`/admin/kb`) | Memory-safe `chunkById(100)` tree walker with canonical-aware modes (`canonical \| raw \| all`); right-panel tabs: Preview (markdown + frontmatter pills) / Meta (canonical grid + AI tags) / **Source** (CodeMirror 6 editor with PATCH `/raw` → validate → write → audit → re-ingest) / **Graph** (1-hop tenant-scoped subgraph, SVG radial layout) / **History** (paginated `kb_canonical_audit`) / **PDF export** (Browsershot, A4 print-optimised) |
| **Logs** (`/admin/logs`) | Five deep-linkable tabs (`?tab=chat \| audit \| app \| activity \| failed`) — chat logs with model/project/rating filters, canonical audit trail, reverse-seek `SplFileObject`-powered application log tailer (whitelist regex, 2000-line cap, optional live polling), Spatie activity log, failed-jobs read-only |
| **Maintenance** (`/admin/maintenance`) | Whitelisted Artisan runner via `CommandRunnerService` with **6 independent gates**: (1) whitelist lookup, (2) args_schema validation, (3) signed `confirm_token` + DB-backed single-use nonce, (4) Spatie permission gate (`commands.run` / `commands.destructive`), (5) audit-before-execute (`admin_command_audits`), (6) per-user `throttle:10,1` rate limit. Three-step React wizard: Preview → Confirm (type-in for destructive) → Run → Result |
| **AI Insights** (`/admin/insights`) | Daily `insights:compute` (05:00 UTC) writes one row into `admin_insights_snapshots`; six widget cards: Promotion Suggestions, Orphan Docs, Suggested Tags, Coverage Gaps, Stale Docs, Quality Report — O(1) DB read, zero LLM calls per page load |

#### Operations & quality

| Feature | Description |
|---|---|
| **Chat Logging** | Structured logging (DB, extensible to BigQuery/CloudWatch) of every interaction with token counts, latency, citations, client info |
| **Scheduler Hygiene** | Daily Laravel jobs at 03:10–05:00 UTC: `kb:prune-embedding-cache`, `chat-log:prune`, `kb:prune-deleted`, `kb:rebuild-graph`, `insights:compute` (`onOneServer()` + `withoutOverlapping()`) |
| **Storage-Agnostic Ingestion** | KB documents read through Laravel disks: `local`, S3, R2, GCS, MinIO. Per-project disk override via `KB_PROJECT_DISKS`; raw vs canonical disk separation supported |
| **Bulk Background Ingestion** | `php artisan kb:ingest-folder` walks a disk and dispatches one queued job per markdown file — supports `sync`, `database`, `redis` queues (Horizon-ready) |
| **Remote Ingestion API** | `POST /api/kb/ingest` + reusable GitHub composite action (`v2`, canonical-folder aware) so any consumer repo can push its `docs/` folder to the KB on every commit to `main` |
| **MCP Server** | **10 tools** (5 retrieval + 5 canonical/promotion) that expose the KB to Claude Desktop, Claude Code, and other MCP-compatible agents |
| **22 review rules** | Codified in `CLAUDE.md` + `.github/copilot-instructions.md` + `.claude/skills/<rule>/` — distilled from ~110 live Copilot findings across PRs #4 — #33; the `ci-failure-investigation` skill (R22) codifies the artefact-first protocol for Playwright debug |
| **63-test Playwright E2E suite** | Real Postgres + pgvector in CI, deterministic via `data-state` + `data-testid` contract (R11), happy-path + failure-injection per feature (R12), real data only — `page.route()` reserved for external boundaries (R13) |

---

## Table of Contents

- [Architecture](#architecture)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Environment file](#environment-file)
  - [Database](#database)
  - [AI Provider](#ai-provider)
  - [Storage (Laravel disks)](#storage-laravel-disks)
  - [Chat Logging](#chat-logging)
  - [Knowledge Base](#knowledge-base)
- [Scheduler](#scheduler)
- [Authentication](#authentication)
- [Enterprise admin surface](#enterprise-admin-surface)
  - [SPA route map](#spa-route-map)
  - [Auth model — Sanctum stateful SPA + Bearer](#auth-model--sanctum-stateful-spa--bearer)
  - [Admin pages at a glance](#admin-pages-at-a-glance)
- [Chat Interface](#chat-interface)
  - [Chat History](#chat-history)
  - [Speech-to-Text](#speech-to-text)
- [Smart Visualizations & Artifacts](#smart-visualizations--artifacts)
- [Feedback & Auto-Learning](#feedback--auto-learning)
- [Reranking](#reranking)
- [Embedding Cache](#embedding-cache)
- [Hybrid Search](#hybrid-search)
- [Citations](#citations)
- [API](#api)
- [MCP Server](#mcp-server)
- [Document Ingestion — two flows](#document-ingestion--two-flows)
  - [Flow 1 — Local / S3 folder (queue-backed)](#flow-1--local--s3-folder-queue-backed)
  - [Flow 2 — Remote push from another repo](#flow-2--remote-push-from-another-repo)
  - [Queue drivers](#queue-drivers)
  - [GitHub Action (reusable)](#github-action-reusable)
  - [Idempotency & retries](#idempotency--retries)
- [Document Deletion](#document-deletion)
  - [Soft vs hard delete](#soft-vs-hard-delete)
  - [Artisan command](#artisan-command)
  - [Orphan pruning on resync](#orphan-pruning-on-resync)
  - [DELETE API endpoint](#delete-api-endpoint)
  - [GitHub Action integration](#github-action-integration)
  - [Scheduled retention sweep](#scheduled-retention-sweep)
- [Extending](#extending)
- [Testing](#testing)
- [Continuous Integration](#continuous-integration)
- [License](#license)
- [Enterprise rules](#enterprise-rules)
- [Contributing](#contributing)
- [Changelog](#changelog)

---

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                        Client                                │
│                  POST /api/kb/chat                           │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────────┐
│  KbChatController (orchestrator)                             │
│                                                              │
│  1. Validate input                                           │
│  2. KbSearchService → embed query → pgvector                 │
│  3. Compose system prompt with RAG chunks                    │
│  4. AiManager::chat() → configured provider                  │
│  5. ChatLogManager::log() → persist the interaction          │
│  6. Respond to client                                        │
└──────────────────────────────────────────────────────────────┘
                       │
          ┌────────────┼────────────────┐
          ▼            ▼                ▼
   ┌────────────┐ ┌──────────┐ ┌──────────────┐
   │ AI Provider│ │ pgvector │ │  Chat Log    │
   │ (OpenAI,   │ │ search   │ │  (DB, BQ,    │
   │  Anthropic,│ │          │ │   CloudWatch)│
   │  Gemini,   │ │          │ │              │
   │  OpenRouter│ │          │ │              │
   │  Regolo)   │ │          │ │              │
   └────────────┘ └──────────┘ └──────────────┘
```

### Main components

| Component | Path | Description |
|---|---|---|
| **AiManager** | `app/Ai/AiManager.php` | Multi-provider AI manager (chat + embeddings) |
| **Providers** | `app/Ai/Providers/*.php` | OpenAI, Anthropic, Gemini, OpenRouter, Regolo |
| **KbSearchService** | `app/Services/Kb/KbSearchService.php` | Semantic search via pgvector |
| **DocumentIngestor** | `app/Services/Kb/DocumentIngestor.php` | Document ingestion pipeline |
| **IngestDocumentJob** | `app/Jobs/IngestDocumentJob.php` | Queued job — reads from disk, delegates to `DocumentIngestor`, retries on failure |
| **KbIngestController** | `app/Http/Controllers/Api/KbIngestController.php` | `POST /api/kb/ingest` — persists payload to disk and dispatches one job per document |
| **ChatLogManager** | `app/Services/ChatLog/ChatLogManager.php` | Structured conversation logging |
| **Console commands** | `app/Console/Commands/*.php` | `kb:ingest`, `kb:ingest-folder`, `kb:prune-embedding-cache`, `chat-log:prune` |
| **GitHub Action** | `.github/actions/ingest-to-askmydocs/action.yml` | Reusable composite action to push markdown from any repo on every commit |
| **MCP Server** | `app/Mcp/Servers/KnowledgeBaseServer.php` | Read-only MCP server for Claude and other AI agents |

---

## Requirements

- **PHP** >= 8.3
- **Laravel** >= 13.x
- **PostgreSQL** >= 15 with the **pgvector** extension
- **Composer** >= 2.x
- **Node.js** >= 20 (only for running the JS test suite; the app itself is server-rendered and uses CDN assets)

### pgvector extension

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

The migration `create_knowledge_chunks_table` calls `Schema::ensureVectorExtensionExists()` automatically. A second migration ships a GIN index on `to_tsvector(chunk_text)` so hybrid search is performant out of the box on PostgreSQL (it is a no-op on SQLite and other drivers).

---

## Installation

```bash
# 1. Clone and enter the project
git clone https://github.com/your-org/askmydocs.git
cd askmydocs

# 2. Install PHP dependencies
composer install

# 3. Create your .env (see the "Environment file" section below)
cp .env.example .env

# 4. Generate the app key
php artisan key:generate

# 5. Configure PostgreSQL credentials in .env (see Database section)

# 6. Run migrations
php artisan migrate

# 7. (Optional) Create a user for authentication
php artisan tinker
# > \App\Models\User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password')]);

# 8. (Optional) Generate a Sanctum token for API access
php artisan tinker
# > \App\Models\User::first()->createToken('api')->plainTextToken;

# 9. Start the dev server
php artisan serve
```

Open `http://localhost:8000`, log in, and you will be redirected automatically to `/chat`.

---

## Configuration

### Environment file

All configuration is driven by environment variables documented in `.env.example`. Copy it to `.env`, fill in the secrets, and you are done — every variable has a sensible default, so an empty key only matters when you actually use that provider or feature.

The defaults are tuned for a **low-cost production** setup:

- Chat via **OpenRouter** (`openai/gpt-4o-mini`, cheap and fast).
- Embeddings via **OpenAI** (`text-embedding-3-small`, the lowest-cost 1536-dim embedding).
- Reranking **on**, hybrid search **off** (enable when your corpus has codes / acronyms / legal refs).
- Chat logging **off**, embedding cache **on**.

### Database

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=askmydocs
DB_USERNAME=postgres
DB_PASSWORD=secret
```

### AI Provider

The system supports **five providers**. Each is called via raw HTTP — no external SDK, full control over auth, retries, timeouts, and response parsing.

Config file: `config/ai.php`

#### Defaults

```env
# Chat provider. Supported: openai, anthropic, gemini, openrouter, regolo
AI_PROVIDER=openrouter

# Embeddings provider. Must support embeddings (openai, gemini, regolo).
# Anthropic and OpenRouter do NOT offer embeddings.
AI_EMBEDDINGS_PROVIDER=openai
```

#### OpenAI

```env
AI_PROVIDER=openai
OPENAI_API_KEY=sk-...
OPENAI_CHAT_MODEL=gpt-4o
OPENAI_EMBEDDINGS_MODEL=text-embedding-3-small
OPENAI_TEMPERATURE=0.2
OPENAI_MAX_TOKENS=4096
OPENAI_TIMEOUT=120
```

#### Anthropic (Claude)

Anthropic has no embeddings endpoint, so pair it with OpenAI or Gemini.

```env
AI_PROVIDER=anthropic
AI_EMBEDDINGS_PROVIDER=openai

ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_CHAT_MODEL=claude-sonnet-4-20250514
OPENAI_API_KEY=sk-...
```

#### Google Gemini

Gemini supports both chat and embeddings. `text-embedding-004` is **768-dim**, so switching embedding providers requires updating `KB_EMBEDDINGS_DIMENSIONS` **and** re-indexing.

```env
AI_PROVIDER=gemini
GEMINI_API_KEY=AIza...
GEMINI_CHAT_MODEL=gemini-2.0-flash
GEMINI_EMBEDDINGS_MODEL=text-embedding-004
```

#### OpenRouter (multi-model gateway) — default

OpenRouter proxies hundreds of models. It does not serve embeddings.

```env
AI_PROVIDER=openrouter
AI_EMBEDDINGS_PROVIDER=openai

OPENROUTER_API_KEY=sk-or-...
OPENROUTER_CHAT_MODEL=openai/gpt-4o-mini
OPENROUTER_APP_NAME="AskMyDocs"
OPENROUTER_SITE_URL=https://kb.example.com

OPENAI_API_KEY=sk-...
```

#### Regolo.ai (by Seeweb)

EU-based, GDPR-compliant, **OpenAI-compatible** REST API. Supports both chat and embeddings. Get keys at [dashboard.regolo.ai](https://dashboard.regolo.ai) and see [docs.regolo.ai](https://docs.regolo.ai) for the full model catalogue.

```env
AI_PROVIDER=regolo
AI_EMBEDDINGS_PROVIDER=regolo

REGOLO_API_KEY=...
REGOLO_BASE_URL=https://api.regolo.ai/v1
REGOLO_CHAT_MODEL=Llama-3.3-70B-Instruct
REGOLO_EMBEDDINGS_MODEL=gte-Qwen2
```

#### Embedding dimension gotcha

If you change the embeddings provider/model (e.g. from OpenAI 1536-dim to Gemini 768-dim):

1. Update `KB_EMBEDDINGS_DIMENSIONS` in `.env`
2. Create a new migration that resizes the `embedding` `vector(N)` column on `knowledge_chunks` and `embedding_cache`
3. `php artisan kb:prune-embedding-cache --days=0` (then `--days=` reset) or `EmbeddingCacheService::flush()` to drop the old vectors
4. Re-index all documents

### Storage (Laravel disks)

KB markdown files are read through a Laravel filesystem disk, so the ingestion pipeline is **storage-agnostic**: local for dev, S3 for production, MinIO for on-prem — no code change needed.

Config file: `config/filesystems.php`. The dedicated `kb` disk defaults to `storage/app/kb`:

```env
# Disk used by kb:ingest and DocumentIngestor (see config/filesystems.php)
KB_FILESYSTEM_DISK=kb
KB_DISK_DRIVER=local
# KB_DISK_ROOT=/absolute/path/to/markdown/root

# Optional path prefix prepended to every ingested path
KB_PATH_PREFIX=
```

#### Switching to S3

Install the Flysystem S3 adapter once:

```bash
composer require league/flysystem-aws-s3-v3 "^3.0"
```

Then switch the disk driver and fill the AWS credentials:

```env
KB_FILESYSTEM_DISK=s3

AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=eu-west-1
AWS_BUCKET=askmydocs-kb
AWS_URL=
AWS_ENDPOINT=              # set for MinIO / R2 / Wasabi
AWS_USE_PATH_STYLE_ENDPOINT=false
```

#### Ingesting a document

```bash
# Reads storage/app/kb/docs/setup.md (local disk)
php artisan kb:ingest docs/setup.md --project=erp-core --title="Installation Guide"

# Override the disk ad-hoc
php artisan kb:ingest docs/setup.md --project=erp-core --disk=s3
```

### Chat Logging

Chat logging is **off by default**. Enable it to get structured analytics about every Q&A turn.

Config file: `config/chat-log.php`

```env
CHAT_LOG_ENABLED=true
CHAT_LOG_DRIVER=database
CHAT_LOG_DB_CONNECTION=      # optional: dedicated DB connection
CHAT_LOG_RETENTION_DAYS=90   # scheduler rotates rows older than N days
```

#### Fields persisted per interaction

| Field | Description |
|---|---|
| `session_id` | Session UUID (from `X-Session-Id` header or auto-generated) |
| `user_id` | Authenticated user id (nullable) |
| `question` | User question |
| `answer` | Assistant response |
| `project_key` | Project key used as RAG filter |
| `ai_provider` | openai / anthropic / gemini / openrouter / regolo |
| `ai_model` | Specific model used |
| `chunks_count` | Number of retrieved context chunks |
| `sources` | Source document paths that contributed context |
| `prompt_tokens` / `completion_tokens` / `total_tokens` | Token usage |
| `latency_ms` | End-to-end latency |
| `client_ip` / `user_agent` | Client metadata |
| `extra` | JSON for custom fields (e.g. `few_shot_count`) |

Logging is wrapped in try/catch — a driver failure never breaks the user response.

### Knowledge Base

Config file: `config/kb.php`

```env
KB_EMBEDDINGS_DIMENSIONS=1536
KB_MIN_SIMILARITY=0.30
KB_DEFAULT_LIMIT=8

# Chunking
KB_CHUNK_TARGET_TOKENS=512
KB_CHUNK_HARD_CAP_TOKENS=1024
KB_CHUNK_OVERLAP_TOKENS=64

# Embedding cache
KB_EMBEDDING_CACHE_ENABLED=true
KB_EMBEDDING_CACHE_RETENTION_DAYS=30
```

### Extending the Ingestion Pipeline

AskMyDocs v3.0 introduces a pluggable ingestion pipeline driven by `config/kb-pipeline.php`. To add support for a new file format:

1. **Implement** `App\Services\Kb\Contracts\ConverterInterface` — convert raw bytes to a `ConvertedDocument` (markdown + extraction metadata). Every converter MUST populate `extractionMeta['filename'] = basename($doc->sourcePath)` so the chunker can attribute chunks back to their source file.
2. **Implement** `App\Services\Kb\Contracts\ChunkerInterface` — or reuse `MarkdownChunker` if your converter outputs markdown (the default for prose formats).
3. **Register** in `config/kb-pipeline.php` under `converters` and `chunkers`.
4. **Map** the MIME type in `mime_to_source_type` so the pipeline can route to the right chunker.

Built-in converters (v3.0):

- `MarkdownPassthroughConverter` — `text/markdown`, `text/x-markdown`
- `TextPassthroughConverter` — `text/plain` (wraps prose in a `# {basename}` header so MarkdownChunker can section it)
- `PdfConverter` — `application/pdf` (smalot/pdfparser primary; falls back to `pdftotext` from Poppler when smalot rejects the file)
- `DocxConverter` — `application/vnd.openxmlformats-officedocument.wordprocessingml.document` (added in T1.6)

**PDF support:** `smalot/pdfparser` is a hard `require` (pure PHP, no system deps). For more robust extraction on complex PDFs (multi-column layouts, certain XFA forms, mixed encodings), install `poppler-utils` on the host (`apt install poppler-utils` on Debian/Ubuntu, `brew install poppler` on macOS) — the `PdfConverter` automatically falls back to the `pdftotext` binary when smalot raises an exception. `extractionMeta.extraction_strategy` records which strategy was used per document so you can audit the rate of fallbacks in production.

Built-in chunkers (v3.0):

- `MarkdownChunker` — handles `markdown`, `md`, `text`, `pdf`, `docx` source types (any source whose converter outputs markdown). For `pdf` the converter emits `# {basename}` + `## Page N` sections so the section_aware mode produces ≥1 chunk per page out-of-the-box.
- `PdfPageChunker` (T1.7, not yet shipped) — will take over `pdf` source-type via the registry's first-match-wins rule and slice by page metadata directly instead of relying on the `## Page N` heading convention.

The polymorphic entry point is `DocumentIngestor::ingest(string $projectKey, SourceDocument $source, string $title, array $extraMetadata = [])`. The pre-v3 `ingestMarkdown(...)` is now a thin facade that synthesises a `text/markdown` `SourceDocument` and delegates to `ingest()` — IngestDocumentJob and the GitHub Action keep working unchanged.

---

## Scheduler

Two daily hygiene jobs are registered in `bootstrap/app.php` and dispatched automatically when the Laravel scheduler runs.

| Time | Command | Retention env | Description |
|---|---|---|---|
| 03:10 | `kb:prune-embedding-cache` | `KB_EMBEDDING_CACHE_RETENTION_DAYS` (default 30) | Deletes `embedding_cache` rows whose `last_used_at` is older than N days |
| 03:20 | `chat-log:prune` | `CHAT_LOG_RETENTION_DAYS` (default 90) | Deletes `chat_logs` rows whose `created_at` is older than N days |
| 03:30 | `kb:prune-deleted` | `KB_SOFT_DELETE_RETENTION_DAYS` (default 30) | Hard-deletes soft-deleted `knowledge_documents` (and their files on the KB disk) older than N days |

Set any env to `0` to disable the corresponding rotation. All commands accept a `--days=` flag that wins over the env value for ad-hoc runs.

Register the scheduler entry in your crontab:

```cron
* * * * * cd /path/to/askmydocs && php artisan schedule:run >> /dev/null 2>&1
```

List what is configured:

```bash
php artisan schedule:list
```

All three commands can also be invoked manually:

```bash
php artisan kb:prune-embedding-cache
php artisan chat-log:prune --days=60
php artisan kb:prune-deleted --days=14
```

---

## Authentication

The system uses standard Laravel session-based auth. No route is public.

### Features

| Feature | Route | Description |
|---|---|---|
| **Login** | `GET /login` | Login form |
| **Login POST** | `POST /login` | Authenticate with email + password → redirects to `/chat` |
| **Logout** | `POST /logout` | End the session |
| **Forgot password** | `GET /forgot-password` | Request a reset link |
| **Send reset** | `POST /forgot-password` | Email a reset token |
| **Reset password** | `GET /reset-password/{token}` | Set a new password |
| **Save password** | `POST /reset-password` | Update the password |

> On successful login the controller redirects to `route('chat')` — visiting `/` when authenticated also redirects to the chat UI.

**Note**: user registration is intentionally NOT implemented. Create users manually:

```bash
php artisan tinker --execute="
    \App\Models\User::create([
        'name' => 'Mario Rossi',
        'email' => 'mario@example.com',
        'password' => bcrypt('password123'),
    ]);
"
```

For password reset, configure the mail driver:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=noreply@example.com
MAIL_PASSWORD=secret
MAIL_FROM_ADDRESS=noreply@example.com
```

---

## Enterprise admin surface

Beyond the chat UI, AskMyDocs ships a full React SPA admin shell at `/app/*`.
It covers every operator workflow end-to-end — KPI dashboard, user + role
management, canonical KB explorer with inline editor and graph, log viewer,
whitelisted maintenance command runner, and daily AI insights. Every page
is RBAC-gated via Spatie roles (`admin` + `super-admin`), every mutation
is audit-trailed, and every destructive command requires a second,
type-to-confirm gate before it runs.

### SPA route map

| Path | Component | Required role | Notes |
|---|---|---|---|
| `/login` + `/forgot-password` + `/reset-password` | Auth pages | guest | CSRF-primed on mount |
| `/app/chat` + `/app/chat/:conversationId` | ChatView | any authenticated | ChatGPT-style RAG UI |
| `/app/admin` | DashboardView | admin, super-admin | 6 KPIs + 3 charts + health strip |
| `/app/admin/users` | UsersView | admin, super-admin | Create / edit / soft-delete / restore / memberships |
| `/app/admin/roles` | RolesView | admin, super-admin | Spatie CRUD + permission matrix |
| `/app/admin/kb` | KbView | admin, super-admin | Tree explorer + Preview / Meta / Source / Graph / History tabs |
| `/app/admin/logs` | LogsView | admin, super-admin | Chat / audit / application / activity / failed-jobs tabs |
| `/app/admin/maintenance` | MaintenanceView | admin (non-destructive) or super-admin (all) | Whitelisted Artisan runner |
| `/app/admin/insights` | InsightsView | admin, super-admin | 6 AI-computed widget cards (daily) |

Every page exposes stable `data-testid` + `data-state="idle\|loading\|ready\|error\|empty"` per R11
so Playwright waits are deterministic — no `waitForTimeout`, no CSS-selector
fishing. See `frontend/e2e/admin-*.spec.ts` for the reference pattern,
including the golden-path `admin-journey.spec.ts` that walks every page in
order as a reviewer demo.

### Auth model — Sanctum stateful SPA + Bearer

Two concurrent flows feed the same Sanctum guard, picked per request:

1. **Stateful SPA (primary)** — cookies-based. The React app calls
   `GET /sanctum/csrf-cookie` at bootstrap, then every `/api/*` call carries
   the session + `X-XSRF-TOKEN` header. Stateful hosts are parsed from
   `SANCTUM_STATEFUL_DOMAINS` (comma-separated), origins from
   `CORS_ALLOWED_ORIGINS`. Wildcard `*` is forbidden because
   `supports_credentials=true`.
2. **Bearer (API clients, MCP, GitHub Action)** — personal access tokens.
   `POST /api/auth/login` returns `{ token, user }`; subsequent requests
   use `Authorization: Bearer <token>`. Same guard, same RBAC scopes.

Spatie `role:` / `permission:` middleware is registered explicitly in
`bootstrap/app.php` (Laravel 13 doesn't auto-alias package middleware).
Role/permission assignments pin to the `web` guard regardless of request
transport via `$guard_name = 'web'` on `User`.

### Admin pages at a glance

Screenshot placeholders — populated pre-release (see `resources/screenshots/README.md`).

**Dashboard (`/app/admin`)**

![Admin Dashboard](resources/screenshots/dashboard-admin.png)

KPI strip (docs / chunks / chats / latency / cache / coverage) + health
strip (db / pgvector / queue / kb-disk / embeddings / chat) + three
code-split recharts cards (chat volume area, token burn stacked bar,
rating donut) + top projects + activity feed. Thirty-second cache layer
(`Cache::remember` keyed by kind+project+days).

**Users & Roles (`/app/admin/users` + `/app/admin/roles`)**

![Users Table](resources/screenshots/users-table.png) ![User Drawer](resources/screenshots/user-drawer-roles.png) ![Roles Permission Matrix](resources/screenshots/roles-permission-matrix.png)

Filterable users table with soft-delete toggle, 3-tab edit drawer
(Details / Roles / Memberships with `scope_allowlist` JSON shape),
and a Spatie-backed role CRUD with a grouped permission matrix
(one card per dotted-prefix domain: `kb`, `users`, `roles`,
`commands`, `logs`, `insights`, ...).

**KB Explorer (`/app/admin/kb`)**

![KB Tree](resources/screenshots/kb-tree.png) ![KB Doc Preview](resources/screenshots/kb-doc-preview.png)
![KB Doc Source Editor](resources/screenshots/kb-doc-source-editor.png) ![KB Doc Graph](resources/screenshots/kb-doc-graph.png) ![KB Doc History](resources/screenshots/kb-doc-history.png)

Left panel: memory-safe `chunkById(100)` tree walker with canonical-aware
scopes (`mode=canonical\|raw\|all`, `with_trashed=0\|1`). Right panel,
when a doc is selected: **Preview** (remark-rendered markdown with
frontmatter pill pack) / **Meta** (canonical meta grid + AI-suggested
tags) / **Source** (CodeMirror 6 editor — `@codemirror/state` +
`/view` + `/lang-markdown`, ~150 KB lighter than basic-setup; PATCH
`/raw` runs validate → write → audit → re-ingest) / **Graph** (1-hop
tenant-scoped subgraph, SVG radial layout, ≤ 50 nodes) / **History**
(paginated audit trail from `kb_canonical_audit`, immutable, survives
hard delete).

**Logs (`/app/admin/logs`)**

![Logs Chat Tab](resources/screenshots/logs-chat-tab.png) ![Logs App Tab](resources/screenshots/logs-app-tab.png)

Five deep-linkable tabs (`?tab=chat\|audit\|app\|activity\|failed`):
paginated chat logs with model/project/rating filters; canonical
audit trail with event-type/actor filters; reverse-seek
`SplFileObject`-powered application log tailer (2000-line cap, filename
whitelist regex, optional live polling via `?live=1`); Spatie
activity log (Spatie, required); failed-jobs
read-only table with expandable exception trace (retry lives in
Maintenance, not here).

**Maintenance (`/app/admin/maintenance`)**

![Maintenance Wizard Step 1](resources/screenshots/maintenance-wizard-step1.png) ![Maintenance Wizard Step 2](resources/screenshots/maintenance-wizard-step2-confirm.png) ![Maintenance History](resources/screenshots/maintenance-history.png)

Whitelisted Artisan runner enforced by `CommandRunnerService` via six
independent gates: (1) whitelist lookup in
`config('admin.allowed_commands')`, (2) args_schema validation,
(3) signed `confirm_token` + DB-backed single-use nonce, (4) Spatie
permission gate (`commands.run` for admin, `commands.destructive`
for super-admin only), (5) audit-before-execute
(`admin_command_audits` row flips `started → completed\|failed`
around the `Artisan::call()`), (6) per-user rate limit
(`throttle:10,1`). Three-step React wizard: Preview →
[Confirm type-in for destructive] → Run → Result. Scheduler widget
reports the next run of every queued command.

**Insights (`/app/admin/insights`)**

![Insights View](resources/screenshots/insights-view.png)

Daily `insights:compute` command (05:00 UTC via scheduler) writes one
row into `admin_insights_snapshots` — six independently-nullable JSON
columns back six widget cards: Promotion Suggestions, Orphan Docs,
Suggested Tags, Coverage Gaps, Stale Docs, Quality Report. The SPA
read path is O(1) DB read; zero LLM calls per page load by design
(moving the compute from on-demand to pre-computed saves a provider
bill at scale — see LESSONS.md PR14).

**Dark mode & auth pages**

![Login Dark Mode](resources/screenshots/login-dark-mode.png) ![Chat Dark Mode](resources/screenshots/chat-dark-mode.png)

The whole SPA is dark-first glassmorphism (violet → cyan accent) built
on CSS custom properties (`frontend/src/styles/tokens.css`); Tailwind
sits alongside as an escape hatch, not the primary styling path.

---

## Chat Interface

The app ships a full ChatGPT/Claude-style chat UI at `/chat` after login.

### Layout

```
┌──────────────────────────────────────────────────────────┐
│  AskMyDocs                                       Logout  │
├──────────┬───────────────────────────────────────────────┤
│          │                                               │
│ + New    │    [Scrollable messages area]                 │
│   Chat   │                                               │
│ ──────── │    User: How does OAuth work?                 │
│ Chat 1   │                                               │
│ Chat 2 ✎🗑│    Assistant: According to the docs...       │
│ Chat 3   │                                               │
│          ├───────────────────────────────────────────────┤
│ user@... │ [Message...                 ] [🎤] [Send]     │
└──────────┴───────────────────────────────────────────────┘
```

### Features

- **New Chat**: start a fresh conversation.
- **Chat list**: sidebar with every conversation, sorted by last activity.
- **Rename**: click the pencil, inline edit, Enter to save.
- **Delete**: click the trash icon (with confirmation).
- **Auto title**: after the first message, the AI generates a descriptive title.
- **Persistence**: each conversation keeps the full history; clicking an old chat reloads everything.
- **Multi-turn**: the whole history is sent to the AI on every request.
- **Markdown rendering**: code, lists, bold, tables.
- **Metadata**: every response shows the model and the latency.

### Chat History

Conversations live in two tables:

- **`conversations`** — `id`, `user_id` (FK), `title`, `project_key`, timestamps
- **`messages`** — `id`, `conversation_id` (FK), `role` (user/assistant), `content`, `metadata` (JSON with provider/model/tokens/latency/citations), `rating`

Each user sees **only their own** conversations; ownership is enforced server-side on every operation.

### AJAX endpoints (session auth)

| Method | Route | Description |
|---|---|---|
| `GET` | `/conversations` | List user conversations |
| `POST` | `/conversations` | Create a new conversation |
| `PATCH` | `/conversations/{id}` | Rename |
| `DELETE` | `/conversations/{id}` | Delete (with every message) |
| `GET` | `/conversations/{id}/messages` | Load messages |
| `POST` | `/conversations/{id}/messages` | Send a message (triggers AI response) |
| `POST` | `/conversations/{id}/generate-title` | Generate an AI title |

### Speech-to-Text

The mic uses the browser-native **Web Speech API** — no external service, no cost.

**Supported browsers**: Chrome, Edge, Safari (partial). Firefox is NOT supported.

**How it works**:
1. Click the mic button (turns red and pulses).
2. Speak — the transcription appears live in the input field.
3. Click again to stop, or let it stop on pause.
4. Edit the transcribed text if needed before sending.
5. Click "Send".

**Language**: defaults to Italian (`it-IT`). Change `this.recognition.lang` in `chat.blade.php` to switch.

If the browser has no Web Speech API, the mic button is disabled.

---

## Smart Visualizations & Artifacts

The AI doesn't just reply with text — when the data justifies it, it emits **interactive visual artifacts** right inside the chat.

### Artifact types

| Artifact | Triggered when | Tech |
|---|---|---|
| **Tables** | Comparisons, config, structured data | Enhanced markdown tables |
| **Charts** | Stats, distributions, trends, numeric comparisons | Chart.js (bar, line, pie, doughnut) |
| **Code blocks** | Code snippets, configs, commands | Syntax highlight + "Copy" button |
| **Action buttons** | Copyable content, downloadable files | Interactive buttons (clipboard, download) |

### How charts work

The system prompt asks the AI to emit a `~~~chart` block with structured JSON whenever a visualization would help:

```
~~~chart
{
    "type": "bar",
    "title": "Tickets by category",
    "labels": ["Bug", "Feature", "Docs"],
    "datasets": [{"label": "Count", "data": [42, 28, 15]}]
}
~~~
```

The frontend:
1. Intercepts `~~~chart` blocks while rendering markdown.
2. Replaces them with `<canvas>` placeholders.
3. Initializes a Chart.js chart with automatic styling.
4. Supports: `bar`, `line`, `pie`, `doughnut`.

### How action buttons work

```
~~~actions
[
    {"label": "Copy config", "action": "copy", "data": "DATABASE_URL=postgresql://..."},
    {"label": "Download YAML", "action": "download", "filename": "config.yml", "data": "server:\n  port: 8080"}
]
~~~
```

Action types:
- **copy** — copies the payload to the clipboard with a "Copied!" flash
- **download** — saves the payload as a file with the given name

### Code blocks with copy

Every code block inside responses gets a **"Copy"** button in the top-right corner — click to copy the full block to the clipboard.

---

## Feedback & Auto-Learning

The system learns from user preferences through a feedback loop that progressively improves response quality.

### How it works

```
User receives an answer
    │
    ├── Click 👍 (positive) → save rating on the message
    │       │
    │       └── Future responses will include this Q&A as a
    │           "well-rated example" in the prompt (few-shot learning)
    │
    └── Click 👎 (negative) → save rating on the message
            │
            └── Analytics signal only (not injected into prompts)
```

### Few-Shot Learning

When a user rates an answer positively:

1. The rating (`positive`) is saved on the message in the database.
2. On subsequent requests, `FewShotService` retrieves the last 3 positively-rated Q&As for the same user/project.
3. They are injected into the system prompt as "Examples of Well-Rated Answers".
4. The AI gradually adapts tone, depth, and format to the user's preferences.

This lets the system **adapt per user** without fine-tuning:
- A user who rewards detailed answers gets deeper answers.
- A user who rewards concise answers gets shorter answers.
- Formatting preferences (tables vs prose, technical vs plain) are learned.

### Toggle

Feedback is a toggle — clicking the same thumb twice removes the rating.

### Feedback in logs

When chat logging is on, every response records in `extra`:
- `few_shot_count` — number of positive examples injected into the prompt
- `citations_count` — number of citations produced

This enables correlating few-shot usage to perceived quality.

---

## Reranking

The system uses **hybrid reranking** that fuses three relevance signals:

### How it works

```
User query
    │
    ▼
1. Over-retrieval: pgvector returns 3× candidates (e.g. 24 instead of 8)
    │                with a cosine similarity score
    ▼
2. Reranking: for each candidate, 3 scores are computed:
    ├── vector_score  (0-1): original cosine similarity from pgvector
    ├── keyword_score (0-1): keyword coverage of the query in the text
    └── heading_score (0-1): keyword match in the chunk heading
    │
    ▼
3. Score fusion: combined = 0.6×vector + 0.3×keyword + 0.1×heading
    │
    ▼
4. Top-K: the best 8 chunks (configurable) are returned
```

### Why reranking helps

Pure vector search may miss results that contain the exact query terms. Keyword-based reranking recovers these by rewarding direct lexical matches.

**Example**: for "OAuth 2.0 configuration", a generic authentication chunk might beat the OAuth-specific one on pure cosine similarity; the reranker pushes the OAuth chunk up because its text and heading contain the exact phrase.

### Configuration

```env
KB_RERANKING_ENABLED=true
KB_RERANK_CANDIDATE_MULTIPLIER=3

# Weights must sum to 1.0
KB_RERANK_VECTOR_WEIGHT=0.60
KB_RERANK_KEYWORD_WEIGHT=0.30
KB_RERANK_HEADING_WEIGHT=0.10
```

### Implementation notes

- **Zero extra cost**: the reranker runs entirely in-process.
- **Stop words**: Italian and English stop words are filtered automatically.
- **Whole-word bonus**: full-word matches earn a bonus over substring matches.
- **Transparent**: every returned chunk carries `rerank_detail` with the individual scores for debugging.

---

## Embedding Cache

The embedding cache skips redundant API calls when the same content is re-ingested or the same query is searched again.

### How it works

```
Text to embed
    │
    ▼
EmbeddingCacheService::generate([$text1, $text2, ...])
    │
    ├── SHA-256 hash per text
    │
    ├── Batch lookup on embedding_cache
    │     (hash + provider + model)
    │
    ├── Cache HIT → embedding returned from DB (zero API call)
    │
    ├── Cache MISS → only the new texts hit the API
    │     └── result stored back in cache
    │
    └── Final result: order-matched array of embeddings
```

### When it helps

- **Re-ingestion**: re-indexing unchanged documents consumes no API tokens.
- **Repeated queries**: the same search query produces no duplicate embeddings.
- **Development**: during dev you run many similar test queries.

### `embedding_cache` table

```sql
id              BIGINT PK
text_hash       VARCHAR(64) UNIQUE    -- SHA-256 of the input text
provider        VARCHAR(64)           -- openai, gemini, regolo, ...
model           VARCHAR(128)          -- text-embedding-3-small, gte-Qwen2, ...
embedding       VECTOR(1536)          -- cached vector
created_at      TIMESTAMP
last_used_at    TIMESTAMP             -- for LRU-style pruning
```

### Configuration

```env
KB_EMBEDDING_CACHE_ENABLED=true
KB_EMBEDDING_CACHE_RETENTION_DAYS=30
```

### Maintenance

Manual pruning / inspection via the service:

```php
use App\Services\Kb\EmbeddingCacheService;

$cache = app(EmbeddingCacheService::class);

$cache->stats();                          // ['total_entries' => 1234, 'providers' => [...]]
$cache->prune(now()->subDays(30));        // manual prune
$cache->flush();                          // wipe everything
$cache->flush('openai');                  // wipe one provider only
```

Or via artisan:

```bash
php artisan kb:prune-embedding-cache --days=30
```

> **Heads up**: when you switch embedding providers (e.g. OpenAI → Gemini), the cache still holds old-provider vectors. Run `flush()` and re-index.

---

## Hybrid Search

Hybrid search combines semantic search (pgvector) with PostgreSQL's full-text search (tsvector / tsquery) to catch cases where exact terms matter.

### Why it matters

Pure semantic search excels at conceptually similar content but can miss:

| Query type | Pure semantic | Hybrid |
|---|---|---|
| "OAuth configuration" | Finds it | Finds it |
| "product code XR-4521" | May miss | Exact match via FTS |
| "article 42 paragraph 3" | May miss | Exact match via FTS |
| "ENOMEM error" | May confuse | Exact match via FTS |

### How it works

```
User query
    │
    ├──────────────────────┐
    ▼                      ▼
Semantic Search         Full-Text Search
(pgvector cosine)       (tsvector/tsquery)
    │                      │
    ├── ranked list #1     ├── ranked list #2
    │                      │
    └──────────┬───────────┘
               │
               ▼
    Reciprocal Rank Fusion (RRF)
    score = Σ weight / (k + rank)
               │
               ▼
    Merged list → Reranker → Top-K
```

**Reciprocal Rank Fusion (RRF)** is the standard algorithm for merging two ranked lists without normalising scores. It is used by Elasticsearch, Pinecone, and most production hybrid-search systems.

### PostgreSQL full-text search

Native features only:
- `to_tsvector(lang, text)` — tokenize the text
- `plainto_tsquery(lang, query)` — safely parse the user query (no syntax errors)
- `ts_rank()` — compute relevance
- Configurable language: italian (default), english, german, french, spanish, ...

A GIN index on `to_tsvector(chunk_text)` is shipped as a migration — no manual SQL required:

```
database/migrations/2026_01_01_000008_add_fts_gin_index_to_knowledge_chunks.php
```

The migration uses the language from `KB_FTS_LANGUAGE` (whitelisted against SQL injection) and is a safe no-op on non-PostgreSQL drivers.

### Configuration

```env
KB_HYBRID_SEARCH_ENABLED=false
KB_FTS_LANGUAGE=italian
KB_RRF_K=60
KB_HYBRID_SEMANTIC_WEIGHT=0.70
KB_HYBRID_FTS_WEIGHT=0.30
```

### When to enable

- **Enable** if your corpus contains product codes, legal refs, acronyms, or other terms that must be matched literally.
- **Leave off** if the content is mostly prose and semantic search is doing fine.
- Runtime cost is minimal (one extra SQL query per search).

---

## Canonical Knowledge Compilation (Knowledge Graph + Anti-Repetition Memory)

> **What a normal RAG can't do for you — and what AskMyDocs now does.**

A plain Retrieval-Augmented Generation system treats your documentation as
a pile of interchangeable chunks. It embeds them, searches by cosine
similarity, stuffs the top-K into a prompt, and calls an LLM. Every query
rediscovers the answer from zero. There is no typed memory, no navigation,
no persistence of what your team has **already decided**. Rejected
approaches get re-proposed. Decisions drift silently. The knowledge base
is read-only — nothing is ever *promoted*.

AskMyDocs `1.3+` adds a **canonical knowledge compilation layer** on top
of the RAG pipeline, inspired by Karpathy's LLM-Wiki idea and adapted for
enterprise Git-based workflows. The result is a system that behaves less
like "semantic search" and more like a **living, typed, navigable corporate
brain**.

### Key capabilities

| Capability | Plain RAG | Wiki + CLI-AI (Obsidian / OmegaWiki + Claude CLI) | **AskMyDocs Canonical** |
|---|:---:|:---:|:---:|
| Semantic search over markdown | ✓ | partial | ✓ |
| Typed documents (decision, runbook, standard, ...) | ✗ | partial | **✓ (9 types)** |
| Stable business IDs (`DEC-2026-0001`) | ✗ | partial | ✓ |
| Canonical statuses (draft/accepted/superseded/...) | ✗ | partial | **✓ (6 statuses)** |
| Retrieval priority per document | ✗ | ✗ | ✓ |
| Lightweight knowledge graph (wikilinks → edges) | ✗ | partial | **✓ (10 relations)** |
| 1-hop graph expansion at retrieval | ✗ | ✗ | **✓** |
| Rejected-approach anti-repetition memory | ✗ | ✗ | **✓ (prompt-level)** |
| Human-gated promotion pipeline (raw → canonical) | ✗ | ✗ | **✓ (REST + CLI)** |
| Scalable indexed projection (pgvector + FTS) | ✓ | ✗ | ✓ |
| Multi-tenant (per-project slug + FK isolation) | partial | ✗ | ✓ |
| Multi-provider AI (OpenAI / Anthropic / Gemini / ...) | partial | ✗ | ✓ |
| MCP server with typed graph tools | ✗ | ✗ | **✓ (10 tools)** |
| Auditable editorial events (`kb_canonical_audit`) | ✗ | ✗ | **✓** |
| Git-based source-of-truth | ✗ | ✓ | ✓ |
| Self-hosted / on-prem / EU-sovereign | varies | ✓ | ✓ |
| Enterprise auth (Sanctum) | ✗ | ✗ | ✓ |
| Cross-repo GitHub composite action | ✗ | ✗ | ✓ |

### How it works

Your team writes **canonical markdown** with a small YAML frontmatter:

```yaml
---
id: DEC-2026-0001
slug: dec-cache-invalidation-v2
type: decision
project: ecommerce-core
module: cache
status: accepted
owners: [platform-team]
retrieval_priority: 90
tags: [cache, invalidation, redis]
related:
  - "[[module-cache-layer]]"
  - "[[runbook-purge-failure]]"
summary: Official cache invalidation strategy using tagged and secondary keys.
---

# Decision: Cache invalidation v2

## Context
...

## Decision
Use tagged invalidation with fallback to secondary keys. See
[[module-cache-layer]] for the implementation and
[[rejected-direct-cache-full-purge-on-price-change]] for what we
explicitly dismissed.
```

When AskMyDocs ingests this file:

1. **Frontmatter parsing** — `CanonicalParser` parses YAML frontmatter
   (via `symfony/yaml`) and validates that `type` is one of the 9
   canonical types, `status` is one of the 6 statuses, `slug` matches
   the slug regex, and `retrieval_priority` is in `[0, 100]`. Invalid
   frontmatter degrades gracefully to non-canonical (R4).
2. **Section-aware chunking** — `MarkdownChunker` splits on H1/H2/H3
   while preserving `heading_path` breadcrumbs and stripping the
   frontmatter block. Implemented as a custom line-based fence-aware
   state machine (no external markdown parser library): `#` inside a
   fenced code block like `~~~bash ... ~~~` or with triple-backtick
   fences is NOT treated as a heading.
3. **Wikilink extraction** — every `[[slug]]` becomes an edge in
   `kb_edges` with `provenance='wikilink'`. Frontmatter `related:`
   entries become edges with `provenance='frontmatter_related'`.
   Dangling links (target not yet canonicalized) are tracked as
   placeholder `kb_nodes` with `payload_json.dangling = true`.
4. **Canonical projection** — `knowledge_documents` row carries the 8
   canonical columns (`doc_id`, `slug`, `canonical_type`,
   `canonical_status`, `is_canonical`, `retrieval_priority`,
   `source_of_truth`, `frontmatter_json`). `kb_nodes` + `kb_edges` form
   the graph, fully **tenant-scoped** (two projects can share
   `dec-cache-v2`).
5. **Audit trail** — every promote/update/deprecate/hard-delete is
   logged to `kb_canonical_audit`.

### Graph-aware retrieval

When a user asks a question:

```
                  ┌─────────────────┐
user query ──────►│ vector + FTS    │──► top-K chunks (primary)
                  │ Reranker fusion │    + canonical boost + status penalty
                  └────────┬────────┘
                           │
                           ├──► GraphExpander (1-hop)
                           │    walks kb_edges: depends_on,
                           │    decision_for, related_to,
                           │    implements, supersedes
                           │    → best chunk of each neighbour
                           │
                           └──► RejectedApproachInjector
                                cosine-searches rejected-approach docs
                                → top-3 injected with ⚠ marker

                  ┌─────────────────┐
prompt    ◄───────│  primary +      │
                  │  expanded +     │
                  │  rejected       │
                  └─────────────────┘
```

The reranker applies a **canonical boost** (priority × 0.003) and
**status penalties** (superseded −0.4, deprecated −0.4, archived −0.6)
on top of the existing vector/keyword/heading fusion. Non-canonical
chunks get zero adjustment (legacy behaviour preserved exactly).

### Anti-repetition memory — the feature everyone forgets

This is the feature that makes the system **learn from what didn't work**.
When a question correlates (cosine ≥ `KB_REJECTED_MIN_SIMILARITY`, default
0.45) with a `type: rejected-approach` document, that document is injected
into the prompt under a clearly-labeled block:

```
⚠ REJECTED APPROACHES (do NOT repeat — these were deliberately dismissed):
- [rejected-direct-cache-full-purge-on-price-change]
  Reason: Too expensive and noisy CDN-side and backend-side. Flooded the
  origin during flash sales.
```

The LLM sees the rejected options **before** generating its answer. It
stops proposing them. This single change is why your team stops re-hashing
the same tradeoffs every quarter.

### Promotion pipeline — from session to canonical (human-gated)

Conversations, incident post-mortems and code reviews produce knowledge
that usually evaporates. The promotion pipeline captures it **without
trusting the LLM to write canonical storage directly** (ADR 0003):

```
  raw session transcript
         │
         ▼
  POST /api/kb/promotion/suggest
         │ (LLM extracts candidate artifacts as structured JSON;
         │  writes NOTHING)
         ▼
  [ candidate: { type: 'decision', slug_proposal: 'dec-X',
                 title_proposal: '...', reason: '...' } ]
         │
         ▼
  Claude skill promote-decision renders a full draft with frontmatter
         │ (writes to developer filesystem as draft — still NOT canonical)
         ▼
  Human reviews, adjusts, commits to Git
         │
         ▼
  GitHub Action ingest-to-askmydocs v2 detects canonical folder patterns
         │
         ▼
  POST /api/kb/ingest  ─►  DocumentIngestor  ─►  CanonicalIndexerJob
         │
         ▼
  Knowledge is now persistent, typed, linked, and retrievable.
```

Three rules:

- **No skill writes directly to canonical storage.** Drafts only.
- **Every promotion produces a `kb_canonical_audit` row.**
- **Rejected approaches are first-class citizens** — the `rejected/`
  folder is as important as `decisions/`.

### Promotion API endpoints (all Sanctum-protected)

| Endpoint | Purpose | Writes? |
|---|---|---|
| `POST /api/kb/promotion/suggest` | LLM extracts candidate artifacts from a transcript | ✗ |
| `POST /api/kb/promotion/candidates` | Validates a markdown draft against `CanonicalParser` | ✗ |
| `POST /api/kb/promotion/promote` | Writes markdown to KB disk + dispatches ingest (HTTP 202) | ✓ |

### Multi-project & non-software domains

`project_key` is the primary tenant isolator — present on every canonical
table. A single AskMyDocs deployment can host N projects of any domain.
The 9 canonical types are **deliberately domain-agnostic**: 7 apply
equally to software, HR, legal, finance, operations, customer-success:

| Type | Software | HR | Legal | Finance | Ops |
|---|:---:|:---:|:---:|:---:|:---:|
| `decision` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `runbook` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `standard` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `incident` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `domain-concept` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `rejected-approach` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `project-index` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `module-kb` | ✓ | partial | — | — | partial |
| `integration` | ✓ | partial | — | partial | partial |

Adding a domain-specific type (e.g. `policy`, `process`, `sla`,
`product-spec`) is a **1-file change** to `app/Support/Canonical/CanonicalType.php`
+ one entry in `config('kb.promotion.path_conventions')`. No migration.

### Roles & permissions — hooks in place, implementation deferred

The data model already provisions the hook points for a future RBAC layer:
`knowledge_documents.access_scope` column, canonical frontmatter
`owners` / `reviewers`, `project_key` tenant boundary, Sanctum auth.
When RBAC ships it needs only:
- 1 new `project_memberships` / `roles` table,
- 1 Eloquent global scope on `KnowledgeDocument`,
- 1 middleware on `/api/kb/*` routes.

All retrieval services (`KbSearchService`, `GraphExpander`,
`RejectedApproachInjector`, MCP tools) query through Eloquent, so the
global scope propagates automatically. **Zero structural debt.**

### The 5 Claude skill templates

Shipped under `.claude/skills/kb-canonical/` as **consumer-side templates**.
Copy into your own `.claude/skills/` to activate:

| Skill | Produces | Trigger |
|---|---|---|
| `promote-decision` | ADR-style canonical decision | "we decided to X" |
| `promote-module-kb` | `module-kb` with 9 standard sections | "document the checkout module" |
| `promote-runbook` | `runbook` (trigger / actions / rollback / escalation) | "turn this into a runbook" |
| `link-kb-note` | Wikilink additions to existing notes | "connect these docs" |
| `session-close` | Shortlist of candidate artifacts | session wrap-up |

Every skill is **human-gated**: it produces drafts, never commits.

Plus `.claude/skills/canonical-awareness/` (R10) — triggers when editing
AskMyDocs itself, carries the 10-point checklist for keeping canonical
invariants intact.

### MCP tools (10 total — 5 base retrieval + 5 canonical/promote)

AskMyDocs's `enterprise-kb` MCP server (v2.0.0, `laravel/mcp ^0.7`):

| Tool | Use |
|---|---|
| `kb.search` | Vector + FTS + Reranker search (legacy, still works) |
| `kb.read_document` | Fetch a document by id |
| `kb.read_chunk` | Fetch a chunk by id |
| `kb.recent_changes` | List recently-ingested docs |
| `kb.search_by_project` | Project-scoped search |
| `kb.graph.neighbours` | 1-hop neighbours of a node, filtered by edge type |
| `kb.graph.subgraph` | BFS subgraph from a seed (≤ 2 hops, capped) |
| `kb.documents.by_slug` | Lookup canonical doc by project-scoped slug |
| `kb.documents.by_type` | List canonical docs of type X, filtered by status |
| `kb.promotion.suggest` | Extract candidate artifacts from a transcript (writes nothing) |

### New Artisan commands (3)

| Command | Purpose |
|---|---|
| `kb:promote {path} [--project=] [--dry-run]` | Operator-side CLI promotion (not used by skills) |
| `kb:validate-canonical [--project=] [--from-disk] [--disk=]` | Walk canonical docs and validate frontmatter; prints per-file errors |
| `kb:rebuild-graph [--project=] [--no-truncate] [--sync]` | Rebuild `kb_nodes` + `kb_edges` from canonical documents. **Scheduled daily at 03:40.** No-op when no canonical docs exist. |

### New env vars (18)

```bash
# Canonical layer
KB_CANONICAL_ENABLED=true
KB_CANONICAL_PRIORITY_WEIGHT=0.003
KB_CANONICAL_SUPERSEDED_PENALTY=0.40
KB_CANONICAL_DEPRECATED_PENALTY=0.40
KB_CANONICAL_ARCHIVED_PENALTY=0.60
KB_CANONICAL_AUDIT_ENABLED=true

# Knowledge graph
KB_GRAPH_EXPANSION_ENABLED=true
KB_GRAPH_EXPANSION_HOPS=1
KB_GRAPH_EXPANSION_MAX_NODES=20
KB_GRAPH_EXPANSION_EDGE_TYPES=depends_on,implements,decision_for,related_to,supersedes

# Anti-repetition memory
KB_REJECTED_INJECTION_ENABLED=true
KB_REJECTED_INJECTION_MAX_DOCS=3
KB_REJECTED_MIN_SIMILARITY=0.45

# Promotion
KB_PROMOTION_ENABLED=true
```

### When AskMyDocs Canonical is the right fit

Choose AskMyDocs Canonical **over a plain RAG** when:
- You care about **stable answers** for questions your team answers repeatedly.
- You need a **system of record** for architectural decisions, runbooks, standards.
- You want LLMs to **stop re-proposing** already-rejected options.
- You need **per-tenant** isolation and **audit trails**.

Choose AskMyDocs Canonical **over wiki + CLI-AI** (Obsidian / OmegaWiki + Claude CLI) when:
- You need a **scalable backend** with proper indexing, not in-memory file walks.
- You need **multi-tenant** separation.
- You need **multi-provider AI** with swappable transport (OpenAI, Anthropic, Gemini, OpenRouter, Regolo).
- You want **HTTP/MCP APIs** for cross-system integration, not a local CLI only.
- You need **enterprise auth**, logging, retention, auditability.
- You want the **wiki as source of truth AND** a scalable searchable projection.

Choose AskMyDocs Canonical **over SaaS** (Glean, Notion AI, ...) when:
- You need **on-prem / EU-sovereign** hosting.
- You refuse vendor lock-in and want your KB in **Git**.
- You want **open source** (MIT) with full control of the prompt surface.

### What this does NOT replace
- Your IDE / code editor. AskMyDocs is a knowledge system, not a developer tool.
- A formal ticketing system. Incident documents here are *post-incident* records.
- A human. The promotion gate is deliberate — LLM output is always a draft.

---

## Citations

Every assistant answer ships the **citations** — the source documents that provided the retrieval context. Users can verify claims at the source.

### How it works

1. The RAG system retrieves N chunks from M distinct documents.
2. Chunks are grouped by source document.
3. For each document we collect: title, path, heading paths, number of chunks used.
4. Citations are persisted on the assistant message metadata.
5. The frontend shows them as a collapsible section under the answer.

### Citation format (API)

```json
{
    "answer": "OAuth is configured by...",
    "citations": [
        {
            "document_id": 12,
            "title": "OAuth 2.0 Configuration",
            "source_path": "docs/auth/oauth.md",
            "headings": ["Prerequisites", "Client setup"],
            "chunks_used": 3
        },
        {
            "document_id": 8,
            "title": "Security Architecture",
            "source_path": "docs/security/overview.md",
            "headings": ["Token Management"],
            "chunks_used": 1
        }
    ],
    "meta": { ... }
}
```

### Frontend UI

Under every assistant reply a clickable "N sources" row appears:

```
Assistant: OAuth is configured by...
   ▶ 2 sources                         ← click to expand
   ┌─────────────────────────────────┐
   │ 📄 OAuth 2.0 Configuration     │
   │    docs/auth/oauth.md          │
   │    [Prerequisites] [Client...] │
   │                                 │
   │ 📄 Security Architecture       │
   │    docs/security/overview.md   │
   │    [Token Management]          │
   └─────────────────────────────────┘
   gpt-4o · 2340ms · 4 chunks
```

Citations are stored in the `metadata.citations` column of the `messages` table so they survive a conversation reload.

---

## API

### POST `/api/kb/chat`

Stateless endpoint to query the knowledge base (no conversation state).

**Headers**

| Header | Required | Description |
|---|---|---|
| `Authorization` | Yes | `Bearer {sanctum-token}` |
| `Content-Type` | Yes | `application/json` |
| `X-Session-Id` | No | UUID grouping messages of the same session |

**Request body**

```json
{
    "question": "How do I configure the auth system?",
    "project_key": "erp-core"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `question` | string | Yes | Natural-language question (max 10,000 chars) |
| `project_key` | string | No | Filters semantic search to a project |

**Response 200**

```json
{
    "answer": "To configure authentication in ERP-Core...",
    "citations": [
        {
            "document_id": 12,
            "title": "Auth Setup",
            "source_path": "docs/auth/setup.md",
            "headings": ["OAuth 2.0", "Prerequisites"],
            "chunks_used": 2
        }
    ],
    "meta": {
        "provider": "openai",
        "model": "gpt-4o",
        "chunks_used": 5,
        "latency_ms": 2340
    }
}
```

**Authentication**: Laravel Sanctum (Bearer token).

### POST `/api/kb/ingest`

Accepts one or many markdown documents and queues them for ingestion. Used by the shipped GitHub Action (Flow 2 in [Document Ingestion](#document-ingestion--two-flows)) and any client that can POST JSON.

**Headers**

| Header | Required | Description |
|---|---|---|
| `Authorization` | Yes | `Bearer {sanctum-token}` — same token format as `/api/kb/chat` |
| `Content-Type` | Yes | `application/json` |

**Request body** (single or batch — always an array, max 100 per call):

```json
{
    "documents": [
        {
            "project_key": "erp-core",
            "source_path": "docs/auth/oauth.md",
            "title": "OAuth 2.0 Setup",
            "content": "# OAuth 2.0\n\n...",
            "metadata": { "language": "en", "author": "team-auth" }
        }
    ]
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `documents` | array | Yes | 1 to 100 document descriptors |
| `documents[].project_key` | string | No | Falls back to `KB_INGEST_DEFAULT_PROJECT` |
| `documents[].source_path` | string | Yes | Relative path on the KB disk (e.g. `docs/auth/oauth.md`) |
| `documents[].content` | string | Yes | Raw markdown, up to ~5 MB |
| `documents[].title` | string | No | Defaults to the filename without extension |
| `documents[].metadata` | object | No | Free-form; persisted on `KnowledgeDocument.metadata` |

**Response 202** (the ingestion runs in the background):

```json
{
    "queued": 1,
    "documents": [
        { "project_key": "erp-core", "source_path": "docs/auth/oauth.md", "status": "queued" }
    ]
}
```

The controller writes every `content` to the configured KB disk at `{KB_PATH_PREFIX}/{source_path}` and dispatches one `App\Jobs\IngestDocumentJob` per document on the `KB_INGEST_QUEUE`. The worker — or `sync` if `QUEUE_CONNECTION=sync` — calls `DocumentIngestor::ingestMarkdown()`, which is idempotent (see [Idempotency & retries](#idempotency--retries)).

### DELETE `/api/kb/documents`

Removes one or many documents from the RAG store (chunks + embeddings + optionally the original file on the KB disk). Used by the shipped GitHub Action when it detects files removed from the consumer repo, and by any client that can send an HTTP `DELETE`.

**Headers** — identical to `/api/kb/ingest`.

**Request body** (batch, max 100 per call):

```json
{
    "force": false,
    "documents": [
        { "project_key": "erp-core", "source_path": "docs/auth/oauth.md" }
    ]
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `documents` | array | Yes | 1 to 100 descriptors |
| `documents[].project_key` | string | No | Falls back to `KB_INGEST_DEFAULT_PROJECT` |
| `documents[].source_path` | string | Yes | Same path that was sent to `/api/kb/ingest` |
| `force` | bool | No | When `true`, hard-deletes regardless of `KB_SOFT_DELETE_ENABLED`. Default follows the server config. |

**Response 200**:

```json
{
    "deleted": 1,
    "missing": 0,
    "documents": [
        {
            "project_key": "erp-core",
            "source_path": "docs/auth/oauth.md",
            "document_id": 42,
            "mode": "soft",
            "file_deleted": false,
            "status": "deleted"
        }
    ]
}
```

Unknown `source_path` values return `status: "not_found"` without failing the whole request — this keeps the GitHub Action green when a file was deleted from the repo but never existed in the KB. See [Document Deletion](#document-deletion) for the full flow.

---

## MCP Server

The MCP (Model Context Protocol) server exposes the knowledge base as a set of read-only tools so Claude and other AI agents can query it directly.

### Endpoint

```
/mcp/kb
```

Protected by `auth:sanctum` and `throttle:api`.

### Available tools

| Tool | Description | Parameters |
|---|---|---|
| `KbSearchTool` | Semantic search | `query` (required), `project_key`, `limit` |
| `KbReadDocumentTool` | Read a full document | `document_id` (required) |
| `KbReadChunkTool` | Read a single chunk | `chunk_id` (required) |
| `KbRecentChangesTool` | Recently indexed documents | `project_key`, `limit` |
| `KbSearchByProjectTool` | Search scoped to a project | `project_key`, `query`, `limit` |

### Claude Desktop / Claude Code integration

```bash
claude mcp add --transport http kb http://localhost:8000/mcp/kb \
    --header "Authorization: Bearer {token}"
```

---

## Document Ingestion — two flows

There are two ways to get markdown into the KB. Both end up calling the same `DocumentIngestor::ingestMarkdown()` — the only difference is **who** triggers the dispatch.

```
Flow 1 — Local / S3
   kb:ingest-folder docs/ --project=erp-core
        │ (walks disk, one file → one job)
        ▼
   IngestDocumentJob   ← queued on KB_INGEST_QUEUE
        │
        ▼
Flow 2 — Remote HTTP
   GitHub Action on push to main
        │ (diff docs/*.md → curl POST /api/kb/ingest)
        ▼
   KbIngestController
        │ (validate, write to disk, dispatch job)
        ▼
   IngestDocumentJob   ← same queue / same worker
        │
        ▼ (one execution path for both)
   DocumentIngestor::ingestMarkdown()
```

### Flow 1 — Local / S3 folder (queue-backed)

Drop markdown onto the configured KB disk (local filesystem, S3, MinIO, …) and run:

```bash
# Single file (legacy, synchronous)
php artisan kb:ingest docs/auth/setup.md --project=erp-core --title="Auth Setup"

# Whole folder — one queued job per file, resumable on worker restart
php artisan kb:ingest-folder docs/ --project=erp-core --recursive

# Preview without dispatching
php artisan kb:ingest-folder docs/ --project=erp-core --recursive --dry-run

# Only .markdown files, max 50, run inline (no queue)
php artisan kb:ingest-folder docs/ --project=erp-core --recursive \
    --pattern=markdown --limit=50 --sync
```

`kb:ingest-folder` options:

| Flag | Default | Description |
|---|---|---|
| `path` | _(empty — prefix root)_ | Folder on the KB disk, **always resolved relative to `KB_PATH_PREFIX`**. E.g. with `KB_PATH_PREFIX=tenant-a/` and `path=docs`, the command scans `tenant-a/docs/`. |
| `--project=` | `KB_INGEST_DEFAULT_PROJECT` | `project_key` stored on each document. |
| `--disk=` | `KB_FILESYSTEM_DISK` | Override the disk just for this run (e.g. `--disk=s3`). |
| `--pattern=` | `md,markdown` | Comma-separated extensions. |
| `--recursive` | off | Walk sub-directories with `allFiles()`. |
| `--sync` | off | Call the ingestor inline — skips the queue entirely. Handy for dev or when the batch is tiny. |
| `--limit=N` | `0` (unlimited) | Stop after N files. |
| `--dry-run` | off | List matches without dispatching jobs. |
| `--prune-orphans` | off | Delete documents in the same folder whose source file no longer exists on disk. Uses soft-delete by default (see [Document Deletion](#document-deletion)). |
| `--force-delete` | off | When combined with `--prune-orphans`, hard-deletes orphans (bypasses `KB_SOFT_DELETE_ENABLED`). |

With `QUEUE_CONNECTION=sync` (the default) every dispatch runs inline, so `kb:ingest-folder docs/` is effectively a progress-tracked version of running `kb:ingest` N times. With `database` or `redis` it returns immediately after enqueuing — start a worker with `php artisan queue:work --queue=kb-ingest`.

### Flow 2 — Remote push from another repo

When the documents live in another Git repo (typical: each project carries its own `docs/` folder) you don't need SSH, SFTP or MCP — just a bearer token and an HTTP POST.

1. Mint a Sanctum token on the AskMyDocs server:

   ```bash
   php artisan tinker --execute="echo \App\Models\User::first()->createToken('docs-ingest')->plainTextToken;"
   ```

2. On the consumer repo, add:
   - GitHub Actions **secret** `ASKMYDOCS_TOKEN` → the bearer token above.
   - GitHub Actions **variable** `ASKMYDOCS_URL` → e.g. `https://kb.example.com`.

3. Drop the workflow below into `.github/workflows/ingest-docs.yml` (copy is shipped at [`docs/examples/github-workflow-ingest.yml`](./docs/examples/github-workflow-ingest.yml)):

   ```yaml
   name: Push docs to AskMyDocs
   on:
     push:
       branches: [main]
       paths: ['docs/**/*.md']
   jobs:
     ingest:
       runs-on: ubuntu-latest
       steps:
         - uses: actions/checkout@v4
           with: { fetch-depth: 2 }
         - uses: padosoft/askmydocs/.github/actions/ingest-to-askmydocs@main
           with:
             server_url:  ${{ vars.ASKMYDOCS_URL }}
             api_token:   ${{ secrets.ASKMYDOCS_TOKEN }}
             project_key: my-project
             docs_path:   docs/
   ```

Every push to `main` now diffs `docs/**/*.md` against the previous commit and POSTs the changed files in batches of 50 (configurable, API hard-cap 100) to `POST /api/kb/ingest`. The controller persists each payload onto the KB disk and queues one `IngestDocumentJob` per document, so the ingestion throttles itself behind the worker — GitHub runners return in seconds regardless of batch size.

The action also detects **deletions** on the same diff (files removed or renamed between `HEAD^` and `HEAD`) and batches them to `DELETE /api/kb/documents` so the RAG store stays in sync. Deletion behaviour (soft vs hard) follows the server-side `KB_SOFT_DELETE_ENABLED` flag; set the action input `force_delete: 'true'` to force a hard delete from the consumer repo — see [Document Deletion](#document-deletion).

Set the action input `full_sync: 'true'` to push every markdown file (useful on first adoption or after a catastrophic KB wipe).

### Queue drivers

| Driver | Setup | When to pick it |
|---|---|---|
| `sync` | Nothing — it's the default. | Dev / tiny corpora. Every dispatch blocks the caller. |
| `database` | `php artisan migrate` (ships the `jobs` + `failed_jobs` tables), then a worker `php artisan queue:work --queue=kb-ingest`. | Zero-infra production up to a few thousand jobs/day. |
| `redis` | `composer require predis/predis`, set `QUEUE_CONNECTION=redis`, run `php artisan queue:work`. Optional UI: `composer require laravel/horizon && php artisan horizon`. | High-throughput production with fan-out and retry visibility. |

All three honour the same `KB_INGEST_QUEUE` name (default `kb-ingest`) — swap the driver without touching application code.

Supervisor snippet for a production worker (redis or database):

```ini
[program:askmydocs-kb-ingest]
command=php /var/www/askmydocs/artisan queue:work --queue=kb-ingest --sleep=3 --tries=3 --timeout=300
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/askmydocs/kb-ingest.log
stopwaitsecs=330
```

### GitHub Action (reusable)

The repo ships a composite action at [`.github/actions/ingest-to-askmydocs/action.yml`](./.github/actions/ingest-to-askmydocs/action.yml). It:

1. Reads the changed markdown under `docs_path` via `git diff --name-only HEAD^..HEAD` (falls back to `find` when there is no parent commit or `full_sync=true`).
2. Batches the files (default 50 per POST, capped at 100).
3. `curl`s each batch to `$server_url/api/kb/ingest` with the `Authorization: Bearer $api_token` header.
4. Fails the job on any non-2xx response, so a broken push never silently skips documents.

Inputs:

| Input | Required | Default | Description |
|---|---|---|---|
| `server_url` | Yes | — | Base URL of the AskMyDocs server. |
| `api_token` | Yes | — | Sanctum bearer token (must be allowed to hit `/api/kb/ingest` **and** `/api/kb/documents`). |
| `project_key` | Yes | — | `project_key` written on every ingested document. |
| `docs_path` | No | `docs/` | Folder (relative to the repo root) to watch. |
| `pattern` | No | `*.md` | Extension filter for the `full_sync` path. |
| `full_sync` | No | `false` | Ignore the diff and push every matching file. Deletions are **not** detected in full-sync mode — rely on [Orphan pruning](#orphan-pruning-on-resync) instead. |
| `batch_size` | No | `50` | Documents per POST (max 100). |
| `force_delete` | No | `false` | When `true`, deletions are sent with `{"force": true}` so the server hard-deletes (ignores `KB_SOFT_DELETE_ENABLED`). |

### Idempotency & retries

Every document is hashed (SHA-256 over the markdown) before insert. The `KnowledgeDocument` table has a unique `(project_key, source_path, version_hash)` constraint, so **re-pushing identical content is a no-op** — the chunks and embeddings survive untouched.

Combined with `$tries = 3` and an exponential backoff of `[10, 30, 60]` seconds on `IngestDocumentJob`, this gives you:

- Safe retries — a transient 500 from the LLM provider never produces duplicates.
- Safe duplicate dispatches — if the GitHub Action fires twice (e.g. a squash-merge + a tag push), the second run just bumps `indexed_at`.
- Safe worker restarts — a SIGTERM mid-job simply re-enqueues it.

### Via code (still available)

```php
use App\Services\Kb\DocumentIngestor;

app(DocumentIngestor::class)->ingestMarkdown(
    projectKey: 'erp-core',
    sourcePath: 'docs/auth/setup.md',
    title: 'Auth Setup',
    markdown: Storage::disk('kb')->get('docs/auth/setup.md'),
    metadata: ['language' => 'en', 'author' => 'team-auth'],
);
```

---

## Document Deletion

Original markdown is persisted on the configured KB disk (local, S3, …) when it is first ingested — the `DocumentIngestor` reads, chunks and embeds it, but never removes the source file. Deleting a document therefore touches **three** places that must stay in sync:

1. The `knowledge_documents` row and its metadata.
2. The `knowledge_chunks` rows (and their pgvector embeddings).
3. The original file on the KB disk.

`App\Services\Kb\DocumentDeleter` is the single entry point that keeps those three in sync for every deletion flow (artisan, API, orphan pruning, scheduled purge).

### Soft vs hard delete

Two env vars drive the default behaviour for every deletion path:

| Env | Default | Purpose |
|---|---|---|
| `KB_SOFT_DELETE_ENABLED` | `true` | When `true`, `kb:delete` / the DELETE endpoint mark the document as deleted (cascade soft-delete) and **keep the original file** on disk. When `false`, deletions are immediately hard. |
| `KB_SOFT_DELETE_RETENTION_DAYS` | `30` | How many days a soft-deleted document survives before `kb:prune-deleted` hard-removes it (file + row + chunks). |

Because `KnowledgeDocument` uses Eloquent's `SoftDeletes` trait, a soft-deleted document is automatically hidden from `KbSearchService`, the MCP tools and every controller query — no changes are needed in the chat path. You can always recover it with `KnowledgeDocument::withTrashed()->restore()` within the retention window.

### Artisan command

```bash
# Default: honours KB_SOFT_DELETE_ENABLED
php artisan kb:delete docs/auth/oauth.md --project=erp-core

# Force a hard delete (DB + chunks + file) regardless of config
php artisan kb:delete docs/auth/oauth.md --project=erp-core --force

# Force a soft delete even when KB_SOFT_DELETE_ENABLED=false
php artisan kb:delete docs/auth/oauth.md --project=erp-core --soft
```

Signature:

| Arg / flag | Default | Description |
|---|---|---|
| `path` | — | Source path relative to `KB_PATH_PREFIX` (matches `KnowledgeDocument.source_path`). |
| `--project=` | `KB_INGEST_DEFAULT_PROJECT` | Project scope. |
| `--force` | off | Hard delete (DB + file). |
| `--soft` | off | Soft delete (deleted_at only). Mutually exclusive with `--force`. |

Returns exit code 1 when no matching document is found so CI pipelines can flag typos.

### Orphan pruning on resync

When you re-sync a whole folder (Flow 1), the ingestor has no way to know about files that were **removed** from the source since the last run. Pass `--prune-orphans` to `kb:ingest-folder` to delete every document under that folder whose source file no longer exists on disk:

```bash
# Soft-delete the orphans (default — uses KB_SOFT_DELETE_ENABLED)
php artisan kb:ingest-folder docs/ --project=erp-core --recursive --prune-orphans

# Hard-delete the orphans (bypass the soft-delete default)
php artisan kb:ingest-folder docs/ --project=erp-core --recursive --prune-orphans --force-delete
```

The scope is always the folder passed as the first argument — siblings outside of it are never touched. An empty folder still triggers the sweep (useful when every file was just removed from the repo).

### DELETE API endpoint

The `DELETE /api/kb/documents` endpoint (see the [API](#api) section) exposes the same pipeline over HTTP so remote pushers — typically the shipped GitHub Action — can keep the RAG store in sync with a repository without needing shell access.

Minimal cURL example:

```bash
curl -X DELETE "$ASKMYDOCS_URL/api/kb/documents" \
    -H "Authorization: Bearer $ASKMYDOCS_TOKEN" \
    -H "Content-Type: application/json" \
    --data '{"documents":[{"project_key":"erp-core","source_path":"docs/auth/oauth.md"}]}'
```

### GitHub Action integration

The composite action at `.github/actions/ingest-to-askmydocs/action.yml` now runs a second pass that detects **removed** and **renamed** markdown files between `HEAD^` and `HEAD` (via `git diff --diff-filter=D` and `--diff-filter=R`) and batches them to `DELETE /api/kb/documents` with the same batch size as ingestion. The run fails on any non-2xx response, so an expired token or a rejected path produces a red CI badge instead of silent drift.

By default the deletion inherits the server-side `KB_SOFT_DELETE_ENABLED` flag. Set the action input `force_delete: 'true'` when you want a consumer repo to always hard-delete (e.g. compliance deletion of sensitive docs):

```yaml
- uses: padosoft/askmydocs/.github/actions/ingest-to-askmydocs@main
  with:
    server_url:   ${{ vars.ASKMYDOCS_URL }}
    api_token:    ${{ secrets.ASKMYDOCS_TOKEN }}
    project_key:  my-project
    docs_path:    docs/
    force_delete: 'true'
```

### Scheduled retention sweep

The scheduled `kb:prune-deleted` command (see [Scheduler](#scheduler)) runs daily at 03:30, finds every soft-deleted document whose `deleted_at` is older than `KB_SOFT_DELETE_RETENTION_DAYS`, and **hard-deletes** it — the row disappears from `withTrashed()` queries, the chunks are wiped via the FK cascade, and the original file is removed from the KB disk.

Set `KB_SOFT_DELETE_RETENTION_DAYS=0` to disable the sweep entirely (soft-deleted rows live forever). You can also invoke it ad-hoc:

```bash
php artisan kb:prune-deleted            # uses KB_SOFT_DELETE_RETENTION_DAYS
php artisan kb:prune-deleted --days=14  # one-off override
```

---

## Extending

### Add a new AI provider

1. Create `app/Ai/Providers/NewProvider.php` implementing `AiProviderInterface`.
2. Implement `chat()`, `chatWithHistory()`, `generateEmbeddings()`, `name()`, `supportsEmbeddings()`.
3. Add a case to the `match` in `AiManager::resolve()`.
4. Add a `providers.new` block to `config/ai.php`.
5. Add env defaults to `.env.example`.
6. Mirror `tests/Unit/Ai/OpenAiProviderTest.php` for test coverage.

### Add a chat-log driver

1. Create `app/Services/ChatLog/Drivers/NewDriver.php` implementing `ChatLogDriverInterface`.
2. Implement `store(ChatLogEntry $entry): void`.
3. Add a case to `ChatLogManager::resolveDriver()`.
4. Add config in `config/chat-log.php`.

### Add an MCP tool

1. Create `app/Mcp/Tools/NewTool.php` extending `Laravel\Mcp\Server\Tool`.
2. Define schema and handler.
3. Register the class on `KnowledgeBaseServer::$tools`.

---

## How RAG works

RAG (Retrieval-Augmented Generation) is the architectural pattern behind this system. Instead of relying only on the model's baked-in knowledge, we retrieve relevant passages from the company KB and inject them into the prompt, producing accurate, up-to-date, source-traceable answers.

### Full request lifecycle

```
User: "How do I configure OAuth in the ERP module?"
                │
                ▼
┌─── 1. QUERY EMBEDDING ──────────────────────────────────────┐
│  AiManager computes a numeric embedding vector using the    │
│  configured embeddings provider.                            │
│  E.g. OpenAI text-embedding-3-small → 1536-dim vector.      │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─── 2. SEMANTIC SEARCH + RERANK ─────────────────────────────┐
│  pgvector compares the vector against every chunk (cosine). │
│  Over-retrieval: 3× candidates (e.g. 24).                   │
│  Reranker fuses vector + keyword + heading.                 │
│  Result: top-K most relevant chunks (default: 8).           │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─── 3. PROMPT COMPOSITION ───────────────────────────────────┐
│  Retrieved chunks are injected into the system prompt       │
│  (Blade template: resources/views/prompts/kb_rag.blade.php).│
│  The prompt enforces strict rules:                          │
│  - Answer ONLY based on the provided context                │
│  - Include citations (document, path, heading)              │
│  - Say so explicitly if context is insufficient             │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─── 4. MODEL CALL ───────────────────────────────────────────┐
│  AiManager::chat() sends the prompt to the configured       │
│  provider. The model grounds its answer in the context      │
│  (no hallucination). Response includes content, token       │
│  usage, finish_reason.                                      │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─── 5. LOGGING (optional) ───────────────────────────────────┐
│  If CHAT_LOG_ENABLED=true, the interaction is persisted     │
│  (question, answer, provider, model, tokens, latency, IP,   │
│  chunks). Guarded by try/catch — logging errors never       │
│  propagate to the user response.                            │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
             JSON response to the client
```

### Why RAG over fine-tuning?

| Aspect | RAG | Fine-tuning |
|---|---|---|
| **Data updates** | Instant (re-ingest) | Needs re-training |
| **Traceability** | Exact citations | None |
| **Cost** | Low (API calls only) | High (training + hosting) |
| **Hallucination** | Controlled (grounded) | Higher risk |
| **Multi-tenant** | Filter by `project_key` | One model per tenant |

---

## How the Multi-Provider AI layer works

The system deliberately does not depend on any AI SDK. Every provider is called via `Illuminate\Support\Facades\Http`, giving full control over auth, retries, timeouts, and response parsing.

### AI layer architecture

```
AiManager (singleton)
    │
    ├── provider('openai')     → OpenAiProvider
    ├── provider('anthropic')  → AnthropicProvider
    ├── provider('gemini')     → GeminiProvider
    ├── provider('openrouter') → OpenRouterProvider
    └── provider('regolo')     → RegoloProvider
    │
    ├── chat()                 → uses the default provider
    ├── generateEmbeddings()   → uses the embeddings provider
    └── embeddingsProvider()   → resolves the embeddings provider
```

### Separate chat vs embeddings providers

Chat and embeddings **can** use different providers. This is useful because:

- **Anthropic** (Claude) is excellent for generation but has no embeddings endpoint.
- **OpenRouter** is a multi-model gateway for chat, not for embeddings.
- **OpenAI**, **Gemini**, and **Regolo** support both.

Typical enterprise setup:

```env
AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-...

AI_EMBEDDINGS_PROVIDER=openai
OPENAI_API_KEY=sk-...
```

### Provider compatibility matrix

| Provider | Chat | Embeddings | Notes |
|---|---|---|---|
| **OpenAI** | Yes | Yes | Reference implementation. |
| **Anthropic** | Yes | No | Requires a separate embeddings provider. |
| **Gemini** | Yes | Yes | Embedding dim differs from OpenAI (768 vs 1536). |
| **OpenRouter** | Yes | No | Multi-model gateway. Requires a separate embeddings provider. |
| **Regolo.ai** | Yes | Yes | EU-based, OpenAI-compatible REST. |

### Response DTOs

Every AI call returns a typed DTO:

- **`AiResponse`** — `content`, `provider`, `model`, `promptTokens`, `completionTokens`, `totalTokens`, `finishReason`
- **`EmbeddingsResponse`** — `embeddings` (list of float vectors), `provider`, `model`, `totalTokens`

The controller and chat log rely on these to automatically track which provider/model produced each response.

---

## How Chat Logging works

The chat logging service is a **standalone, driver-based service** following the same pattern Laravel uses for filesystem, cache, and queue.

### Architecture

```
KbChatController
    │
    ▼
ChatLogManager::log(ChatLogEntry)
    │
    ├── enabled? → No → return (no-op)
    │
    ├── resolveDriver() → configured driver
    │      │
    │      ├── 'database' → DatabaseChatLogDriver
    │      │                    └── ChatLog::create(...)
    │      ├── 'bigquery' → (stub — implement on demand)
    │      └── 'cloudwatch' → (stub — implement on demand)
    │
    └── try/catch → errors are logged to the standard logger, never propagated
```

### Why a dedicated service instead of Monolog?

Chat data is **structured data** (question, answer, token count, latency, IP), not textual log lines. A dedicated service enables:

- **Direct SQL queries**: "how many requests for project X this month?"
- **Structured analytics**: cost per provider, average latency, chunk usage.
- **Clean export**: to BI, dashboards, monitoring.
- **Separation of concerns**: app logs go to Monolog; interactions go to `chat_logs`.

### `chat_logs` table

```sql
id                  BIGINT PK AUTO
session_id          UUID (indexed)
user_id             FK nullable → users
question            TEXT
answer              TEXT
project_key         VARCHAR(120) nullable (indexed)
ai_provider         VARCHAR(64) (indexed) — openai, anthropic, gemini, openrouter, regolo
ai_model            VARCHAR(128) (indexed)
chunks_count        SMALLINT
sources             JSON
prompt_tokens       INT nullable
completion_tokens   INT nullable
total_tokens        INT nullable
latency_ms          INT
client_ip           VARCHAR(45) nullable
user_agent          VARCHAR(512) nullable
extra               JSON nullable
created_at          TIMESTAMP (indexed)
```

### Example queries

```sql
-- Average cost per provider this month
SELECT ai_provider, ai_model,
       COUNT(*) as requests,
       AVG(total_tokens) as avg_tokens,
       AVG(latency_ms) as avg_latency_ms
FROM chat_logs
WHERE created_at >= NOW() - INTERVAL '30 days'
GROUP BY ai_provider, ai_model
ORDER BY requests DESC;

-- Longest sessions
SELECT session_id, COUNT(*) as messages,
       MIN(created_at) as started_at,
       MAX(created_at) as ended_at
FROM chat_logs
GROUP BY session_id
HAVING COUNT(*) > 1
ORDER BY messages DESC;

-- Chunk usage distribution
SELECT chunks_count, COUNT(*) as frequency
FROM chat_logs
GROUP BY chunks_count
ORDER BY chunks_count;
```

---

## Quick Start: 10-minute onboarding (clone → working SPA)

**Audience:** a junior developer who has just cloned the repo and wants to see the
React SPA, the chat, and the admin dashboard running locally with seeded demo data.

### Prerequisites (install these BEFORE step 1)

| Tool | Minimum version | Why |
|---|---|---|
| **PHP** | `8.3` | Laravel 13 floor |
| **Composer** | `2.x` | PHP package manager |
| **PostgreSQL** | `15` (or higher) | Required — the `vector(N)` columns and the FTS GIN index are pgsql-only |
| **`pgvector` extension** | matching your PG | `CREATE EXTENSION vector;` (see below) |
| **Node.js** | `20` LTS | The React SPA build pipeline (Vite) |
| **npm** | bundled with Node | JS deps |

Quick PostgreSQL + pgvector setup (Docker is the fastest path):

```bash
# Option A — Docker (preinstalled pgvector image, recommended)
docker run -d --name askmydocs-pg \
    -e POSTGRES_USER=askmydocs \
    -e POSTGRES_PASSWORD=askmydocs \
    -e POSTGRES_DB=askmydocs \
    -p 5432:5432 \
    pgvector/pgvector:pg16

# Option B — local PostgreSQL: enable pgvector inside the database
psql -U postgres -d askmydocs -c "CREATE EXTENSION IF NOT EXISTS vector;"
```

### Step-by-step

```bash
# ─── 1. Clone + install PHP deps ────────────────────────────────────
git clone https://github.com/lopadova/AskMyDocs.git
cd AskMyDocs
composer install

# ─── 2. Install JS deps + build the React SPA ───────────────────────
# Without this step the /app/* routes return a blank page (the Vite
# manifest is missing). DO NOT SKIP.
npm ci
npm run build

# ─── 3. Configure .env ──────────────────────────────────────────────
cp .env.example .env
php artisan key:generate

# Edit .env and set:
#   DB_CONNECTION=pgsql
#   DB_HOST=127.0.0.1
#   DB_PORT=5432
#   DB_DATABASE=askmydocs
#   DB_USERNAME=askmydocs
#   DB_PASSWORD=askmydocs
#
#   AI_PROVIDER=openrouter
#   OPENROUTER_API_KEY=sk-or-...        # https://openrouter.ai/keys
#   AI_EMBEDDINGS_PROVIDER=openai
#   OPENAI_API_KEY=sk-...               # required ONLY for embeddings

# ─── 4. Migrate + seed RBAC + create demo data ──────────────────────
php artisan migrate

# RbacSeeder creates the 4 baseline roles (super-admin, admin, editor,
# viewer) + every permission used by the SPA. WITHOUT this, a logged-in
# user has zero permissions and the admin pages return 403.
php artisan db:seed --class=RbacSeeder

# DemoSeeder creates 3 ready-to-use demo accounts + 3 canonical KB
# documents + a small graph + sample chat logs. Skip this only if you
# want a totally empty KB.
php artisan db:seed --class=DemoSeeder

# Demo accounts created by DemoSeeder (password is the same: "password"):
#   super@demo.local     → super-admin (sees + can run destructive maintenance commands)
#   admin@demo.local     → admin       (sees admin pages, non-destructive only)
#   viewer@demo.local    → viewer      (sees /app/chat only)

# ─── 5. Start the dev server ────────────────────────────────────────
php artisan serve
```

Open <http://localhost:8000> in your browser:

- Log in with `super@demo.local` / `password`
- The SPA redirects to <http://localhost:8000/app/chat> — the React chat UI
- Click **Dashboard** in the sidebar to land on `/app/admin` — the KPI dashboard,
  populated by the DemoSeeder's chat logs and 3 canonical docs
- Click **Knowledge** to open the KB tree explorer; expand `policies/` and click
  `remote-work-policy.md` to see Preview / Source (CodeMirror editor) / Graph /
  History

**That's it — clone to working SPA in under 10 minutes.**

### What if I want to use my own credentials instead of the demo accounts?

```bash
php artisan tinker --execute="
    \$u = \App\Models\User::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => bcrypt('your-strong-password'),
    ]);
    \$u->assignRole('super-admin');
    echo 'Created user with super-admin role';
"
```

⚠ The `assignRole()` call is **not optional** — `User::create()` alone gives you a
user with zero permissions, who will get 403s on every admin page. Always assign
at least one role.

### What if I want to skip DemoSeeder and ingest my own docs?

```bash
# Drop your markdown files under storage/app/kb/ (the default `kb` disk),
# or set KB_FILESYSTEM_DISK in .env to `s3`, `r2`, `gcs`, or `minio` to
# use an object-storage backend. KB_DISK_DRIVER only configures the
# built-in `kb` disk's driver/root and is not the backend selector.

# Then walk the folder and ingest every .md (or .markdown) file:
php artisan kb:ingest-folder docs/ --project=my-project --recursive

# For a small batch (≤ 100 docs), the synchronous mode is fine:
php artisan kb:ingest-folder docs/ --project=my-project --recursive --sync

# For a real ingest, run a queue worker in a second terminal:
php artisan queue:work --queue=kb-ingest
```

### Troubleshooting the first-run

| Symptom | Likely cause | Fix |
|---|---|---|
| `/app/*` shows a blank page | The React bundle is missing | `npm ci && npm run build` (re-run after every git pull) |
| `/app/admin` returns 403 even as the seeded admin | `RbacSeeder` was skipped — the user has no `admin` / `super-admin` role | `php artisan db:seed --class=RbacSeeder` then re-login |
| `vector` column type errors during migrate | `pgvector` extension is not installed in the database | `psql -d askmydocs -c "CREATE EXTENSION vector;"` |
| `OPENROUTER_API_KEY missing` on first chat | `.env` not picked up | Ensure you ran `php artisan config:clear` (or the app is in `APP_ENV=local` where caching is off by default) |
| Empty dashboard charts | Cache layer is warm with empty data | Refetch in 30s, or `php artisan cache:clear` |
| `php artisan serve` exits immediately on Windows | Built-in PHP server can't bind 8000 | `php artisan serve --port=8001` and update `APP_URL` |

For programmatic API access (Sanctum token):

```bash
php artisan tinker --execute="echo \App\Models\User::first()->createToken('api')->plainTextToken;"

curl -X POST http://localhost:8000/api/kb/chat \
  -H 'Authorization: Bearer {token}' \
  -H 'Content-Type: application/json' \
  -d '{"question": "How does the system work?"}'
```

Enable chat logging:

```env
CHAT_LOG_ENABLED=true
```

Switch to Claude:

```env
AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-...
AI_EMBEDDINGS_PROVIDER=openai
```

Enable hybrid search (useful if documents contain product codes, acronyms, legal refs):

```env
KB_HYBRID_SEARCH_ENABLED=true
KB_FTS_LANGUAGE=italian
```

### Quick Start: ingest a whole folder (onboarding recipe)

You have a `docs/` folder full of markdown and want everything in the KB — no UI clicking, no one-by-one runs.

```bash
# 1. Tell the app where to find the markdown. Easiest: drop it under
#    storage/app/kb (the built-in `kb` disk) or point to S3 via KB_DISK_DRIVER.
cp -r ~/my-project/docs/. storage/app/kb/docs/

# 2. Pick a queue driver. For dev, keep the default:
#      QUEUE_CONNECTION=sync     # every dispatch is inline — no worker needed
#    For a real batch on prod, switch to database (or redis) and start a worker:
#      QUEUE_CONNECTION=database
#      php artisan migrate
#      php artisan queue:work --queue=kb-ingest &

# 3. Walk the folder and enqueue one job per markdown file.
php artisan kb:ingest-folder docs/ --project=my-project --recursive

# 4. (Optional) Preview first. Lists every match without touching the DB:
php artisan kb:ingest-folder docs/ --project=my-project --recursive --dry-run

# 5. (Optional) Skip the queue altogether for a small batch:
php artisan kb:ingest-folder docs/ --project=my-project --recursive --sync
```

Re-running the command is safe — unchanged documents are detected by SHA-256 and skipped.

### Quick Start: push docs from another repo on every commit

Want your product repo to keep its `docs/` folder in sync with AskMyDocs automatically? Two steps.

```bash
# On the AskMyDocs server — mint a bearer token:
php artisan tinker --execute="echo \App\Models\User::first()->createToken('docs-ingest')->plainTextToken;"
```

Then on the **consumer** repo (e.g. `acme/erp-core`):

1. Settings → Secrets and variables → Actions:
   - secret `ASKMYDOCS_TOKEN` → the token from step 1.
   - variable `ASKMYDOCS_URL` → `https://kb.example.com`.
2. Add `.github/workflows/ingest-docs.yml`:
   ```yaml
   name: Push docs to AskMyDocs
   on:
     push:
       branches: [main]
       paths: ['docs/**/*.md']
   jobs:
     ingest:
       runs-on: ubuntu-latest
       steps:
         - uses: actions/checkout@v4
           with: { fetch-depth: 2 }
         - uses: padosoft/askmydocs/.github/actions/ingest-to-askmydocs@main
           with:
             server_url:  ${{ vars.ASKMYDOCS_URL }}
             api_token:   ${{ secrets.ASKMYDOCS_TOKEN }}
             project_key: erp-core
             docs_path:   docs/
   ```

Every push to `main` now diffs the markdown in `docs/`, POSTs the changes, and the server queues them for RAG. See the full walkthrough in [Flow 2 — Remote push from another repo](#flow-2--remote-push-from-another-repo).

---

## Directory layout

```
askmydocs/
├── app/
│   ├── Ai/
│   │   ├── AiManager.php                    # Multi-provider manager (singleton)
│   │   ├── AiProviderInterface.php          # Contract for all providers
│   │   ├── AiResponse.php                   # Chat response DTO
│   │   ├── EmbeddingsResponse.php           # Embeddings response DTO
│   │   ├── Agents/
│   │   │   └── KbAssistant.php              # RAG agent (prompt builder)
│   │   └── Providers/
│   │       ├── OpenAiProvider.php            # OpenAI (chat + embeddings)
│   │       ├── AnthropicProvider.php         # Anthropic (chat only)
│   │       ├── GeminiProvider.php            # Gemini (chat + embeddings)
│   │       ├── OpenRouterProvider.php        # OpenRouter (chat, multi-model)
│   │       └── RegoloProvider.php            # Regolo.ai (chat + embeddings, EU)
│   ├── Console/
│   │   └── Commands/
│   │       ├── KbIngestCommand.php           # Single-file ingestion CLI
│   │       ├── KbIngestFolderCommand.php     # Folder walker → queued jobs
│   │       ├── PruneEmbeddingCacheCommand.php
│   │       └── PruneChatLogsCommand.php
│   ├── Http/Controllers/
│   │   ├── Auth/
│   │   │   ├── LoginController.php          # Login / logout (redirect to /chat)
│   │   │   └── PasswordResetController.php  # Forgot / reset password
│   │   ├── ChatController.php               # Chat UI
│   │   └── Api/
│   │       ├── KbChatController.php         # Stateless chat API (Sanctum)
│   │       ├── KbIngestController.php       # Remote ingestion API (Sanctum)
│   │       ├── ConversationController.php   # Conversations CRUD
│   │       ├── MessageController.php        # Messages + AI response
│   │       └── FeedbackController.php       # Thumbs up/down rating
│   ├── Jobs/
│   │   └── IngestDocumentJob.php            # ShouldQueue — reads disk, calls DocumentIngestor
│   ├── Mcp/
│   │   ├── Servers/KnowledgeBaseServer.php   # MCP server
│   │   └── Tools/                            # 5 read-only MCP tools
│   ├── Models/
│   │   ├── User.php
│   │   ├── Conversation.php
│   │   ├── Message.php
│   │   ├── KnowledgeDocument.php
│   │   ├── KnowledgeChunk.php
│   │   ├── ChatLog.php
│   │   └── EmbeddingCache.php
│   ├── Providers/
│   │   ├── AppServiceProvider.php           # Registers console commands
│   │   ├── AiServiceProvider.php
│   │   └── ChatLogServiceProvider.php
│   └── Services/
│       ├── ChatLog/
│       │   ├── ChatLogDriverInterface.php
│       │   ├── ChatLogEntry.php              # Immutable DTO
│       │   ├── ChatLogManager.php
│       │   └── Drivers/
│       │       └── DatabaseChatLogDriver.php
│       └── Kb/
│           ├── DocumentIngestor.php
│           ├── KbSearchService.php           # Hybrid search + reranking
│           ├── EmbeddingCacheService.php
│           ├── FewShotService.php
│           ├── Reranker.php
│           └── MarkdownChunker.php
├── bootstrap/
│   ├── app.php                              # Laravel 13 bootstrap + schedule
│   └── providers.php
├── config/
│   ├── ai.php                                # Multi-provider config
│   ├── chat-log.php
│   ├── filesystems.php                      # Disks (local, kb, s3)
│   ├── kb.php                                # KB + reranking + retention + ingest queue
│   └── queue.php                             # Queue driver connections (sync/database/redis)
├── .github/
│   └── actions/
│       └── ingest-to-askmydocs/action.yml    # Reusable composite GitHub Action
├── docs/examples/
│   └── github-workflow-ingest.yml            # Drop-in consumer workflow example
├── database/migrations/
│   ├── ..._create_users_table.php
│   ├── ..._create_knowledge_documents_table.php
│   ├── ..._create_knowledge_chunks_table.php
│   ├── ..._create_chat_logs_table.php
│   ├── ..._create_conversations_table.php
│   ├── ..._create_messages_table.php
│   ├── ..._create_embedding_cache_table.php
│   ├── ..._add_rating_to_messages_table.php
│   └── ..._add_fts_gin_index_to_knowledge_chunks.php   # pgvector GIN index
├── resources/
│   ├── js/
│   │   └── rich-content.mjs                 # Chart/action parser (Vitest-tested)
│   └── views/
│       ├── layouts/app.blade.php             # Base layout (Tailwind CDN)
│       ├── auth/
│       ├── chat.blade.php                    # Chat UI (Alpine.js + STT)
│       └── prompts/
│           └── kb_rag.blade.php              # System prompt template
├── routes/
│   ├── web.php                               # Auth + chat + AJAX
│   ├── api.php                               # Sanctum API
│   ├── ai.php                                # MCP server (/mcp/kb)
│   └── console.php                           # Closure commands placeholder
├── .env.example
├── artisan
├── composer.json
├── package.json
├── phpunit.xml
├── vitest.config.mjs
└── README.md
```

---

## Environment variables

The complete reference is in [`.env.example`](./.env.example). Every variable is documented inline with defaults tuned for a low-cost production setup.

---

## Testing

The test suite covers the core components: DTOs, AI providers, reranker, chunker, embedding cache, few-shot learning, chat logging, scheduled commands (prune, rotate, ingest), the Regolo provider, the FTS migration, the login flow, and the frontend rich-content parser.

### Stack

| Layer | Tool | Scope |
|---|---|---|
| **PHP** | PHPUnit 12 + Orchestra Testbench 11 + Mockery | Unit + feature tests (SQLite in-memory) |
| **JS** | Vitest 2 | Pure tests for `resources/js/rich-content.mjs` |

### Install dependencies

```bash
# PHP
composer install

# JavaScript
npm install
```

### Run the tests

```bash
# Full PHP suite
vendor/bin/phpunit

# Unit only
vendor/bin/phpunit --testsuite Unit

# Feature only (DB-backed)
vendor/bin/phpunit --testsuite Feature

# Readable testdox output
vendor/bin/phpunit --testdox

# JS suite
npm test

# Vitest watch mode
npm run test:watch
```

### Test configuration

- **Database**: SQLite in-memory. Production migrations use `pgvector` (not SQLite-compatible), so dedicated migrations in `tests/database/migrations/` replace `vector(...)` with JSON text. This lets embedding cache, FewShot, and command tests run without PostgreSQL.
- **HTTP**: every AI provider call (OpenAI, Anthropic, Gemini, OpenRouter, Regolo) is intercepted with `Http::fake()` — no external calls during tests.
- **Test env vars**: set directly in `phpunit.xml` (`<php><env ... /></php>`). No `.env.testing` required.

### Layout

```
tests/
├── bootstrap.php                          # Ensures Testbench cache dir exists
├── TestCase.php                           # Orchestra Testbench base
├── Unit/
│   ├── Ai/                                # DTOs, AiManager, providers (OpenAI/Anthropic/Gemini/OpenRouter/Regolo)
│   ├── ChatLog/                           # ChatLogEntry DTO
│   ├── Kb/                                # MarkdownChunker, Reranker
│   └── Migrations/                        # FTS GIN migration safety
├── Feature/
│   ├── Api/                               # KbIngestController (validation, disk write, dispatch)
│   ├── Auth/                              # Login redirect regression
│   ├── ChatLog/                           # ChatLogManager (persist, error swallowing)
│   ├── Commands/                          # kb:ingest, kb:ingest-folder, prune commands
│   ├── Jobs/                              # IngestDocumentJob (queue retries, disk read, metadata)
│   └── Kb/                                # EmbeddingCacheService, FewShotService
├── database/migrations/                   # SQLite-compatible schema
└── js/
    └── rich-content.spec.mjs
```

### Current coverage

- 115 PHPUnit tests, 317 assertions
- 18 Vitest tests

---

## Continuous Integration

A GitHub Actions workflow at `.github/workflows/tests.yml` runs both test suites on every push to `main` and on every pull request. The job:

1. Installs PHP 8.3 with required extensions.
2. Caches Composer and npm dependencies.
3. `composer install --prefer-dist`.
4. `npm ci`.
5. Runs `vendor/bin/phpunit`.
6. Runs `npm test`.

No secrets are required — all provider HTTP calls are faked at the unit level.

---

## License

This project is open-source under the [MIT License](LICENSE).

You are free to use, modify, and distribute it for any purpose, including commercial use.

---

## Enterprise rules

This repository has 21 codified review rules (R1..R21) that every PR
must satisfy. They are distilled from ~110 live Copilot review
findings across the PR #16..#31 enterprise-enhancement series and
mirror in three files for different consumers:

- **`CLAUDE.md §7`** — the canonical R1..R21 list, authored for
  Claude Code. Each rule: one-line imperative + "why this exists"
  paragraph citing the representative PR + SHA + check-list + pointer
  to the skill that enforces it.
- **`.github/copilot-instructions.md §6`** — the mirror for GitHub
  Copilot. Same rules, shorter bullets, plus a "Copilot review
  checklist" (R1..R21) that reviewers walk on every PR.
- **`.claude/skills/`** — one skill per rule, with grep patterns,
  before/after fix templates, and counter-examples. Claude Code
  auto-loads them when the trigger conditions match.

Two CI gates enforce the rules at merge time:

- **`scripts/verify-e2e-real-data.sh`** — R13 — every
  `page.route(...)` / `context.route(...)` call against an internal
  route carries an `R13: failure injection` marker or a match against
  the external-boundary allowlist.
- **`scripts/verify-copilot-catalogue.sh`** — R9 applied to the
  catalogue itself — every `fix(enh-*): address Copilot review on PR
  #N` commit has at least one row under `### PR #N` in
  `docs/enhancement-plan/COPILOT-FINDINGS.md`.

A pre-push review agent lives at
`.claude/agents/copilot-review-anticipator.md`. Invoke
`@copilot-review-anticipator` before `git push` on a feature branch:
it applies R1..R21 to the outgoing diff and produces a numbered
finding list with skill pointers — catching what Copilot will catch,
but faster and cheaper.

The full catalogue of every Copilot finding that shaped R1..R21 lives
at
[`docs/enhancement-plan/COPILOT-FINDINGS.md`](docs/enhancement-plan/COPILOT-FINDINGS.md)
with a per-PR per-tag frequency table. New phases should start from
[`.claude/briefings/enterprise-phase-template.md`](.claude/briefings/enterprise-phase-template.md)
— a reusable Phase-1 briefing skeleton that wires the agent into the
rules before the first commit.

---

## Contributing

Contributions are welcome.

1. **Fork** the repository.
2. **Create** a feature branch: `git checkout -b feature/my-feature`.
3. **Commit** your changes: `git commit -m 'Add my feature'`.
4. **Push** the branch: `git push origin feature/my-feature`.
5. **Open** a Pull Request.

### Guidelines

- Follow PSR-12 for PHP.
- Add or update tests when the change is meaningful.
- Keep PRs focused — one feature or fix per PR.
- Update the README when user-facing behaviour changes.
- English for code, comments, and commit messages.

### Reporting issues

Use [GitHub Issues](../../issues). Please include:
- Steps to reproduce
- Expected vs. actual behavior
- Laravel version, PHP version, AI provider used

---

## Changelog

### v2.0.0 — Enterprise edition (10-PR roadmap A → J + canonical compilation)

The 2.0 series promotes AskMyDocs from a single-user RAG chat tool into a
full enterprise knowledge platform. Two parallel tracks landed simultaneously:
the **canonical knowledge compilation** layer (knowledge graph + anti-repetition
memory + 9-type document taxonomy) and the **enterprise admin surface**
(React SPA + RBAC + 6 admin pages).

**Canonical Knowledge Compilation (PRs #9 – #15)**
- 9 canonical document types with YAML frontmatter validated by `CanonicalParser`
  (`decision`, `runbook`, `standard`, `incident`, `integration`, `domain-concept`,
  `module-kb`, `rejected-approach`, `project-index`)
- Knowledge graph with tenant-scoped composite FKs: `kb_nodes` (9 node kinds) +
  `kb_edges` (10 edge kinds: `depends_on`, `uses`, `implements`, `related_to`,
  `supersedes`, `invalidated_by`, `decision_for`, `documented_by`, `affects`,
  `owned_by`)
- Reranker fusion includes canonical boost + status penalty
- Graph-aware retrieval: 1-hop walk of `kb_edges` from canonical seeds
- Anti-repetition memory: `RejectedApproachInjector` cosine-correlates the query
  against `rejected-approach` docs and surfaces them under `⚠ REJECTED APPROACHES`
  in the prompt — config-gated via `KB_REJECTED_INJECTION_ENABLED`
- Promotion pipeline (ADR 0003, human-gated): three-stage API
  (`/suggest` → `/candidates` → `/promote`); only humans + operators commit
- Immutable `kb_canonical_audit` trail (no `updated_at`, no FK — survives hard delete)
- `CanonicalIndexerJob` populates the graph after every canonical ingest
- `kb:rebuild-graph` scheduler at 03:40 UTC (no-op when no canonical docs exist)
- 5 Claude skill templates under `.claude/skills/kb-canonical/` for consumer repos
- 10 MCP tools (5 retrieval + 5 canonical/promotion)

**Enterprise Admin Surface (PRs #16 – #33, 10 phases A → J)**

*Phase A — Storage & Scheduler hardening (PR #16)*
- Per-project disk override (`KB_PROJECT_DISKS` map → `App\Support\KbDiskResolver`)
- Raw vs canonical disk separation (Omega-inspired)
- Scheduled maintenance commands (`bootstrap/app.php`):
  `kb:prune-embedding-cache`, `chat-log:prune`, `kb:prune-deleted`,
  `kb:rebuild-graph`, `queue:prune-failed`, `admin-audit:prune`,
  `admin-nonces:prune`, `kb:prune-orphan-files --dry-run`, `insights:compute`
  (all `onOneServer()->withoutOverlapping()`). The `activitylog:clean`
  cron is stubbed as a comment — flip it on by uncommenting once a
  retention policy is locked in. Laravel 13 doesn't ship a
  `notifications:prune` command, so we don't schedule one.
- Configurable filesystems blocks: R2, GCS, MinIO

*Phase B — Auth JSON API + Sanctum stateful SPA (PR #17)*
- `Route::middleware('web')->prefix('auth')` group with JSON endpoints
  (`/login`, `/logout`, `/me`, `/forgot-password`, `/reset-password`)
- 2FA stub controller behind `AUTH_2FA_ENABLED=false` feature flag
- Throttling: 5/min on login (failure-only counter), 3/min on forgot-password

*Phase C — RBAC foundation (PR #18)*
- `spatie/laravel-permission` with 4 baseline roles (`super-admin`, `admin`,
  `editor`, `viewer`) + 12 permissions (`users.manage`, `roles.manage`,
  `permissions.view`, `kb.read.any`, `kb.edit.any`, `kb.delete.any`,
  `kb.promote.any`, `commands.run`, `commands.destructive`, `logs.view`,
  `insights.view`, `admin.access`)
- New tables: `project_memberships` (tenant scope JSON), `kb_tags` +
  `knowledge_document_tags` pivot, `knowledge_document_acl` (row-level)
- Global Eloquent scope `AccessScopeScope` on `KnowledgeDocument` filters every
  read-path query to the user's permitted projects (config-gated via
  `RBAC_ENFORCED`)
- `EnsureProjectAccess` middleware + `KnowledgeDocumentPolicy`
- `auth:grant {email} {role}` operator CLI

*Phase D — Frontend scaffold + auth pages (PR #19)*
- React 18 + TypeScript + Vite + Tailwind 3.4 + shadcn/ui (Radix) + TanStack
  Router/Query + Zustand + react-i18next
- Catch-all `Route::get('/app/{any}', SpaController)` for the SPA
- AppShell with collapsible sidebar, command palette, dark/light toggle
  (persisted in localStorage + `prefers-color-scheme`), i18n it/en
- Auth pages: Login, Forgot, Reset, Verify — shadcn forms + zod + react-hook-form
- Vite manifest output to `public/build/`, code-split per feature

*Phase E — Chat React (PR #20)*
- Full porting of the legacy Blade chat (`chatApp()` Alpine) to React
- `ConversationList`, `MessageThread`, `MessageBubble`, `Composer`,
  `CitationsPopover`, `FeedbackButtons`, `VoiceInput`
- TanStack Query for server state; Zustand for UI state
- `react-markdown` + `remark-gfm` + custom `[[wikilink]]` plugin (resolves via
  `GET /api/kb/resolve-wikilink`); recharts for charts; `useChatMutation` with
  optimistic updates
- Legacy `chat.blade.php` deprecated (kept for fallback during migration)

*Phase F1 + F2 — Admin shell + Dashboard + Users & Roles (PRs #22 + #23)*
- KPI dashboard (`/app/admin`): 6 KPI tiles + health strip + 3 recharts cards
  (chat volume area, token burn stacked bar, rating donut) + top projects +
  activity feed; 30-second `Cache::remember` layer
- Filterable users table with soft-delete + restore via `with_trashed` toggle
- 3-tab user edit drawer (Details / Roles / Memberships with `scope_allowlist`
  JSON editor)
- Spatie role CRUD with grouped permission matrix (`kb`, `users`, `roles`,
  `commands`, `logs`, `insights` cards)

*Phase G1 – G4 — KB Explorer (PRs #24 + #25 + #26 + #27)*
- Memory-safe `chunkById(100)` tree walker with canonical-aware modes
  (`canonical | raw | all`)
- Detail panel tabs: **Preview** (markdown + frontmatter pills) / **Meta**
  (canonical grid + AI-suggested tags) / **Source** (CodeMirror 6 editor —
  `@codemirror/state` + `/view` + `/lang-markdown`, ~150 KB lighter than
  basic-setup; PATCH `/raw` runs validate → write → audit → re-ingest) /
  **Graph** (1-hop tenant-scoped subgraph, SVG radial layout, ≤ 50 nodes) /
  **History** (paginated `kb_canonical_audit`)
- **PDF export** via Browsershot (Chrome headless), A4 print-optimised, with
  TOC and clickable wikilink anchors; feature-flagged via `ADMIN_PDF_ENGINE`

*Phase H1 + H2 — Log Viewer + Maintenance Panel (PRs #28 + #29)*
- 5 deep-linkable log tabs (`?tab=chat | audit | app | activity | failed`):
  paginated chat logs with model/project/rating filters, canonical audit trail
  with event-type/actor filters, reverse-seek `SplFileObject`-powered
  application log tailer (filename whitelist regex, 2000-line cap, optional
  live polling via `?live=1`), Spatie activity log (required), failed-jobs
  read-only with expandable exception trace
- Whitelisted Artisan runner enforced by `CommandRunnerService` via **6
  independent gates**: (1) whitelist lookup in `config('admin.allowed_commands')`,
  (2) args_schema validation, (3) signed `confirm_token` + DB-backed single-use
  nonce, (4) Spatie permission gate (`commands.run` for admin,
  `commands.destructive` for super-admin only), (5) audit-before-execute
  (`admin_command_audits` row flips around the `Artisan::call()`), (6) per-user
  `throttle:10,1` rate limit
- Three-step React wizard: Preview → [Confirm type-in for destructive] → Run → Result

*Phase I — AI Insights (PR #30)*
- Daily `insights:compute` command (05:00 UTC scheduler) writes one row into
  `admin_insights_snapshots` (six independently-nullable JSON columns)
- Six widget cards: Promotion Suggestions, Orphan Docs, Suggested Tags,
  Coverage Gaps, Stale Docs, Quality Report
- O(1) DB read on the SPA side; zero LLM calls per page load (compute moved
  from on-demand to pre-computed for cost control)

*Phase J — Docs + E2E + polish (PRs #31 + #32 + #33)*
- 63-test Playwright E2E suite running against real Postgres + pgvector in CI
- Deterministic via `data-testid` + `data-state="idle | loading | ready | error | empty"`
  contract (R11)
- Real data only — `page.route()` reserved for external boundaries (R13)
- Golden-path `admin-journey.spec.ts` walks every admin page in order

**22 Codified Review Rules**
- R1 — `KbPath::normalize()` everywhere | R2 — soft-delete awareness |
  R3 — memory-safe bulk ops | R4 — no silent failures | R5 — action.yml hygiene |
  R6 — docs/config coupling | R7 — no `0777` / no `@`-silenced errors |
  R8 — `KB_PATH_PREFIX` consistency | R9 — docs match code | R10 — canonical
  awareness | R11 — testid/state contract | R12 — UI changes ship E2E |
  R13 — E2E real data | R14 — surface failures loudly | R15 — a11y checklist |
  R16 — tests test what they claim | R17 — React effect/cache sync |
  R18 — derive options from DB | R19 — input escaping is complete |
  R20 — route contracts match FE shape | R21 — security invariants atomic-or-absent |
  **R22 — CI failure investigation: artefact-first, then code** (NEW PR #33)
- Each rule has a dedicated skill at `.claude/skills/<rule>/SKILL.md` with
  worked examples and counter-examples

**Tests**
- PHPUnit 12: 200+ tests covering RBAC isolation, canonical parsing, document
  ingestion/deletion, retrieval, MCP tools, command runner gates
- Vitest: pure-module tests against `resources/js/*.mjs` + frontend unit tests
- Playwright: 63 scenarios across `setup`, `chromium`, `chromium-viewer`,
  `chromium-super-admin` projects; admin-journey golden path; failure injection
  pattern (R13)

**Migration notes**
- Existing v1.3 deployments need: `composer update` → `npm ci && npm run build` →
  `php artisan migrate` → `php artisan db:seed --class=RbacSeeder` (assigns
  every existing user the `viewer` role + membership on every distinct
  `project_key` of `knowledge_documents`).
- Set `RBAC_ENFORCED=false` in `.env` to keep the v1.3 read-path open while
  you migrate stakeholders to the new admin shell.

### v1.3.0

**New**
- Document deletion pipeline — see the [Document Deletion](#document-deletion) section.
- `App\Services\Kb\DocumentDeleter` — single entry point for soft/hard delete, orphan cleanup on folder resync, and scheduled retention purge. Keeps `knowledge_documents`, `knowledge_chunks`, and the original file on the KB disk in sync.
- `SoftDeletes` on `KnowledgeDocument` — soft-deleted documents are automatically hidden from `KbSearchService`, MCP tools, and all read paths.
- `kb:delete {path} --project= --force|--soft` artisan command.
- `kb:prune-deleted --days=` scheduled command (runs daily at 03:30). Hard-deletes soft-deleted documents older than `KB_SOFT_DELETE_RETENTION_DAYS` and removes their files from the KB disk.
- `DELETE /api/kb/documents` Sanctum endpoint — accepts batch of `{project_key, source_path}` descriptors and an optional `force` flag.
- `kb:ingest-folder --prune-orphans [--force-delete]` — detects documents whose source file was removed between runs and deletes them (respects the folder scope).
- GitHub Action now detects `--diff-filter=D` and `--diff-filter=R` (deletions + renames) and batches them to `DELETE /api/kb/documents`. New `force_delete` input.
- New env: `KB_SOFT_DELETE_ENABLED` (default `true`), `KB_SOFT_DELETE_RETENTION_DAYS` (default `30`).
- New config section `kb.deletion` (`soft_delete`, `retention_days`).

**Tests**
- +29 new PHPUnit tests (11 `DocumentDeleterTest`, 6 `KbDeleteControllerTest`, 5 `KbDeleteCommandTest`, 3 `PruneDeletedDocumentsCommandTest`, 4 `KbIngestFolderPruneOrphansTest`) — suite is now **149 PHPUnit tests / 442 assertions**.

### v1.2.0

**New**
- `kb:ingest-folder` artisan command — walks the configured KB disk and dispatches one queued `IngestDocumentJob` per markdown file. Supports `--recursive`, `--pattern`, `--sync`, `--limit`, `--dry-run`, and a per-run `--disk` override.
- `App\Jobs\IngestDocumentJob` — `ShouldQueue` job with `$tries=3` + exponential backoff, driven by the `KB_INGEST_QUEUE` name.
- `POST /api/kb/ingest` — Sanctum-authenticated endpoint that accepts 1–100 markdown documents per call, persists them on the KB disk, and queues the ingestion.
- `.github/actions/ingest-to-askmydocs/action.yml` — reusable GitHub composite action. Any consumer repo can push its `docs/` folder to the KB on every commit to `main`. Copy-paste workflow shipped at `docs/examples/github-workflow-ingest.yml`.
- Queue config (`config/queue.php`) with `sync` / `database` / `redis` connections out of the box, plus the `jobs` + `failed_jobs` migrations for the database driver.
- New env: `KB_INGEST_QUEUE`, `KB_INGEST_DEFAULT_PROJECT`, `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_DB`, `REDIS_QUEUE_CONNECTION`, `REDIS_QUEUE`.
- New config section `kb.ingest` (`queue`, `default_project`).
- `composer.json` `suggest` section for `predis/predis`, `laravel/horizon`, `league/flysystem-aws-s3-v3`.

**Changed**
- README: the "Document Ingestion" section is now split into *Flow 1 — Local / S3 folder* and *Flow 2 — Remote push from another repo*, with a queue-driver comparison, a Supervisor template, and two new jr-friendly onboarding recipes.
- `tests/TestCase.php` pins `queue.default = sync` so the suite never touches a real queue backend.

**Tests**
- +20 new tests (5 for `IngestDocumentJob`, 8 for `KbIngestFolderCommand`, 7 for `KbIngestController`) — suite is now **115 PHPUnit tests / 317 assertions** plus **18 Vitest tests**.

### v1.1.0

**New**
- Regolo.ai provider (OpenAI-compatible REST, EU-based)
- Laravel 11 bootstrap + scheduler
- Daily scheduled commands: `kb:prune-embedding-cache`, `chat-log:prune`
- CLI ingestion: `kb:ingest` reads through Laravel disks (local, S3)
- `config/filesystems.php` with dedicated `kb` disk and S3 template
- FTS GIN index migration (pgsql-only, SQLite-safe)
- Complete `.env.example`
- GitHub Actions CI for PHPUnit + Vitest
- Full English README

**Changed**
- Default chat provider is now `openrouter` with `openai/gpt-4o-mini`
- Default embeddings provider is `openai` with `text-embedding-3-small`
- Chat log and embedding cache retention are configurable via env (`CHAT_LOG_RETENTION_DAYS`, `KB_EMBEDDING_CACHE_RETENTION_DAYS`)

### v1.0.0 — Initial release

**Core RAG Pipeline**
- Document ingestion with markdown chunking and pgvector storage
- Semantic search with cosine similarity on PostgreSQL + pgvector
- Hybrid search (vector + full-text) with Reciprocal Rank Fusion
- Hybrid reranking (vector + keyword + heading)
- Embedding cache to eliminate redundant API calls

**Multi-Provider AI**
- OpenAI, Anthropic (Claude), Google Gemini, OpenRouter
- Separate chat and embeddings providers
- Multi-turn conversation history sent to the AI
- HTTP-direct integration (no external SDKs)

**Chat Interface**
- ChatGPT-style UI with sidebar and conversation management
- Speech-to-text via Web Speech API
- Smart visualizations: Chart.js charts, action buttons, enhanced tables
- Citations showing source documents per answer
- Feedback loop with few-shot learning from positive ratings
- Markdown rendering with syntax-highlighted code blocks + copy button

**Enterprise features**
- Laravel session auth (login, logout, password reset — no public registration)
- Structured chat logging (DB, extensible to BigQuery/CloudWatch)
- Per-user conversation isolation
- MCP server with 5 read-only tools for Claude Desktop/Code
- Full Sanctum API for programmatic access
