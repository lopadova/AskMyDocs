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
  <a href="CHANGELOG.md"><img src="https://img.shields.io/badge/release-v4.5.0-blueviolet?style=flat-square" alt="Release v4.5.0"></a>
  <a href="#universal-connectors"><img src="https://img.shields.io/badge/connectors-7%20native-0ea5e9?style=flat-square" alt="7 Native Connectors"></a>
  <a href="#quality--observability"><img src="https://img.shields.io/badge/tests-1885%20PHPUnit%20%2B%20384%20Vitest-brightgreen?style=flat-square" alt="1885 PHPUnit + 384 Vitest"></a>
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
- **Canvas-ready architecture (artifact panel ships in v5.0)** — Tier 2 stretch (tool-result rendering, streaming source-document parts, conversation export, image attachments, artifact panel) is deliberately deferred to v5.0 so it can be designed alongside the MCP **client** tool dispatcher and share one storage contract. See ADR 0008 D4.
- **Zero-config for OpenAI / Anthropic / Gemini / OpenRouter / Regolo** — every provider is called via raw `Http::` (no SDK); `AiManager::chatStream()` synthesises a single-chunk SSE for providers without native streaming via the `FallbackStreaming` trait.

**Try it.** Open `/app/chat` in the React SPA. Start a long answer
and hit Stop; click Regenerate; hover the assistant message and pick
Branch (a new conversation forks from that point); pick a follow-up
pill chip to chain into the next prompt; hover any code block for the
Copy button.

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
| `KbSearchService` facets (`source` + `tag`) | `searchWithContext()` accepts optional `facets` param; emits `facets[source]` + `facets[tag]` counts; backed by 2 new GIN-on-`jsonb` indexes (`source_type` + `search_tags`) plus 1 B-tree on `knowledge_chunks.metadata` `recency_bucket`, all PostgreSQL-only (SQLite is a no-op) | v4.5 |
| **Tabular Review** (spreadsheet-style document extraction, BE-only in rc1) | `tabular_reviews` + `tabular_cells` tables; `TabularReviewExtractor` runs ONE multi-column LLM call per document (cost `O(documents)` not `O(documents × columns)`); 17 format types (Mike's 9 + 8 AskMyDocs-new including the LLM-free `json_path` shortcut leveraging v4.5/W5.5 source-aware metadata); R14 loud refusal with red flag + reasoning on no-evidence / LLM error / JSON parse failure; atomic upsert keyed on the composite UNIQUE `(tenant_id, review_id, document_id, column_index)` so concurrent generate/regenerate cannot race. Admin SPA + Glide Data Grid + SSE streaming land in v4.7/W3 | v4.7 (W1 rc1) |

### Chat & Conversation

| Feature | Description | Since |
|---|---|---|
| Vercel AI SDK v6 streaming | `MessageStreamController` emits SDK v6 `UIMessageChunk` frames (`start` / `text-start` / `text-delta` / `text-end` / `source-url` / `data` / `finish`) over SSE; first-token latency dropped from ~2.8 s synchronous to ~400 ms streaming on the Lighthouse baseline | v4.0 |
| `useChatStream()` React hook | `mapStatusToDataState()` adapter exposes `data-state="idle\|loading\|ready\|empty\|error"` for deterministic Playwright waits (SDK `submitted` and `streaming` statuses both collapse to `loading` per the R11 comment in `MessageThread.tsx`); unit-tested in `frontend/src/features/chat/map-status-to-data-state.test.ts` | v4.0 |
| Citations panel | Every assistant reply ships the source documents (`document_id`, `title`, `source_path`, `headings`, `chunks_used`); persisted on `messages.metadata.citations`; survives conversation reload | v1.0 |
| Conversation history | `conversations` + `messages` tables (user-scoped); inline rename, delete with confirmation, AI-generated title after first turn, full multi-turn history sent to provider on every request | v1.0 |
| Composite confidence score (0–100) | `ConfidenceCalculator`: `0.40·mean_top_k_sim + 0.20·threshold_margin + 0.20·chunk_diversity + 0.20·citation_density`; renders as `high / moderate / low / refused` tier in the `ConfidenceBadge` | v3.0 |
| Refusal handling | Two refusal paths: deterministic `no_relevant_context` short-circuit (Mockery `shouldNotReceive('chat')` per R26 proves no LLM call) and `llm_self_refusal` via exact-match-after-trim `__NO_GROUNDED_ANSWER__` sentinel. `RefusalNotice` uses `role="status"` not `alert` (R24) | v3.0 |
| `@mention` doc pinning | Type `@docname` in the composer → `/api/kb/documents/search` autocomplete → `MentionPopover` with cursor-context detection → pinned `doc_id` forces inclusion in retrieval even when scored below the similarity floor | v3.0 |
| Filter chips + saved presets | Persistent `FilterBar` with per-dimension removable `FilterChip`s; tabbed `FilterPickerPopover` (Project / Type / Tag / Folder / Date / Language); per-user saved presets at `RESTful /api/chat-filter-presets` (lossless round-trip) | v3.0 |
| Speech-to-text (Web Speech API) | Browser-native mic input via `webkitSpeechRecognition`; zero external service, zero cost; defaults to `it-IT` (configurable). Chrome / Edge / Safari supported | v1.0 |
| Few-shot learning loop | Thumbs up/down rating on every assistant message; `FewShotService` retrieves last 3 positively-rated Q&As per user/project and injects as "Examples of Well-Rated Answers" in the system prompt | v1.0 |
| Smart visual artifacts | `~~~chart` JSON blocks render as Chart.js bar/line/pie/doughnut; `~~~actions` JSON renders as copy/download buttons; every code block ships a "Copy" button | v1.0 |
| Multi-provider AI federation | OpenAI / Anthropic / Gemini / OpenRouter / Regolo via raw `Http::` calls (no SDK); `AiManager::chat()` + `chatStream()` + `embeddings()`; per-provider streaming where supported (all 5 native or via `FallbackStreaming` trait); chat and embeddings providers configured separately | v1.0 |
| Stateless JSON chat API | `POST /api/kb/chat` synchronous endpoint kept as backward-compat fallback alongside the v4 SSE streaming path; same hybrid retrieval pipeline + refusal short-circuit + confidence score serve both | v1.0 |
| Stop / regenerate / branch / inline-edit affordances | Vercel AI SDK UI Tier 1 closure: stop-streaming via `AbortController`; regenerate-last-assistant; branch-from-message endpoint (forks the conversation tree); inline-edit user message; copy-code-block. All wired on `MessageStreamController` + the `useChatStream()` hook | v4.5 |
| Per-message provider/model/cost metadata | Enhanced badge below every assistant message shows `provider`, `model`, `started_at`, prompt + completion tokens, and derived USD cost when `config('ai.cost_rates')` is populated (keyed by `provider → model → {input, output}`); cost is omitted (not zero) when rates are missing. Public lookup at `GET /api/chat/cost-rates` with 1-hour CDN cache | v4.5 |
| Suggested follow-up pills | `SuggestedFollowupGenerator` derives three follow-up prompts from the assistant's last reply via `AiManager::chat()`; renders as clickable pill chips above the composer; clicking submits via the streaming endpoint. Best-effort — provider error / parse failure / empty response returns `[]` and the row is not rendered. Triggered once on `onFinish` per assistant turn at `POST /conversations/{id}/suggested-followups` | v4.5 |

### Security & Compliance

| Feature | Description | Since |
|---|---|---|
| PII redaction at 11 persistence boundaries | `padosoft/laravel-pii-redactor` v1.2 wired at: (1) chat-message middleware, (2) embedding-cache pre-redact, (3) AI-insights snippet sanitiser, (4) operator detokenize endpoint, (5) Monolog log channel processor, (6) failed-jobs sanitiser via `JobFailed` listener with deterministic UUID match, (7) `Conversation`+`Message` `saving` observers, (8) `ChatLog::creating` observer, (9) `AdminCommandAudit::creating` observer, (10) `AdminInsightsSnapshot::creating` observer (6 JSON columns), (11) Flow `CurrentPayloadRedactorProvider` contract binding (covers run input + step results + audit + webhook outbox + approvals in one wire). All 5 v4.3 env knobs default OFF | v4.3 |
| Multi-tenant isolation (R30 + R31) | 17 tenant-aware tables carry `tenant_id`; `BelongsToTenant` trait auto-fills from `TenantContext` on `creating`; composite tenant-scoped FK on `kb_edges` makes cross-tenant edges structurally impossible; architecture test `TenantIdMandatoryTest` gates new models | v4.0 |
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
| Admin SPA shell (`/app/admin/*`) | React 18+ (React 19 since v4.3) + TypeScript + Vite + TanStack Router/Query + shadcn/ui; dark-first glassmorphism; code-split routes (~400 KB initial gzipped); RBAC-gated via Spatie; sidebar visibility enforced server-side | v3.0 |
| Dashboard KPIs + health | 6 KPIs (docs / chunks / chats / p95 latency / cache hit rate / canonical coverage) + 6 health probes (db / pgvector / queue / kb-disk / embeddings / chat) + 3 code-split recharts cards (chat volume area, token burn stacked, rating donut) + top projects + activity feed; 30s `Cache::remember` layer keyed by kind+project+days | v3.0 |
| Users + Roles + Memberships | Filterable users table with soft-delete + restore; 3-tab edit drawer (Details / Roles / Memberships with `scope_allowlist` JSON editor); Spatie-backed role CRUD with grouped permission matrix; `project_memberships` rows scope canonical visibility per project | v3.0 |
| KB Explorer (tree + 5 right-panel tabs) | Memory-safe `chunkById(100)` tree walker with canonical-aware modes (`canonical \| raw \| all`, `with_trashed=0\|1`); right-panel tabs Preview (remark-rendered + frontmatter pills) / Meta (canonical grid + AI tags) / **Source** (CodeMirror 6 editor with PATCH `/raw` → validate → write → audit → re-ingest) / **Graph** (1-hop tenant-scoped subgraph, SVG radial, ≤ 50 nodes) / **History** (paginated `kb_canonical_audit`) | v3.0 |
| PDF export (Browsershot + Dompdf fallback) | `PdfRenderer` interface with `BrowsershotPdfRenderer` primary (full CSS / fonts / charts) and `DompdfPdfRenderer` fallback (no headless Chromium dependency); A4 print-optimised; renderer chosen at controller level (R23 registry mutex) | v3.0 |
| Log viewer (5 tabs) | Five deep-linkable tabs (`?tab=chat\|audit\|app\|activity\|failed`): chat logs with model/project/rating filters; canonical audit trail with event-type/actor filters; reverse-seek `SplFileObject`-powered application log tailer (whitelist regex, 2000-line cap, optional live polling via `?live=1`); Spatie activity log; failed-jobs read-only table | v3.0 |
| Maintenance command runner | Three-step React wizard (Preview → Confirm with type-in for destructive → Run → Result); whitelist + args_schema + confirm_token + Spatie gates + audit + throttle (see Security row); scheduler widget reports next run of every queued command | v3.0 |
| AI insights panel | Daily `insights:compute` (05:00 UTC) writes one row to `admin_insights_snapshots`; six widget cards (Promotion Suggestions / Orphan Docs / Suggested Tags / Coverage Gaps / Stale Docs / Quality Report) read from JSON columns; O(1) DB read, zero LLM calls per page load | v3.0 |
| Cross-mounted admin SPAs (3 packages) | `padosoft/laravel-pii-redactor-admin` v1.0.2 at `/admin/pii-redactor` (cross-mount since v4.4/W2) + `padosoft/laravel-flow-admin` v1.0.0 at `/admin/flows` (iframe — Blade+Alpine, will remain iframed per ADR 0005) + `padosoft/eval-harness-ui` v1.0.0 at `/admin/eval-harness` non-prod-only (cross-mount since v4.4/W3, 3 fail-closed fences preserved) | v4.2 |
| Laravel scheduler (10+ entries) | `kb:prune-embedding-cache` 03:10 / `chat-log:prune` 03:20 / `kb:prune-deleted` 03:30 / `kb:rebuild-graph` 03:40 / `insights:compute` 05:00 / `eval:nightly` 05:30 (v4.3+, default OFF); all `onOneServer()->withoutOverlapping()` | v3.0 |
| Sidebar gating + R29 testid hierarchy | Sidebar entries always rendered, visibility enforced server-side via per-route fences (RequireRole + middleware `can:` + env `abort(404)`); every actionable element uses `feature-resource-{id}-{action[-substep]}` testid convention for Playwright stability | v3.0 |
| Connector admin SPA (`/app/admin/connectors`) | React DataTable with per-connector install/uninstall flow; OAuth callback handler at `/app/admin/connectors/$key/callback`; per-installation `connector_installations` + `connector_credentials` rows (encrypted via `OAuthCredentialVault`); scheduler-driven `ConnectorSyncJob`; Spatie `manageConnectors` super-admin gate at controller + route layer | v4.5 |

### Integrations & Extensibility

| Feature | Description | Since |
|---|---|---|
| MCP server (inward, 10 tools) | `enterprise-kb` server at `/mcp/kb` exposes the KB to Claude Desktop / Claude Code / any MCP-compatible agent (5 retrieval + 5 canonical/promote tools); `auth:sanctum` + `throttle:api` | v3.0 |
| GitHub composite action `ingest-to-askmydocs` (v2) | Reusable action with diff-mode (every push: `git diff --diff-filter=AMR` ingest + `D`+`R` delete batches via `DELETE /api/kb/documents`) and full-sync mode; canonical-folder aware; max 100 docs / batch; `--rawfile` for ARG_MAX safety (R5) | v3.0 |
| 9 registered Flow definitions (saga / compensation) | `kb.ingest` (5-step) / `kb.canonical-index` (3-step) / `kb.promote` (4-step approval-gated, first use of `approval-gate` primitive) / `kb.delete` (4-step) / `kb.prune-deleted` / `kb.prune-embedding-cache` (conditional approval gate) / `kb.prune-chat-logs` / `kb.rebuild-graph` / `kb.ingest-folder` (3-step fan-out). Reverse-order compensation chains; persisted to `flow_runs` + `flow_steps` + `flow_audit` + `flow_approvals` + `flow_webhook_outbox` | v4.2 |
| Multi-AI-provider abstraction | OpenAI / Anthropic / Gemini / OpenRouter / Regolo via raw `Http::` (no SDK); Regolo via `padosoft/laravel-ai-regolo` on `laravel/ai`; `FallbackStreaming` trait synthesises single-chunk SSE for providers without native streaming | v1.0 |
| Pluggable ingestion pipeline | 3 contracts (`ConverterInterface` / `ChunkerInterface` / `EnricherInterface`); `PipelineRegistry` with FQCN-validated-at-boot + `supports()` mutex (R23); add a new format = implement 3 interfaces + register in `config/kb-pipeline.php` | v3.0 |
| Pluggable chat-log driver | `ChatLogDriverInterface`; `database` driver shipped; BigQuery / CloudWatch are extension points via `ChatLogManager::resolveDriver()` | v1.0 |
| Sister `padosoft/*` package stack | `laravel-ai-regolo` v1.0 (Regolo provider for `laravel/ai`) + `laravel-pii-redactor` v1.2 (PII detection with EU country packs: Italy + Germany + Spain) + `laravel-pii-redactor-admin` v1.0.2 + `laravel-flow` v1.0 (saga engine + approval gates + webhook outbox + replay) + `laravel-flow-admin` v1.0.0 + `eval-harness` v1.2 (golden datasets + 7 metrics + cohorts + adversarial + LLM-as-judge) + `eval-harness-ui` v1.0.0 — every package MIT, every architecture test enforces standalone-agnostic invariants (zero refs to `KnowledgeDocument` / `kb_*` tables / `lopadova/askmydocs` in `src/`) | v4.2 |
| External Patent Box dossier tool | `padosoft/laravel-patent-box-tracker` v0.1 generates audit-grade Italian Patent Box dossiers; **deliberately NOT in AskMyDocs `composer.json`** — operators install it in a separate Laravel project (R37 standalone-agnostic) and consume `tools/patent-box/2026.yml` from this repo. Commercialista-validated 2026-05-02 | v4.0 |
| Connector framework + 7 native connectors | Plugin/package architecture (`ConnectorInterface` 10-method contract + `BaseConnector` + `OAuthCredentialVault` + `ConnectorRegistry` with R23 FQCN-validated discovery via `config/connectors.php::built_in` OR `composer.json::extra.askmydocs.connectors`). 7 native connectors: `google-drive` + `notion` + `evernote` + `fabric` + `onedrive` + `confluence` + `jira` (all inline for v4.5; extracted to `padosoft/askmydocs-connector-*` packages in v4.6 per ADR 0008 D1) | v4.5 |
| MCP client framework (planned v5.0) | AskMyDocs as MCP **CLIENT** (outward direction) — registry per workspace/tenant (`mcp_servers` table + `auth_config_json` + `enabled_tools_json` + per-tool RBAC); credential vault; admin UI to register MCP servers; per-conversation tool authorization; audit trail (`mcp_tool_call_audit`); Node sidecar via `@modelcontextprotocol/sdk`. NOT yet shipped | v5.0 ⏳ |

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
| Test inventory | **1885 PHPUnit tests** across PHP 8.3 / 8.4 / 8.5 + **384 Vitest react scenarios** + **18 Vitest legacy** + 36 Playwright spec files + RAG regression workflow — all green as of v4.5.0 GA | v4.5 |
| Opt-in live-test recording infrastructure | `tests/Live/Connectors/` skeleton + `LiveConnectorTestCase` per-provider env-var guard: each test gates on `CONNECTOR_<PROVIDER>_LIVE=1` (e.g. `CONNECTOR_NOTION_LIVE=1`) and needs the provider credential vars (e.g. `CONNECTOR_NOTION_TOKEN`, `CONNECTOR_CONFLUENCE_TOKEN`+`CONNECTOR_CONFLUENCE_CLOUD_ID`); fixture recording is enabled via `CONNECTOR_RECORD_FIXTURES=1`. Default CI runs `Unit` + `Feature` only (zero provider cost). Manual workflow `.github/workflows/live-recording-nightly.yml` available via `workflow_dispatch`. Junior-proof per-provider setup in [`docs/v4-platform/RUNBOOK-live-fixture-recording.md`](docs/v4-platform/RUNBOOK-live-fixture-recording.md) | v4.5 |
| Structured chat logging | DB driver (extensible to BigQuery / CloudWatch); `session_id` / `user_id` / `question` / `answer` / `project_key` / `ai_provider` / `ai_model` / `chunks_count` / `sources` / `prompt_tokens` / `completion_tokens` / `total_tokens` / `latency_ms` / `client_ip` / `user_agent` / `extra` columns; try/catch — never propagates failures | v1.0 |
| 39 codified review rules (R1–R39) | Distilled from ~110+ live Copilot findings across PR #4–#142; mirrored in `CLAUDE.md` + `.github/copilot-instructions.md` + per-rule `.claude/skills/<rule>/`; auto-loaded by Claude Code when trigger conditions match; pre-push agent at `.claude/agents/copilot-review-anticipator.md` | v3.0 |
| ADR set (ADR 0001 → 0008) | Architectural decisions records: 0001 ingestion path, 0002 storage agnostic, 0003 human-gated promotion, 0004 v4.2 sister-package integration, 0005 React 19 host bump + iframe→cross-mount deferral, 0006 nightly eval cron, 0007 adversarial nightly opt-in, 0008 v4.5 universal connectors + source-aware ingestion + modern chat surface | v3.0 |

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

---

## Architecture

The v4.x platform routes every request through `ResolveTenant`
middleware that populates the `TenantContext` singleton, so every
Eloquent query that follows is tenant-scoped (R30 / R31). The chat
surface ships **two interchangeable transports** — the v3 synchronous
JSON path on `KbChatController` (backward-compat fallback) and the v4
SSE streaming path on `MessageStreamController` (default for the React
SPA, emits SDK v6 `UIMessageChunk` frames). Both converge on the same
hybrid retrieval pipeline (vector + FTS + reranker + canonical graph
expansion + rejected-approach injection).

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
│  │ → { answer, citations,       │   │ → UIMessageChunk frames          │     │
│  │     refusal_reason,          │   │   (start/text-delta/source-url/  │     │
│  │     confidence, meta }       │   │    data-confidence/data-refusal/ │     │
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
└───────────────────┘  └───────────────────┘  └──────────────────────────────┘
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
| **v4.6** | ⏳ planned | Connector package extraction — 7 inline connectors lifted to `padosoft/askmydocs-connector-*` packages + shared `-base` package + `padosoft/askmydocs-connector-template` for community contributors |
| **v4.7** | ⏳ planned | Tabular Review + Workflows + AI-suggest — Glide Data Grid canvas tables + workflow editor + KB-sample-driven workflow suggester (16 format types + 12 UX differentiators) |
| **v5.0** | ⏳ planned | Agentic platform — MCP **client** framework (outward direction) + per-tenant tool registry + credential vault + Node sidecar via `@modelcontextprotocol/sdk` |
| **v6.0** | ⏳ planned | AI Act compliance bundle — via extracted `padosoft/laravel-ai-act-compliance` + admin SPA package (DSAR / bias monitoring / risk register / human-review tracker / consent / disclosure middleware) + AskMyDocs-specific token-level explainability and refusal-quality cohorts |

For the strategic reasoning behind v4.5+ see
[`docs/v4-platform/AUDIT-2026-05-11-competitor-comparison.md`](docs/v4-platform/AUDIT-2026-05-11-competitor-comparison.md)
(competitor gap analysis, top 5 highest-leverage gaps) and
[`docs/v4-platform/AUDIT-2026-05-11-vercel-ai-sdk-ui-coverage.md`](docs/v4-platform/AUDIT-2026-05-11-vercel-ai-sdk-ui-coverage.md)
(Vercel AI SDK UI coverage gap analysis, Tier 1/2/3 backlog).

---

## Documentation

| Document | What it covers |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | Authoritative project brief — what AskMyDocs is, critical components, schemas, flows, 39 codified review rules (R1–R39), branching strategy (R37), Copilot review loop (R36) |
| [`docs/adr/`](docs/adr/) | Architectural decision records — 0001 ingestion path / 0002 storage agnostic / 0003 human-gated promotion / 0004 v4.2 sister-package integration / 0005 React 19 host bump / 0006 nightly eval cron / 0007 adversarial nightly opt-in / 0008 v4.5 universal connectors + source-aware ingestion + modern chat surface |
| [`docs/v4-platform/STATUS-*`](docs/v4-platform/) | Per-cycle weekly status docs (v4.0 W1–W8 / v4.1 W4.1 / v4.2 W1–W5 / v4.3 W1–W4 / v4.4 W1–W4 / v4.5 W1–W8) — what shipped, test count delta, RC tag SHAs |
| [`docs/v4-platform/ROADMAP-v4-v5-v6.md`](docs/v4-platform/ROADMAP-v4-v5-v6.md) | Multi-major roadmap — v4.5 → v4.6 → v4.7 → v5.0 → v6.0 with Wn breakdowns, acceptance gates, and locked-in decision dates |
| [`docs/connectors/README.md`](docs/connectors/README.md) | Connector framework developer guide — 10-method `ConnectorInterface` contract + composer-package auto-discovery + helper traits + the four channels available to a new connector author |
| [`docs/v4-platform/RUNBOOK-live-fixture-recording.md`](docs/v4-platform/RUNBOOK-live-fixture-recording.md) | Junior-proof per-provider runbook for recording fresh fixtures — exact dev-console URLs, sidebar paths, button labels, scopes, env vars produced, verification one-liner with expected output |
| [`docs/v4-platform/INTEGRATION-ROADMAP-sister-packages.md`](docs/v4-platform/INTEGRATION-ROADMAP-sister-packages.md) | Per-sister-package integration timeline + status: regolo / pii-redactor / flow / eval-harness + the 3 admin SPAs + patent-box-tracker (external) |
| [`docs/v4-platform/AUDIT-2026-05-11-competitor-comparison.md`](docs/v4-platform/AUDIT-2026-05-11-competitor-comparison.md) | Competitor audit vs Glean / Notion AI / ChatGPT Enterprise / M365 Copilot / Mendable / Vectara — feature parity matrix + 5 moats + top 5 v4.5+ gaps |
| [`docs/v4-platform/AUDIT-2026-05-11-vercel-ai-sdk-ui-coverage.md`](docs/v4-platform/AUDIT-2026-05-11-vercel-ai-sdk-ui-coverage.md) | Vercel AI SDK v6 UI coverage audit — what AskMyDocs already implements vs Vercel reference / Claude / ChatGPT; Tier 1/2/3 v4.5 backlog |
| [`docs/v4-platform/FEATURE-CATALOG-*.md`](docs/v4-platform/) | Per-sister-package feature catalogs (laravel-flow / eval-harness / pii-redactor / flow-admin / eval-harness-admin) |
| [`CHANGELOG.md`](CHANGELOG.md) | Full per-release changelog (v1.0 → v4.5.0 GA) — every milestone, every RC tag, every test count delta |

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
| `padosoft/laravel-flow-admin` v1.0.0 | Blade + Alpine cockpit SPA — runs / approvals / webhook outbox / definitions | ✅ iframe-mounted at `/admin/flows` since v4.2 W4 (stays iframed per ADR 0005 — Blade+Alpine, not React) | [github](https://github.com/padosoft/laravel-flow-admin) |
| `padosoft/eval-harness` v1.2 | RAG / LLM evaluation framework — golden datasets, 7 metrics, cohorts, adversarial lane, LLM-as-judge regression detection | ✅ wired since v4.2 W3 (CI gate) + v4.3 W3 (nightly cron) + v4.4 W4 (adversarial opt-in) | [github](https://github.com/padosoft/eval-harness) |
| `padosoft/eval-harness-ui` v1.0.0 | 8-page React + Vite admin SPA — read-only, non-prod-only | ✅ cross-mounted at `/admin/eval-harness` since v4.4 W3 (iframe in v4.2/W4); 3 fail-closed fences preserved | [github](https://github.com/padosoft/eval-harness-ui) |
| `padosoft/laravel-patent-box-tracker` v0.1 | Italian Patent Box dossier auto-generator | ❌ external by design — operators install in a separate Laravel project; AskMyDocs ships `tools/patent-box/2026.yml` as input | [github](https://github.com/padosoft/laravel-patent-box-tracker) |
| **Connectors** (8 packages, v4.6 extraction) — `padosoft/askmydocs-connector-base` v1.1.1 + `-google-drive` v1.0.1 + `-notion` v1.0.1 + `-evernote` v1.0.0 + `-fabric` v1.0.0 + `-onedrive` v1.0.0 + `-confluence` v1.0.0 + `-jira` v1.0.0 | Framework primitives + 7 standalone external-source connectors (OAuth2 + sync + source-aware markdown rendering) — each `composer require`-able; auto-discovered via `composer.json::extra.askmydocs.connectors`; talk to AskMyDocs through the `ConnectorIngestionContract` IoC bridge | ✅ wired since v4.6 W4 — `HostIngestionBridge` implements the contract (dispatch / path resolve / PII redact / audit / soft-delete by remote-id); inline `app/Connectors/BuiltIn/` from v4.5 fully replaced | [base](https://github.com/padosoft/askmydocs-connector-base) · [google-drive](https://github.com/padosoft/askmydocs-connector-google-drive) · [notion](https://github.com/padosoft/askmydocs-connector-notion) · [evernote](https://github.com/padosoft/askmydocs-connector-evernote) · [fabric](https://github.com/padosoft/askmydocs-connector-fabric) · [onedrive](https://github.com/padosoft/askmydocs-connector-onedrive) · [confluence](https://github.com/padosoft/askmydocs-connector-confluence) · [jira](https://github.com/padosoft/askmydocs-connector-jira) |
| `padosoft/askmydocs-mcp-pack` | MCP (Model Context Protocol) tool surface wrapping every AskMyDocs connector for use by Claude Desktop / Cursor / VS Code / any MCP client | 📅 Coming soon in v5.0 — design lives at `docs/v4-platform/ROADMAP-v4-v5-v6.md` | n/a |
| `padosoft/laravel-ai-act-compliance` + `-admin` | EU AI Act compliance pack: DSAR, bias monitoring, risk register, human-review tracker, consent + disclosure middleware, audit-grade evidence | 📅 Coming soon in v6.0 — design spec lives at `docs/v4-platform/DESIGN-SPEC-v6.0-ai-act-compliance-admin.md` | n/a |

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
- Honour the 39 codified review rules (R1–R39) — see [`CLAUDE.md`](CLAUDE.md) section 7

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
v1.0 through v4.5.0 GA.
