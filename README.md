# AskMyDocs — AI Hub & Intelligent Agentic Platform for the Enterprise

> **Enterprise RAG + Knowledge Graph + Agentic Tool Use, self-hostable, MIT licensed.**

AskMyDocs is a self-hostable AI hub for enterprise knowledge. It fuses
hybrid retrieval-augmented generation (pgvector + FTS + reranker), a
typed canonical knowledge graph with human-gated promotion, a streaming
chat surface on the Vercel AI SDK, and a full admin operations cockpit
into a single Laravel platform. It is the open-source, on-prem alternative
to Glean / Notion AI / ChatGPT Enterprise — without the per-seat lock-in.

<p align="center">
  <img src="resources/cover-AskMyDocs.png" alt="AskMyDocs" width="100%" />
</p>


<p align="center">
  <a href="#quick-start-5-minutes"><img src="https://img.shields.io/badge/Laravel-13+-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/Claude-Compatible-cc785c?style=flat-square&logo=anthropic&logoColor=white" alt="Claude"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/OpenAI-Compatible-412991?style=flat-square&logo=openai&logoColor=white" alt="OpenAI"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/Gemini-Compatible-4285F4?style=flat-square&logo=google&logoColor=white" alt="Gemini"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/OpenRouter-Multi--Model-6366f1?style=flat-square" alt="OpenRouter"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/Regolo.ai-EU-10b981?style=flat-square" alt="Regolo.ai"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/MCP-10%20tools-0ea5e9?style=flat-square" alt="MCP Server"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/Canonical--KB-9%20types-ff7a00?style=flat-square" alt="Canonical KB"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/Knowledge%20Graph-10%20relations-7c3aed?style=flat-square" alt="Knowledge Graph"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/Anti--Repetition-%E2%9A%A0%EF%B8%8F%20built--in-dc2626?style=flat-square" alt="Anti-Repetition Memory"></a>
  <a href="#prerequisites"><img src="https://img.shields.io/badge/PostgreSQL-pgvector-336791?style=flat-square&logo=postgresql&logoColor=white" alt="PostgreSQL + pgvector"></a>
  <a href="#license"><img src="https://img.shields.io/badge/License-MIT-green?style=flat-square" alt="MIT License"></a>
  <a href="#prerequisites"><img src="https://img.shields.io/badge/PHP-8.3+-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.3+"></a>
  <a href="CHANGELOG.md"><img src="https://img.shields.io/badge/release-v8.4.0-blueviolet?style=flat-square" alt="Release v8.4.0"></a>
  <a href="#universal-connectors"><img src="https://img.shields.io/badge/connectors-7%20native-0ea5e9?style=flat-square" alt="7 Native Connectors"></a>
  <a href="#quality--observability"><img src="https://img.shields.io/badge/tests-2063%20PHPUnit%20%2B%20494%20Vitest-brightgreen?style=flat-square" alt="2063 PHPUnit + 494 Vitest"></a>
</p>

<p align="center">
  <strong>Ask your docs. Get grounded answers. See the sources. Run agentic tools.</strong>
</p>


# CHATBOT UI/UX
![AskMyDoc - ChatBot.png](resources/screenshots/AskMyDoc%20-%20ChatBot.png)

# DASHBOARD UI/UX
![AskMyDoc - Dashboard.png](resources/screenshots/AskMyDoc%20-%20Dashboard.png)

---

## Table of Contents

- [What it is](#what-it-is)
- [Why AskMyDocs — the 5 moats](#why-askmydocs--the-5-moats)
- [✨ Universal Connectors](#universal-connectors)
- [✨ Modern Chat Surface (Vercel AI SDK UI)](#modern-chat-surface-vercel-ai-sdk-ui)
- [Features by area](#features-by-area)
  - [Retrieval & Knowledge](#retrieval--knowledge)
  - [Chat & Conversation](#chat--conversation)
  - [Security & Compliance](#security--compliance)
  - [Admin & Operations](#admin--operations)
  - [Integrations & Extensibility](#integrations--extensibility)
  - [Quality & Observability](#quality--observability)
- [Quick start (5 minutes)](#quick-start-5-minutes)
- [Architecture](#architecture)
- [Roadmap](#roadmap)
- [Documentation](#documentation)
- [Screenshots gallery](#screenshots-gallery)
- [Sister packages](#sister-packages)
- [Contributing](#contributing)
- [License](#license)
- [Changelog](#changelog)

---

## What it is

**What.** AskMyDocs is an **AI hub for enterprise knowledge** built on
Laravel 13 + PostgreSQL + pgvector. It ingests markdown, text, PDF and
DOCX documents into a typed canonical knowledge graph, answers
questions over them with streaming RAG, exposes the same knowledge as
MCP tools for any agentic client (Claude Desktop, Claude Code,
Cursor, custom agents), and ships a full React admin SPA — KPI
dashboard, canonical KB explorer with inline editor and graph viewer,
log viewer (five tabs), whitelisted Artisan maintenance runner, daily
AI-insights panel — all behind Spatie role-based access control with
audit trails on every destructive mutation.

**Why.** Most "RAG over docs" tools treat your KB as a pile of
interchangeable chunks. They re-discover the answer from zero on every
query, never persist what your team has *already decided*, and
re-propose options that were explicitly dismissed three quarters ago.
SaaS competitors (Glean, ChatGPT Enterprise, Notion AI) either lock
you into per-seat contracts and proprietary data residency, or charge
~$500K/year for the on-prem option. AskMyDocs is MIT-licensed,
self-hostable, EU-sovereign-feasible, and ships a typed canonical layer
with human-gated promotion that no public competitor offers.

**For whom.** Enterprise teams ingesting their architectural decisions
/ runbooks / standards / incidents / domain concepts into a *navigable*
KB; operators of regulated-industry RAG (GDPR, AI Act) needing
field-level PII redaction at every persistence boundary; engineering
orgs that want LLMs to stop re-proposing rejected approaches; Italian
software companies filing under `documentazione_idonea` Patent Box;
and anyone allergic to vendor lock-in.

---

## Why AskMyDocs — the 5 moats

These five differentiators come from the public competitor audit at
[`docs/v4-platform/AUDIT-2026-05-11-competitor-comparison.md`](docs/v4-platform/AUDIT-2026-05-11-competitor-comparison.md)
(Section 3, "Where AskMyDocs is genuinely AHEAD"). They are the moats
no other public RAG platform — open-source or SaaS — currently ships.

| ★ | Moat | One-line |
|:---:|---|---|
| ★ | **Human-gated canonical promotion pipeline** (ADR 0003) | Three-stage API (`/suggest` → `/candidates` → `/promote`) holds the LLM at "draft"; only humans (git push → GH Action) and operators (`kb:promote` CLI) commit canonical storage. Immutable `kb_canonical_audit` trail. No public competitor splits "AI proposes" from "human writes" this way. |
| ★ | **Retrieval-time knowledge graph + rejected-approach injection** | `GraphExpander` walks `kb_edges` 1-hop at every query and folds neighbours into the `SearchResult`. `RejectedApproachInjector` vector-correlates the query against `rejected-approach` canonical docs and surfaces them under a ⚠ marker so the LLM stops re-proposing dismissed options. ChatGPT Enterprise / Glean / Vectara do not do this. |
| ★ | **PII redaction at 11 persistence boundaries** (default-OFF, granular per touch-point) | `padosoft/laravel-pii-redactor` v1.2 wired at 11 touch-points across observers, middleware, Monolog processor, failed-job listener, Flow payload redactor, insights inspector. EU-GDPR-grade *field-level* redaction inside the app boundary — not just data-residency. Every knob default-OFF so v3 / v4.0 hosts see byte-identical behaviour until they opt in. |
| ★ | **MIT-licensed, self-hostable, on-prem feasible** (no $500K/yr vendor contract) | Vectara is the only competitor that ships on-prem ($500K/yr public list). Glean / Notion AI / ChatGPT Enterprise / M365 Copilot are SaaS-only. AskMyDocs runs on any Laravel + PostgreSQL + pgvector host with zero vendor lock-in; the entire sister-package stack is MIT and independently reusable. |
| ★ | **Eval-harness CI gate + nightly LLM-as-judge + adversarial cohorts** | `padosoft/eval-harness` v1.2 RAG regression gate on every PR (4 datasets / 1 baseline + 3 adversarial / 7 metrics including custom `CitationGroundednessMetric` + `CosineGroundednessMetric`); `eval:nightly` Artisan cron at 05:30 UTC with three-fence cost guard, regression detection vs prior baseline, `Log::alert` + sidecar on regression; adversarial-lane nightly opt-in shipped in v4.4. Out-of-the-box eval surface nobody else publicly ships. |

### Plus: a closed-loop **KB Lifecycle Intelligence** suite (v8.7 → v8.8)

Beyond the five moats, the v8.7–v8.8 cycles shipped a closed governance loop most
RAG tools simply don't have — the exact capabilities the
[2026 Affine KB Buyer's Guide](docs/v4-platform/AUDIT-2026-06-02-affine-buyers-guide-gap.md)
tells buyers to demand:

- **Content-gap analytics** — every question the KB *couldn't* answer (sync **and** streaming
  refusals) is ranked under **Admin → Content Gaps** so editors write the missing article next.
  The guide names this in three separate sections; few competitors expose it at all.
- **Obsolescence intelligence on every change *and delete*** — the AI deep-analysis flags which
  *other* docs a change (or deletion) makes stale or dangling, suggest-only, human-gated.
- **Synonym expansion + per-query multilingual FTS** — the guide literally lists "Synonym
  Expansion: does the AI connect industry terms?" (shipped v8.7) and multilingual consistency
  (shipped v8.8).
- **Review cadence + archival, not deletion** — automated stale-review reminders + the Cloud
  Time Machine (browse / diff / restore any version) — the guide's "Review Cadence and Archival
  Policy" governance section, shipped.
- **Graph-native navigation** — a chat-side **Related** panel walks the knowledge graph straight
  from a grounded answer.

---

## ✨ Universal Connectors

**Plug AskMyDocs into Google Drive, Notion, OneDrive, Evernote, Fabric, Confluence, and Jira with OAuth in one click — every document chunked and cited correctly per source.**

Most "RAG over docs" tools either expect a pile of pre-flattened
markdown or ship a single brittle "Google Drive sync" feature. AskMyDocs
v4.5 ships a real **connector framework** + **seven native connectors**
+ **per-source chunkers** so every external knowledge corpus lands in
the canonical KB with its provenance, native IDs, ACL hints, and
status preserved — and gets chunked the way that source actually wants
to be chunked.

- **7 native connectors live in v4.5** — `google-drive` (OAuth2 + delta-query), `notion` (OAuth2 + block paginator), `evernote` (OAuth + `.enex` bulk import), `fabric` (API-key, OAuth pending upstream), `onedrive` (Microsoft Graph delta-query — supports `text/markdown` / `text/plain` / `application/pdf`; Office formats `.docx` / `.xlsx` / `.pptx` ingestion deferred), `confluence` (Atlassian OAuth 2.0 3LO; `cloud_id` persisted in tenant-scoped `connector_credentials.extra_json.cloud_id`, optionally reused by a Jira install in the same tenant/workspace), `jira` (Atlassian OAuth 2.0 3LO + ADF-to-markdown + injection-safe JQL builder).
- **Per-source chunkers** — `NotionBlockChunker` / `ConfluencePageChunker` / `OfficeDocChunker` / `AtomicNoteChunker` / `JiraIssueChunker` / `PdfPageChunker` dispatched via `PipelineRegistry::resolveChunker()` (R23 FQCN-validated + `supports()` mutex-checked at boot).
- **Rich frontmatter capture** — every connector populates document-level metadata (`connector`, `external_id`, `external_url`, native timestamps) plus chunk-level metadata (`source_type`, `search_tags` (top-level in chunk metadata), `recency_bucket`, ACL hint, status, preamble-path). Drives `KbSearchService` facets + `Reranker` Layer-4 signals (tag overlap + recency + status-active + preamble-match).
- **Admin OAuth flow at `/app/admin/connectors`** — React SPA + Spatie super-admin gate + signed OAuth callback + per-installation `connector_installations` + `connector_credentials` rows + scheduler-driven incremental sync via `App\Jobs\ConnectorSyncJob`.
- **Opt-in live-test recording infrastructure** — `tests/Live/Connectors/` skeleton + per-provider env-var guard + `docs/v4-platform/RUNBOOK-live-fixture-recording.md` junior-proof setup guide. CI runs only `Unit` + `Feature`; operators refresh fixtures explicitly when provider APIs drift.

### How it compares

| Capability | AskMyDocs v4.5 | Glean | Notion AI | ChatGPT Enterprise | M365 Copilot | Mendable | Vectara |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Self-hostable + connector framework | ✅ MIT | ❌ SaaS | ❌ SaaS | ❌ SaaS | ❌ SaaS | ❌ SaaS | ❌ $500K/yr |
| Native Google Drive | ✅ | ✅ | ❌ | ✅ | ❌ | partial | ❌ |
| Native Notion | ✅ | ✅ | ✅ | ✅ | ❌ | partial | ❌ |
| Native OneDrive | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| Native Evernote | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Native Confluence | ✅ | ✅ | ❌ | ❌ | partial | partial | ❌ |
| Native Jira | ✅ | ✅ | ❌ | ❌ | ❌ | partial | ❌ |
| Source-aware chunking framework | ✅ | private | ❌ | ❌ | ❌ | partial | partial |
| Plugin/package extensibility | ✅ (v4.6 packages) | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

**Try it.** Read [`docs/connectors/README.md`](docs/connectors/README.md)
for the developer guide (10-method `ConnectorInterface` contract +
auto-discovery + framework reuse pattern), then log in as a
super-admin and navigate to `/app/admin/connectors` to install the first
connector.

---

## ✨ Modern Chat Surface (Vercel AI SDK UI)

**Stop / regenerate / branch / inline-edit / token-cost meter — the chat surface every modern AI app should have, with full streaming citations and suggested follow-ups.**

The chat UX gap against Claude Desktop / ChatGPT Plus / Vercel
reference apps is what 90% of first-time users notice and 0% of
self-hostable RAG OSS ships. v4.5 closes that gap on all seven Tier 1
affordances plus the first Tier 2 win (suggested follow-ups), built on
top of the v4.0 Vercel AI SDK v6 `UIMessageChunk` streaming foundation.

- **7 Tier 1 features** — stop-streaming button (`AbortController`-backed), regenerate-last-assistant, branch-from-message endpoint (forks the conversation tree), inline-edit user message, token+cost meter (BE `config('ai.cost_rates')`), enhanced per-message provider+model+timestamp badge, copy-code-block.
- **Suggested follow-up pills** — `SuggestedFollowupGenerator` derives three follow-up prompts from the assistant's last reply; renders as clickable pill chips under the message; submits via the streaming endpoint when clicked.
- **Full Vercel AI SDK v6 message-parts integration** — `MessageStreamController` emits canonical `start` / `text-start` / `text-delta` / `text-end` / `source-url` / `data` / `finish` frames over SSE; `useChatStream()` exposes `data-state="idle|loading|ready|empty|error"` for deterministic Playwright waits (SDK `submitted` and `streaming` statuses both map to `loading` via `mapStatusToDataState()` — see `frontend/src/features/chat/map-status-to-data-state.test.ts`).
- **Canvas-ready architecture (artifact panel deferred to v5.x)** — Tier 2 stretch (tool-result rendering, streaming source-document parts, conversation export, image attachments, artifact panel) is deliberately deferred to a v5.x milestone so it can be designed alongside the MCP **client** tool-result surface and share one storage contract. See ADR 0008 D4.
- **Zero-config for OpenAI / Anthropic / Gemini / OpenRouter / Regolo** — OpenAI, Anthropic, Gemini, and OpenRouter are called via raw `Http::` (no SDK); Regolo is wired through the `padosoft/laravel-ai-regolo` SDK adapter on `laravel/ai`. `AiManager::chatStream()` synthesises a single-chunk SSE for providers without native streaming via the `FallbackStreaming` trait.

**Try it.** Open `/app/chat` in the React SPA. Start a long answer
and hit Stop; click Regenerate; hover the assistant message and pick
Branch (a new conversation forks from that point); pick a follow-up
pill chip to chain into the next prompt; hover any code block for the
Copy button.

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

The system supports **five providers**. OpenAI, Anthropic, Gemini, and OpenRouter are called via raw `Http::`, which keeps auth, retries, timeouts, and response parsing under our control. Regolo is the exception: it is wired through the `padosoft/laravel-ai-regolo` SDK adapter (built on `Laravel\Ai`), so chat + embeddings reuse its OpenAI-compatible client.

Config file: `config/ai.php`

#### Defaults

```env
# Chat provider. Supported: openai, anthropic, gemini, openrouter, regolo
AI_PROVIDER=openrouter

# Embeddings provider. Must support embeddings (openai, gemini, regolo, openrouter).
# Anthropic does NOT offer embeddings. OpenRouter exposes OpenAI-compatible
# /v1/embeddings (since Oct 2025) routing openai/text-embedding-3-small (default)
# and qwen/qwen3-embedding-4b. Leave empty to let AiManager reuse AI_PROVIDER
# when the default chat provider supports embeddings; otherwise it falls back
# to the first embeddings-capable provider with a configured API key in this order:
# openai → openrouter → regolo → gemini. The 1536-dim defaults (openai +
# openrouter) come first so the stock KB_EMBEDDINGS_DIMENSIONS=1536 pgvector
# schema stays consistent under auto-selection — regolo (4096) and gemini
# (768) require a pgvector resize in lock-step, set AI_EMBEDDINGS_PROVIDER
# explicitly to opt in.
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

Anthropic has no embeddings endpoint, so pair it with any embeddings-capable provider — OpenAI, OpenRouter, Regolo, or Gemini. If `AI_EMBEDDINGS_PROVIDER` is left empty, `AiManager` auto-selects the first one with a configured API key in this order: openai → openrouter → regolo → gemini. The 1536-dim defaults (OpenAI's `text-embedding-3-small` + OpenRouter routing the same model) come first so a deployment with the stock `KB_EMBEDDINGS_DIMENSIONS=1536` pgvector schema stays consistent under auto-selection; Regolo (4096) and Gemini (768) require a `vector(N)` resize and a matching `KB_EMBEDDINGS_DIMENSIONS` change before use, so set `AI_EMBEDDINGS_PROVIDER=regolo|gemini` explicitly when you've migrated.

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

OpenRouter proxies hundreds of models. Since Oct 2025 it also exposes an
OpenAI-compatible `/v1/embeddings` endpoint, so it can serve both chat
and embeddings from the same gateway. Default embedding model is
`openai/text-embedding-3-small` (1536 dims — matches the default
`KB_EMBEDDINGS_DIMENSIONS`, no re-index needed). Alternative
`qwen/qwen3-embedding-4b` (2560 dims) requires resizing the pgvector
column on `knowledge_chunks.embedding` + `embedding_cache.embedding`
and re-indexing. Pair with a separate provider if you prefer.

```env
AI_PROVIDER=openrouter
AI_EMBEDDINGS_PROVIDER=openrouter

OPENROUTER_API_KEY=sk-or-...
OPENROUTER_CHAT_MODEL=openai/gpt-4o-mini
OPENROUTER_EMBEDDINGS_MODEL=openai/text-embedding-3-small
OPENROUTER_APP_NAME="AskMyDocs"
OPENROUTER_SITE_URL=https://kb.example.com
```

#### Regolo.ai (by Seeweb)

EU-based, GDPR-compliant, **OpenAI-compatible** REST API. Supports chat, streaming, embeddings, and reranking via the [`padosoft/laravel-ai-regolo`](https://github.com/padosoft/laravel-ai-regolo) extension on top of the official `laravel/ai` SDK. Get keys at [dashboard.regolo.ai](https://dashboard.regolo.ai) and see [docs.regolo.ai](https://docs.regolo.ai) for the full model catalogue.

```env
AI_PROVIDER=regolo
AI_EMBEDDINGS_PROVIDER=regolo

REGOLO_API_KEY=...
REGOLO_BASE_URL=https://api.regolo.ai/v1

# Chat models — `cheapest` / `smartest` aliases pick the right model for
# cost-vs-quality shortcuts (see `Lab::Cheapest` / `Lab::Smartest` in laravel/ai).
REGOLO_CHAT_MODEL=Llama-3.3-70B-Instruct
REGOLO_CHAT_MODEL_CHEAPEST=Llama-3.1-8B-Instruct
REGOLO_CHAT_MODEL_SMARTEST=Llama-3.3-70B-Instruct

# Embeddings — set KB_EMBEDDINGS_DIMENSIONS to the same value below.
REGOLO_EMBEDDINGS_MODEL=Qwen3-Embedding-8B
REGOLO_EMBEDDINGS_DIMENSIONS=4096

# Reranker — used when KB_RERANKING_ENABLED=true.
REGOLO_RERANKING_MODEL=jina-reranker-v2

# Transport + per-call defaults. `REGOLO_MAX_TOKENS` / `REGOLO_TEMPERATURE`
# are the provider-level fallbacks; per-call `$options['max_tokens']` /
# `$options['temperature']` (e.g. `ConversationController::generateTitle`
# capping titles at 60 tokens) take precedence.
REGOLO_TIMEOUT=120
REGOLO_MAX_TOKENS=4096
REGOLO_TEMPERATURE=0.2
```

#### Embedding dimension gotcha

If you change the embeddings provider/model (e.g. from OpenAI 1536-dim to Gemini 768-dim):

1. Update `KB_EMBEDDINGS_DIMENSIONS` in `.env`
2. Create a new migration that resizes the `embedding` `vector(N)` column on `knowledge_chunks` and `embedding_cache`
3. Flush the cache so stale-dimension vectors don't pollute retrieval — call `app(\App\Services\Kb\EmbeddingCacheService::class)->flush()` (or scope by retired provider with `->flush('openai')`) from a tinker session. `kb:prune-embedding-cache --days=N` only evicts rows older than N days and returns early when `N <= 0`, so it is **not** a full-flush substitute.
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

### `GET /api/kb/documents/search` (v3.0+)

Document title/path autocomplete used by the chat composer's `@mention` popover (T2.7/T2.8). Sanctum-protected.

**Query params:**

- `q` — search string (2-120 chars, escaped for `LIKE` wildcards via `\` + `ESCAPE '\\'` clause per R19; literal `_` and `%` in the query do NOT act as wildcards)
- `project_keys[]` — optional tenant scope (zero or more)

**Response:** `{ "data": [{ "id", "project_key", "title", "source_path", "source_type", "canonical_type" }] }`

Up to 20 results per request. Archived documents are excluded.

### Saved filter presets (v3.0+)

Authenticated users can save / load / delete personal filter combinations via `RESTful /api/chat-filter-presets` (consumed by the FE FilterBar dropdown — UI work in a follow-up FE PR).

- `GET    /api/chat-filter-presets` — list the user's presets (alphabetical by name).
- `POST   /api/chat-filter-presets` — create. Required body: `{ "name": "…", "filters": { … } }`. Per-user uniqueness enforced on `name` (422 on duplicate within the same account). Different users may pick the same display name independently.
- `GET    /api/chat-filter-presets/{id}` — show one. Returns `404` for IDs owned by a different user (deliberate — the API does not leak the existence of other users' presets).
- `PUT    /api/chat-filter-presets/{id}` — update name + filters; same `404` semantics for non-owned rows.
- `DELETE /api/chat-filter-presets/{id}` — delete; `204` on success, `404` for non-owned rows.

The `filters` JSON column carries a serialised RetrievalFilters payload — the same shape the chat controller's `KbChatRequest::toFilters()` consumes. Round-trip is lossless: load preset → POST to `/api/kb/chat` produces identical retrieval scope as if the user had re-selected every filter manually.

### Chat filters (v3.0+)

`POST /api/kb/chat` accepts an optional `filters` object that narrows the retrieval scope BEFORE reranking + graph expansion + rejected-approach injection — filters change the candidate population, not the post-hoc ranking. Every dimension is optional.

```json
{
  "question": "What is our cache invalidation policy?",
  "filters": {
    "project_keys": ["hr-portal", "engineering"],
    "tag_slugs": ["policy", "security"],
    "source_types": ["markdown", "pdf"],
    "canonical_types": ["decision", "runbook"],
    "connector_types": ["local", "google-drive"],
    "doc_ids": [42, 99],
    "folder_globs": ["hr/policies/**"],
    "date_from": "2026-01-01",
    "date_to": "2026-12-31",
    "languages": ["it", "en"]
  }
}
```

Field semantics:

- `project_keys` — multi-tenant scope; takes precedence over the legacy `project_key` field when both are sent.
- `tag_slugs` — match documents tagged with ANY listed slug (T2.3 join, ships in a follow-up).
- `source_types` — one of `markdown`, `text`, `pdf`, `docx` (validated against `App\Support\Kb\SourceType` so adding a new type extends the validator automatically).
- `canonical_types` — one of the `App\Support\Canonical\CanonicalType` enum values currently stored on `knowledge_documents.canonical_type`: `decision`, `module-kb`, `runbook`, `standard`, `incident`, `integration`, `domain-concept`, `rejected-approach`, `project-index`. The validator is built from `CanonicalType::cases()` so adding a new case auto-extends the accepted set.
- `connector_types` — connector identifier strings (for example `local`, `google-drive`, `onedrive`, `notion`, `asana`, `imap`). Accepted in v3.0 but currently a no-op in retrieval until the `connector_type` column is added in v3.1.
- `doc_ids` — explicit document-id allowlist (used by the `@mention` UI in the chat composer, T2.7).
- `folder_globs` — path globs against `source_path`. `*` matches a single segment (does NOT cross `/`), `**` matches across segments (e.g. `hr/policies/**` matches `hr/policies/leave.md` AND `hr/policies/inner/leave.md`), `?` matches a single char (not `/`). Applied PHP-side after the SQL pre-filter via `App\Support\KbPath::matchesAnyGlob` (PostgreSQL has no native fnmatch and `**` doesn't translate to LIKE cleanly).
- `date_from` / `date_to` — ISO 8601 date range against `indexed_at`. `date_to` must be after-or-equal to `date_from`.
- `languages` — ISO 639-1 codes (normalized to lowercase during DTO construction; the validator enforces `size:2`).

Pre-T2.2 callers using the legacy `{question, project_key}` payload keep working unchanged — internally `project_key` is wrapped into `filters.project_keys = [project_key]`. The response `meta.filters_selected` echoes the count of user-selected filter dimensions for the FE composer to render "5 filters selected".

### Multi-format ingest (v3.0+)

`kb:ingest-folder` now picks up `.md`, `.markdown`, `.txt`, `.pdf`, and `.docx` files automatically (default `--pattern` is the union of every supported extension). Operators who want pre-T1.8 markdown-only behavior pass `--pattern=md,markdown` explicitly.

The `POST /api/kb/ingest` endpoint accepts an optional `mime_type` field per document (defaults to `text/markdown` for back-compat). Binary formats (`application/pdf`, `application/vnd.openxmlformats-officedocument.wordprocessingml.document`) require `documents.*.content` to be **base64-encoded**; the controller decodes-or-422 before writing to disk. Text MIMEs (`text/markdown`, `text/x-markdown`, `text/plain`) keep accepting raw content. Unsupported MIME types return 422 with an actionable error naming the supported set.

The `App\Support\Kb\SourceType` enum is a typed helper for the markdown/text/pdf/docx domain — `SourceType::fromMime()` and `SourceType::fromExtension()` are the canonical conversions used by the API controller and the folder walker. The actual ingest routing is config-driven via `config/kb-pipeline.php` (`converters` / `chunkers` / `mime_to_source_type`); adding a new format requires updating BOTH `config/kb-pipeline.php` AND `SourceType::fromMime()` / `fromExtension()` / `toMime()` / `supportedMimes()` so the API/CLI surfaces stay consistent with what the registry resolves.

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
- `DocxConverter` — `application/vnd.openxmlformats-officedocument.wordprocessingml.document` (parses the `.docx` package via `phpoffice/phpword`; maps `Heading{N}` paragraph styles to `#{×N+1}` markdown headings nested under the basename H1; tables become markdown pipe-tables. Embedded images are NOT extracted in v3.0 — planned for v3.1 with the vision-LLM pipeline.)

**PDF support:** `smalot/pdfparser` is a hard `require` (pure PHP, no system deps). For more robust extraction on complex PDFs (multi-column layouts, certain XFA forms, mixed encodings), install `poppler-utils` on the host (`apt install poppler-utils` on Debian/Ubuntu, `brew install poppler` on macOS) — the `PdfConverter` automatically falls back to the `pdftotext` binary when smalot raises an exception. `extractionMeta.extraction_strategy` records which strategy was used per document so you can audit the rate of fallbacks in production.

Built-in chunkers (v3.0):

- `PdfPageChunker` — handles `pdf` source-type. Slices on the `## Page N` heading boundaries emitted by `PdfConverter`; emits one chunk per non-empty page with `heading_path = "Page N"` so citations like "see page N of foo.pdf" map 1:1 to a single chunk row. Pages exceeding `KB_CHUNK_HARD_CAP_TOKENS` are split intra-page on `\n\n` paragraph boundaries; all pieces of the same page share the same `heading_path` so page-level citations still resolve cleanly.
- `MarkdownChunker` — handles `markdown`, `md`, `text`, `docx` source types (any source whose converter outputs markdown). Uses `section_aware` mode: emits one chunk per ATX heading section with `heading_path` as a `>`-joined breadcrumb of H1-H3 ancestors. Falls back to `paragraph_split` (one chunk per blank-line-separated block) for documents without headings.

The chunker registry is order-significant — `PdfPageChunker` is listed FIRST in `config/kb-pipeline.php`'s `chunkers` so the first-match-wins resolution prefers it for `pdf` over the markdown fallback.

The polymorphic entry point is `DocumentIngestor::ingest(string $projectKey, SourceDocument $source, string $title, array $extraMetadata = [])`. The pre-v3 `ingestMarkdown(...)` is now a thin facade that synthesises a `text/markdown` `SourceDocument` and delegates to `ingest()` — IngestDocumentJob and the GitHub Action keep working unchanged.

### Multi-tenant deployment (v4.0)

The v4.0 cycle adds a **per-request tenant context** that scopes every Eloquent query against tenant-aware tables (R30/R31). Existing v3.x deployments are backward-compatible — every row gets `tenant_id = 'default'` and the resolver returns `'default'` unless explicitly configured otherwise.

**The plumbing**

| Piece | Path | Responsibility |
|---|---|---|
| `TenantContext` | `app/Support/TenantContext.php` | Request-scoped singleton; holds the active `tenant_id` for the duration of one HTTP request or one CLI command |
| `ResolveTenant` middleware | `app/Http/Middleware/ResolveTenant.php` | Reads the tenant from the configured resolver and sets `TenantContext`; runs at the top of the global middleware stack so every controller / job dispatched from the request inherits the context |
| `BelongsToTenant` trait | `app/Models/Concerns/BelongsToTenant.php` | Auto-fills `tenant_id` on `creating` events from `TenantContext::current()`; provides `forTenant($id)` query scope |
| `--tenant=X` CLI option | every domain Artisan command | CLI commands (`kb:ingest-folder`, `kb:rebuild-graph`, `kb:promote`, `insights:compute`) accept the option and set the context before running |

**Configuration (`.env`)**

```bash
# Single-tenant deployment (v3.x backward compatible — DEFAULT)
TENANT_DEFAULT=default
TENANT_RESOLVER=default          # always returns 'default'

# Multi-tenant by HTTP header (suitable for B2B SaaS with API gateway routing)
TENANT_RESOLVER=header
TENANT_HEADER_NAME=X-Tenant-ID

# Multi-tenant by domain (suitable for subdomain-per-customer deployments)
TENANT_RESOLVER=domain
TENANT_DOMAIN_PATTERN='([^.]+)\\.example\\.com'   # captures tenant slug

# Multi-tenant by authenticated user (suitable for shared-host SaaS)
TENANT_RESOLVER=auth
TENANT_USER_COLUMN=tenant_id     # column on the User model that holds the tenant
```

**What's tenant-scoped (and what isn't)**

The 20 tenant-aware models (enumerated in `tests/Architecture/TenantIdMandatoryTest::TENANT_AWARE_MODELS` — `KnowledgeDocument`, `KnowledgeChunk`, `ChatLog`, `Conversation`, `Message`, `KbNode`, `KbEdge`, `KbCanonicalAudit`, `ProjectMembership`, `KbTag`, `KnowledgeDocumentAcl`, `AdminCommandAudit`, `AdminCommandNonce`, `AdminInsightsSnapshot`, `ChatFilterPreset`, `ChatLogProvenance`, `TabularReview`, `TabularCell`, `Workflow`, `HiddenWorkflow`) all carry `tenant_id` and use the `BelongsToTenant` trait. The architecture test gates new models on every CI run so this list stays in lock-step with the migrations. Composite tenant-scoped FKs on `kb_edges` make cross-tenant edges **structurally impossible** at the database level.

`embedding_cache` is **intentionally NOT tenant-scoped** — the cache is a cross-tenant reuse layer keyed on `text_hash` UNIQUE alone (provider + model are retrieval-time filters). Sharing embeddings across tenants is a deliberate cost optimisation; eviction goes through `EmbeddingCacheService::flush($provider)` whenever the embedding model changes.

**The 6 v4 cycle rules guard the boundary**

| Rule | What it enforces |
|---|---|
| **R30** | Every Eloquent query against a tenant-aware table MUST be scoped to the active tenant via `forTenant()` or explicit `where('tenant_id', $ctx->current())` — cross-tenant leak is a GDPR catastrophe |
| **R31** | Every tenant-aware model MUST `use BelongsToTenant;` and list `'tenant_id'` in `$fillable`; `tests/Architecture/TenantIdMandatoryTest.php` enumerates the model list and gates new entries on every CI run |
| **R36** | Mandatory Copilot review + CI green loop on every PR — caught the v4 PR #98 regression where `embedding_cache` was wrongly tagged tenant-scoped |
| **R37** | `feature/vX.Y` integration branch + once-per-major merge to main — preserves stable consumers from in-flight major work |
| **R38** | Heavy work (`migrate:fresh`, big seeders) belongs in CLI workflow steps, not behind `php artisan serve` — keeps E2E reliable |
| **R39** | Tag `vX.Y.0-rcN` at every Wn weekly milestone closure pinned to the exact closure SHA — gives auditors and downstream consumers serialised milestone visibility |
---

## Features by area

Six grouped feature tables. Every entry is verifiable against the
codebase (see [`CLAUDE.md`](CLAUDE.md) section 3 for the component map,
the per-cycle STATUS docs under [`docs/v4-platform/`](docs/v4-platform/),
and the ADR set under [`docs/adr/`](docs/adr/)).

### Retrieval & Knowledge

| Feature | Description | Since |
|---|---|---|
| Hybrid retrieval (pgvector + FTS + reranker) | Vector top-K (pgvector cosine) fused with full-text top-K (PostgreSQL `to_tsvector` GIN index) via Reciprocal Rank Fusion; `Reranker` runs `0.55·vec + 0.25·kw + 0.05·heading` on top of 3× over-retrieval | v1.0 |
| Multi-format ingestion pipeline | `markdown`, `text`, **PDF** (`smalot/pdfparser` + Poppler fallback), **DOCX** (`phpoffice/phpword`) — all converge on `DocumentIngestor::ingest(SourceDocument)` via the `PipelineRegistry` (R23: FQCN validated at boot, `supports()` mutex-checked) | v3.0 |
| Canonical knowledge graph (9 node types / 10 edge types) | `kb_nodes` + `kb_edges` with `decision` / `runbook` / `standard` / `incident` / `integration` / `domain-concept` / `module-kb` / `rejected-approach` / `project-index` nodes and `depends_on` / `uses` / `implements` / `related_to` / `supersedes` / `invalidated_by` / `decision_for` / `documented_by` / `affects` / `owned_by` edges. Tenant-scoped composite FKs make cross-tenant edges structurally impossible | v3.0 |
| `CanonicalParser` (9 canonical types / 6 statuses) | YAML frontmatter parser via `symfony/yaml`; validates `type`, `status`, `slug`, `retrieval_priority` in `[0, 100]`. Invalid frontmatter degrades gracefully to non-canonical (R4) | v3.0 |
| `GraphExpander` 1-hop graph expansion at retrieval | Walks `kb_edges` from canonical seed docs at retrieval time, returns best chunk per neighbour; config-gated via `KB_GRAPH_EXPANSION_ENABLED=true` (default); degrades to no-op when no canonical docs exist | v3.0 |
| `RejectedApproachInjector` anti-repetition memory | Vector-correlates the query against `rejected-approach` canonical docs above `KB_REJECTED_MIN_SIMILARITY` (default 0.45); top-N (default 3) injected into the prompt under `⚠ REJECTED APPROACHES`; the LLM sees dismissed options *before* answering | v3.0 |
| Promotion pipeline (`suggest` / `candidates` / `promote`) | Three-stage human-gated API (ADR 0003): `/suggest` extracts candidates via LLM (writes nothing), `/candidates` validates a draft (writes nothing), `/promote` writes markdown + dispatches `IngestDocumentJob` (HTTP 202). Only humans and `kb:promote` CLI commit canonical storage | v3.0 |
| Idempotent SHA-256 ingestion | Composite UNIQUE on `(project_key, source_path, version_hash)`; re-pushing identical bytes is a no-op; a new version archives the prior; `$tries=3` with backoff `[10, 30, 60]` on `IngestDocumentJob` | v1.0 |
| `MarkdownChunker` section-aware fence-safe FSM | Custom line-based fence-aware state machine: emits one chunk per ATX heading section with `heading_path` breadcrumb (H1>H2>H3); fences (` ``` `, `~~~`) suppress heading detection inside code blocks; falls back to `paragraph_split` on docs without headings | v3.0 |
| `PdfPageChunker` page-aware PDF chunking | Slices on the `## Page N` boundaries emitted by `PdfConverter`; emits one chunk per non-empty page with `heading_path = "Page N"` for page-precise citations; intra-page split on `\n\n` when over `KB_CHUNK_HARD_CAP_TOKENS` | v3.0 |
| Embedding cache (cross-tenant by design) | DB-backed LRU cache keyed on SHA-256(`text`) UNIQUE; eliminates redundant API calls on re-ingestion and repeated queries; `EmbeddingCacheService::flush($provider)` on provider/model change. Conditional approval gate via `KB_EMBEDDING_CACHE_APPROVAL_THRESHOLD` (default 5000) on v4.2+ | v1.0 |
| Soft delete + retention sweep | `SoftDeletes` on `KnowledgeDocument`; hidden from every read path by default; `kb:prune-deleted` (03:30 daily) hard-deletes after `KB_SOFT_DELETE_RETENTION_DAYS` (default 30); cascades `kb_nodes` + `kb_edges` on final hard delete; immutable `kb_canonical_audit` row survives | v3.0 |
| MCP server `enterprise-kb` (10 tools) | 5 retrieval tools (`kb.search` / `kb.read_document` / `kb.read_chunk` / `kb.recent_changes` / `kb.search_by_project`) + 5 canonical/promotion tools (`kb.graph.neighbours` / `kb.graph.subgraph` / `kb.documents.by_slug` / `kb.documents.by_type` / `kb.promotion.suggest`) exposed at `/mcp/kb` for Claude Desktop / Claude Code / any MCP-compatible agent | v3.0 |
| Enterprise chat filters (10 dimensions) | `RetrievalFilters` DTO with `project_keys` / `tag_slugs` / `source_types` / `canonical_types` / `connector_types` / `doc_ids` / `folder_globs` / `date_from` / `date_to` / `languages`. Per-user saved presets with 404-not-403 cross-user isolation; `@mention` doc pinning via cursor-context detection | v3.0 |
| Reranker canonical boost + status penalty | Reranker applies `priority × 0.003` canonical boost and `superseded −0.4` / `deprecated −0.4` / `archived −0.6` status penalties on top of the vector/keyword/heading fusion; non-canonical chunks get zero adjustment (legacy behaviour preserved) | v3.0 |
| Source-aware chunkers + rich frontmatter capture | `PipelineRegistry::resolveChunker($sourceType)` dispatches per source (R23 FQCN-validated + `supports()` mutex-checked at boot) to: `NotionBlockChunker` / `ConfluencePageChunker` / `OfficeDocChunker` / `AtomicNoteChunker` / `JiraIssueChunker` / `PdfPageChunker` / `MarkdownChunker`. Document-level metadata carries `connector` + `external_id` + `external_url` + native timestamps; chunk-level metadata carries `source_type` + `search_tags` (top-level) + `recency_bucket` + ACL hint + status + preamble-path | v4.5 |
| `Reranker` Layer-4 signals (tag overlap, recency, status-active, preamble) | Four additive Layer-4 deltas: `tag_overlap_weight=0.05` + `preamble_match_weight=0.05` + `recency_weight=0.02` + `status_active_weight=0.02`, on top of the base `0.55·vec + 0.25·kw + 0.05·heading`. Max score ~1.44 (documented in code); base 4 signals still sum to 1.0 | v4.5 |
| `KbSearchService` facets (`source` + `tag`) | `searchWithContext()` accepts optional `facets` param; emits `facets[source]` + `facets[tag]` counts; backed by 2 new GIN-on-`jsonb` indexes (`source_type` + `search_tags`) plus 1 B-tree expression index on `metadata->>'recency_bucket'` on `knowledge_chunks`, all PostgreSQL-only (SQLite is a no-op) | v4.5 |
| Synonym Expansion (industry jargon ↔ plain language) | Per-(tenant, project) synonym groups managed under **Admin → Synonyms** (`kb_synonyms`). `SynonymExpander` bidirectionally expands a query — mentioning any group member also searches every other member — enriching the query embedding (all drivers) and OR-expanding the FTS `tsquery` (PostgreSQL, injection-safe). Connects internal acronyms / product codenames the base embedding model has never seen. Toggle via `KB_SYNONYM_EXPANSION_ENABLED` (default on; no-op without groups) + `KB_SYNONYM_CACHE_TTL_SECONDS` (default 300) | v8.7 |
| AI deep-analysis on document change + **delete** (Doc Insights) | When a document is **ingested, modified, or deleted**, an async job asks the LLM — given the changed doc + its closest semantic neighbours — to (a) suggest how to strengthen it, (b) surface its cross-references, and (c) flag which OTHER docs the change makes obsolete / in need of revision. **On a delete (v8.8)** a pre-delete snapshot drives an obsolescence-impact pass: which remaining docs now have a dangling reference. Results land in `kb_doc_analyses` (`trigger ∈ ingested\|modified\|deleted`), notify reviewers, and render under **Admin → Doc Insights** (`/app/admin/kb/insights`). **Suggest-only** — never mutates a doc (ADR 0003). Cost-gated: default ON for canonical docs, opt-in for non-canonical; **v8.8 adds a per-(tenant, project) override** (**Admin → Analysis Gate**, `kb_analysis_settings`) so an operator can turn the analysis on/off per project independently of the change / canonical-split / on-delete knobs; master switch `KB_CHANGE_ANALYSIS_ENABLED` | v8.7 · v8.8 |
| Per-query multilingual FTS | `QueryLanguageDetector` detects each query's language and stems with the matching PostgreSQL FTS dictionary (`italian` / `english` / …) instead of a single fixed one — a dependency-free, deterministic stopword heuristic that returns a dictionary ONLY on a confident, language-specific signal and otherwise **falls back to the configured default (R14 — never silently stems with the wrong dictionary)**. Default OFF (`KB_FTS_LANGUAGE_DETECTION`); supported set via `KB_FTS_SUPPORTED_LANGUAGES` | v8.8 |
| Content-gap analytics (Content Gaps) | Every refused chat turn — the deterministic grounding gate **and** the LLM self-refusal sentinel, across the sync **and** streaming chat paths — increments a per-`(tenant, project, normalized query, reason)` rollup in `kb_search_failures` (atomic, never breaks the chat path). **Admin → Content Gaps** (`/app/admin/kb/content-gaps`, API `/api/admin/kb/content-gaps`) ranks the most-asked unanswered questions so editors know what to write next, with a reason filter (options derived from the DB) and a one-click resolve to dismiss a gap once an article covers it. Toggle via `KB_CONTENT_GAPS_ENABLED` (default on) | v8.8 |
| Cloud Time Machine (version timeline + diff + restore) | Every re-ingest already retains the prior `knowledge_documents` row + its chunks (status `archived`); the Time Machine surfaces that history under **Admin → Time Machine** (`/app/admin/kb/time-machine/{id}`). `GET .../versions` lists the version timeline for a `(tenant, project, source_path)` family; `.../versions/diff?from=&to=` returns an in-house LCS line diff (`App\Support\MarkdownDiff`) of the reconstructed content; `POST .../restore-version` re-activates an archived version (transactional status-flip + canonical-identity transfer + `kb_canonical_audit` row) — no re-embedding, reuses retained chunks. `kb:prune-archived-versions` (daily) caps retained archived versions per family at `KB_KEEP_ARCHIVED_VERSIONS` (default 10); the live + soft-deleted rows are never pruned | v8.7 |
| **Tabular Review** (spreadsheet-style document extraction) | `tabular_reviews` + `tabular_cells` tables; `TabularReviewExtractor` runs ONE multi-column LLM call per document (cost `O(documents)` not `O(documents × columns)`); 17 format types (Mike's 9 + 8 AskMyDocs-new including the LLM-free `json_path` shortcut leveraging v4.5/W5.5 source-aware metadata); R14 loud refusal with red flag + reasoning on no-evidence / LLM error / JSON parse failure; DB-level upsert keyed on the composite UNIQUE `(tenant_id, review_id, document_id, column_index)` prevents duplicate rows under concurrent generate/regenerate. Admin SPA at `/app/admin/tabular-reviews` (list / show / create + grid view with flag-tinted cells + a per-cell flag glyph and inline reasoning text, plus an `aria-label` combining summary + flag + reasoning so AT users get the same context as sighted users — R15); SSE streaming variant `POST /api/admin/tabular-reviews/{id}/generate-stream` is wired end-to-end on the BE and emits per-cell `event: cell` frames, but the v4.7 GA SPA still calls the synchronous `/generate` endpoint — the progressive-paint FE consumer ships in v4.7.x alongside the Glide Data Grid migration (ADR 0010 D1) | v4.7 GA |
| **Workflows** (reusable prompt templates + AI-suggested catalogue) | `workflows` + `workflow_shares` + `hidden_workflows` tables; `WorkflowService` enforces ownership / share / hide semantics with per-user scope; `WorkflowSuggester` analyzes the tenant's KB (`MetadataPatternAnalyzer` detects recurring practices / projects / column patterns) and proposes up to 5 assistant + tabular workflow drafts via the LLM. 15 system-shipped templates (legal review / GDPR DPIA / DPA review / commercial agreement triage / privacy policy audit / vendor due diligence / employment policy review / regulatory mapping / risk register / litigation timeline / NDA review / IP-licensing review / consent record audit / processor-list extraction / contract-clause comparison). Admin SPA at `/app/admin/workflows` with Mine / Shared / System scope tabs + AI-suggest gallery + create dialog (**assistant type only in GA**; tabular create UI deferred to v4.7.x — tabular workflows ARE accepted by the JSON API and via the AI-suggest gallery's save-this path); email-based share model scales to invitees not yet on the platform | v4.7 GA |

### Chat & Conversation

| Feature | Description | Since |
|---|---|---|
| Vercel AI SDK v6 streaming | `MessageStreamController` emits SDK v6 `UIMessageChunk` frames (`start` / `text-start` / `text-delta` / `text-end` / `source-url` / `data` / `finish`) over SSE; first-token latency dropped from ~2.8 s synchronous to ~400 ms streaming on the Lighthouse baseline | v4.0 |
| `useChatStream()` React hook | `mapStatusToDataState()` adapter exposes `data-state="idle\|loading\|ready\|empty\|error"` for deterministic Playwright waits (SDK `submitted` and `streaming` statuses both collapse to `loading` per the R11 comment in `MessageThread.tsx`); unit-tested in `frontend/src/features/chat/map-status-to-data-state.test.ts` | v4.0 |
| Citations panel | Every assistant reply ships the source documents (`document_id`, `title`, `source_path`, `slug`, `project_key`, `headings`, `chunks_used`); persisted on `messages.metadata.citations`; survives conversation reload | v1.0 |
| Chat-side **Related** graph panel | A lazy, collapsible panel under each grounded answer shows the **1-hop knowledge-graph neighbours** of the cited canonical docs (both directions — dependencies AND docs that depend on the cited one), so a user can navigate the graph straight from an answer. Backed by `GET /api/kb/related` (`RelatedGraphService` walks `kb_edges`, tenant + project scoped, config-gated by `KB_GRAPH_EXPANSION_ENABLED`, no-op without a canonical graph). **ACL-safe** — a neighbour the user can't access shows its slug but never its title | v8.8 |
| Conversation history | `conversations` + `messages` tables (user-scoped); inline rename, delete with confirmation, AI-generated title after first turn, full multi-turn history sent to provider on every request | v1.0 |
| Composite confidence score (0–100) | `ConfidenceCalculator`: `0.40·mean_top_k_sim + 0.20·threshold_margin + 0.20·chunk_diversity + 0.20·citation_density`; renders as `high / moderate / low / refused` tier in the `ConfidenceBadge` | v3.0 |
| Refusal handling | Two refusal paths: deterministic `no_relevant_context` short-circuit (Mockery `shouldNotReceive('chat')` per R26 proves no LLM call) and `llm_self_refusal` via exact-match-after-trim `__NO_GROUNDED_ANSWER__` sentinel. `RefusalNotice` uses `role="status"` not `alert` (R24) | v3.0 |
| `@mention` doc pinning | Type `@docname` in the composer → `/api/kb/documents/search` autocomplete → `MentionPopover` with cursor-context detection → pinned `doc_id` forces inclusion in retrieval even when scored below the similarity floor | v3.0 |
| Filter chips + saved presets | Persistent `FilterBar` with per-dimension removable `FilterChip`s; tabbed `FilterPickerPopover` (Project / Type / Tag / Folder / Date / Language); per-user saved presets at `RESTful /api/chat-filter-presets` (lossless round-trip) | v3.0 |
| Speech-to-text (Web Speech API) | Browser-native mic input via `webkitSpeechRecognition`; zero external service, zero cost; defaults to `it-IT` (configurable). Chrome / Edge / Safari supported | v1.0 |
| Few-shot learning loop | Thumbs up/down rating on every assistant message; `FewShotService` retrieves last 3 positively-rated Q&As per user/project and injects as "Examples of Well-Rated Answers" in the system prompt | v1.0 |
| Smart visual artifacts | `~~~chart` JSON blocks render as Chart.js bar/line/pie/doughnut; `~~~actions` JSON renders as copy/download buttons; every code block ships a "Copy" button | v1.0 |
| Multi-provider AI federation | OpenAI / Anthropic / Gemini / OpenRouter via raw `Http::` calls (no SDK); Regolo via the `padosoft/laravel-ai-regolo` SDK adapter on `laravel/ai`; `AiManager::chat()` + `chatStream()` + `embeddings()`; per-provider streaming where supported (all 5 native or via `FallbackStreaming` trait); chat and embeddings providers configured separately | v1.0 |
| Stateless JSON chat API | `POST /api/kb/chat` synchronous endpoint kept as backward-compat fallback alongside the v4 SSE streaming path; same hybrid retrieval pipeline + refusal short-circuit + confidence score serve both | v1.0 |
| Stop / regenerate / branch / inline-edit affordances | Vercel AI SDK UI Tier 1 closure: stop-streaming via `AbortController`; regenerate-last-assistant; branch-from-message endpoint (forks the conversation tree); inline-edit user message; copy-code-block. All wired on `MessageStreamController` + the `useChatStream()` hook | v4.5 |
| Per-message provider/model/cost metadata | Enhanced badge below every assistant message shows `provider`, `model`, `started_at`, prompt + completion tokens, and derived USD cost when `config('ai.cost_rates')` is populated (keyed by `provider → model → {input, output}`); cost is omitted (not zero) when rates are missing. Public lookup at `GET /api/chat/cost-rates` with 1-hour CDN cache | v4.5 |
| Suggested follow-up pills | `SuggestedFollowupGenerator` derives three follow-up prompts from the assistant's last reply via `AiManager::chat()`; renders as clickable pill chips above the composer; clicking submits via the streaming endpoint. Best-effort — provider error / parse failure / empty response returns `[]` and the row is not rendered. Triggered once on `onFinish` per assistant turn at `POST /conversations/{id}/suggested-followups` | v4.5 |

### Security & Compliance

| Feature | Description | Since |
|---|---|---|
| PII redaction at 11 persistence boundaries | `padosoft/laravel-pii-redactor` v1.2 wired at: (1) chat-message middleware, (2) embedding-cache pre-redact, (3) AI-insights snippet sanitiser, (4) operator detokenize endpoint, (5) Monolog log channel processor, (6) failed-jobs sanitiser via `JobFailed` listener with deterministic UUID match, (7) `Conversation`+`Message` `saving` observers, (8) `ChatLog::creating` observer, (9) `AdminCommandAudit::creating` observer, (10) `AdminInsightsSnapshot::creating` observer (6 JSON columns), (11) Flow `CurrentPayloadRedactorProvider` contract binding (covers run input + step results + audit + webhook outbox + approvals in one wire). All 5 v4.3 env knobs default OFF | v4.3 |
| Multi-tenant isolation (R30 + R31) | 20 tenant-aware models carry `tenant_id` (enumerated in `tests/Architecture/TenantIdMandatoryTest::TENANT_AWARE_MODELS`); `BelongsToTenant` trait auto-fills from `TenantContext` on `creating`; composite tenant-scoped FK on `kb_edges` makes cross-tenant edges structurally impossible; architecture test `TenantIdMandatoryTest` gates new models | v4.0 |
| `ResolveTenant` middleware + 4 resolvers | Header (`X-Tenant-ID`), domain regex, authenticated user column, or `'default'` (v3 backward compat); per-request singleton; queue workers re-bind tenant via try/finally restore | v4.0 |
| Spatie RBAC (5 roles) | `super-admin` / `admin` / `editor` / `viewer` / `dpo` (DPO added in v4.2 for PII admin); permission matrix grouped by dotted-prefix domain; gates wired at controller + route + middleware layer | v3.0 |
| Sanctum stateful SPA + Bearer tokens | Two transports feed the same guard: cookie-based SPA (`/sanctum/csrf-cookie` + `X-XSRF-TOKEN`) and personal access tokens for API clients / MCP / GitHub Action; `AuthenticateForSse` middleware emits JSON 401 (not HTML redirect) on streaming endpoints | v3.0 |
| Immutable audit trail | `kb_canonical_audit` records every promote/update/deprecate/hard-delete (no `updated_at`, no FK to docs — survives hard deletes for forensic access); `admin_command_audit` stamps every destructive maintenance run with started/completed/failed timestamps + output/error capture | v3.0 |
| DB-backed single-use confirm tokens for destructive commands | `AdminCommandNonce` table; signed `confirm_token` issued at preview, consumed inside `DB::transaction` with `lockForUpdate()` + `update()` in the same closure (R21 atomic invariant); composite UNIQUE on `(token_hash, consumed_at)` | v3.0 |
| 6-gate Artisan whitelist runner | `CommandRunnerService` enforces: (1) whitelist lookup in `config('admin.allowed_commands')`, (2) args_schema validation, (3) confirm_token + single-use nonce, (4) Spatie permission gate (`commands.run` admin / `commands.destructive` super-admin), (5) audit-before-execute, (6) per-user `throttle:10,1` rate limit | v3.0 |
| 2FA stub | `TwoFactorController` skeleton behind `AUTH_2FA_ENABLED=false` for future TOTP rollout | v3.0 |
| Operator detokenize endpoint | `POST /api/admin/logs/chat/{id}/detokenize` round-trips a tokenised chat-log row back to original PII text; 422 when strategy ≠ `tokenise`; 403 when caller lacks `kb.pii_redactor.detokenize_permission` (default `pii.detokenize`); every 200/403 writes `admin_command_audit` row | v4.1 |
| GDPR-aware soft delete + retention | `KB_SOFT_DELETE_ENABLED=true` (default); `KB_SOFT_DELETE_RETENTION_DAYS` (default 30); `kb:prune-deleted` (03:30 daily) hard-deletes file on disk + chunks + audit-trails the deprecation | v3.0 |
| CSRF + CORS hardening | `SANCTUM_STATEFUL_DOMAINS` + `CORS_ALLOWED_ORIGINS`; wildcard `*` forbidden because `supports_credentials=true`; whitelist-driven origin parsing with whitespace-safe CSV (R19) | v3.0 |

### Admin & Operations

| Feature | Description | Since |
|---|---|---|
| Admin SPA shell (`/app/admin/*`) | React 18+ (React 19 since v4.3) + TypeScript + Vite + TanStack Router/Query + shadcn/ui; dark-first glassmorphism; code-split routes (~400 KB initial gzipped); RBAC-gated via Spatie; sidebar visibility enforced server-side. **Since v8.8.2 a single unified, grouped + collapsible sidebar** (`nav-config.ts` SSOT — 23 sections in 5 groups) replaces the old primary-rail + secondary-`AdminShell`-rail double menu, and every admin surface now renders **center-only with no nested second admin shell** (cross-mounted sister-package admins drop their own sidebar/header into an in-content tab strip; the Flow surface launches its cockpit in a new tab) — so the host's unified rail is the only menu on any `/app/admin/*` page | v3.0 · v8.8.2 |
| Dashboard KPIs + health | 6 KPIs (docs / chunks / chats / p95 latency / cache hit rate / canonical coverage) + 6 health probes (db / pgvector / queue / kb-disk / embeddings / chat) + 3 code-split recharts cards (chat volume area, token burn stacked, rating donut) + top projects + activity feed; 30s `Cache::remember` layer keyed by kind+project+days | v3.0 |
| Users + Roles + Memberships | Filterable users table with soft-delete + restore; 3-tab edit drawer (Details / Roles / Memberships with `scope_allowlist` JSON editor); Spatie-backed role CRUD with grouped permission matrix; `project_memberships` rows scope canonical visibility per project | v3.0 |
| KB Explorer (tree + 5 right-panel tabs) | Memory-safe `chunkById(100)` tree walker with canonical-aware modes (`canonical \| raw \| all`, `with_trashed=0\|1`); right-panel tabs Preview (remark-rendered + frontmatter pills) / Meta (canonical grid + AI tags) / **Source** (CodeMirror 6 editor with PATCH `/raw` → validate → write → audit → re-ingest) / **Graph** (1-hop tenant-scoped subgraph, SVG radial, ≤ 50 nodes) / **History** (paginated `kb_canonical_audit`) | v3.0 |
| PDF export (Browsershot + Dompdf fallback) | `PdfRenderer` interface with `BrowsershotPdfRenderer` primary (full CSS / fonts / charts) and `DompdfPdfRenderer` fallback (no headless Chromium dependency); A4 print-optimised; renderer chosen at controller level (R23 registry mutex) | v3.0 |
| Log viewer (5 tabs) | Five deep-linkable tabs (`?tab=chat\|audit\|app\|activity\|failed`): chat logs with model/project/rating filters; canonical audit trail with event-type/actor filters; reverse-seek `SplFileObject`-powered application log tailer (whitelist regex, 2000-line cap, optional live polling via `?live=1`); Spatie activity log; failed-jobs read-only table | v3.0 |
| Maintenance command runner | Three-step React wizard (Preview → Confirm with type-in for destructive → Run → Result); whitelist + args_schema + confirm_token + Spatie gates + audit + throttle (see Security row); scheduler widget reports next run of every queued command | v3.0 |
| AI insights panel | Daily `insights:compute` (05:00 UTC) writes one row to `admin_insights_snapshots`; six widget cards (Promotion Suggestions / Orphan Docs / Suggested Tags / Coverage Gaps / Stale Docs / Quality Report) read from JSON columns; O(1) DB read, zero LLM calls per page load | v3.0 |
| Per-user notification feed (bell + panel + API) | Top-bar `<NotificationBell />` polls `/api/notifications/unread-count` every 30s (R11 `data-state` + `aria-busy`); `/app/admin/notifications` full panel with `unread\|read\|dismissed\|all` tabs, BE-derived event-type filter (R18 — `GET /api/notifications/event-types`), pagination, per-row mark-read/dismiss, bulk mark-all-read scoped to the active filter; HMAC-signed one-click email unsubscribe; channels (`in_app`, `email`) ship as part of v8.0/W1.3, joined by **W2.1** external channels `discord` + `slack` + `teams` + generic `webhook` (all default-OFF — opt in by setting the corresponding `NOTIFICATIONS_DISCORD_URL` / `NOTIFICATIONS_SLACK_URL` / `NOTIFICATIONS_TEAMS_URL` / `NOTIFICATIONS_WEBHOOK_URL` env var; the generic webhook channel additionally signs every request with `X-AskMyDocs-Signature: sha256=<hmac>` when `NOTIFICATIONS_WEBHOOK_SECRET` is set). External-channel sends route through the queueable `SendExternalNotificationJob` with `[5, 30, 120]s` backoff (R14 — terminal failure recorded on the row's `channel_dispatch_log`); 4xx responses (except 429) are surfaced as `failed` immediately without retry. Per-user `notification_preferences` matrix wired in v8.0/W2; daily `notifications:prune` 04:10 retains rows for `NOTIFICATIONS_RETENTION_DAYS` (default 90, set 0 to disable) — see env block below. R21 atomic mark-read + dismiss (`whereNull('read_at')->update(...)` + COALESCE); R30 cross-tenant isolation enforced on every endpoint including mutations; presenter strips forensic `channel_dispatch_log` + `tenant_id` + `user_id` from the FE feed. | v8.0 |
| Stale-doc review + weekly digest (KB lifecycle) | `kb:stale-review-sweep` (daily) fires a `kb_doc_stale_review` notification for any document untouched longer than `KB_HEALTH_STALE_REVIEW_MONTHS` (default 6, set 0 to disable) — time-based, every doc type, ACL-scoped to eligible reviewers, idempotent per content version via a `metadata.stale_review_notified_at` marker. `notifications:digest-weekly` (Monday) aggregates the week's `notification_events` per tenant into a `notification_digests` row and emails each email-opted-in user their OWN roundup (`WeeklyDigestMail`), stamping `sent_at` + `recipients_count` — so a user can keep noisy per-event email OFF and still get the Monday digest. Both slots are env-tunable (`SCHEDULE_KB_STALE_REVIEW_SWEEP_*` / `SCHEDULE_NOTIFICATIONS_DIGEST_WEEKLY_*`). | v8.7 |
| Cross-mounted admin SPAs (3 packages) | `padosoft/laravel-pii-redactor-admin` v1.0.2 at `/admin/pii-redactor` (cross-mount since v4.4/W2) + `padosoft/laravel-flow-admin` v1.0.0 at `/admin/flows` + `padosoft/eval-harness-ui` v1.0.0 at `/admin/eval-harness` non-prod-only (cross-mount since v4.4/W3, 3 fail-closed fences preserved). **Since v8.8.2 each package admin mounts center-only with no nested chrome (the host unified rail is the only menu):** the PII and Eval trees cross-mount their React panels directly; the Flow surface renders a native host panel (KPI probe of `/admin/flows/api/live` + section cards) that links out to the full Flow cockpit in a new tab (`target="_blank"`) — so no Blade+Alpine page is ever nested inside the host chrome | v4.2 · v8.8.2 |
| Laravel scheduler (13+ entries) | `kb:prune-embedding-cache` 03:10 / `chat-log:prune` 03:20 / `kb:prune-deleted` 03:30 / `kb:rebuild-graph` 03:40 / `queue:prune-failed` 04:00 / **`notifications:prune` 04:10 (v8.0/W1.5, default 90d retention via `NOTIFICATIONS_RETENTION_DAYS`; set 0 to disable)** / `admin-audit:prune` 04:30 / `kb:prune-orphan-files` 04:40 / `admin-nonces:prune` 04:50 / `insights:compute` 05:00 / `eval:nightly` 05:30 (v4.3+, default OFF) / **`kb:stale-review-sweep` 03:55 + `notifications:digest-weekly` Mon 07:00 (v8.7/W2)**; all `onOneServer()->withoutOverlapping()`. **v8.0/W2.4 — every slot's cron + enabled flag is now env-tunable** via the 24 `SCHEDULE_*_CRON` / `SCHEDULE_*_ENABLED` knobs (see `.env.example` Tier-1 scheduler section); defaults preserve the overnight rotation above byte-for-byte. The `GET /api/admin/commands/scheduler-status` widget surfaces the effective cron times after env overrides. | v3.0 |
| Sidebar gating + R29 testid hierarchy | Sidebar entries always rendered, visibility enforced server-side via per-route fences (RequireRole + middleware `can:` + env `abort(404)`); every actionable element uses `feature-resource-{id}-{action[-substep]}` testid convention for Playwright stability | v3.0 |
| Connector admin SPA (`/app/admin/connectors`) | React DataTable with per-connector install/uninstall flow; OAuth callback handler at `/app/admin/connectors/$key/callback`; per-installation `connector_installations` + `connector_credentials` rows (encrypted via `OAuthCredentialVault`); scheduler-driven `ConnectorSyncJob`; Spatie `manageConnectors` super-admin gate at controller + route layer | v4.5 |

### Integrations & Extensibility

| Feature | Description | Since |
|---|---|---|
| MCP server (inward, 10 tools) | `enterprise-kb` server at `/mcp/kb` exposes the KB to Claude Desktop / Claude Code / any MCP-compatible agent (5 retrieval + 5 canonical/promote tools); `auth:sanctum` + `throttle:api` | v3.0 |
| GitHub composite action `ingest-to-askmydocs` (v2) | Reusable action with diff-mode (every push: `git diff --diff-filter=AMR` ingest + `D`+`R` delete batches via `DELETE /api/kb/documents`) and full-sync mode; canonical-folder aware; max 100 docs / batch; `--rawfile` for ARG_MAX safety (R5) | v3.0 |
| 9 registered Flow definitions (saga / compensation) | `kb.ingest` (5-step) / `kb.canonical-index` (3-step) / `kb.promote` (4-step approval-gated, first use of `approval-gate` primitive) / `kb.delete` (4-step) / `kb.prune-deleted` / `kb.prune-embedding-cache` (conditional approval gate) / `kb.prune-chat-logs` / `kb.rebuild-graph` / `kb.ingest-folder` (3-step fan-out). Reverse-order compensation chains; persisted to `flow_runs` + `flow_steps` + `flow_audit` + `flow_approvals` + `flow_webhook_outbox` | v4.2 |
| Multi-AI-provider abstraction | OpenAI / Anthropic / Gemini / OpenRouter via raw `Http::` (no SDK); Regolo via the `padosoft/laravel-ai-regolo` SDK adapter on `laravel/ai`; `FallbackStreaming` trait synthesises single-chunk SSE for providers without native streaming | v1.0 |
| Pluggable ingestion pipeline | 3 contracts (`ConverterInterface` / `ChunkerInterface` / `EnricherInterface`); `PipelineRegistry` with FQCN-validated-at-boot + `supports()` mutex (R23); add a new format = implement 3 interfaces + register in `config/kb-pipeline.php` | v3.0 |
| Pluggable chat-log driver | `ChatLogDriverInterface`; `database` driver shipped; BigQuery / CloudWatch are extension points via `ChatLogManager::resolveDriver()` | v1.0 |
| Sister `padosoft/*` package stack | `laravel-ai-regolo` v1.0 (Regolo provider for `laravel/ai`) + `laravel-pii-redactor` v1.2 (PII detection with EU country packs: Italy + Germany + Spain) + `laravel-pii-redactor-admin` v1.0.2 + `laravel-flow` v1.0 (saga engine + approval gates + webhook outbox + replay) + `laravel-flow-admin` v1.0.0 + `eval-harness` v1.2 (golden datasets + 7 metrics + cohorts + adversarial + LLM-as-judge) + `eval-harness-ui` v1.0.0 — every package MIT, every architecture test enforces standalone-agnostic invariants (zero refs to `KnowledgeDocument` / `kb_*` tables / `lopadova/askmydocs` in `src/`) | v4.2 |
| External Patent Box dossier tool | `padosoft/laravel-patent-box-tracker` v0.1 generates audit-grade Italian Patent Box dossiers; **deliberately NOT in AskMyDocs `composer.json`** — operators install it in a separate Laravel project (R37 standalone-agnostic) and consume `tools/patent-box/2026.yml` from this repo. Commercialista-validated 2026-05-02 | v4.0 |
| Connector framework + 7 native connectors | Plugin/package architecture (`ConnectorInterface` 10-method contract + `BaseConnector` + `OAuthCredentialVault` + `ConnectorRegistry` with R23 FQCN-validated discovery via `config/connectors.php::built_in` OR `composer.json::extra.askmydocs.connectors`). 7 native connectors: `google-drive` + `notion` + `evernote` + `fabric` + `onedrive` + `confluence` + `jira` (all inline for v4.5; extracted to `padosoft/askmydocs-connector-*` packages in v4.6 per ADR 0008 D1) | v4.5 |
| **MCP client framework** | AskMyDocs as MCP **CLIENT** (outward direction) — tenant-scoped `McpServerRegistry` + `McpToolCallingService` orchestrates multi-turn tool-calling loops (max 3 iterations, configurable); `McpToolAuthorizer` gates per-user/per-server/per-tool access; v7.0/W6.3.B retired the v5.0 Node sidecar and now drives JSON-RPC directly over native HTTP / SSE / stdio transports via `padosoft/askmydocs-mcp-pack`; `McpHandshakeService` persists initialize+tools/list under `mcp_servers.handshake_response_json`; immutable audit trail in `mcp_tool_call_audit` (with `transport_error` status when the upstream connection is unreachable but not timing out); admin API for server CRUD + handshake + tool-list management; `AI_AGENTIC_ENABLED` master switch; OpenAI + OpenRouter providers wire tool schemas automatically | v5.0 |
| **MCP admin web panel** (optional companion) | Standalone Laravel package `padosoft/askmydocs-mcp-pack-admin` ships a React SPA that cross-mounts under `/admin/mcp-pack` and surfaces every MCP-side capability above through 12 routes (Dashboard, Servers list + new-server wizard, per-server detail with 7 tabs, Tools matrix + try-it, Resources tree, Prompts playground, Audit log + drilldown, Circuit breakers, OpenAPI explorer, Settings, Help). **v1.1.0** (shipped 2026-05-18) drives the full live `padosoft/askmydocs-mcp-pack` v1.5+ REST surface end-to-end — 22 typed endpoints, 23 TanStack Query hooks across read+write paths, R21 two-call confirm-token protocol on tool invoke / audit replay / breaker reset, SSE live-feed consumer, 154 Vitest specs covering every binding. Composer-discoverable, RBAC-gated, dark+light themed — see [Optional: mount the MCP admin web panel](#optional-mount-the-mcp-admin-web-panel) | v7.0 |

### Quality & Observability

| Feature | Description | Since |
|---|---|---|
| RAG regression CI gate | `.github/workflows/rag-regression.yml` triggers on every PR touching `app/Services/Kb/**` / `app/Ai/**` / `app/Eval/**` / `tests/Eval/golden/**` / `composer.lock`. Drives the golden Q&A set through the LIVE `KbSearchService` + `GraphExpander` + `RejectedApproachInjector` + `AiManager::chat()` against the seeded `DemoSeeder` corpus; fails the build on regression; 14-day artifact retention | v4.2 |
| 4 eval datasets × per-lane metric stacks | 1 baseline (42 samples, 4 metrics: `contains` + `cosine-embedding` + custom `CosineGroundednessMetric` + custom `CitationGroundednessMetric`) + 3 adversarial cohorts (12 samples each: out-of-corpus refusal / contradicting claims / rejected-approach trigger — 3 metrics: `contains` + `refusal-quality` + `CitationGroundednessMetric`). Cohorts: `source_type × canonical_type × language × query_complexity` | v4.2 |
| Custom RAG-specific metrics | `CosineGroundednessMetric` (cosine of answer-vs-cited-chunk-text — catches "fluent answer that doesn't track its own citations") and `CitationGroundednessMetric` (every expected `source_path` must appear; phantom citations cap score at 0.5; refusal-with-citations drops to 0) | v4.2 |
| `eval:nightly` cron with LLM-as-judge | Default-OFF via `EVAL_NIGHTLY_ENABLED`; three-fence cost guard (enable flag + `EVAL_NIGHTLY_LIVE` provider-key check + key presence check inside the command); R26 defense-in-depth test pre-seeds both flags + asserts `Http::assertNothingSent()`; persisted `<date>.json` + `<date>.md` artefacts; regression detection vs prior baseline; `Log::alert()` + `<date>.alert.json` sidecar on regression > `EVAL_NIGHTLY_REGRESSION_THRESHOLD` (default 0.05); auto-prunes beyond `EVAL_NIGHTLY_RETENTION_DAYS` (default 90); 3 ops flags (`--dry-run` / `--status` / `--prune-only`). ADR 0006 | v4.3 |
| Adversarial nightly opt-in | 2 env knobs (`EVAL_NIGHTLY_ADVERSARIAL` / `EVAL_NIGHTLY_ADVERSARIAL_DATASETS`) default OFF; runs the 3 adversarial datasets after baseline SUCCESS using the `nightly` batch profile; advisory-only summary sidecar; baseline-gates-adversarial alerting policy. ADR 0007 | v4.4 |
| Regression-detection self-test | `RegressionDetectionTest` proves the gate ACTUALLY catches regressions: runs the metric stack against a canonical SUT (asserts green report) then against a hallucinating SUT (asserts `citation-groundedness-strict` mean AND macro_f1 drop, strict `>` comparison per R16) | v4.2 |
| Playwright E2E suite | Real Postgres + pgvector in CI; deterministic via `data-state` + `data-testid` contract (R11); happy-path + failure-injection per feature (R12); real data only — `page.route()` reserved for external boundaries (R13) gated by `scripts/verify-e2e-real-data.sh` | v3.0 |
| Test inventory | **~1695 PHPUnit tests** across PHP 8.3 / 8.4 / 8.5 + **408 Vitest react scenarios** + **18 Vitest legacy** + 39 Playwright spec files + RAG regression workflow — all green as of v5.0.0 GA | v5.0 |
| Opt-in live-test recording infrastructure | `tests/Live/Connectors/` skeleton + `LiveConnectorTestCase` per-provider env-var guard: each test gates on `CONNECTOR_<PROVIDER>_LIVE=1` (e.g. `CONNECTOR_NOTION_LIVE=1`) and needs the provider credential vars (e.g. `CONNECTOR_NOTION_TOKEN`, `CONNECTOR_CONFLUENCE_TOKEN`+`CONNECTOR_CONFLUENCE_CLOUD_ID`); fixture recording is enabled via `CONNECTOR_RECORD_FIXTURES=1`. Default CI runs `Unit` + `Feature` only (zero provider cost). Manual workflow `.github/workflows/live-recording-nightly.yml` available via `workflow_dispatch`. Junior-proof per-provider setup in [`docs/v4-platform/RUNBOOK-live-fixture-recording.md`](docs/v4-platform/RUNBOOK-live-fixture-recording.md) | v4.5 |
| Structured chat logging | DB driver (extensible to BigQuery / CloudWatch); `session_id` / `user_id` / `question` / `answer` / `project_key` / `ai_provider` / `ai_model` / `chunks_count` / `sources` / `prompt_tokens` / `completion_tokens` / `total_tokens` / `latency_ms` / `client_ip` / `user_agent` / `extra` columns; try/catch — never propagates failures | v1.0 |
| 40 codified review rules (R1–R43; R33–R35 reserved) | Distilled from live Copilot findings — R14–R21 alone from ~110 findings catalogued at PR #16 across PRs #16–#31 (`docs/enhancement-plan/COPILOT-FINDINGS.md`), with earlier and later rules appended over the project's PRs; mirrored in `CLAUDE.md` + `.github/copilot-instructions.md` + per-rule `.claude/skills/<rule>/`; auto-loaded by Claude Code when trigger conditions match; pre-push agent at `.claude/agents/copilot-review-anticipator.md`. The set grows over time — started at v3.0 (R1–R29); R42/R43 were added in v8.8.1/v8.8.2 | v3.0 · v8.8.2 |
| ADR set (ADR 0001 → 0010) | Architectural decisions records: 0001 ingestion path, 0002 storage agnostic, 0003 human-gated promotion, 0004 v4.2 sister-package integration, 0005 React 19 host bump + iframe→cross-mount deferral, 0006 nightly eval cron, 0007 adversarial nightly opt-in, 0008 v4.5 universal connectors + source-aware ingestion + modern chat surface, 0009 v4.6 connector package extraction, 0010 v4.7 tabular review + workflows architecture | v3.0 |
| Retrieval-quality benchmark (`kb:benchmark`) | A 5-doc labelled corpus (markdown + PDF + DOCX, graph-linked + rejected-approach) under `resources/benchmark/` + 14 gold queries scored on **nDCG@k / MRR / precision@k / citation-precision / graph-recall / rejected-recall / refusal-accuracy** via `RetrievalQualityMetrics`. `--stub` runs anywhere (SQLite + PHP-cosine, no key); LIVE uses real embeddings + pgvector. Dated JSON+MD scorecards in `storage/app/kb-benchmark/`. The deterministic `RetrievalPipelineScenarioTest` runs the FULL pipeline (ingest → per-type chunk → embed → graph → search → citations → refusal) in CI with **no mocks** — closing the gap that let search bugs ship green | v8.2 |

---

#### Running the retrieval-quality benchmark

The benchmark measures the *real* quality of search / vector / rerank /
citations / graph / rejected-injection / refusal end-to-end, and produces a
dated scorecard you can re-run after any retrieval change (or at a milestone
close) to catch regressions.

**1. Deterministic (no key, runs anywhere — CI-safe):**

```bash
php artisan kb:benchmark --stub
# SQLite + PHP-cosine + a deterministic embedder. Exercises the full pipeline
# wiring + lexical ranking. (Also runs as a PHPUnit feature test:
# vendor/bin/phpunit tests/Feature/Benchmark/)
```

**2. LIVE (real embeddings + LLM — true semantic quality):**

```bash
# a) Postgres + pgvector. A throwaway durable container (host port 5433,
#    leaves your local PostgreSQL untouched):
docker run -d --name askmydocs-pgvector --restart unless-stopped \
  -e POSTGRES_DB=askmydocs -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=aaaa \
  -p 5433:5432 -v askmydocs-pgvector-data:/var/lib/postgresql/data \
  pgvector/pgvector:pg16
# later just: docker start askmydocs-pgvector  /  docker stop askmydocs-pgvector

# b) Point the app at it + an embeddings provider, then migrate + run:
DB_PORT=5433 php artisan migrate --force
DB_PORT=5433 php artisan kb:benchmark        # uses AI_EMBEDDINGS_PROVIDER (e.g. openrouter)
```

`.env` for LIVE: `AI_EMBEDDINGS_PROVIDER=openrouter` (or `openai`) with the
key set — **Anthropic has no embeddings API**, so it can drive chat
(`AI_PROVIDER`) but not the vector side. `text-embedding-3-small` is 1536-dim
= the stock pgvector column (no migration).

**3. Answer faithfulness (real LLM answers — v8.3):**

```bash
# Adds answer-faithfulness to the scorecard: per answerable query it
# generates the REAL chat answer (same kb_rag prompt the app uses) and
# scores cosine(answer, grounding-text) — catching a fluent answer that
# drifts from its own grounding.
DB_PORT=5433 php artisan kb:benchmark --with-answers
```

`--with-answers` makes LIVE chat **and** embeddings calls (even under
`--stub`, which only stubs the *retrieval* ranking) — it needs a configured
chat + embeddings provider; the command warns early if the chat provider has
no key. Faithfulness embeddings bypass `embedding_cache` so a benchmark never
mutates production cache state.

**Reading the scorecard.** The command prints a per-query table + an
aggregate block and writes `storage/app/kb-benchmark/<timestamp>.{json,md}`.
Enterprise pass thresholds (gate with `--gate`, exit non-zero on miss):
`nDCG@5 ≥ 0.80`, `MRR ≥ 0.85`, `citation-precision ≥ 0.90`,
`refusal-accuracy ≥ 0.95` (tunable via `kb.benchmark.*`). When
`--with-answers` ran, an `answer-faithful.` line is added.

**Validating faithfulness with the eval-harness (live LLM-as-judge).** The
benchmark scores faithfulness with embedding cosine; for an independent
judge-graded read, the `eval:nightly` cron runs the golden Q&A
(`tests/Eval/golden/`) through the real RAG pipeline and the
`padosoft/eval-harness` LLM-as-judge + groundedness metrics. To run it LIVE
against a real model (otherwise it uses a deterministic fake):

```bash
# Point the judge + embeddings metrics at any OpenAI-compatible endpoint
# (OpenRouter shown) and flip the three live gates:
EVAL_LIVE_AI=1 EVAL_NIGHTLY_ENABLED=true EVAL_NIGHTLY_LIVE=true \
EVAL_HARNESS_JUDGE_ENDPOINT=https://openrouter.ai/api/v1/chat/completions \
EVAL_HARNESS_JUDGE_MODEL=openai/gpt-4o-mini EVAL_HARNESS_JUDGE_API_KEY=$OPENROUTER_API_KEY \
EVAL_HARNESS_EMBEDDINGS_ENDPOINT=https://openrouter.ai/api/v1/embeddings \
EVAL_HARNESS_EMBEDDINGS_MODEL=openai/text-embedding-3-small \
EVAL_HARNESS_EMBEDDINGS_API_KEY=$OPENROUTER_API_KEY \
DB_PORT=5433 php artisan eval:nightly
# Reports land in storage/app/eval-harness/nightly/<date>.{json,md}.
```

A live run on the seeded corpus scores **citation-groundedness ≈ 0.98** and
cosine-groundedness ≈ 0.62 (p95 1.0) — the answers track their citations.
(The `contains` metric reads ~0 by design: it is a verbatim-substring check,
and a real LLM paraphrases rather than echoing the gold string — that is what
the cosine + judge metrics exist to measure.)

**Seeing compliance + PII live in your own runs.** The data-mutating
observability features (chat logging + PII redaction) ship **default-OFF** for
production safety; the AI Act disclosure header (`X-AI-Disclosure`, Art. 50)
and token-level explainability are **on by default** (they add no data
mutation). To watch the opt-in ones fire locally, flip the relevant flags in
`.env` (mask strategy needs no salt):
`CHAT_LOG_ENABLED=true`, `KB_PII_REDACTOR_ENABLED=true` +
`KB_PII_REDACT_PERSIST=true` + `KB_PII_REDACT_ANSWERS=true` +
`PII_REDACTOR_ENABLED=true` + `PII_REDACTOR_STRATEGY=mask`. The consolidated
`KbChatFullStackComplianceTest` proves one
chat turn fires grounded citations + the disclosure header + a `chat_logs` row
+ PII answer-redaction together.

**Milestone ritual.** Run `php artisan kb:benchmark --stub` (deterministic)
at the close of any retrieval-touching milestone, and the LIVE run before
shipping a retrieval change — if a knob (rerank weights,
`KB_RERANK_NORMALIZE_SCORES`, `kb.refusal.*`, `kb.mentions.mode`,
`kb.diversification.*`) moves the scorecard, you'll see it.

---

## Canonical Knowledge Compilation

AskMyDocs's signature differentiator: every retrieved chunk passes through a
**typed canonical knowledge graph** with **human-gated promotion**. The LLM
proposes; only humans (or operators via `kb:promote`) commit canonical storage.

The three-stage promotion API is the architectural boundary between "AI
drafting" and "knowledge canon":

| Stage | Route | Effect |
|---|---|---|
| **Suggest** | `POST /api/kb/promotion/suggest` | LLM extracts candidate artefacts from a transcript via `PromotionSuggestService`. **Writes nothing.** |
| **Validate** | `POST /api/kb/promotion/candidates` | Validates a markdown draft against `CanonicalParser` (9 canonical types / 6 statuses / YAML frontmatter). Returns `{valid, errors}`. **Writes nothing.** |
| **Promote** | `POST /api/kb/promotion/promote` | `CanonicalWriter` writes markdown to KB disk + dispatches `IngestDocumentJob`. HTTP 202. **Only this stage commits canonical storage.** |

Claude skills + the `suggest` / `candidates` endpoints stop at the validation
boundary. Only humans (via git push → GitHub Action → ingest) and operators
(via `kb:promote` CLI) commit canonical storage. Every promotion writes an
immutable `kb_canonical_audit` row — promotion is forever traceable.

See **ADR 0003** for the architectural decision rationale + the
**Retrieval & Knowledge** features table above for the surrounding canonical
infrastructure (typed parser, knowledge graph, rejected-approach injection).

---

## Quick start (5 minutes)

### Prerequisites

- **PHP** `>= 8.3`
- **Composer** `2.x`
- **PostgreSQL** `>= 15` with the **pgvector** extension
- **Node.js** `>= 20` (Vite SPA build)
- **npm** (bundled with Node)

Fastest PostgreSQL + pgvector setup:

```bash
docker run -d --name askmydocs-pg \
    -e POSTGRES_USER=askmydocs \
    -e POSTGRES_PASSWORD=askmydocs \
    -e POSTGRES_DB=askmydocs \
    -p 5432:5432 \
    pgvector/pgvector:pg16
```

### Clone → working SPA

```bash
# 1. Clone + install
git clone https://github.com/lopadova/AskMyDocs.git
cd AskMyDocs
composer install
npm ci && npm run build

# 2. Configure
cp .env.example .env
php artisan key:generate
# Edit .env: DB_*, AI_PROVIDER, OPENROUTER_API_KEY (or OPENAI_API_KEY)

# 3. Migrate + seed
php artisan migrate
php artisan db:seed --class=RbacSeeder      # 5 roles + permission matrix
php artisan db:seed --class=DemoSeeder      # 3 demo accounts + canonical KB

# 4. Run
php artisan serve
```

Open `http://localhost:8000` and log in as `super@demo.local` /
`password` (DemoSeeder creates the account with the `super-admin` role).
The SPA redirects to `/app/chat`; click **Dashboard** in the sidebar
to land on `/app/admin`.

### Full configuration reference

Every environment variable is documented inline in
[`.env.example`](.env.example). Sister-package configs live in
[`config/ai.php`](config/ai.php), [`config/kb.php`](config/kb.php),
[`config/kb-pipeline.php`](config/kb-pipeline.php),
[`config/chat-log.php`](config/chat-log.php),
[`config/admin.php`](config/admin.php),
[`config/laravel-flow.php`](config/laravel-flow.php),
[`config/eval-harness.php`](config/eval-harness.php).

### Optional: mount the MCP admin web panel

The MCP client framework (v5.0+) is exposed through the parent host
admin under `/app/admin/mcp-tools` with a server-list page and chat-time
tool-call UI. For a richer single-pane-of-glass view dedicated to the
MCP fleet (12 routes — fleet table, three-step new-server wizard,
seven-tab per-server detail, tools matrix + try-it, three-pane resource
tree, prompt playground, audit drilldown, circuit-breaker grid,
OpenAPI explorer, settings + tour), install the standalone companion
package:

```bash
composer require padosoft/askmydocs-mcp-pack-admin:^1.1
# Service provider auto-discovers via composer.json::extra.laravel.providers
# Pre-built SPA bundle ships inside vendor/padosoft/askmydocs-mcp-pack-admin/public/vendor/mcp-pack-admin/
# Publish the assets so Laravel can serve them at /vendor/mcp-pack-admin/:
php artisan vendor:publish --tag=mcp-pack-admin-assets --force
# Optionally override the mount prefix (default: /admin/mcp-pack)
php artisan vendor:publish --tag=mcp-pack-admin-config
```

Then sign in as a `super-admin` and open
`http://localhost:8000/admin/mcp-pack`.

> **Status note (v1.1.0 GA — shipped 2026-05-18):** the panel drives
> the full live `padosoft/askmydocs-mcp-pack` v1.5+ REST surface
> end-to-end: 22 typed endpoints, 23 TanStack Query hooks across
> read+write paths, R21 two-call confirm-token protocol on tool
> invoke / audit replay / breaker reset, SSE live-feed consumer
> replacing the prototype simulator. 154 Vitest specs cover every
> endpoint binding with loading / error / empty / ready states +
> R21 happy + failure paths + ValidationError surfacing + SSE
> consumer behaviour via MSW handlers shaped to the real wire
> schema. AskMyDocs v7.1+ requires `padosoft/askmydocs-mcp-pack:^1.5`
> (auto-resolved by composer) so all 22 admin endpoints answer the
> SPA out of the box.

### Enabling AI Act compliance features (junior-proof)

The v6.0 GA wires AskMyDocs as **the first Laravel platform AI-Act-ready
out of the box** — the 9 baseline compliance modules (Disclosure, DSAR,
Risk Register, Bias Monitoring, Human Review Tracker, Incident, Consent,
Cybersecurity, Attestation) ship configured and active. The v6.1 catch-up
adds four additional capabilities that **default OFF** so existing
installs see no behavioural change. Turn them on in this order — each
section is independently optional.

#### 1. Pluggable bias-metric registry (v1.2 — already active)

Three reference metrics ship and auto-register:

```env
AI_ACT_BIAS_DEFAULT_METRIC=demographic_parity   # alternatives: equalized_odds | calibration
AI_ACT_BIAS_DISPARITY_THRESHOLD=0.05            # drift alert threshold (0..1)
```

Switch the active metric on the chat path:

```php
app(\Padosoft\AiActCompliance\BiasMonitoring\Services\BiasMonitorService::class)
    ->capture([
        'metric_name' => 'equalized_odds',
        'cohort_dimension' => 'language',
        // ... domain payload
    ]);
```

Verification one-liner:

```bash
php artisan tinker --execute='dump(app(Padosoft\AiActCompliance\BiasMonitoring\Services\MetricRegistry::class)->has("equalized_odds"))'
# expect: true
```

#### 2. Cohort-drift real-time alerting cascade (v1.3 — opt-in)

```env
AI_ACT_ALERTING_ENABLED=true
AI_ACT_ALERT_THROTTLE_MINUTES=60
AI_ACT_ALERT_CB_FAILURES=5
AI_ACT_ALERT_CB_COOLDOWN=30
# Optional click-through link for the DPO email body:
AI_ACT_ALERT_EVIDENCE_URL_TEMPLATE="${APP_URL}/admin/ai-act-compliance/bias?tenant={tenant_id}&metric={metric_name}"
```

Seed at least one channel route (Slack / Discord / email):

```php
\Padosoft\AiActCompliance\Alerting\Models\AlertRoute::query()->create([
    'tenant_id' => null,                                                  // null = platform-global
    'channel' => 'slack',
    'webhook_url' => 'https://hooks.slack.com/services/T0/B0/xyz',        // auto-encrypted at rest
    'enabled' => true,
]);
\Padosoft\AiActCompliance\Alerting\Models\AlertRoute::query()->create([
    'tenant_id' => null,
    'channel' => 'email',
    'email' => 'dpo@yourcompany.example',
    'enabled' => true,
]);
```

Make sure `queue.default` is NOT `sync` for production deployments
(otherwise alerts fire on the request thread). `database` is fine for
small installs; `redis` for prod-grade.

#### 3. EU AI Act regulatory-feed auto-flagger (v1.4 — opt-in)

```env
AI_ACT_REGULATORY_FEED_ENABLED=true
AI_ACT_REGULATORY_FEED_URL=https://eur-lex.europa.eu/EN/legal-content/summaries/AI-act.xml
AI_ACT_REGULATORY_FEED_MAX_ENTRIES=50
AI_ACT_REGULATORY_FEED_TIMEOUT=15
```

`bootstrap/app.php` schedules `ai-act:regulatory-poll` daily at 04:10
once the env flag is on. Trigger it on demand:

```bash
php artisan ai-act:regulatory-poll
# expect output: "Regulatory poll complete: ingested=N skipped=M failures=0"
```

DPO operators triage the resulting rows on
`/admin/ai-act-compliance/regulatory` (companion admin SPA cross-mount).

#### 4. DPO multi-org tenant management (v1.5 — opt-in)

No env vars — driven entirely via the `tenants` table. Create a tenant:

```bash
php artisan tinker --execute='
\Padosoft\AiActCompliance\MultiTenancy\Models\Tenant::query()->create([
    "slug" => "acme",
    "name" => "Acme Inc.",
    "subscription_tier" => "enterprise",
    "dpo_email" => "dpo@acme.example",
    "config_overrides_json" => ["bias.disparity_threshold" => 0.02],
]);
'
```

Send a request with the tenant header:

```bash
curl -H "X-Tenant-Id: acme" http://localhost:8000/api/admin/ai-act-compliance/tenants/acme
# expect: {"data":{"tenant":{"slug":"acme",...},"kpis":{...}}}
```

AskMyDocs's `ResolveTenant` middleware propagates the host tenant id
into the sister-package context via `App\Compliance\TenantContextBridge`
automatically — every call to `TenantConfigResolver::resolve()` returns
the per-tenant override when it exists, the host config otherwise.

Verification: an unknown slug returns 404, suspended → 423 Locked,
archived → 410 Gone (per the package's `ai-act.tenant-context`
middleware).

#### Reference

- Full `.env.example` section: search for `# AI Act compliance v1.2 → v1.5`.
- Backend package: <https://github.com/padosoft/laravel-ai-act-compliance> (READMEs §4-§6 "killer modules")
- Admin SPA package: <https://github.com/padosoft/laravel-ai-act-compliance-admin> (11 screens)
- Host-side end-to-end tests live in [`tests/Feature/AiAct/`](tests/Feature/AiAct/) — open them for working code samples of every flow.

---

## Architecture

The v5.x platform routes every request through `ResolveTenant`
middleware that populates the `TenantContext` singleton, so every
Eloquent query that follows is tenant-scoped (R30 / R31). The chat
surface ships **two interchangeable transports** — the v3 synchronous
JSON path on `KbChatController` (backward-compat fallback) and the v4
SSE streaming path on `MessageStreamController` (default for the React
SPA, emits SDK v6 `UIMessageChunk` frames). Both converge on the same
hybrid retrieval pipeline (vector + FTS + reranker + canonical graph
expansion + rejected-approach injection). When `AI_AGENTIC_ENABLED=true`,
`McpToolCallingService` intercepts after the first provider response and
runs a multi-turn tool-calling loop (max `AI_MCP_TOOL_CALL_MAX_ITERATIONS`
iterations) — invoking registered MCP servers via native JSON-RPC
transports (HTTP / SSE / stdio) provided by `padosoft/askmydocs-mcp-pack`
and accumulating results before returning the final answer. (v5.0
shipped this via a separate Node sidecar process; v7.0/W6.3.B retired
the sidecar and moved every call onto the host PHP process.)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                Client (React SPA / API / MCP / GitHub Action)               │
│                                                                             │
│   v4 streaming           v3 JSON              ingest                        │
│   POST /conversations/   POST /api/kb/chat    POST /api/kb/ingest           │
│        {id}/messages/    (legacy fallback)    (Sanctum, batch ≤ 100)        │
│        stream (SSE)                                                         │
└──────────────────────────────┬──────────────────────────────────────────────┘
                               ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│  ResolveTenant middleware → TenantContext singleton (R30/R31)                │
│  AuthenticateForSse middleware (JSON 401 on streaming endpoints)             │
│  RedactChatPii middleware (v4.1 W4.1 — default-OFF, narrow scope)            │
└──────────────────────────────┬──────────────────────────────────────────────┘
                               ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│  Chat orchestrators                                                          │
│  ┌──────────────────────────────┐   ┌──────────────────────────────────┐     │
│  │ KbChatController (v3 sync)   │   │ MessageStreamController (v4 SSE) │     │
│  │ • Refusal short-circuit R26  │   │ • Refusal short-circuit R26      │     │
│  │ • KbSearchService            │   │ • KbSearchService                │     │
│  │ • AiManager::chat()          │   │ • AiManager::chatStream()        │     │
│  │ • McpToolCallingService       │   │ • McpToolCallingService (v5)     │     │
│  │   (v5, if AI_AGENTIC_ENABLED) │   │   multi-turn tool loop →        │     │
│  │ → { answer, citations,       │   │   native JSON-RPC transport      │     │
│  │     refusal_reason,          │   │   (v7 — HTTP / SSE / stdio,      │     │
│  │                              │   │    no Node sidecar)              │     │
│  │                              │   │ → UIMessageChunk frames          │     │
│  │     confidence, meta,        │   │   (start/text-delta/source-url/  │     │
│  │     tool_calls }             │   │    data-confidence/data-refusal/ │     │
│  │                              │   │    finish)                       │     │
│  └──────────────────────────────┘   └──────────────────────────────────┘     │
│           │                                   │                              │
│           └─────────────────┬─────────────────┘                              │
│                             ▼                                                │
│  ChatLogManager::log() — try/catch; never propagates failures                │
└──────────────────────────────┬──────────────────────────────────────────────┘
        ┌──────────────────────┼──────────────────────────┐
        ▼                      ▼                          ▼
┌───────────────────┐  ┌───────────────────┐  ┌──────────────────────────────┐
│ KbSearchService   │  │ AI Providers      │  │ Persistence (Postgres)       │
│ • Embed query     │  │ via raw Http::    │  │                              │
│ • pgvector top-K  │  │                   │  │ • knowledge_documents +      │
│ • FTS GIN top-K   │  │ • OpenAI          │  │   knowledge_chunks (FK       │
│ • RRF + reranker  │  │ • Anthropic       │  │   CASCADE)                   │
│   0.6v + 0.3k +   │  │ • Gemini          │  │ • embedding_cache (cross-    │
│   0.1h            │  │ • OpenRouter      │  │   tenant on text_hash)       │
│ • Canonical boost │  │ • Regolo (via     │  │ • kb_nodes / kb_edges /      │
│ • Status penalty  │  │   laravel-ai-     │  │   kb_canonical_audit         │
│ • GraphExpander   │  │   regolo)         │  │ • chat_logs / conversations  │
│   1-hop kb_edges  │  │                   │  │   / messages                 │
│ • RejectedApproach│  │                   │  │ • admin_command_audit /      │
│   Injector        │  │                   │  │   admin_command_nonces /     │
│ → SearchResult    │  │                   │  │   admin_insights_snapshots   │
│   { primary,      │  │                   │  │ • flow_runs / flow_steps /   │
│     expanded,     │  │                   │  │   flow_audit / approvals /   │
│     rejected,     │  │                   │  │   webhook_outbox             │
│     meta }        │  │                   │  │ • pii_token_maps (v4.1)      │
└───────────────────┘  └───────────────────┘  │ • mcp_servers /              │
                                               │   mcp_tool_call_audit (v5.0) │
                                               └──────────────────────────────┘
```

**Ingestion** has two entrypoints (CLI `kb:ingest-folder` + HTTP
`POST /api/kb/ingest`) that converge on a single execution path:
`IngestDocumentJob` → `DocumentIngestor::ingest(SourceDocument)` →
`PipelineRegistry`-resolved `Converter`+`Chunker`+`Enricher` chain →
idempotent SHA-256 upsert on `(project_key, source_path,
version_hash)`. When canonical YAML frontmatter is detected,
`CanonicalParser` validates it and `CanonicalIndexerJob` populates
`kb_nodes` + `kb_edges` after commit.

**Promotion** (ADR 0003) is human-gated: `/suggest` extracts
candidates from a transcript, `/candidates` validates a draft,
`/promote` writes the markdown and dispatches ingestion. Only humans
(git push → GH Action) and operators (`kb:promote` CLI) commit
canonical storage.

For the full component map see [`CLAUDE.md`](CLAUDE.md) section 3.

---

## Roadmap

| Major | Status | Theme |
|---|:---:|---|
| **v4.0** | ✅ shipped 2026-05-02 | Enterprise platform foundation — multi-tenant + Vercel AI SDK streaming + canonical KB graph + admin shell + 5 sister packages on Packagist |
| **v4.1** | ✅ shipped 2026-05-03 | PII redactor v1.1 integrated at 4 chat / embedding / insights / detokenize touch-points (default-OFF) |
| **v4.2** | ✅ shipped 2026-05-10 | Sister-package integration GA — laravel-flow v1.0 (9 Flow definitions) + eval-harness v1.2 RAG regression CI gate + 3 admin SPAs cross-mounted |
| **v4.3** | ✅ shipped 2026-05-10 | Host-side hardening — PII at 11 persistence touch-points + React 19 host bump + `eval:nightly` LLM-as-judge cron (ADR 0005 + ADR 0006) |
| **v4.4** | ✅ shipped 2026-05-11 | Tailwind v4 host migration + iframe→cross-mount of pii-redactor-admin + eval-harness-ui + adversarial nightly opt-in (ADR 0007) |
| **v4.5** | ✅ shipped 2026-05-12 | Universal Connectors (Google Drive / Notion / Evernote / Fabric / OneDrive / Confluence / Jira) + admin OAuth SPA + source-aware ingestion (per-source chunker dispatch + Reranker Layer-4 + facets) + Vercel AI SDK UI Tier 1 + partial Tier 2 (suggested follow-ups). Stretch Tier 2 (tool-result render / streaming source parts / export / image attachments / artifact panel) deferred to v5.0 per ADR 0008 D4 |
| **v4.6** | ✅ shipped 2026-05-12 | Connector package extraction — 7 inline connectors lifted to 8 standalone `padosoft/askmydocs-connector-*` packages (`-base` v1.1.1 + `-notion` v1.0.1 + `-google-drive` v1.0.1 + `-evernote` + `-fabric` + `-onedrive` + `-confluence` + `-jira` all v1.0.0) + `HostIngestionBridge` (binds `ConnectorIngestionContract`) + composer-extra auto-discovery + chunkers stay in host (ADR 0009) |
| **v4.7** | ✅ shipped 2026-05-12 | Tabular Review + Workflows + AI-suggest — admin SPA list/show/create + SSE streaming extractor + workflow list / create / AI-suggest gallery + KB-sample-driven AI suggester + ~115 tests across PHPUnit / Vitest / Playwright. Workflow edit + share modal + use-as-template + Glide Data Grid migration deferred to v4.7.x per ADR 0010 |
| **v5.0** | ✅ shipped 2026-05-13 | Agentic platform — MCP **client** framework: `McpToolCallingService` multi-turn orchestration + `McpServerRegistry` per-tenant + `McpToolAuthorizer` RBAC + `McpClientBridge` Node sidecar + immutable `mcp_tool_call_audit` trail + admin CRUD API + `AI_AGENTIC_ENABLED` master switch; OpenAI + OpenRouter tool-schema auto-wiring; +147 PHPUnit + 1 Playwright spec |
| **v6.0** | ✅ shipped 2026-05-14 | AI Act compliance bundle — `padosoft/laravel-ai-act-compliance` v1.1.0 (9 modules: Disclosure / RiskRegister / DSAR / BiasMonitoring / HumanReviewTracker / Incident / Consent / Cybersecurity / ComplianceAttestation) + `padosoft/laravel-ai-act-compliance-admin` v1.1.0 (8 pixel-ported screens from Claude Design handoff) + AskMyDocs host depth: `TokenLevelExplainability` decorator over `streamReply()` writing into `chat_log_provenance`, `RagRefusalQualityMetric implements CohortParityMetric`, `ProvenanceChain::forChatLog()` joining chunks + documents (withTrashed) — ADR 0011 |
| **v6.1** | ✅ shipped 2026-05-15 | AI Act compliance v1.2 → v1.5 catch-up wave — bumps `padosoft/laravel-ai-act-compliance` + `-admin` pins from `^1.1.3` to `^1.5.0` (skipping v1.2 → v1.5 in one hop). Layered capabilities arrive via the package upgrade: pluggable `CohortParityMetric` registry (DemographicParity / EqualizedOdds / Calibration), cohort-drift real-time alerting cascade (Slack → Discord → always-CC email with throttle + circuit breaker + severity-escalation bypass), EU AI Act regulatory-feed auto-flagger (RSS + Atom, XXE-safe), DPO multi-org tenant registry + per-tenant config overrides + cross-tenant overview. The companion admin SPA (already cross-mounted under `/admin/ai-act-compliance/` from v6.0) automatically surfaces three new screens (`/alerts`, `/regulatory`, `/tenants`) once the pin is bumped — no AskMyDocs-side route / middleware changes required. 1729/1729 PHPUnit on the bumped pin. |
| **v6.1.1** | ✅ shipped 2026-05-15 | AI Act compliance host wiring — `bootstrap/app.php` registers `ai-act.tenant-context` middleware alias + scheduled `ai-act:regulatory-poll` daily 04:10 (env-gated); new `App\Compliance\TenantContextBridge` propagates host tenant id → package `Tenant` model; 18 new host-side end-to-end tests under `tests/Feature/AiAct/` (4 `AlertingCascadeFlowTest` + 2 `BiasMetricRegistryHostFlowTest` + 4 `RegulatoryFeedFlowTest` + 8 `TenantContextHostFlowTest`) prove every default-OFF v1.3 / v1.4 / v1.5 feature works when the opt-in flag is flipped; new `.env.example` AI Act section + junior-proof setup tutorial in README |
| **v7.0/W1.A** | ✅ shipped 2026-05-15 | MCP client framework extraction — `padosoft/askmydocs-mcp-pack` v1.0.1 published on Packagist (6 contracts + multi-turn tool-calling orchestrator + stdio/HTTP transports + hash-only audit + RBAC hooks + 42 tests across 7 PHP × Laravel CI cells). Standalone, zero AskMyDocs dependencies; v5.0's inline `app/Mcp/Client/*` not yet replaced — the host integration is intentionally deferred until the package roadmap closes |
| **v7.0/W2** | ✅ shipped 2026-05-15 | mcp-pack v1.1.0 — SSE transport (`SseJsonRpcTransport`) for remote HTTP+SSE gateways, JSON-RPC `resources/*` + `prompts/*` methods so the orchestrator can read from upstream resource catalogs and pre-prompt templates |
| **v7.0/W3** | ✅ shipped 2026-05-15 | mcp-pack v1.2.0 — first-class server-side. The same package exposes a Laravel app AS an MCP server (stdio long-lived process via artisan command + HTTP+SSE route + JSON-RPC handler routing initialize / tools/list / tools/call to host-supplied tool catalog). Auth + RBAC integration with host gates |
| **v7.0/W4** | ✅ shipped 2026-05-15 | mcp-pack v1.3.0 — production-hardening. Per-tool circuit breaker (open / half-open / closed states tracked in cache with TTL recovery) + adaptive retry budget (token-bucket per server per minute, exponential backoff on failure). Decorator over `ToolInvoker`; new config keys + telemetry events |
| **v7.0/W5** | ✅ shipped 2026-05-15 | mcp-pack v1.4.0 — admin backend surface. Package registers REST routes under a configurable prefix (default `/api/admin/mcp-pack`): server CRUD, handshake action, tool catalog, paginated audit log, circuit-breaker state. Middleware-driven auth (host wires Sanctum / RBAC). OpenAPI 3.1 spec + Postman collection ship with the package. NO React/Vue code — this is the backend the standalone `-admin` SPA consumes in the post-v7.0 cycle |
| **v7.0/W6** | ✅ shipped 2026-05-16 | Host integration over `padosoft/askmydocs-mcp-pack` v1.4 — closed across five sub-waves: PR #174 composer require, PR #175 `mcp_tool_call_audit` `input_hash`/`actor` coexistence + bulk CASE-WHEN backfill, PR #176 host adapters (`McpServerAdapter` / `EloquentMcpServerRegistry` / `McpToolAuthorizerAdapter` / `HostBridge`) bound via `AppServiceProvider::boot()` + `status` ENUM→`varchar(32)` + `user_id`/`result_hash` NULLABLE + `mcp_server_name` added, PR #177 Node sidecar fully retired (entire `mcp-client/` TypeScript project deleted, `ToolInvoker` + `McpHandshakeService` rewritten to drive `McpClient::forServer()` natively, `/api/mcp/credentials` decrypted-secret callback removed), PR #179 final sidecar-artefact retirement (`/api/mcp/internal-auth` probe + `MCP_INTERNAL_AUTH_TOKEN` env + `mcp.internal_auth_token` config + `McpInternalAuthController` all gone). DSAR coverage on actor-written rows, SPA contract aligned (`server_id` filter + `page`/`per_page` + `meta.*` pagination), `StatusPill` widened with `transport_error`. Inline orchestrator (`McpToolCallingService` + host registry + custom authorizer) keeps its surface — it already runs on native transports (PR #177 rewrote the invoker) and the consolidation is a refactor that's deferred to a post-v7.0 cycle (no capability gain, just translation-adapter work). See [`docs/v4-platform/STATUS-2026-05-16-v7-w6.md`](docs/v4-platform/STATUS-2026-05-16-v7-w6.md) for the full closure status. |
| **v7.1** | ✅ shipped 2026-05-18 | mcp-pack v1.4→v1.5 + mcp-pack-admin v1.0→v1.1 live wire-up cycle. mcp-pack v1.5.0 ships the full 22-endpoint admin REST surface (+16 over v1.4) with BC-safe sub-interface extensions (`McpHostBridgeIdentityContract` + `McpServerMutableRegistryContract`), R21-atomic confirm-token protocol with host-owned mint/consume, OpenAPI 3.1 spec, 325 PHPUnit tests; mcp-pack-admin v1.1.0 wires the React SPA against the live surface end-to-end (23 hooks across read+write paths, R21 two-call with second-leg expired-token guard, SSE live-feed consumer, 154 Vitest specs); AskMyDocs host bumps `padosoft/askmydocs-mcp-pack` from `^1.4` to `^1.5` — zero breakage (1750 PHPUnit tests green: 613 Unit + 1137 Feature). 8 R36 iters across the cycle (mcp-pack v1.5: 4 PRs, mcp-pack-admin v1.1: 4 PRs). Full real-backend Playwright suite parked for v1.1.x patch (`docs/W5-E2E-REWRITE.md`). |
| **v8.0** | ✅ shipped 2026-05-21 | Killer-features cycle closed (W1..W8). W1-W7 features shipped as planned (notifications core + channels/preferences, why-not-cited + counterfactual, decision-debt heatmap, living collections foundation+semantic, MCP-as-KB-debugger). **W8 Compliance Differential Pack v1** closed via PRs #217..#221: `compliance_reports` schema, report generator (delta + audit aggregate + tamper-evident hash), PDF/JSON export, `/app/admin/compliance/reports` SPA + verify endpoint, and tenant opt-in quarterly digest cron `compliance:digest-quarterly`. RC sequence completed (`v8.0.0-rc1`..`v8.0.0-rc4`), then GA. Plan: [`docs/v4-platform/PLAN-v8.0-killer-features.md`](docs/v4-platform/PLAN-v8.0-killer-features.md). ADRs: 0012..0018. |
| **v8.0.1** | ✅ shipped 2026-05-22 | Deep-review hotfix (PR #223 — 12 R36 iterations). Six findings from a post-merge comparative review of `v8.0.0-rc1`..`rc3`: **F1 HIGH** project-membership gate on `KbChunkFeedbackController` (IDOR-class cross-project feedback), **F2 HIGH** atomic upsert replacing `updateOrCreate` race, **F3** retrieval correctness on `KbSearchService::fullTextSearch` (filter DTO now applied to hybrid FTS branch), **F4** R31 gate entry for `KbChunkFeedback`, **F5** per-user server-side chat preferences (`users.chat_preferences` JSON + `GET/PATCH /api/me/chat-preferences`), **F6** CHANGELOG doc/code drift on `payload_hash`. |
| **v8.0.2** | ✅ shipped 2026-05-22 | Cross-release deep-review hotfix (PR #224 — 9 R36 iterations). Four cross-release findings against tags v5.0.0 → v7.1.0: **B HIGH** AI Act middleware (`ai.disclosure` + optional `ai.consent:*`) mounted on the SPA's real chat path `POST /conversations/{id}/messages` + `/messages/stream` (was only on `/api/kb/chat`), **C** DSAR exporter + deleter now iterate every tenant the user has membership OR data in (data-derived sweep across `project_memberships` + `conversations` + `chat_logs` + `connector_installations` + `mcp_tool_call_audit` + `kb_canonical_audit`), atomic outer transaction on the deleter + new `_dsar_meta` envelope, **D** `ResolveTenant` now `report()`s + `Log::warning()`s bridge failures instead of swallowing silently, **E** `verify-e2e-real-data.sh` no longer allowlists `/api/admin/ai-act-compliance/`. New `App\Compliance\UserTenantResolver` is single source of truth for tenant enumeration + actor sets. **Adopt v8.0.2 over v8.0.0 / v8.0.1** — F1 and B are both pre-adoption blockers. |
| **v8.1.0** | ✅ shipped 2026-05-26 | Retrieval-quality minor release (focused review on result extraction + citations/mentions + rerank; PRs #227..#231). **P0.1** — fixed a production-broken anti-hallucination refusal gate: the controllers read chunk scores via object syntax on array-shaped data (`$c->vector_score` → `null` → `0`), so the gate was non-functional on `/api/kb/chat` + the sync conversation path (only the stream path was patched), and the suite stayed green because every chat test mocked `(object)` chunks production never emits (R13/R16). New shape-agnostic `RetrievalGrounding` gate (grounds on `rerank_score` OR the vector floor). **P0.2** — unified all three chat channels onto one `ChatRetrievalService` (one `searchWithContext` path, one grounding gate, one origin-aware citation builder; grouped by `document_id`). **P0.3** — `@mention` is now a recall-safe rerank **boost** (`kb.mentions.mode=boost`) instead of a hard `WHERE id IN` filter; FE mention-min aligned to the BE `min:2`. **P1** — evidence-grade citations (`chunks[]` with `chunk_id`/`evidence_hash`/`heading`/`score`/`snippet`, R27 additive), doc-cap diversification (`kb.diversification.max_chunks_per_doc=3`), and a ConfidenceCalculator diversity fix (read nested `document.id`, was always ~1/n). **P2** — stream/sync citation parity via `source-url` `providerMetadata`, mention-search relevance ranking (title-exact > prefix > contains > path), and an IR-metrics core (`RetrievalQualityMetrics`: nDCG@k / MRR / precision@k). +21 tests. Follow-up: rerank scale-calibration (findings #7/#9) deferred to validate against a labelled benchmark using the new metrics. |
| **v8.2.0** | ✅ shipped 2026-05-26 | Retrieval-quality benchmark + live-validated calibration (PRs #233..#236). A reproducible, repeatable quality gate: a 5-doc labelled corpus (markdown + PDF + DOCX, graph-linked + rejected-approach) + 14 gold queries scored on **nDCG@k / MRR / precision@k / citation-precision / graph-recall / rejected-recall / refusal-accuracy** by `RetrievalQualityMetrics`, via the `kb:benchmark` runner (`--stub` no-key + LIVE). **The whole RAG pipeline is now testable end-to-end with NO mocks** — `KbSearchService` gained a driver-aware **PHP-cosine fallback** so vector search runs on SQLite in CI (pgsql keeps native pgvector), closing the structural gap that let the v8.1 P0.1 search bug ship green; the deterministic `RetrievalPipelineScenarioTest` exercises ingest → per-type chunk → embed → graph → search → citations → refusal. **Rerank scale-calibration** (findings #7/#9) implemented (`KB_RERANK_NORMALIZE_SCORES`) and **validated on the LIVE benchmark** (real OpenRouter embeddings + pgvector) which drove a measured calibration of three defaults (`KB_CANONICAL_PRIORITY_WEIGHT` 0.003→0.001, normalize on, `KB_REJECTED_MIN_SIMILARITY` 0.45→0.40): scorecard **nDCG 0.855→0.997, MRR 0.833→1.000, citation/refusal/graph/rejected all 1.000 — PASSED**. The live run also caught a real `strict_types` RRF bug invisible to the mocked suite. README "Running the retrieval-quality benchmark" + milestone ritual + manual CI workflow. +30 tests. |
| **v8.0.3** | ✅ shipped 2026-05-26 | Multi-tenant isolation + security deep-review hotfix (PR #226). Four audits: the 31-finding review (26 confirmed — 5 CRITICAL incl. **C1** `X-Tenant-Id` header now gated by a post-auth `AuthorizeTenantHeader` + `tenant.cross-access` permission, **C2-C5** `{document}`/`{membership}`/`{report}` bindings + LogViewer + ComplianceReport + KbTree scoped; 5 false positives), **7 bonus leaks** caught by the new `TenantReadScopeTest`; **Audit #3** all 10 MCP tools + `AiInsightsService` (+ per-tenant `insights:compute` & `(tenant_id,snapshot_date)` unique) + `ProvenanceChain` + `Conversation` binding; **Audit #4** embedding-cache batch crash, filter `max:N` caps, compliance `promoted`-delta via the audit event. Plus the **HY093** root-cause (`ESCAPE '\\'` → `~` across all LIKE sites, the deterministic Postgres E2E blocker) + the `lockForUpdate()->count()` Postgres FOR-UPDATE crash. New guards: `TenantReadScopeTest` (all 33 BelongsToTenant models, scans Http/Services/Mcp/Console/Compliance) + `NoBackslashLikeEscapeTest`. Behavioural change: tenant switching via header is super-admin-only. Feature-completeness backlog in [`docs/ENTERPRISE-COMPLETENESS-ROADMAP.md`](docs/ENTERPRISE-COMPLETENESS-ROADMAP.md) (R1-R29). **Adopt v8.0.3** — C1-C5 are cross-tenant data-exposure blockers. |
| **v8.3.0** | ✅ shipped 2026-05-27 | Full-stack live verification. **WS-A** — `kb:benchmark --with-answers` scores **answer-faithfulness** = cosine(real chat answer, the grounding text the LLM saw) via `AiManager` (no cache pollution), mirroring the kb_rag per-bucket rendering; live-validated **0.68** with every retrieval metric still at ceiling (also caught + fixed a real OpenRouter `temperature` string→400 bug, hardened with `is_numeric()` guards on every numeric provider env). **WS-C** — the `eval:nightly` LLM-as-judge path validated LIVE against OpenRouter (real judge + embeddings): **citation-groundedness 0.976**, cosine-groundedness 0.621 (p95 1.0). **WS-B** — consolidated `KbChatFullStackComplianceTest` proving one chat turn fires grounded citations + AI-Act disclosure header + `chat_logs` row + PII answer-redaction together; README documents the `--with-answers` + live eval-harness commands + the local feature-flag recipe. +2 PHPUnit tests across the cycle — WS-A `--with-answers` + WS-B full-stack smoke (2058→2060). |
| **v8.4.0** | ✅ shipped 2026-05-27 | Security + correctness hardening. **RBAC access-control matrix** (`AdminAuthorizationMatrixTest`, R32 + skill): one data-driven gate over 21 admin endpoints × 5 roles + guest — its first run caught a **real unauthenticated-access vulnerability** where the `padosoft/laravel-ai-act-compliance` package mounted `api/admin/ai-act-compliance/*` (DSAR / incidents / bias / risk-register / consent / attestations / tenants) with `middleware: ['api']` (NO auth), unfixed because the host never published a config to override it; closed via `config/ai-act-compliance.php` gating with `auth:sanctum` + `tenant.authorize` + `ai-act.tenant-context` + `can:viewAiActCompliance`. Per-role Playwright `role-access.spec.ts` + dpo/editor demo users. **Chat streaming crash fixes**: the SSE `source-url` frame emitted `providerMetadata` as a flat map (SDK requires `Record<string,Record<…>>`) and the `finish` frame carried a `usage` key the SDK rejects — both aborted the entire stream in the browser; fixed + an **exhaustive `stream-contract.test.ts`** now validates every BE frame against the real `@ai-sdk` `uiMessageChunkSchema`. Repo default `CACHE_STORE` database→file (no `cache` table migration shipped). +4 PHPUnit (2060→2063) + 13 Vitest. |
| **v8.5.0** | ✅ shipped 2026-05-27 | Definitive browser streaming E2E (PR #242). The v8.4 chat crashes (`source-url` `providerMetadata` shape + `finish.usage`) shipped because the streaming E2E were all `test.skip` and the unskipped chat specs stubbed the AI boundary — so the **real `/messages/stream` SSE through the real `@ai-sdk` transport** (the only layer that validates each `UIMessageChunk` against the SDK zod schema, where those crashes fired) had **zero browser coverage**. New `chat-stream-browser.spec.ts` drives a real grounded turn **and** a real refusal turn end-to-end (R13: nothing stubbed) — asserting the citation chip renders (`source-url` parses), the thread reaches `ready` (`finish` parses), `RefusalNotice` renders on empty retrieval, and **no SDK "Type validation failed" pageerror** fires. Determinism without a live LLM: a new offline `FakeProvider` (canned answer + constant embedding vector, hard-gated to testing/local by `AiManager::resolveFakeProvider()`) + `E2eStreamSeeder` ingesting one doc through the **real** `DocumentIngestor` so it is vector-searchable (DemoSeeder chunks have NULL embeddings). +3 PHPUnit (2063→2066). |
| **v8.6.0** | ✅ shipped 2026-05-27 | Live chat actions (PR #243). Wired up chat surfaces that looked interactive but did nothing: **cited sources** now navigate to the cited KB document detail (`/app/admin/kb?doc=<id>`, admin-gated via the auth-store role so viewer/editor chips stay hover-only instead of dead-ending on a 403; null-`document_id` citations aren't openable; the `admin/kb` route gained a `validateSearch` so the deep-link survives navigation); the **conversation title** auto-generates from the transcript on first turn-settle (the existing BE `generateTitle`, called once per thread) and the header shows it via a new `ConversationTitle` with an inline **rename pencil** (ChatGPT-style, `PATCH /conversations/{id}`); **feedback thumbs** (already wired) got real E2E coverage. New `app-smoke.spec.ts` walks every admin-accessible screen asserting zero uncaught exceptions. +9 Vitest + 2 E2E specs (`chat-actions` incl. an R13 rename-500 injection, `app-smoke`). |
| **v8.7.0** | ✅ shipped 2026-06-02 | **KB Lifecycle Intelligence** cycle (W1–W6, PRs #244..#247). **W1 Synonym Expansion** — per-(tenant, project) synonym groups (`kb_synonyms`) bidirectionally expand queries (embedding text + injection-safe FTS `tsquery`) so in-house jargon connects to plain language. **W2 Weekly digest + stale-review** — `notifications:digest-weekly` (closes the dead `notification_digests` scaffold, R6) emails each user their own weekly roundup; `kb:stale-review-sweep` flags docs untouched beyond `KB_HEALTH_STALE_REVIEW_MONTHS` via a new `kb_doc_stale_review` event (R21-atomic, slug-version-idempotent). **W3–W4 AI deep-analysis on change (flagship)** — an async, cost-gated `AnalyzeDocumentChangeJob` asks the LLM, on every ingest/modify, to suggest enhancements, surface cross-references, and flag which OTHER docs the change makes obsolete; results land in `kb_doc_analyses`, notify reviewers, and render under **Admin → Doc Insights**. Suggest-only (ADR 0003); default ON for canonical docs, opt-in otherwise. **W5 Cloud Time Machine** — version timeline + in-house LCS diff (`App\Support\MarkdownDiff`) + atomic restore (status-flip + canonical-identity transfer + audit) + `kb:prune-archived-versions` retention, under **Admin → Time Machine**. Every sub-PR ran the R40 local-critic + R36 cloud Copilot loops to 0 must-fix; rc1..rc4 tagged per Wn (R39). |
| **v8.8.0** | ✅ shipped 2026-06-03 | **KB Lifecycle Intelligence — Plus** cycle (W1–W7, PRs #250..#255). **W1** stabilized the test suite (rule **R41**: roll the DB back BEFORE `Mockery::close()` — 38 fragile teardowns reordered so an unmet mock can't cascade an "active transaction" failure) + a line-by-line Affine buyer's-guide gap audit. **W2 delete-trigger deep-analysis** — deleting a doc now runs an obsolescence-impact pass (pre-delete snapshot → which remaining docs have a dangling reference; `trigger='deleted'`). **W3 per-(tenant, project) analysis gate** — `kb_analysis_settings` + `ChangeAnalysisGate` (config → tenant `*` → project, each NULL inherits) + **Admin → Analysis Gate**. **W4 content-gap analytics** — every refused turn (sync + streaming) increments `kb_search_failures`; **Admin → Content Gaps** ranks the unanswered questions to write next. **W5 per-query multilingual FTS** — detect the query language, stem with the matching PostgreSQL dictionary, fall back on an inconclusive signal (R14). **W6 chat-side Related graph panel** — 1-hop `kb_edges` neighbours of an answer's cited canonical docs, ACL-safe. +74 PHPUnit (2141→2215) + 14 Vitest (536→550). Every sub-PR ran the R40 local-critic + R36 cloud Copilot loops to 0 must-fix. |
| **v8.8.1** | ✅ merged to `feature/v8.8` 2026-06-03 (PR #258) | **Live-verification patch.** Driving a REAL browser against live pgvector + a real OpenRouter key (not mocks) surfaced 4 bugs the mocked suites missed: (1) chat **citation `project_key`** must be read from the chunk, not the unselected `document` relation — the W6 chat **Related** panel was dead in production; (2) the primary sidebar dead-ended on `Coming in Phase…` **placeholders** while the real Dashboard / Knowledge / AI-Insights / Users / Maintenance views sat under `/app/admin/*` (e2e navigated there directly, so never caught it) → repointed + redirects + placeholder components deleted; (3) the sidebar **role label** was hardcoded from a seed constant → now reads the real auth-store role (least-privilege fallback); (4) the **AI Act** page had an **infinite iframe recursion** (a v6.0 redirect placeholder looped the iframe back into the host SPA) → replaced with a **native panel** on the real `/api/admin/ai-act-compliance/*` endpoints. Adds a gated `tests/Live/Rag` end-to-end suite (real pgvector + AI, `LIVE_RAG=1`, throwaway tenant, full teardown). New rule **R42** — on a transient external-API failure (429 / 5xx / stream-idle-timeout / no-connection) never stop: wait ~60 s and retry in a loop. |
| **v8.8.2** | ✅ merged to `feature/v8.8` 2026-06-03 (PR #260) | **Unified admin navigation + center-only sister mounts.** Removes the confusing "double menu": the primary sidebar and a near-identical secondary `AdminShell` rail are merged into ONE grouped, collapsible sidebar driven by a single `nav-config.ts` source of truth (23 sections, 5 groups); `AdminShell` is reduced to a content-only wrapper. Each sister-package admin now mounts **center-only** — no second sidebar / nested chrome: **Flows** → a native host landing (live KPIs) + the full cockpit via `target=_blank` (no iframe); **PII Redactor** + **Eval Harness** cross-mounts drop their own sidebar/header into an in-content tab strip; **Eval** additionally probes its data API and shows a clean "unavailable" landing when it isn't wired (safe with the flag ON or OFF). New rule **R43** — a boolean feature flag is tested in BOTH states (OFF and ON), never just enabled; the OFF path must degrade cleanly (404 / disabled / unavailable), never a 500. 9-round Copilot R36 loop to 0 must-fix; the whole admin surface re-verified live (1 nav, 0 nested chrome, every backing API healthy). |
| **Future** | ⏳ planned for v8.x or v9.0 | SSO / SCIM enterprise auth (SAML / OAuth / SCIM provisioning) + content export/portability — surfaced by the v8.8 Affine gap audit, deferred to a dedicated cycle; #1 Semantic Time Travel + #8 v2 (answer drift replay) — parked from v8.0 per A7/A10 of the killer-features plan |

For the strategic reasoning behind v4.5+ see
[`docs/v4-platform/AUDIT-2026-05-11-competitor-comparison.md`](docs/v4-platform/AUDIT-2026-05-11-competitor-comparison.md)
(competitor gap analysis, top 5 highest-leverage gaps) and
[`docs/v4-platform/AUDIT-2026-05-11-vercel-ai-sdk-ui-coverage.md`](docs/v4-platform/AUDIT-2026-05-11-vercel-ai-sdk-ui-coverage.md)
(Vercel AI SDK UI coverage gap analysis, Tier 1/2/3 backlog).

---

## Documentation

| Document | What it covers |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | Authoritative project brief — what AskMyDocs is, critical components, schemas, flows, 40 codified review rules (R1–R43; R33–R35 reserved), branching strategy (R37), Copilot review loop (R36) |
| [`docs/adr/`](docs/adr/) | Architectural decision records — 0001 ingestion path / 0002 storage agnostic / 0003 human-gated promotion / 0004 v4.2 sister-package integration / 0005 React 19 host bump / 0006 nightly eval cron / 0007 adversarial nightly opt-in / 0008 v4.5 universal connectors + source-aware ingestion + modern chat surface / 0009 v4.6 connector package extraction / 0010 v4.7 tabular review + workflows |
| [`docs/v4-platform/STATUS-*`](docs/v4-platform/) | Per-cycle weekly status docs (v4.0 W1–W8 / v4.1 W4.1 / v4.2 W1–W5 / v4.3 W1–W4 / v4.4 W1–W4 / v4.5 W1–W8 / v4.6 W4 / v4.7 W1–W3) — what shipped, test count delta, RC tag SHAs |
| [`docs/v4-platform/ROADMAP-v4-v5-v6.md`](docs/v4-platform/ROADMAP-v4-v5-v6.md) | Multi-major roadmap — v4.5 → v4.6 → v4.7 → v5.0 → v6.0 with Wn breakdowns, acceptance gates, and locked-in decision dates |
| [`docs/connectors/README.md`](docs/connectors/README.md) | Connector framework developer guide — 10-method `ConnectorInterface` contract + composer-package auto-discovery + helper traits + the four channels available to a new connector author |
| [`docs/v4-platform/RUNBOOK-live-fixture-recording.md`](docs/v4-platform/RUNBOOK-live-fixture-recording.md) | Junior-proof per-provider runbook for recording fresh fixtures — exact dev-console URLs, sidebar paths, button labels, scopes, env vars produced, verification one-liner with expected output |
| [`docs/v4-platform/INTEGRATION-ROADMAP-sister-packages.md`](docs/v4-platform/INTEGRATION-ROADMAP-sister-packages.md) | Per-sister-package integration timeline + status: regolo / pii-redactor / flow / eval-harness + the 3 admin SPAs + patent-box-tracker (external) |
| [`docs/v4-platform/AUDIT-2026-05-11-competitor-comparison.md`](docs/v4-platform/AUDIT-2026-05-11-competitor-comparison.md) | Competitor audit vs Glean / Notion AI / ChatGPT Enterprise / M365 Copilot / Mendable / Vectara — feature parity matrix + 5 moats + top 5 v4.5+ gaps |
| [`docs/v4-platform/AUDIT-2026-05-11-vercel-ai-sdk-ui-coverage.md`](docs/v4-platform/AUDIT-2026-05-11-vercel-ai-sdk-ui-coverage.md) | Vercel AI SDK v6 UI coverage audit — what AskMyDocs already implements vs Vercel reference / Claude / ChatGPT; Tier 1/2/3 v4.5 backlog |
| [`docs/v4-platform/FEATURE-CATALOG-*.md`](docs/v4-platform/) | Per-sister-package feature catalogs (laravel-flow / eval-harness / pii-redactor / flow-admin / eval-harness-admin) |
| [`CHANGELOG.md`](CHANGELOG.md) | Full per-release changelog (v1.0 → v4.7.0 GA) — every milestone, every RC tag, every test count delta |

---

## Screenshots gallery

The chat surface and admin shell, page by page.

![AskMyDoc - ChatBot.png](resources/screenshots/AskMyDoc%20-%20ChatBot.png)
![AskMyDoc - Dashboard.png](resources/screenshots/AskMyDoc%20-%20Dashboard.png)
![AskMyDoc - Dashboard-dark.png](resources/screenshots/AskMyDoc%20-%20Dashboard-dark.png)
![AskMyDoc - KB.png](resources/screenshots/AskMyDoc%20-%20KB.png)
![AskMyDoc - User and Roles.png](resources/screenshots/AskMyDoc%20-%20User%20and%20Roles.png)
![AskMyDoc - Manteinance.png](resources/screenshots/AskMyDoc%20-%20Manteinance.png)
![AskMyDoc - Logs.png](resources/screenshots/AskMyDoc%20-%20Logs.png)
![AskMyDoc - Ai.png](resources/screenshots/AskMyDoc%20-%20Ai.png)

**Admin pages in detail.**

*Dashboard (`/app/admin`)*
![Admin Dashboard](resources/screenshots/dashboard-admin.png)

*Users + Roles (`/app/admin/users` + `/app/admin/roles`)*
![Users Table](resources/screenshots/users-table.png)
![User Drawer](resources/screenshots/user-drawer-roles.png)
![Roles Permission Matrix](resources/screenshots/roles-permission-matrix.png)

*KB Explorer (`/app/admin/kb`)*
![KB Tree](resources/screenshots/kb-tree.png)
![KB Doc Preview](resources/screenshots/kb-doc-preview.png)
![KB Doc Source Editor](resources/screenshots/kb-doc-source-editor.png)
![KB Doc Graph](resources/screenshots/kb-doc-graph.png)
![KB Doc History](resources/screenshots/kb-doc-history.png)

*Logs (`/app/admin/logs`)*
![Logs Chat Tab](resources/screenshots/logs-chat-tab.png)
![Logs App Tab](resources/screenshots/logs-app-tab.png)

*Maintenance (`/app/admin/maintenance`)*
![Maintenance Wizard Step 1](resources/screenshots/maintenance-wizard-step1.png)
![Maintenance Wizard Step 2](resources/screenshots/maintenance-wizard-step2-confirm.png)
![Maintenance History](resources/screenshots/maintenance-history.png)

*AI Insights (`/app/admin/insights`)*
![Insights View](resources/screenshots/insights-view.png)

*Dark mode and auth pages*
![Login Dark Mode](resources/screenshots/login-dark-mode.png)
![Chat Dark Mode](resources/screenshots/chat-dark-mode.png)

---

## Sister packages

Several `padosoft/*` MIT Composer packages ship alongside AskMyDocs. Every
package carries architecture tests enforcing **standalone-agnostic
invariants** — zero references to `KnowledgeDocument`, `KbSearchService`,
`kb_*` tables, or `lopadova/askmydocs` in `src/`. `composer require
<package>` on a fresh empty Laravel app produces a working in-process
feature.

| Package | Role | AskMyDocs integration | Repo |
|---|---|---|---|
| `padosoft/laravel-ai-regolo` v1.0 | Regolo provider for `laravel/ai` (EU-based OpenAI-compatible REST) | ✅ wired since v4.0 W2 — `RegoloProvider` delegates to the SDK | [github](https://github.com/padosoft/laravel-ai-regolo) |
| `padosoft/laravel-pii-redactor` v1.2 | PII detection + redaction with EU country packs (Italy + Germany + Spain), 6 checksum-validated detectors, 4 strategies, dual NER drivers | ✅ wired at 11 persistence touch-points since v4.3 W1 (was 4 in v4.1) | [github](https://github.com/padosoft/laravel-pii-redactor) |
| `padosoft/laravel-pii-redactor-admin` v1.0.2 | 7-screen admin SPA for PII operator workflows | ✅ cross-mounted at `/admin/pii-redactor` since v4.4 W2 (iframe in v4.2/W4) | [github](https://github.com/padosoft/laravel-pii-redactor-admin) |
| `padosoft/laravel-flow` v1.0 | In-process saga / compensation engine + approval gates + webhook outbox + replay lineage | ✅ wired since v4.2 W2 — 9 Flow definitions registered | [github](https://github.com/padosoft/laravel-flow) |
| `padosoft/laravel-flow-admin` v1.0.0 | Blade + Alpine cockpit SPA — runs / approvals / webhook outbox / definitions | ✅ at `/admin/flows`. **Since v8.8.2 the host shows a native center-only panel** (KPI probe of `/admin/flows/api/live` + section cards) that launches the full Blade+Alpine cockpit in a new tab (`target="_blank"`) — so the cockpit is never nested inside the host chrome (ADR 0005: the cockpit itself stays Blade+Alpine, not React) | [github](https://github.com/padosoft/laravel-flow-admin) |
| `padosoft/eval-harness` v1.2 | RAG / LLM evaluation framework — golden datasets, 7 metrics, cohorts, adversarial lane, LLM-as-judge regression detection | ✅ wired since v4.2 W3 (CI gate) + v4.3 W3 (nightly cron) + v4.4 W4 (adversarial opt-in) | [github](https://github.com/padosoft/eval-harness) |
| `padosoft/eval-harness-ui` v1.0.0 | 8-page React + Vite admin SPA — read-only, non-prod-only | ✅ cross-mounted at `/admin/eval-harness` since v4.4 W3 (iframe in v4.2/W4); 3 fail-closed fences preserved | [github](https://github.com/padosoft/eval-harness-ui) |
| `padosoft/laravel-patent-box-tracker` v0.1 | Italian Patent Box dossier auto-generator | ❌ external by design — operators install in a separate Laravel project; AskMyDocs ships `tools/patent-box/2026.yml` as input | [github](https://github.com/padosoft/laravel-patent-box-tracker) |
| **Connectors** (8 packages, v4.6 extraction) — `padosoft/askmydocs-connector-base` v1.1.1 + `-google-drive` v1.0.1 + `-notion` v1.0.1 + `-evernote` v1.0.0 + `-fabric` v1.0.0 + `-onedrive` v1.0.0 + `-confluence` v1.0.0 + `-jira` v1.0.0 | Framework primitives + 7 standalone external-source connectors (OAuth2 + sync + source-aware markdown rendering) — each `composer require`-able; auto-discovered via `composer.json::extra.askmydocs.connectors`; talk to AskMyDocs through the `ConnectorIngestionContract` IoC bridge | ✅ wired since v4.6 W4 — `HostIngestionBridge` implements the contract (dispatch / path resolve / PII redact / audit / soft-delete by remote-id); inline `app/Connectors/BuiltIn/` from v4.5 fully replaced | [base](https://github.com/padosoft/askmydocs-connector-base) · [google-drive](https://github.com/padosoft/askmydocs-connector-google-drive) · [notion](https://github.com/padosoft/askmydocs-connector-notion) · [evernote](https://github.com/padosoft/askmydocs-connector-evernote) · [fabric](https://github.com/padosoft/askmydocs-connector-fabric) · [onedrive](https://github.com/padosoft/askmydocs-connector-onedrive) · [confluence](https://github.com/padosoft/askmydocs-connector-confluence) · [jira](https://github.com/padosoft/askmydocs-connector-jira) |
| `padosoft/askmydocs-mcp-pack` v1.5.0 | Framework-agnostic MCP (Model Context Protocol) plumbing for Laravel — 6 contracts + multi-turn tool-calling orchestrator + stdio/HTTP transports + hash-only audit + RBAC hooks + **full admin REST surface (22 endpoints): me/tenants/api-keys, server CRUD + handshake + tools/resources/prompts, R21-atomic tool invoke / audit replay / breaker reset, SSE events, OpenAPI 3.1 spec**; standalone, zero AskMyDocs dependencies; 325 PHPUnit tests across 7 CI cells (PHP 8.3 × Laravel 11/12/13, PHP 8.4 × Laravel 11/12/13, PHP 8.5 × Laravel 13 only) | ✅ shipped 2026-05-18 (v1.5.0) — full cycle v1.0→v1.5 closed: v1.0 contracts, v1.1 transports + SSE, v1.2 server-side, v1.3 circuit breaker + retry, v1.4 admin REST minimal (6 endpoints), v1.5 admin REST complete (22 endpoints with sub-interface BC-safe extensions: `McpHostBridgeIdentityContract` + `McpServerMutableRegistryContract`). AskMyDocs v7.1+ pins `^1.5` | [github](https://github.com/padosoft/askmydocs-mcp-pack) |
| `padosoft/askmydocs-mcp-pack-admin` v1.1.0 | Standalone React SPA companion — 12 routes covering server CRUD, handshake, tool catalog, paginated audit log, circuit-breaker dashboard, three-pane resources browser, prompt playground, OpenAPI explorer, settings + tour. Cross-mounts under `/admin/mcp-pack` exactly like `pii-redactor-admin` / `flow-admin` / `eval-harness-ui` | ✅ live wire-up GA 2026-05-18 (v1.1.0) — every page surface drives real `padosoft/askmydocs-mcp-pack` v1.5+ endpoints via TanStack Query: 22 typed endpoints + 19 hand-written types mirroring v1.5 OpenAPI, 13 read hooks + 10 mutation hooks, R21 two-call confirm-token protocol with second-leg expired-token guard on `useInvokeTool` / `useReplayAudit` / `useResetBreaker`, SSE live-feed consumer replacing prototype simulator, `<DataState>` shared wrapper enforcing R14+R11+R15 invariants. **154 Vitest specs across 22 test files** covering loading / error / empty / ready states + R21 happy + failure + ValidationError + SSE behaviour via MSW handlers shaped to the real wire schema. Full real-backend Playwright rewrite tracked for v1.1.x; v1.1.0 ships with a smoke spec only | [github](https://github.com/padosoft/askmydocs-mcp-pack-admin) |
| `padosoft/laravel-ai-act-compliance` + `-admin` | EU AI Act compliance pack: DSAR, **pluggable bias-metric registry** (DemographicParity / EqualizedOdds / Calibration), risk register, FRIA (Art. 27), human-review tracker, incident state machine, consent + disclosure middleware, cybersecurity middleware stack, Article 30 attestation PDF, **cohort-drift real-time alerting cascade** (Slack → Discord → always-CC email; throttle + circuit breaker + severity-escalation bypass), **EU AI Act regulatory-feed auto-flagger** (RSS + Atom, XXE-safe), **DPO multi-org tenant registry** + per-tenant config overrides + cross-tenant overview; companion admin SPA cross-mounts under `/admin/ai-act-compliance` (host cross-mount infrastructure shipped in v6.0; v6.1 brings 3 additional screens via the package upgrade) with 12 fully-featured screens (Overview / DSAR / Consent / Risks / FRIA / Incidents / Bias / DPO / Settings / **Alerts** / **Regulatory** / **Tenants**) | v1.5.0 — Packagist ✅ | v6.0–v6.1 |

See [`docs/v4-platform/INTEGRATION-ROADMAP-sister-packages.md`](docs/v4-platform/INTEGRATION-ROADMAP-sister-packages.md)
for the per-package timeline and locked composer constraints.

---

## Contributing

Contributions welcome. Workflow:

1. **Fork** the repository
2. **Create** a feature branch (`feature/v4.x/<sub-task>` for v4.x work — R37)
3. **Commit** with conventional-style messages
4. **Push** + open a PR with `--reviewer copilot-pull-request-reviewer` (R36)
5. **Wait** for Copilot review + CI green; iterate until 0 outstanding must-fix
6. **Merge** when both gates pass

Guidelines:

- PSR-12 for PHP; ESLint + Prettier for TS/React
- Add or update tests for any meaningful change (PHPUnit + Vitest + Playwright as appropriate)
- Keep PRs focused — one feature or fix per PR
- Update the relevant CLAUDE.md / docs / CHANGELOG when user-facing behaviour changes
- English for code, comments, and commit messages
- Honour the 40 codified review rules (R1–R43; R33–R35 reserved) — see [`CLAUDE.md`](CLAUDE.md) section 7

Report issues via [GitHub Issues](../../issues) with reproduction
steps, expected vs actual behaviour, and Laravel / PHP / provider
versions.

---

## License

This project is open-source under the [MIT License](LICENSE).

You are free to use, modify, and distribute it for any purpose,
including commercial use.

---

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md) for detailed release notes from
v1.0 through v8.7.0. **v8.7.0** is the **KB Lifecycle Intelligence** cycle:
**Synonym Expansion** (per-tenant jargon ↔ plain-language query expansion),
a **weekly notification digest** + a settings-tunable **stale-document
review** sweep, the flagship **AI deep-analysis on document change** (an async
LLM pass that, on every ingest/modify, suggests enhancements, surfaces
cross-references, and flags which other docs the change makes obsolete —
surfaced under Admin → Doc Insights, suggest-only per ADR 0003), and the
**Cloud Time Machine** (version timeline + diff + atomic restore + retention
prune under Admin → Time Machine). **v8.6.0** makes the chat's dead clickable actions live:
cited sources now navigate to the KB document (admin-gated), the conversation
title auto-generates after the first turn and is inline-renamable via a pencil
(ChatGPT-style), and a new boot/navigation smoke test asserts every main screen
mounts with zero uncaught exceptions. **v8.5.0** ships the definitive browser streaming E2E:
`chat-stream-browser.spec.ts` drives a real grounded turn **and** a real
refusal turn through the real `/messages/stream` SSE + the real `@ai-sdk`
transport (the layer where the v8.4 wire-format crashes fired) with nothing
stubbed — backed by an offline `FakeProvider` (hard-gated to testing/local)
and an `E2eStreamSeeder` that makes one doc genuinely vector-searchable.
**v8.4.0** is a security + correctness hardening release:
an RBAC access-control matrix (R32) that caught a real unauthenticated AI-Act
API vulnerability on its first run, two chat-stream wire-format crash fixes
(source-url + finish) with an exhaustive SDK-schema contract guard, and the
`CACHE_STORE` default fix. **v8.3.0** adds full-stack live verification:
`kb:benchmark --with-answers` scores real-LLM **answer-faithfulness**
(cosine of the answer vs the grounding the LLM saw), the `eval:nightly`
LLM-as-judge path is validated LIVE against a real model
(citation-groundedness ≈ 0.98), and a consolidated full-stack test proves
grounded citations + AI-Act disclosure + chat logging + PII answer-redaction
all fire on one chat turn. **v8.2.0** shipped a reproducible retrieval-quality
benchmark (`kb:benchmark`) + made the full RAG pipeline testable
end-to-end with no mocks (SQLite PHP-cosine fallback), and a
**live-validated calibration** (real embeddings + pgvector) that took the
scorecard to nDCG 0.997 / MRR 1.000 / citation 1.000 / refusal 1.000.
**v8.1.0** was the retrieval-quality minor before it (refusal-gate fix +
3-channel unification + @mention boost + evidence citations + IR metrics).
