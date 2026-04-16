# AskMyDocs — Enterprise AI Knowledge Base on Laravel

<p align="center">
  <img src="resources/cover-AskMyDocs.png" alt="AskMyDocs" width="100%" />
</p>

<p align="center">
  <a href="#installation"><img src="https://img.shields.io/badge/Laravel-11+-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel"></a>
  <a href="#ai-provider"><img src="https://img.shields.io/badge/Claude-Compatible-cc785c?style=flat-square&logo=anthropic&logoColor=white" alt="Claude"></a>
  <a href="#ai-provider"><img src="https://img.shields.io/badge/OpenAI-Compatible-412991?style=flat-square&logo=openai&logoColor=white" alt="OpenAI"></a>
  <a href="#ai-provider"><img src="https://img.shields.io/badge/Gemini-Compatible-4285F4?style=flat-square&logo=google&logoColor=white" alt="Gemini"></a>
  <a href="#ai-provider"><img src="https://img.shields.io/badge/OpenRouter-Multi--Model-6366f1?style=flat-square" alt="OpenRouter"></a>
  <a href="#ai-provider"><img src="https://img.shields.io/badge/Regolo.ai-EU-10b981?style=flat-square" alt="Regolo.ai"></a>
  <a href="#mcp-server"><img src="https://img.shields.io/badge/MCP-Server-0ea5e9?style=flat-square" alt="MCP Server"></a>
  <a href="#requirements"><img src="https://img.shields.io/badge/PostgreSQL-pgvector-336791?style=flat-square&logo=postgresql&logoColor=white" alt="PostgreSQL + pgvector"></a>
  <a href="#license"><img src="https://img.shields.io/badge/License-MIT-green?style=flat-square" alt="MIT License"></a>
  <a href="#requirements"><img src="https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.2+"></a>
</p>

<p align="center">
  <strong>Ask your docs. Get grounded answers. See the sources.</strong>
</p>

---

An enterprise-grade RAG system built on Laravel and PostgreSQL. Ingest your documents, ask questions in natural language, and get AI-powered answers grounded in your actual knowledge base — with full source citations, visual artifacts, and a ChatGPT-like interface.

### Key Features

| | Feature | Description |
|---|---|---|
| **Multi-Provider AI** | Swap between OpenAI, Anthropic Claude, Google Gemini, OpenRouter, or Regolo.ai with a single `.env` change |
| **Hybrid Search** | Semantic vector search (pgvector) + full-text keyword search fused via Reciprocal Rank Fusion |
| **Smart Reranking** | Over-retrieval + keyword/heading scoring to surface the most relevant chunks |
| **Embedding Cache** | DB-backed cache eliminates redundant API calls on re-ingestion and repeated queries |
| **Citations** | Every answer shows exactly which documents and sections were used — verify at the source |
| **Visual Artifacts** | The AI generates charts (Chart.js), enhanced tables, and action buttons (copy, download) when the data justifies it |
| **Feedback Learning** | Thumbs up/down on answers; positive examples are injected as few-shot context to improve future responses |
| **Chat History** | Full conversation persistence with sidebar, rename, delete, auto-generated titles — like ChatGPT |
| **Speech-to-Text** | Browser-native microphone input via Web Speech API — zero external services |
| **Chat Logging** | Structured logging (DB, extensible to BigQuery/CloudWatch) of every interaction with token counts, latency, client info |
| **Scheduler Hygiene** | Daily Laravel jobs to rotate chat logs and prune the embedding cache by configurable retention |
| **Storage-Agnostic Ingestion** | KB documents are read through Laravel disks: `local` by default, S3 with a single env change |
| **MCP Server** | Five read-only tools that expose the KB to Claude Desktop, Claude Code, and other MCP-compatible agents |
| **Auth** | Laravel session auth with login, logout, password reset — no registration (admin-created users); automatic redirect to `/chat` on login |

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
- [Document Ingestion](#document-ingestion)
- [Extending](#extending)
- [Testing](#testing)
- [Continuous Integration](#continuous-integration)
- [License](#license)
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
| **ChatLogManager** | `app/Services/ChatLog/ChatLogManager.php` | Structured conversation logging |
| **Scheduled commands** | `app/Console/Commands/*.php` | `kb:ingest`, `kb:prune-embedding-cache`, `chat-log:prune` |
| **MCP Server** | `app/Mcp/Servers/KnowledgeBaseServer.php` | Read-only MCP server for Claude and other AI agents |

---

## Requirements

- **PHP** >= 8.2
- **Laravel** >= 11.x
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

---

## Scheduler

Two daily hygiene jobs are registered in `bootstrap/app.php` and dispatched automatically when the Laravel scheduler runs.

| Time | Command | Retention env | Description |
|---|---|---|---|
| 03:10 | `kb:prune-embedding-cache` | `KB_EMBEDDING_CACHE_RETENTION_DAYS` (default 30) | Deletes `embedding_cache` rows whose `last_used_at` is older than N days |
| 03:20 | `chat-log:prune` | `CHAT_LOG_RETENTION_DAYS` (default 90) | Deletes `chat_logs` rows whose `created_at` is older than N days |

Set either env to `0` to disable the corresponding rotation. Both commands accept a `--days=` flag that wins over the env value for ad-hoc runs.

Register the scheduler entry in your crontab:

```cron
* * * * * cd /path/to/askmydocs && php artisan schedule:run >> /dev/null 2>&1
```

List what is configured:

```bash
php artisan schedule:list
```

Both commands can also be invoked manually:

```bash
php artisan kb:prune-embedding-cache
php artisan chat-log:prune --days=60
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

## Document Ingestion

### Via CLI (recommended)

```bash
php artisan kb:ingest docs/auth/setup.md \
    --project=erp-core \
    --title="Auth Setup"
```

The command reads the file through the configured Laravel disk (`KB_FILESYSTEM_DISK`), so the same command works for `local` and `s3` backends.

### Via code

```php
use App\Services\Kb\DocumentIngestor;

$ingestor = app(DocumentIngestor::class);

$ingestor->ingestMarkdown(
    projectKey: 'erp-core',
    sourcePath: 'docs/auth/setup.md',
    title: 'Auth Setup',
    markdown: Storage::disk('kb')->get('docs/auth/setup.md'),
    metadata: [
        'language' => 'en',
        'access_scope' => 'internal',
        'author' => 'team-auth',
    ],
);
```

### Pipeline

1. **Hash** — SHA256 of the content for idempotency.
2. **Chunking** — Split the markdown into chunks (ready for an AST-aware parser).
3. **Embedding** — Generate embeddings via the configured provider (with cache).
4. **Storage** — Atomic transaction: `KnowledgeDocument` + N `KnowledgeChunk` rows with embedding.

### Idempotency

Ingestion is idempotent: re-ingesting the same document with the same content creates no duplicates. Unique constraints on `(project_key, source_path, version_hash)` and `(knowledge_document_id, chunk_hash)` enforce consistency.

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

## Quick Start: 5-minute onboarding

For the fastest path (OpenRouter + OpenAI embeddings + PostgreSQL):

```bash
# 1. Setup
git clone https://github.com/your-org/askmydocs.git
cd askmydocs
composer install
cp .env.example .env
php artisan key:generate

# 2. Configure PostgreSQL in .env
#    DB_DATABASE=askmydocs
#    (make sure the pgvector extension is installed)

# 3. Add your API keys in .env
#    OPENROUTER_API_KEY=sk-or-...
#    OPENAI_API_KEY=sk-...

# 4. Run migrations
php artisan migrate

# 5. Create a user
php artisan tinker --execute="
    \App\Models\User::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => bcrypt('password'),
    ]);
"

# 6. Start the server
php artisan serve

# 7. Open http://localhost:8000 → log in → you are redirected to /chat
# 8. Start chatting with your knowledge base!
```

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
│   │       ├── KbIngestCommand.php           # Disk-driven ingestion CLI
│   │       ├── PruneEmbeddingCacheCommand.php
│   │       └── PruneChatLogsCommand.php
│   ├── Http/Controllers/
│   │   ├── Auth/
│   │   │   ├── LoginController.php          # Login / logout (redirect to /chat)
│   │   │   └── PasswordResetController.php  # Forgot / reset password
│   │   ├── ChatController.php               # Chat UI
│   │   └── Api/
│   │       ├── KbChatController.php         # Stateless API (Sanctum)
│   │       ├── ConversationController.php   # Conversations CRUD
│   │       ├── MessageController.php        # Messages + AI response
│   │       └── FeedbackController.php       # Thumbs up/down rating
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
│   ├── app.php                              # Laravel 11 bootstrap + schedule
│   └── providers.php
├── config/
│   ├── ai.php                                # Multi-provider config
│   ├── chat-log.php
│   ├── filesystems.php                      # Disks (local, kb, s3)
│   └── kb.php                                # KB + reranking + retention
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
| **PHP** | PHPUnit 11 + Orchestra Testbench + Mockery | Unit + feature tests (SQLite in-memory) |
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
│   ├── Auth/                              # Login redirect regression
│   ├── ChatLog/                           # ChatLogManager (persist, error swallowing)
│   ├── Commands/                          # kb:ingest, kb:prune-embedding-cache, chat-log:prune
│   └── Kb/                                # EmbeddingCacheService, FewShotService
├── database/migrations/                   # SQLite-compatible schema
└── js/
    └── rich-content.spec.mjs
```

### Current coverage

- 93 PHPUnit tests, 251 assertions
- 18 Vitest tests

---

## Continuous Integration

A GitHub Actions workflow at `.github/workflows/tests.yml` runs both test suites on every push to `main` and on every pull request. The job:

1. Installs PHP 8.2 with required extensions.
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
