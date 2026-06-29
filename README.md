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
  <a href="#quick-start-5-minutes"><img src="https://img.shields.io/badge/Laravel-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/Claude-Compatible-cc785c?style=flat-square&logo=anthropic&logoColor=white" alt="Claude"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/OpenAI-Compatible-412991?style=flat-square&logo=openai&logoColor=white" alt="OpenAI"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/Gemini-Compatible-4285F4?style=flat-square&logo=google&logoColor=white" alt="Gemini"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/OpenRouter-Multi--Model-6366f1?style=flat-square" alt="OpenRouter"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/Regolo.ai-EU-10b981?style=flat-square" alt="Regolo.ai"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/MCP-Server-0ea5e9?style=flat-square" alt="MCP Server"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/Canonical--KB-typed-ff7a00?style=flat-square" alt="Canonical KB"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/Knowledge%20Graph-typed-7c3aed?style=flat-square" alt="Knowledge Graph"></a>
  <a href="#features-by-area"><img src="https://img.shields.io/badge/Anti--Repetition-%E2%9A%A0%EF%B8%8F%20built--in-dc2626?style=flat-square" alt="Anti-Repetition Memory"></a>
  <a href="#prerequisites"><img src="https://img.shields.io/badge/PostgreSQL-pgvector-336791?style=flat-square&logo=postgresql&logoColor=white" alt="PostgreSQL + pgvector"></a>
  <a href="#license"><img src="https://img.shields.io/badge/License-MIT-green?style=flat-square" alt="MIT License"></a>
  <a href="#universal-connectors"><img src="https://img.shields.io/badge/connectors-native-0ea5e9?style=flat-square" alt="Native Connectors"></a>
  <a href="#quality--observability"><img src="https://img.shields.io/badge/tests-PHPUnit%20%2B%20Vitest%20%2B%20Playwright-brightgreen?style=flat-square" alt="PHPUnit + Vitest + Playwright"></a>
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
- [Why AskMyDocs — the 6 moats](#why-askmydocs--the-6-moats)
- [✨ Universal Connectors](#universal-connectors)
- [✨ Modern Chat Surface (Vercel AI SDK UI)](#modern-chat-surface-vercel-ai-sdk-ui)
- [✨ KITT — Knowledge Interface Tour Toolkit](#kitt--knowledge-interface-tour-toolkit)
- [✨ Institutional Memory — anti-repetition retrieval over a living knowledge graph](#-institutional-memory--anti-repetition-retrieval-over-a-living-knowledge-graph)
- [✨ Auto-Wiki — self-compiling agentic knowledge tier](#-auto-wiki--self-compiling-agentic-knowledge-tier-v811)
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
AI-insights panel, and a self-compiling **Auto-Wiki** admin (health /
indices / explorer with promote-auto→human / per-project settings,
v8.12) — all behind Spatie role-based access control with audit trails
on every destructive mutation.

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

## 📖 Official Documentation

The full documentation — quickstart, guides, integrations, architecture deep-dives,
and API/CLI reference — lives at:

### **→ [doc.askmydocs.padosoft.com](https://doc.askmydocs.padosoft.com/)**

This README is the above-the-fold pitch; the documentation site is the
authoritative, argued, diagrammed reference.

---

## Why AskMyDocs — the 6 moats

These differentiators come from the public competitor audit at
[`docs/v4-platform/AUDIT-2026-05-11-competitor-comparison.md`](docs/v4-platform/AUDIT-2026-05-11-competitor-comparison.md)
(Section 3, "Where AskMyDocs is genuinely AHEAD") plus the v8.11 Auto-Wiki
cycle. They are the moats no other public RAG platform — open-source or SaaS —
currently ships.

| ★ | Moat | One-line |
|:---:|---|---|
| ★ | **Human-gated canonical promotion pipeline** (ADR 0003) | Three-stage API (`/suggest` → `/candidates` → `/promote`) holds the LLM at "draft"; only humans (git push → GH Action) and operators (`kb:promote` CLI) commit canonical storage. Immutable `kb_canonical_audit` trail. No public competitor splits "AI proposes" from "human writes" this way. |
| ★ | **Retrieval-time knowledge graph + rejected-approach injection** | `GraphExpander` walks `kb_edges` 1-hop at every query and folds neighbours into the `SearchResult`. `RejectedApproachInjector` vector-correlates the query against `rejected-approach` canonical docs and surfaces them under a ⚠ marker so the LLM stops re-proposing dismissed options. ChatGPT Enterprise / Glean / Vectara do not do this. |
| ★ | **PII redaction at 11 persistence boundaries** (default-OFF, granular per touch-point) | `padosoft/laravel-pii-redactor` v1.2 wired at 11 touch-points across observers, middleware, Monolog processor, failed-job listener, Flow payload redactor, insights inspector. EU-GDPR-grade *field-level* redaction inside the app boundary — not just data-residency. Every knob default-OFF so v3 / v4.0 hosts see byte-identical behaviour until they opt in. |
| ★ | **MIT-licensed, self-hostable, on-prem feasible** (no $500K/yr vendor contract) | Vectara is the only competitor that ships on-prem ($500K/yr public list). Glean / Notion AI / ChatGPT Enterprise / M365 Copilot are SaaS-only. AskMyDocs runs on any Laravel + PostgreSQL + pgvector host with zero vendor lock-in; the entire sister-package stack is MIT and independently reusable. |
| ★ | **Eval-harness CI gate + nightly LLM-as-judge + adversarial cohorts + retrieval-metric source-of-truth** | `padosoft/eval-harness` v1.3 RAG regression gate on every PR (4 datasets / 1 baseline + 3 adversarial / 7 metrics including custom `CitationGroundednessMetric` + `CosineGroundednessMetric`); `eval:nightly` Artisan cron at 05:30 UTC with three-fence cost guard, regression detection vs prior baseline, `Log::alert` + sidecar on regression; adversarial-lane nightly opt-in shipped in v4.4. **Since v8.18 it is also a runtime `require` dependency**: the retrieval-metric math (MRR, nDCG@k) is delegated to it through a single anti-corruption adapter (`PackageMetricAdapter`), so the package — not bespoke host code — is the source of truth for ranking metrics. Out-of-the-box eval surface nobody else publicly ships. |
| ★ | **Self-compiling Auto-Wiki tier behind an anti-hallucination firewall** (v8.11) | A second-class **`auto` tier** the system *builds itself* — on ingest the LLM enriches frontmatter (tags / summary / cross-refs / evidence-tier), materialises a navigable graph, synthesizes new `domain-concept` pages, indexes + lints them, agentically navigates (multi-hop BFS), and cross-model-reviews its own output — yet the **reranker firewall always ranks human-`accepted` > auto > raw**, so machine knowledge never silently becomes authoritative. Every layer is reversible, audited, tenant-scoped, default-ON-but-degradable, and exposed PHP + HTTP API + MCP. No public RAG platform ships a self-maintaining knowledge tier *behind* a human-vouched firewall. |

### Plus: a closed-loop **KB Lifecycle Intelligence** suite (v8.7 → v8.11)

Beyond the moats, the v8.7–v8.11 cycles shipped a closed governance loop most
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
- **Self-compiling Auto-Wiki** (v8.11) — ingest auto-enriches frontmatter + materialises graph
  edges, synthesizes recurring-concept pages, maintains per-tenant index hubs, lints wiki health,
  agentically navigates multi-hop, cross-model-reviews auto pages, applies change/delete
  suggestions, and self-maintains on a daily schedule — all behind the human > auto > raw
  firewall. See the dedicated section below.
- **Engagement & Intelligence Suite** (v8.15) — a proactive, multi-channel **digest** (newly
  created / promoted docs · stale review queue · top unanswered questions · KB-health trend ·
  "your attention needed"; modified-doc activity shows in the headline metrics) delivered to
  **email + Discord + Slack + Teams + an in-app feed** with
  an opt-in AI narrative on a dedicated free model; an admin engagement dashboard (contributor
  leaderboard / coverage / answer-rate / decision-debt trend) and a personal **My KB** dashboard
  (your score / rank / impact / review queue); **gamification** (config-driven badges
  over real contribution metrics, **default-ON since v8.18**); and (v8.18) an **AI coaching
  layer** — curation-quality metrics turned into LLM coaching cards + project/tenant health
  narratives (`GET /api/me/coaching`, admin insights panel, `KbGamificationInsightsTool`),
  degrading to deterministic copy when the model is off. Built to surpass Stack Overflow for
  Teams / Zendesk / Notion on packaging + delivery breadth; every capability tri-surface
  (PHP + API + MCP). See the [doc-site](https://padosoft.mintlify.app/engagement-suite).
- **AI FinOps spend governance** (v8.16) — a cross-provider AI-spend layer: an immutable per-call
  usage **ledger**, N-scope **budgets**, a policy DSL, chargeback, forecasting + anomaly detection
  and multi-channel alerts, attributed per tenant, behind a method-aware admin gate. Every provider
  moved onto the `laravel/ai` SDK (ADR 0015) so spend is metered natively; **real server-side
  per-turn cost** now lands on every chat log (replacing the old client-side guess), and 3 MCP read
  tools expose spend to agents. See the [doc-site](https://padosoft.mintlify.app/ai-finops).
- **Credential-based connectors** (v8.17) — the connector framework gains its first **non-OAuth**
  source: an **IMAP** mailbox (host/port/encryption/username/password **or** XOAUTH2) activatable
  entirely from **Admin → Connectors**. The mechanism is **generic + schema-driven** (a connector
  advertises `SupportsCredentialForm`; the host renders the form and routes the **secret straight to
  the encrypted vault, never `config_json`**) — any future credential connector works unchanged. See
  the [doc-site](https://padosoft.mintlify.app/connectors-credential).
  **IMAP connections are serialized per mailbox** (host+port+username, **cross-tenant**) so a server
  never returns *"Too many simultaneous connections"*: at most one live connection per account at a
  time across every surface, and concurrent same-mailbox sync jobs **re-queue** instead of piling up.
  Tunable via `CONNECTOR_IMAP_SERIALIZE_CONNECTIONS` (default on; needs an atomic lock store / Redis)
  + `CONNECTOR_IMAP_MAILBOX_LOCK_*`. See the
  [doc-site](https://padosoft.mintlify.app/connectors-imap-serialization).
- **AI Guardrails on the live chat path** (v8.19) — every chat turn is **screened on input** (a
  malicious / policy-violating prompt becomes a localized refusal — never a 500 — with an append-only
  audit row) and **sanitized on output** (exfil links defanged before the answer reaches the client),
  via `padosoft/laravel-ai-guardrails` behind a host adapter. Modes `enforce`/`monitor`/`off`; an
  8-screen admin SPA mounts at `/admin/ai-guardrails` (default-OFF). See the
  [doc-site](https://padosoft.mintlify.app/ai-guardrails).
- **Agentic Knowledge Reports** (v8.19) — the Tabular Review engine gains genuinely **agentic
  columns**: `graph` columns resolve a **deterministic, LLM-free** governance metric from the
  canonical graph, `verify` columns run a bounded anti-hallucination second pass that downgrades a
  flag when the value isn't backed by cited evidence. A flagship **"Canonical KB Governance Audit"**
  preset turns the KB into an auditable, per-cell-cited matrix, with a 16-template ready-made library
  and an evidence side-panel. See the [doc-site](https://padosoft.mintlify.app/agentic-knowledge-reports).
- **Invite-by-code & referral suite** (v8.22) — onboarding the way a growth team runs it: campaigns,
  multi-use + vanity codes, **referrals + rewards + waitlist**, fail-open anti-abuse (HMAC'd PII), and
  funnel analytics, powered by the standalone `padosoft/laravel-invitations` engine whose **atomic,
  idempotent, concurrency-safe** redemption (a single conditional `UPDATE … WHERE current_uses <
  max_uses`) makes seat-count safe under load. Each invite carries a per-tenant **grant** — a Spatie
  role *and* KB project memberships — so one code provisions access across one or more tenants at
  once (GRANT-never-REVOKE). Tri-surface (PHP + HTTP + 3 MCP tools), admin gated by
  `can:manageInvitations`, surfaced as a **native in-app admin** (invite-funnel dashboard with 11 live
  KPIs, a campaign builder with the multi-tenant grant editor, code inventory with batch generation /
  CSV export / revoke, a direct-invitation sender, and referral / reward / waitlist / anti-abuse tables)
  inside the unified admin chrome, and a closed-beta `INVITE_REQUIRED` signup gate that is
  **default-OFF**. See the [doc-site](https://padosoft.mintlify.app/invitations).

---

## ✨ Universal Connectors

**Plug AskMyDocs into Google Drive, Notion, OneDrive, Evernote, Fabric, Confluence, Jira (OAuth in one click) and IMAP mailboxes (credential form) — every document chunked and cited correctly per source.**

Most "RAG over docs" tools either expect a pile of pre-flattened
markdown or ship a single brittle "Google Drive sync" feature. AskMyDocs
ships a real **connector framework** + **eight native connectors**
+ **per-source chunkers** so every external knowledge corpus lands in
the canonical KB with its provenance, native IDs, ACL hints, and
status preserved — and gets chunked the way that source actually wants
to be chunked.

- **8 native connectors** — **OAuth** (v4.5): `google-drive` (OAuth2 + delta-query), `notion` (OAuth2 + block paginator), `evernote` (OAuth + `.enex` bulk import), `fabric` (API-key, OAuth pending upstream), `onedrive` (Microsoft Graph delta-query — supports `text/markdown` / `text/plain` / `application/pdf`; Office formats `.docx` / `.xlsx` / `.pptx` ingestion deferred), `confluence` (Atlassian OAuth 2.0 3LO; `cloud_id` persisted in tenant-scoped `connector_credentials.extra_json.cloud_id`, optionally reused by a Jira install in the same tenant/workspace), `jira` (Atlassian OAuth 2.0 3LO + ADF-to-markdown + injection-safe JQL builder); **credential** (v8.17): `imap` — the first **credential-based** connector (host/port/encryption/username/password **or** XOAUTH2 Gmail/M365), body + headers + attachments.
- **Credential-based connectors (v8.17)** — a **generic, schema-driven** mechanism (no IMAP-specific code in the host): a connector implements `SupportsCredentialForm` and the host renders a credential form, validates it dynamically, and routes the **secret straight to the encrypted vault** (never to `config_json`). `POST /api/admin/connectors/{name}/configure` activates it from the panel — basic-auth pings + vaults (bad login → 422), XOAUTH2 redirects through the standard callback. Any future credential connector (SMTP, API-key sources) reuses the same form + endpoint unchanged. See the [credential-connectors doc](https://padosoft.mintlify.app/connectors-credential).
- **Per-source chunkers** — `NotionBlockChunker` / `ConfluencePageChunker` / `OfficeDocChunker` / `AtomicNoteChunker` / `JiraIssueChunker` / `PdfPageChunker` dispatched via `PipelineRegistry::resolveChunker()` (R23 FQCN-validated + `supports()` mutex-checked at boot).
- **Rich frontmatter capture** — every connector populates document-level metadata (`connector`, `external_id`, `external_url`, native timestamps) plus chunk-level metadata (`source_type`, `search_tags` (top-level in chunk metadata), `recency_bucket`, ACL hint, status, preamble-path). Drives `KbSearchService` facets + `Reranker` Layer-4 signals (tag overlap + recency + status-active + preamble-match).
- **Admin install flow at `/app/admin/connectors`** — React SPA + Spatie super-admin gate; OAuth connectors use a signed OAuth callback, credential connectors a host-rendered schema-driven form; per-installation `connector_installations` + `connector_credentials` rows + scheduler-driven incremental sync via `App\Jobs\ConnectorSyncJob`.
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
| Native IMAP / email (credential) | ✅ (v8.17) | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Source-aware chunking framework | ✅ | private | ❌ | ❌ | ❌ | partial | partial |
| Plugin/package extensibility | ✅ (v4.6 packages + v8.17 credential form) | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

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
- **Zero-config for OpenAI / Anthropic / Gemini / OpenRouter / Regolo** — every provider runs on the `laravel/ai` SDK (since v8.16, ADR 0015), so AI FinOps meters them natively. Anthropic + Gemini are fully SDK; OpenAI + OpenRouter are hybrid (SDK for no-tools chat + embeddings, raw `Http::` `/chat/completions` for the MCP tool-calling turn the SDK can't host); Regolo via the `padosoft/laravel-ai-regolo` SDK adapter. `AiManager::chatStream()` synthesises a single-chunk SSE for providers without native streaming via the `FallbackStreaming` trait.

**Try it.** Open `/app/chat` in the React SPA. Start a long answer
and hit Stop; click Regenerate; hover the assistant message and pick
Branch (a new conversation forks from that point); pick a follow-up
pill chip to chain into the next prompt; hover any code block for the
Copy button.

---

## ✨ KITT — Knowledge Interface Tour Toolkit

**KITT (Knowledge Interface Tour Toolkit) is a one-`<script>` embeddable, page-aware, agentic AI assistant for any website — it answers grounded questions with citations, *reads the page*, and (when allowed) drives it: clicks, types, navigates, submits, and calls your backend tools.**

![AskMyDoc - KITT.jpeg](resources/screenshots/AskMyDoc%20-%20KITT.jpeg)

Most "chat widget" products are a stateless text box bolted to a generic
LLM. **KITT** is the embeddable surface of the *same* AskMyDocs retrieval
stack — grounded, cited, tenant-isolated — **plus** a bounded ReAct loop
that perceives and acts on the host page. A customer pastes a snippet; the
widget captures a structured snapshot of the current page (regions, fields,
actions, messages, outline) and reasons about what's actually on screen.

- **Embed in one snippet** — `<script>window.AskMyDocsWidget = { key: 'pk_…', apiBase: '…' }</script>` + the async loader. Two layouts: a floating `helper` launcher or an `inline` mounted block. Theme, title, skill all configurable via `window.AskMyDocsWidget` or `data-*` attrs.
- **Grounded + cited, never a generic bot** — the widget runs the first-party `KbSearchService` + reranker + refusal gate, scoped to the key's tenant + project. The browser **never** names a tenant — tenant/project are resolved server-side from the key (R30); cross-key/cross-tenant session access is `404` (anti-IDOR).
- **Agentic by design** — the LLM emits tool calls executed in the page DOM (`click` / `type` / `select` / `navigate_to` / `submit_form` / `wait_for` + ~15 more), or server-side via `/exec-tool` (`search_knowledge_base`), in a bounded loop with per-session step + consecutive-error caps. **Skills** (JSON manifests under `resources/widget/skills/*`) declare which tools, what auto-annotation rules, and the run policies.
- **Host-Tools Protocol (HTP)** — your app can expose its *own* tools to the agent ("create order", "set rate"), **double-gated** (per key *and* per skill) and **off by default**. The page is annotated with stable, verb-based `data-kitt-*` attributes (`region` / `field` / `action` / `message` / `locale` / `skip`); `data-kitt-sensitive` and `type=password`/`hidden` values are force-nulled server-side so secrets never reach the LLM or the step log.
- **Secure embedding** — exact-match `Origin` allowlist (browser mode) or `sk_` secret (server-to-server); **single-use, origin-bound session tokens** consumed atomically under a lock (R21, hashed at rest, rate-limit checked *before* burn); snapshot byte + count caps; `javascript:`/`data:`/protocol-relative navigation blocked on both server and client; PII masked on every persisted step (Italian VAT masking is checksum-validated so non-PII codes stay readable).
- **Full admin surface** — `/app/admin/widget` (super-admin): create / rotate / revoke keys, manage allowed origins + theme, toggle host-tools, copy the ready-made embed snippet, and replay every session step (PII-masked).

**Try it.** As super-admin, open `/app/admin/widget`, create a key (set a
`project_key` + your site's origin), copy the **Embed code** snippet into
your page, and reload. Locally, set `WIDGET_DEMO_ENABLED=true` and open
`/widget-demo` for a self-contained annotated demo page (add `?mode=inline`
for the inline layout). Full developer guide:
[`docs/kitt/INTEGRATION.md`](docs/kitt/INTEGRATION.md).

> **Security & embedding.** KITT is a cross-origin embeddable *and* an
> agentic (page-driving) surface, so before embedding it on anything beyond a
> public, low-sensitivity page, read the
> [**Security & threat model**](docs/kitt/INTEGRATION.md#14-security--threat-model)
> section of the integration guide — it documents exactly what is enforced and
> *why* (tenant isolation, exact-match origin allowlist, no-credential CORS, no
> host-page XSS, credential-field and navigation guards), the residual/inherent
> risks of a public embeddable agent (public-key abuse, prompt-injection-driven
> actions, data egress to the LLM), and the **best practices the operator and
> the host site must follow to mitigate them** — including `data-kitt-skip` to
> keep sensitive page regions out of the snapshot.

## ✨ Desktop & mobile client + token authentication

**A native Tauri v2 + React desktop/iOS client — and the stateless Bearer-token auth flow that powers any non-browser client without a cookie/CSRF dance.**

The React SPA authenticates with Sanctum's *stateful* cookie flow (CSRF
round-trip), which is exactly wrong for a native app, a CLI, or a CI runner. So
AskMyDocs ships a **second transport** behind the same `auth:sanctum` guard —
least-privilege, finite-expiry Bearer tokens — plus a reference desktop client
that proves it end to end.

- **`POST /api/auth/token`** — verifies credentials with **no session, no CSRF**
  and returns a Sanctum personal access token (`201 { token, token_type:
  "Bearer", user }`). The token is scoped to exactly `kb:read` + `kb:chat`
  (never the `['*']` wildcard) and **self-expires after 30 days**, so a token
  leaked from a lost device revokes itself server-side. `POST
  /api/auth/token/revoke` is the stateless sign-out (`204`).
- **`EnforceTokenAbility` (`token.ability:<ability>`)** — a per-route gate that
  constrains **only** Bearer PATs (`403` `token_ability_forbidden` if a token is
  scoped wrong) and is a **no-op for the cookie SPA**, so dual-auth routes
  (`/api/kb/chat`, `/api/kb/documents/search`, `/api/kb/documents/{documentId}/preview`)
  serve both transports without breaking either.
- **Tauri v2 desktop client** (`desktop/`) — login, grounded chat with clickable
  markdown citations, document search, and a full-page source-document viewer.
  Conversation threads persist **locally**; all backend calls go through the
  Tauri HTTP plugin so the backend needs **no CORS change**.
- **iOS from the same codebase** — Tauri v2 mobile, responsive UI with
  safe-area insets and an off-canvas thread drawer, no native code changes.

**Try it.** `cd desktop && npm install && npm run tauri dev`. Full runbook —
including the iOS build flow — in [`desktop/README.md`](desktop/README.md); the
auth flow is documented in the
[doc site](https://padosoft.mintlify.app/desktop-client).

## ✨ Institutional Memory — anti-repetition retrieval over a living knowledge graph

**Your KB doesn't just store documents — it remembers what your team decided,
refuses to let the LLM re-propose approaches you already rejected, and keeps that
memory current by itself.**

Commodity RAG treats your corpus as interchangeable chunks: it re-discovers the
answer from zero on every query, never persists what was *already decided*, and
happily re-suggests an option your team dismissed three quarters ago. AskMyDocs
ships a **typed canonical layer** (decisions / runbooks / standards / incidents /
integrations / domain-concepts / **rejected-approaches**) over a lightweight
**knowledge graph** — and wires both straight into retrieval, so the memory is
not a passive archive but an active participant in every answer.

**How your searches benefit — on every query, automatically:**

- **Graph-expanded recall** — `GraphExpander` walks `kb_edges` one hop out from
  each canonical seed hit and folds the connected decisions / runbooks / concepts
  into the result set, so the answer carries the surrounding context a bare vector
  match would miss (config-gated; a clean no-op for tenants with no canonical docs).
- **Anti-repetition firewall** — `RejectedApproachInjector` vector-correlates the
  query against your `rejected-approach` docs and surfaces the dismissed options
  under a ⚠ marker in the prompt, so the model stops re-proposing exactly what your
  team already ruled out. This is the single most-requested behaviour commodity RAG
  cannot give you, because it has nowhere to *store* a rejection.
- **Evidence-aware weighting** — every retrieved chunk carries its evidence tier
  (`guideline > peer_reviewed > official > … > unverified`); the prompt flags
  low-confidence claims so an answer never leans on a blog post as if it were a
  ratified standard.
- **Trust-ranked fusion** — the reranker fuses vector + keyword + heading signals
  and **always ranks human-`accepted` > `auto` > raw**, so machine-written
  knowledge can enrich an answer but never silently outranks a human-vouched
  decision.

**How the memory self-updates and maintains itself** — the part that keeps it
from rotting (the self-compiling engine detailed in the
[Auto-Wiki](#-auto-wiki--self-compiling-agentic-knowledge-tier-v811) section below):

- **On ingest**, an LLM compiler enriches each document's frontmatter (tags /
  summary / cross-references) and materialises those cross-references into real
  graph edges + nodes — so a freshly-landed doc becomes *navigable* immediately,
  with zero manual linking.
- **Recurring concepts** that appear across many documents are synthesized into
  brand-new `domain-concept` pages, grounded *only* in the docs that mention them —
  the KB grows its own connective tissue instead of waiting for someone to write it.
- **A daily maintenance sweep** rebuilds the per-tenant index hubs, lints the graph
  (dangling / orphan / stale cross-references, with safe auto-fix), and backfills
  enrichment — so "knowledge improves over time" rather than decaying.
- **An independent, cross-model review-LLM** audits every auto-generated page for
  grounding, novelty and contradictions before it is trusted — and the entire
  self-maintaining tier sits *behind* the human > auto > raw firewall, fully
  reversible and audited.

Every layer is tenant-scoped (R30), written to the immutable `kb_canonical_audit`
trail, and config-gated (default-degradable, R43) — and, per R44, exposed across
PHP + HTTP API + MCP. **No public RAG platform — open-source or SaaS — ships
institutional memory that both *feeds retrieval* and *maintains itself* behind a
human-vouched firewall.**

**Try it.** Promote a `decision` doc and a `rejected-approach` doc (git push → GH
Action, or the `kb:promote` CLI), then ask the chat a question near the rejected
option: the ⚠ rejected-approaches block appears inside the grounded answer, and the
chat-side **Related** panel walks the graph straight from the decision you just
promoted.

---

## ✨ Auto-Wiki — self-compiling agentic knowledge tier (v8.11)

Inspired by Andrej Karpathy's *LLM-Wiki* pattern (raw → wiki → schema layers;
ingest / query / lint ops) and **AutoSci** (concept pages + graph edges, entity
index, BFS exploration, cross-model review), AskMyDocs **compiles its own wiki**
on top of every ingested corpus — and keeps it healthy over time — without ever
weakening the human-vouched authoritative tier.

The cornerstone is a **second-class `auto` tier** (`knowledge_documents.generation_source ∈ {human, auto}`):
AI-compiled knowledge is real, searchable and navigable, but an
**anti-hallucination reranker firewall always ranks human-`accepted` > `auto` > raw**,
and an admin can promote `auto` → human. Every layer is reversible, audited to
`kb_canonical_audit`, tenant-scoped (R30), and config-gated (default-ON but
cleanly degradable to today's behaviour, R43).

Shipped incrementally across **v8.11.0 → v8.11.10** (each its own tagged release):

| Phase | Capability |
|---|---|
| **Foundations** (v8.11.0) | The `auto` tier discriminator + reranker firewall + layered `AutoWikiGate` + dedicated AI-model override + source-retention config + ADR 0014. |
| **Compiler** (v8.11.1) | After ingest, the LLM auto-enriches frontmatter — tags / summary / aliases / cross-references (allow-listed to real neighbours, anti-hallucination) → `frontmatter_json._autowiki`. |
| **Evidence-tier** (v8.11.2) | An evidence-strength axis (`guideline > peer_reviewed > official > … > unverified`) derived in the same call + surfaced in the RAG prompt to flag low-confidence claims ([AutoSci #67](https://github.com/skyllwt/AutoSci/issues/67)). |
| **Graph canonicalization** (v8.11.3) | Cross-references become real `kb_edges` + `kb_nodes` — the auto tier becomes *navigable*; every auto doc gets a stable `auto-`-namespaced slug. |
| **Concept synthesis** (v8.11.4) | Recurring concepts across a project become **new `domain-concept` pages**, grounded in the docs that mention them (AutoSci `/prefill` dedup). |
| **Indices + log** (v8.11.5) | Per-project roll-ups + a **per-tenant index hub** (the agentic map) + the auto-wiki operation log. |
| **Lint / health** (v8.11.6) | Deterministic checks — dangling / orphan / stale-cross-ref / missing-index — with safe auto-fix. |
| **Agentic navigation** (v8.11.7) | Multi-hop, cycle-safe **BFS** over the graph, anchor-driven from the index — the "navigate the wiki" primitive beyond 1-hop expansion. |
| **Cross-model review** (v8.11.8) | An *independent* review-LLM audits each auto page for grounding / novelty / **contradictions** before it's trusted. |
| **Apply engine** (v8.11.9) | Change/delete suggestions become concrete, audited, reversible mutations (add cross-ref / deprecate impacted) — manual + opt-in auto-apply (default-OFF). |
| **Scheduled maintenance** (v8.11.10) | A daily sweep that rebuilds indices, lints, and backfills enrichment so "knowledge improves over time". |

**Tri-surface everywhere (R44):** every capability is exposed and consumable via
**PHP** (Artisan commands: `kb:evidence-tier`, `kb:wiki-link`,
`kb:synthesize-concepts`, `kb:wiki-index`, `kb:wiki-lint`, `kb:wiki-navigate`,
`kb:wiki-review`, `kb:apply-suggestion`, `kb:wiki-maintain`, `kb:wiki-promote`),
**HTTP API** (RBAC-gated admin endpoints under `/api/admin/kb/*`), and **MCP**
(the `enterprise-kb` server grew from 14 → **34 tools**, incl.
`KbWikiNavigateTool` as the primary agentic surface, `KbWikiPromoteTool` for
promote/discard, the v8.15 engagement trio `KbEngagementSummaryTool` /
`KbDigestPreviewTool` / `KbUserBadgesTool`, the v8.16 AI FinOps read trio
`FinOpsSpendSummaryTool` / `FinOpsTopModelsTool` / `FinOpsBudgetStatusTool`,
the v8.18 AI gamification read tool `KbGamificationInsightsTool`, the v8.19
AI Guardrails posture tool `KbGuardrailsInsightsTool` and the v8.19 Agentic
Knowledge Reports reader `KbRunReportTool`) — all thin layers over one shared
core service per capability.

**Admin Wiki UI (v8.12.0):** a full web surface on the whole engine — **Wiki
Health** (lint + safe auto-fix), **Wiki Indices** (hub + per-project roll-ups +
operation log + rebuild), **Wiki Explorer** (browse by tier, promote auto→human,
discard), **Doc Insights → Apply** (turn change/delete suggestions into audited
reversible mutations), **Auto-Wiki Settings** (per-project auto-build gate), and
**tier-badged chat citations** (auto vs human-vouched) — every screen real-data
Playwright-covered (R13).

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

The system supports **five providers**, all on the `laravel/ai` SDK since v8.16 (ADR 0015) so AI FinOps meters every provider through the SDK lifecycle events. Anthropic + Gemini are fully on the SDK; OpenAI + OpenRouter are hybrid — the SDK serves no-tools chat + embeddings, while the MCP tool-calling turn stays on raw `Http::` `/chat/completions` because the SDK owns its own tool loop and can't host AskMyDocs's external-MCP loop. Regolo runs through the `padosoft/laravel-ai-regolo` SDK adapter (built on `Laravel\Ai`).

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

### AI FinOps — spend governance (v8.16)

Cost governance over AI spend is provided by
[`padosoft/laravel-ai-finops`](https://github.com/padosoft/laravel-ai-finops)
(+ the companion React admin panel
[`padosoft/laravel-ai-finops-admin`](https://github.com/padosoft/laravel-ai-finops-admin)):
a per-call usage ledger, N-scope budgets, a declarative policy DSL, chargeback,
forecasting/anomaly detection, cost-aware routing and multi-channel alerts.

The package meters automatically only for calls that flow through the
`laravel/ai` SDK (here: Regolo). AskMyDocs extends coverage to the
raw-`Http::` providers by hooking `AiManager` — `App\FinOps\AiCallMeter`
records every **synchronous** OpenAI / Anthropic / Gemini / OpenRouter chat
(`chat` / `chatWithHistory`) + embedding into the ledger (non-blocking,
`ChatLogManager`-style; Regolo is skipped to avoid double-counting).
**Streaming chat (`chatStream`, the SSE endpoint) is not yet metered** — a
documented follow-up — so a turn served over streaming is not recorded in the
ledger. Every recorded row is tenant-scoped via `App\Support\TenantContext`
(R30).

The API mounts under `api/admin/ai-finops` behind the admin stack
(`auth:sanctum` + `tenant.authorize` + a **method-aware** gate: reads →
`viewAiFinOps` = super-admin + admin; writes → `manageAiFinOps` =
super-admin). The admin SPA mounts under `/admin/ai-finops` (default OFF).

```bash
# After composer install, create the ai_finops_* tables:
php artisan migrate

# Turn the admin panel on (AI_FINOPS_ADMIN_ENABLED=true) and publish its assets:
php artisan vendor:publish --tag=ai-finops-admin-assets --force
```

```env
AI_FINOPS_ENABLED=true          # master switch (routes + metering hook)
AI_FINOPS_METERING=true         # record usage into the ledger
AI_FINOPS_ENFORCEMENT=false     # hard budget/policy HTTP-402 blocks (opt-in)
AI_FINOPS_CURRENCY=USD          # base = provider list-price currency
AI_FINOPS_DISPLAY_CURRENCY=EUR
AI_FINOPS_RETENTION_DAYS=730
AI_FINOPS_ADMIN_ENABLED=false   # React cockpit at /admin/ai-finops (opt-in)
```

Maintenance crons (Tier-1 slots, staggered in the 04:xx window):
`ai-finops:capture-prices`, `ai-finops:check-alerts`, `ai-finops:prune`.

Since **v8.19** the host runs `padosoft/laravel-ai-finops` **v1.4.0** (`^1.4`),
released onto the `laravel/ai` **0.8** line (the platform-wide migration in v8.19/W1,
[ADR 0016](docs/adr/0016-v819-laravel-ai-0.8-platform-migration.md)); it keeps the
v8.18 fixed-precision **8-dp decimal string** money shape under additive `*_decimal`
keys (no float-rounding drift on small per-token costs). The three FinOps MCP read
tools and `ChatTurnCostResolver` consume the new shape; the existing numeric keys
stay in place (R27 additive).

### Invitations — invite-by-code & referral suite (v8.22)

Onboarding via invite codes + referrals is provided by the standalone
[`padosoft/laravel-invitations`](https://github.com/padosoft/laravel-invitations)
engine, wired tri-surface (R44) into AskMyDocs over its vendor-neutral seams.
The package owns the 9 invite tables (campaigns / codes / invitations /
redemptions / referrals / rewards / waitlist / abuse-signals / analytics) and
the redemption engine; AskMyDocs binds the tenant + provisioning seams:

- the package `TenantResolver` resolves the active **host** tenant
  (`App\Support\TenantContext`), so every invite query/write is tenant-scoped (R30);
- `App\Models\User` implements the package `InvitedAccount` contract;
- `App\Invitations\ProjectMembershipProvisioner` turns an invite's per-tenant
  **grant** into `project_memberships` rows — **GRANT-never-REVOKE** (never
  downgrades an existing membership) and **best-effort** (a fault is logged,
  never thrown). It runs alongside the package's Spatie-role provisioner, so one
  code can grant a role **and** project access across one or more tenants.

Routes auto-mount (no host route file): the admin surface
`/api/admin/invitations/*` (campaigns / code generation / metrics / direct
invitations) behind the SPA-session + `auth:sanctum` + `tenant.authorize` +
`can:manageInvitations` stack (super-admin + admin, R32-matrix-locked); the user
redeem surface `/api/invitations/*` behind the authenticated stack. Three MCP
read/write tools (`Invite{ValidateCode,GenerateCodes,Metrics}Tool`) register on
`KnowledgeBaseServer`.

```bash
# After composer install, create the invite_* tables:
php artisan migrate
```

```env
# Package-level closed-beta gate, read by padosoft/laravel-invitations (NOT by
# the host SPA register endpoint, which is always invite-only — see below).
# Default FALSE (R43 both-states).
INVITE_REQUIRED=false

# Dedicated HMAC secret for signed codes (falls back to APP_KEY-derived
# material when unset). PRODUCTION MUST set its own.
# INVITE_SIGNING_KEY=
# Salt for the HMAC of redemption IP / fingerprint (PII is never plaintext).
# INVITE_PII_SALT=
# INVITE_CODE_LENGTH=8
# INVITE_INVITATION_TTL_DAYS=7
# INVITE_ANTI_ABUSE_ENABLED=true
```

**Sign-up is invite-only and SPA-native.** The React `/register` screen posts to
**`POST /api/auth/register`** — a guest route, throttled `6/min` per IP
(`throttle:register`), sitting outside the `auth:sanctum` group. The controller
**pre-validates** the code with the package `CodeValidator` *before* creating the
account (so a bad code never mints an orphan user), then redeems it authoritatively
via `RedemptionService` (atomic; run **outside** a DB transaction so the package's
PostgreSQL compensation path is not poisoned); on an exhausted-between-checks race
the brand-new account is **force-deleted** so the invite-only invariant holds. The
account is floored at `viewer` (layered on any grant role — GRANT-never-revoke) and
the SPA session is opened. Every invite-code failure surfaces as a localized
**422 on the `invite_code` field** (`lang/{en,it}/register.php`, R24). Here
`invite_code` is **always required**, regardless of the `INVITE_REQUIRED` gate. The
whole auth surface — `/login`, `/register`, `/forgot-password`, `/reset-password` —
is the React SPA on a hard reload too; the legacy Blade auth views were removed.

The admin surface is a **native, in-app tabbed page** at
`/app/{team}/admin/invitations` (Overview · Campaigns · Codes · Invite ·
Referrals · Rewards · Waitlist · Anti-abuse), built on the same
`/api/admin/invitations/*` core so it stays inside the unified admin chrome +
team switcher — no new tab. It ships the invite-funnel dashboard (all 11
`MetricsService::summary` fields + a proportional funnel), a **campaign builder**
(create/edit with the **multi-tenant grant editor** — a Spatie role + KB project
memberships across one or more tenants), code inventory with batch generation /
Copy-all / CSV export / revoke, a **direct-invitation sender**, and read tables
for referrals, rewards, waitlist and anti-abuse (each with the honest "first 500
rows" truncation notice, since the core read surfaces are capped at 500). The
standalone `padosoft/laravel-invitations-admin` package panel is still offered as
an optional **"Advanced"** launcher, shown **only when
`INVITATIONS_ADMIN_ENABLED=true`** so it never links to the unregistered
`/admin/invitations` 404 (R14/R43); the host learns the flag from the additive
`features.invitations_admin` field on `/api/auth/me` (R27). See the
[doc-site page](https://padosoft.mintlify.app/invitations).

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
# Timezone the chatbot presents as "now" in the RAG system prompt so it can
# reason about time-relative questions ("documents from last month", "is this
# deadline past?"). The app keeps running on UTC; this only sets the zone the
# bot *displays* (ISO 8601). Must be a valid IANA timezone. Default Europe/Rome.
KB_PROMPT_TIMEZONE=Europe/Rome

# Chunking
KB_CHUNK_TARGET_TOKENS=512
KB_CHUNK_HARD_CAP_TOKENS=1024
# Tail-overlap budget for MarkdownChunker (approx tokens). 0 = off. Since v8.18
# this is active: it carries the tail of each oversized-section piece onto the
# next chunk on paragraph boundaries. APPLIES TO NEW INGESTS ONLY — ingest is
# idempotent on sha256(markdown), so changing this alone won't re-chunk existing
# docs; force a new version (the markdown CONTENT must change, or HARD-delete +
# re-ingest via `kb:delete --force` — a soft delete keeps the chunks).
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

### UI upload (admin drag-and-drop)

Admins can ingest documents straight from the KB admin page (no CLI / API client
needed). An **Upload** button (and drag-and-drop onto a project/folder node when a
single project is selected) opens a modal: files are buffered on the dedicated
`kb-staging` disk, reviewed, then on **Commit** moved to the canonical `kb` disk and
ingested through the **exact same pipeline** as `kb:ingest-folder` — one
`IngestDocumentJob` per file. The modal then polls
`GET /api/admin/kb/uploads/{batch}/status` and shows per-file progress
(`staged → queued → processing → succeeded | failed`). Accepted formats match the
CLI (`md`, `markdown`, `txt`, `pdf`, `docx`). Stale staging batches are swept daily
by `kb:prune-staging-batches` (retention `KB_STAGING_RETENTION_HOURS`, default 24h).

> **Queue prerequisite.** Meaningful, streaming progress requires an async queue
> and a running worker:
>
> ```bash
> # .env
> QUEUE_CONNECTION=database        # jobs / failed_jobs tables already exist
> # process (supervisor / systemd / Horizon)
> php artisan queue:work --queue=kb-ingest,default
> ```
>
> Under the default `QUEUE_CONNECTION=sync` the commit request runs the ingest
> inline and **blocks** until every file is parsed/chunked/embedded/persisted; the
> status endpoint then returns the terminal state on the first poll. Functional,
> but the per-file progress stream is only visible with a real queue + worker.
> Files canonical-typed (YAML frontmatter) are accepted but flagged with a
> non-blocking warning that they will not be committed to your git `kb/` repo —
> the canonical source of truth stays git → GitHub Action.

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

The tenant-aware models — the authoritative list lives in `tests/Architecture/TenantIdMandatoryTest::TENANT_AWARE_MODELS`, kept in lock-step with the migrations by the architecture test on every CI run (it spans `KnowledgeDocument` / `KnowledgeChunk`, the chat + conversation tables, the `kb_*` graph / canonical / engagement tables, `ProjectMembership` + the first-class `Project`, and the `KbIngestBatch` / `KbIngestBatchItem` upload-tracking pair, among others) — all carry `tenant_id` and use the `BelongsToTenant` trait. The composite FKs on `kb_edges` are **project-scoped** (`(project_key, node_uid)` — intra-project referential integrity); cross-**tenant** isolation is enforced at the **application layer** via the R30 `forTenant()` scope (`BelongsToTenant` auto-stamps on write but adds **no** global read scope), not by the FK — `project_key` is shared across tenants.

`embedding_cache` is **intentionally NOT tenant-aware** — the cache is a cross-tenant reuse layer keyed by the composite `UNIQUE (text_hash, provider, model)` (v4.0.1, widened from `text_hash` alone so multiple provider/model embeddings of the same text coexist without forcing a flush). Sharing embeddings across tenants is a deliberate cost optimisation; `TenantIdMandatoryTest` documents the exclusion.

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

### Team switcher (per-team SPA routing)

The front-end half of multi-tenancy. A user who belongs to more than one team gets
a **topbar team switcher** and a tenant that is always visible in the URL.

- **`/api/auth/me` returns a `teams` array** (`UserTeamsResolver`) grouping the
  caller's memberships by tenant + any cross-access tenants — the switcher only ever
  offers teams whose requests would actually be authorised.
- **Per-team URLs** — every authenticated screen lives under `/app/{teamHash}/…`
  (`TeamHash` is a BE-computed, non-secret routing namespace; authorization stays on
  the server-validated header). Legacy hash-less bookmarks redirect into the active
  team's hash.
- **Automatic `X-Tenant-Id` stamping** — the shared axios client stamps the header on
  every call, with one deliberate exception: the **`default` sentinel** is never sent
  (it resolves the same host context with or without the header, and omitting it keeps
  sister-package route mounts on their host-config fallback instead of 404ing). The
  chat SSE transport and the Flows live-probe raw fetch apply the same rule.
- **Cache isolation on switch** — switching team `clear()`s the whole TanStack Query
  cache and remounts the route outlet (`key=tenant_id`), so no tenant's data ever
  renders under another; a revoked membership self-heals on the next bootstrap.
- **Membership-aware gate** — `AuthorizeTenantHeader` accepts the requested tenant for
  the caller's own tenant, a cross-access permission, **or** a `project_membership` in
  that tenant (scoped to both the tenant *and* the user — no escalation); anything else
  is `403 tenant_forbidden`, which the SPA turns into a snap-back to the first valid team.

→ Deep dive: [padosoft.mintlify.app/team-switcher](https://padosoft.mintlify.app/team-switcher).

### First-class project registry

`project_key` — the join key behind every document, chunk, chat log, membership, node
and edge — is now a manageable first-class row. The **project registry** gives it a
human name + description, a governed lifecycle, and a delete-guard, while staying a
**soft registry** (the key keeps working everywhere even with no registry row, so the
feature is purely additive, R27).

- **`projects` table, tenant-aware** — `UNIQUE (tenant_id, project_key)` (per-tenant,
  **not** global: two teams may both own `engineering`); auto-filled `tenant_id` via
  `BelongsToTenant` (R30/R31).
- **CRUD at `/app/{team}/admin/projects`** + `/api/admin/projects/*` (admin / super-admin).
- **Immutable key** — `project_key` may be supplied explicitly on create or, when
  omitted, is auto-slugged from the name (`resolveKey`); it is validated for slug shape
  + per-tenant uniqueness (clean 422, not a raw DB error) and is **immutable** afterwards
  (changing it would orphan every referencing row → 422).
- **Delete-guard** — deleting a project still referenced by a document or membership is
  blocked with a 422, so the registry can never drift from the content.
- **Backfill** — the `create_projects_table` migration's `up()` backfills one row per
  distinct `(tenant_id, project_key)` already in use across `knowledge_documents` +
  `project_memberships`. The KB project picker then aggregates the registry + existing
  documents + memberships for a complete list (R18).

→ Deep dive: [padosoft.mintlify.app/projects-registry](https://padosoft.mintlify.app/projects-registry).

### In-chat source preview

Citations are now openable **in the chat itself**. Clicking a cited source opens a
modal with the full document text, reconstructed from its chunks in `chunk_order` —
tenant- and access-scope-isolated (`forTenant` + `AccessScopeScope` + soft-delete), so
a citation can never open another tenant's or another scope's bytes. Available to every
authenticated user via `GET /api/kb/documents/{documentId}/preview`; admins additionally
get a deep-link to the full KB document page. A missing/forbidden document returns the
correct 404/403 (R14), never a 200 with empty body.

→ Deep dive: [padosoft.mintlify.app/chat-and-retrieval](https://padosoft.mintlify.app/chat-and-retrieval).

### Automated isolation testing

Isolation is not just enforced — it's **verified against a real ingested KB**. A single
executable `IsolationMatrix` (`app/Support/CaseStudy/IsolationMatrix.php`) is consumed
identically by a live E2E test (`LiveRagIsolationTest`, opt-in `LIVE_RAG=1` + real
pgvector + embeddings), an operator CLI (`php artisan case-study:verify-isolation
[--strict]`), and a CI membership-axis test (`CaseStudyProjectIsolationTest`). It
separates **HARD breaches** (a real leak — a foreign chunk/citation/canary surfaces, or
an owning fact is unreachable → fails the gate) from **SOFT misses** (the refusal *ideal*
wording was missed but nothing leaked → warning unless `--strict`), so an isolation gate
is never coupled to model-phrasing calibration.

→ Deep dive: [padosoft.mintlify.app/isolation-testing](https://padosoft.mintlify.app/isolation-testing).

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
| `MarkdownChunker` section-aware fence-safe FSM | Custom line-based fence-aware state machine: emits one chunk per ATX heading section with `heading_path` breadcrumb (H1>H2>H3); fences (` ``` `, `~~~`) suppress heading detection inside code blocks; falls back to `paragraph_split` on docs without headings. **Configurable tail overlap (v8.18)**: `KB_CHUNK_OVERLAP_TOKENS` (default `64`; `0`=off) carries the tail of each oversized-section piece onto the next chunk on paragraph boundaries — re-ingest required to apply to existing docs | v3.0 · v8.18 |
| `PdfPageChunker` page-aware PDF chunking | Slices on the `## Page N` boundaries emitted by `PdfConverter`; emits one chunk per non-empty page with `heading_path = "Page N"` for page-precise citations; intra-page split on `\n\n` when over `KB_CHUNK_HARD_CAP_TOKENS` | v3.0 |
| Embedding cache (cross-tenant by design) | DB-backed LRU cache keyed on SHA-256(`text`) UNIQUE; eliminates redundant API calls on re-ingestion and repeated queries; `EmbeddingCacheService::flush($provider)` on provider/model change. Conditional approval gate via `KB_EMBEDDING_CACHE_APPROVAL_THRESHOLD` (default 5000) on v4.2+ | v1.0 |
| Soft delete + retention sweep | `SoftDeletes` on `KnowledgeDocument`; hidden from every read path by default; `kb:prune-deleted` (03:30 daily) hard-deletes after `KB_SOFT_DELETE_RETENTION_DAYS` (default 30); cascades `kb_nodes` + `kb_edges` on final hard delete; immutable `kb_canonical_audit` row survives | v3.0 |
| MCP server `enterprise-kb` (34 tools) | 5 retrieval + 5 canonical/promotion tools (v3.0), 4 propose-only canonical tools (v7), **11 Auto-Wiki tools** (v8.11–v8.12: set-evidence-tier / rebuild-wiki-links / synthesize-concepts / build-wiki-index / wiki-hub / wiki-lint / **wiki-navigate** / wiki-review / apply-suggestion / wiki-maintain / **wiki-promote**), **3 Engagement tools** (v8.15: engagement-summary / digest-preview / user-badges), **3 AI FinOps read tools** (v8.16: finops-spend-summary / finops-top-models / finops-budget-status), **1 AI gamification read tool** (v8.18: gamification-insights), **1 AI Guardrails posture tool** (v8.19: guardrails-insights), and **1 Agentic Knowledge Reports reader** (v8.19: run-report) exposed for Claude Desktop / Claude Code / any MCP-compatible agent. Every host capability is reachable via MCP (R44 tri-surface) | v3.0 · v8.11 · v8.12 · v8.15 · v8.16 · v8.18 · v8.19 |
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
| **Agentic Knowledge Reports** (governance-aware tabular columns) | v8.19 promotes Tabular Review to first-class agentic columns: a column-level `agent` dimension — `extract` (today's RAG single-shot, default) · `graph` (a **deterministic, LLM-free** governance metric resolved from the canonical graph) · `verify` (a bounded anti-hallucination second pass that can only **downgrade** a flag when the cell value isn't supported by the document's cited evidence — R14, never worse than extract). `GovernanceColumnResolver` computes 10 metrics (evidence_tier, frontmatter_completeness, canonical_status, is_canonical, incoming/outgoing_edges, graph_connectivity, is_orphan, supersession_status, staleness_days) tenant-scoped (R30) from the real `EvidenceTier`/`CanonicalStatus` enums. A flagship **"Canonical KB Governance Audit"** preset ships in a 16-template ready-made library (`BuiltInWorkflowSeeder`). FE: an agentic column editor (graph→metric picker), a per-cell **evidence side-panel** (summary + flag + reasoning + cited chunks), and a one-click template gallery. Tri-surface (R44) with the MCP `KbRunReportTool` (COUNT(DISTINCT)+LIMIT-bounded, R30/R43). SSE progressive-paint + Glide canvas grid are documented v8.19.x follow-ups | v8.19 |
| **AI Guardrails** (chat input + output safety firewall) | `padosoft/laravel-ai-guardrails` enforced on the live chat path via a host `ChatGuardrails` adapter: the user query is **screened before** retrieval/model (a blocked prompt becomes a localized refusal — R26, never a 500 — with an append-only audit row) and the answer is **sanitized after** generation (exfil link neutralized before it reaches the client). Modes `enforce`/`monitor`/`off`; every store wrapped in try/catch so safety telemetry never breaks the user path. The 8-screen admin SPA mounts at `/admin/ai-guardrails` (default-OFF → clean 404, R43) behind `can:viewAiGuardrails`. Tri-surface (R44): the 14-endpoint core API behind the authenticated admin stack (R32-matrix-locked) + the MCP `KbGuardrailsInsightsTool`. Tables are GLOBAL security infra (no `tenant_id`, like `embedding_cache`) | v8.19 |
| **Workflows** (reusable prompt templates + AI-suggested catalogue) | `workflows` + `workflow_shares` + `hidden_workflows` tables; `WorkflowService` enforces ownership / share / hide semantics with per-user scope; `WorkflowSuggester` analyzes the tenant's KB (`MetadataPatternAnalyzer` detects recurring practices / projects / column patterns) and proposes up to 5 assistant + tabular workflow drafts via the LLM. 15 system-shipped templates (legal review / GDPR DPIA / DPA review / commercial agreement triage / privacy policy audit / vendor due diligence / employment policy review / regulatory mapping / risk register / litigation timeline / NDA review / IP-licensing review / consent record audit / processor-list extraction / contract-clause comparison). Admin SPA at `/app/admin/workflows` with Mine / Shared / System scope tabs + AI-suggest gallery + create dialog (**assistant type only in GA**; tabular create UI deferred to v4.7.x — tabular workflows ARE accepted by the JSON API and via the AI-suggest gallery's save-this path); email-based share model scales to invitees not yet on the platform | v4.7 GA |
| **Auto-Wiki — self-compiling `auto` tier** | A second-class AI-compiled knowledge tier (`generation_source ∈ {human, auto}`) the system builds + maintains itself: ingest-time frontmatter enrichment (`AutoWikiCompiler`) + evidence-tier derivation + graph materialisation (`AutoWikiGraphLinker`, `auto-`-namespaced slugs) + concept-page synthesis (`ConceptSynthesizer`) + per-tenant index hub (`WikiIndexBuilder`, `kb_wiki_indices`) + wiki lint (`WikiLinter`) + multi-hop agentic navigation (`WikiNavigator`) + cross-model review (`AutoWikiReviewer`) + change/delete apply engine (`SuggestionApplier`, `kb_doc_analysis_applications`) + daily self-maintenance (`WikiMaintainer`, `kb:wiki-maintain`) + promote/discard (`WikiExplorerService`, `kb:wiki-promote`). The **reranker firewall** keeps human-`accepted` > `auto` > raw so machine knowledge never silently becomes authoritative; every layer reversible + audited + tenant-scoped + config-gated (R43) + tri-surface PHP/API/MCP (R44). **Full admin UI (v8.12)**: Wiki Health / Indices / Explorer / Doc-Insights Apply / Auto-Wiki Settings + tier-badged chat citations. See [the dedicated section](#-auto-wiki--self-compiling-agentic-knowledge-tier-v811) | v8.11 · v8.12 |

### Chat & Conversation

| Feature | Description | Since |
|---|---|---|
| Vercel AI SDK v6 streaming | `MessageStreamController` emits SDK v6 `UIMessageChunk` frames (`start` / `text-start` / `text-delta` / `text-end` / `source-url` / `data` / `finish`) over SSE; first-token latency dropped from ~2.8 s synchronous to ~400 ms streaming on the Lighthouse baseline | v4.0 |
| `useChatStream()` React hook | `mapStatusToDataState()` adapter exposes `data-state="idle\|loading\|ready\|empty\|error"` for deterministic Playwright waits (SDK `submitted` and `streaming` statuses both collapse to `loading` per the R11 comment in `MessageThread.tsx`); unit-tested in `frontend/src/features/chat/map-status-to-data-state.test.ts` | v4.0 |
| Citations panel | Every assistant reply ships the source documents (`document_id`, `title`, `source_path`, `slug`, `project_key`, `headings`, `chunks_used`); persisted on `messages.metadata.citations`; survives conversation reload | v1.0 |
| Chat-side **Related** graph panel | A lazy, collapsible panel under each grounded answer shows the **1-hop knowledge-graph neighbours** of the cited canonical docs (both directions — dependencies AND docs that depend on the cited one), so a user can navigate the graph straight from an answer. Backed by `GET /api/kb/related` (`RelatedGraphService` walks `kb_edges`, tenant + project scoped, config-gated by `KB_GRAPH_EXPANSION_ENABLED`, no-op without a canonical graph). **ACL-safe** — a neighbour the user can't access shows its slug but never its title | v8.8 |
| Conversation history | `conversations` + `messages` tables (user-scoped); inline rename, delete with confirmation, AI-generated title after first turn, full multi-turn history sent to provider on every request | v1.0 |
| Anonymous (non-persisted) chat | `KB_ANONYMOUS_CHAT_ENABLED` (default **OFF**). "New anonymous chat" opens `/app/chat/anonymous`, which posts the stateless `POST /api/kb/chat` with `anonymous:true`: **no `conversations` / `messages` row** (in-memory only, lost on refresh) and **minimal-or-no `chat_logs`** per `CHAT_LOG_ANONYMOUS_LEVEL` (`minimal` keeps by-norm provider / model / token / latency / chunks / project fields under a fresh per-request session id and strips question / answer / sources / user_id / client_ip / user_agent; `none` writes nothing). PII is **force-masked (non-persistent) before** retrieval / LLM / log / content-gap, so the turn is *more* redacted than a normal stateless turn — every other guard (tenant, RBAC, AI-Act, R26 refusal) still applies. Off → BE **422** + a clean SPA disabled landing via `GET /api/kb/chat/anonymous-config` (R43 both-states; a probe error is surfaced, not shown as the off-state — R14) | v8.8.3 |
| Composite confidence score (0–100) | `ConfidenceCalculator`: `0.40·mean_top_k_sim + 0.20·threshold_margin + 0.20·chunk_diversity + 0.20·citation_density`; renders as `high / moderate / low / refused` tier in the `ConfidenceBadge` | v3.0 |
| Refusal handling | Two refusal paths: deterministic `no_relevant_context` short-circuit (Mockery `shouldNotReceive('chat')` per R26 proves no LLM call) and `llm_self_refusal` via exact-match-after-trim `__NO_GROUNDED_ANSWER__` sentinel. `RefusalNotice` uses `role="status"` not `alert` (R24) | v3.0 |
| `@mention` doc pinning | Type `@docname` in the composer → `/api/kb/documents/search` autocomplete → `MentionPopover` with cursor-context detection → pinned `doc_id` forces inclusion in retrieval even when scored below the similarity floor | v3.0 |
| Filter chips + saved presets | Persistent `FilterBar` with per-dimension removable `FilterChip`s; tabbed `FilterPickerPopover` (Project / Type / Tag / Folder / Date / Language); per-user saved presets at `RESTful /api/chat-filter-presets` (lossless round-trip) | v3.0 |
| Speech-to-text (Web Speech API) | Browser-native mic input via `webkitSpeechRecognition`; zero external service, zero cost; defaults to `it-IT` (configurable). Chrome / Edge / Safari supported | v1.0 |
| Few-shot learning loop | Thumbs up/down rating on every assistant message; `FewShotService` retrieves last 3 positively-rated Q&As per user/project and injects as "Examples of Well-Rated Answers" in the system prompt | v1.0 |
| Smart visual artifacts | `~~~chart` JSON blocks render as Chart.js bar/line/pie/doughnut; `~~~actions` JSON renders as copy/download buttons; every code block ships a "Copy" button | v1.0 |
| Multi-provider AI federation | OpenAI / Anthropic / Gemini / OpenRouter / Regolo all on the `laravel/ai` SDK (ADR 0015); OpenAI + OpenRouter hybrid (SDK no-tools chat + embeddings, raw `Http::` for the MCP tool-calling turn); `AiManager::chat()` + `chatStream()` + `embeddings()`; per-provider streaming where supported (all 5 native or via `FallbackStreaming` trait); chat and embeddings providers configured separately | v1.0 |
| Stateless JSON chat API | `POST /api/kb/chat` synchronous endpoint kept as backward-compat fallback alongside the v4 SSE streaming path; same hybrid retrieval pipeline + refusal short-circuit + confidence score serve both | v1.0 |
| Stop / regenerate / branch / inline-edit affordances | Vercel AI SDK UI Tier 1 closure: stop-streaming via `AbortController`; regenerate-last-assistant; branch-from-message endpoint (forks the conversation tree); inline-edit user message; copy-code-block. All wired on `MessageStreamController` + the `useChatStream()` hook | v4.5 |
| Per-message provider/model/cost metadata | Enhanced badge below every assistant message shows `provider`, `model`, `started_at`, prompt + completion tokens, and derived USD cost when `config('ai.cost_rates')` is populated (keyed by `provider → model → {input, output}`); cost is omitted (not zero) when rates are missing. Public lookup at `GET /api/chat/cost-rates` with 1-hour CDN cache | v4.5 |
| Suggested follow-up pills | `SuggestedFollowupGenerator` derives three follow-up prompts from the assistant's last reply via `AiManager::chat()`; renders as clickable pill chips above the composer; clicking submits via the streaming endpoint. Best-effort — provider error / parse failure / empty response returns `[]` and the row is not rendered. Triggered once on `onFinish` per assistant turn at `POST /conversations/{id}/suggested-followups` | v4.5 |
| In-chat source preview | Clicking a citation opens the cited document in a modal — full text reconstructed from chunks in `chunk_order` via `GET /api/kb/documents/{documentId}/preview`, tenant- + access-scope-isolated (`forTenant` + `AccessScopeScope` + soft-delete; cross-scope read impossible), 404/403 on miss (R14). Open to every authenticated user; admins get an extra deep-link to the full KB document page | team switcher cycle |

### Security & Compliance

| Feature | Description | Since |
|---|---|---|
| Evidence & Risk Review firewall | `padosoft/laravel-evidence-risk-review` v1.1 wired tri-surface (PHP command + MCP tools + HTTP API + native FE admin at `/app/admin/evidence-risk-review`): a budget-bounded sweep labels source evidence tiers and scores per-claim risk verdicts (keep / soften / flag / remove) into a tenant-scoped review log. Host `TenantResolver` binding forces R30 isolation (a client `tenant` filter cannot widen scope); opt-in via `EVIDENCE_RISK_REVIEW_ADMIN_ENABLED` + optional LLM pass over `AiManager` via `EVIDENCE_RISK_REVIEW_LLM_ENABLED` (both default-OFF, R43) | v8.13 |
| PII redaction at 11 persistence boundaries | `padosoft/laravel-pii-redactor` v1.2 wired at: (1) chat-message middleware, (2) embedding-cache pre-redact, (3) AI-insights snippet sanitiser, (4) operator detokenize endpoint, (5) Monolog log channel processor, (6) failed-jobs sanitiser via `JobFailed` listener with deterministic UUID match, (7) `Conversation`+`Message` `saving` observers, (8) `ChatLog::creating` observer, (9) `AdminCommandAudit::creating` observer, (10) `AdminInsightsSnapshot::creating` observer (6 JSON columns), (11) Flow `CurrentPayloadRedactorProvider` contract binding (covers run input + step results + audit + webhook outbox + approvals in one wire). All 5 v4.3 env knobs default OFF | v4.3 |
| Multi-tenant isolation (R30 + R31) | The tenant-aware models (authoritative list in `tests/Architecture/TenantIdMandatoryTest::TENANT_AWARE_MODELS` — incl. `Project` + the `KbIngestBatch`/`KbIngestBatchItem` upload-tracking pair added with the team switcher) carry `tenant_id`; `BelongsToTenant` auto-fills from `TenantContext` on `creating`; the `kb_edges` composite FK is **project-scoped** (`(project_key, node_uid)`, intra-project integrity) while cross-**tenant** isolation is the application-layer R30 `forTenant()` scope (the trait adds **no** global read scope); architecture tests `TenantIdMandatoryTest` + `TenantReadScopeTest` gate new models | v4.0 |
| Team switcher membership gate | `AuthorizeTenantHeader` validates `X-Tenant-Id` after `auth:sanctum`: accepts the caller's own tenant, a cross-access permission, **or** a `project_membership` in the requested tenant — scoped to **both** the tenant *and* the user, so a membership in another tenant or another user's membership never widens access; else `403 tenant_forbidden`. `TeamHash` is a non-secret routing namespace (auth never keys on it). The SPA omits the `X-Tenant-Id` header for the `default` sentinel so sister-package mounts fall back instead of 404ing | team switcher cycle |
| Automated isolation verification | Executable `IsolationMatrix` shared by a live E2E (`LiveRagIsolationTest`, opt-in `LIVE_RAG=1` + real pgvector/embeddings), the `case-study:verify-isolation [--strict]` CLI, and the CI membership-axis `CaseStudyProjectIsolationTest`; separates HARD breaches (real leak → fail) from SOFT refusal-ideal misses (warning unless `--strict`); `KB_PROJECT_ISOLATION_ENABLED` tested in both states (R43) | team switcher cycle |
| `ResolveTenant` middleware + 4 resolvers | Header (`X-Tenant-ID`), domain regex, authenticated user column, or `'default'` (v3 backward compat); per-request singleton; queue workers re-bind tenant via try/finally restore | v4.0 |
| Spatie RBAC (5 roles) | `super-admin` / `admin` / `editor` / `viewer` / `dpo` (DPO added in v4.2 for PII admin); permission matrix grouped by dotted-prefix domain; gates wired at controller + route + middleware layer | v3.0 |
| Sanctum stateful SPA + Bearer tokens | Two transports feed the same guard: cookie-based SPA (`/sanctum/csrf-cookie` + `X-XSRF-TOKEN`) and personal access tokens for API clients / MCP / GitHub Action; `AuthenticateForSse` middleware emits JSON 401 (not HTML redirect) on streaming endpoints | v3.0 |
| Stateless token-auth for non-browser clients | `POST /api/auth/token` verifies credentials with **no session / no CSRF** and mints a Sanctum PAT scoped to least-privilege `kb:read` + `kb:chat` with a **finite 30-day expiry** (never `['*']`, never immortal); `POST /api/auth/token/revoke` is the stateless sign-out (`204`); `EnforceTokenAbility` (`token.ability:<ability>`) gate constrains **only** PATs (`403 token_ability_forbidden`) on the dual-auth `/api/kb/chat` + `/api/kb/documents/search` + `/api/kb/documents/{documentId}/preview` routes and is a no-op for the cookie SPA | desktop client |
| Tauri desktop + iOS client (`desktop/`) | Self-contained Tauri v2 + React (Vite) demo client: login, grounded chat with clickable markdown citations, document search, full-page source viewer; conversation threads persist **locally** (the Bearer client can't reach the session-guarded `/conversations`); all calls route through the Tauri HTTP plugin (no CORS change); same codebase targets iOS via Tauri v2 mobile; outside Laravel CI | desktop client |
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
| Admin SPA shell (`/app/admin/*`) | React 18+ (React 19 since v4.3) + TypeScript + Vite + TanStack Router/Query + shadcn/ui; dark-first glassmorphism; code-split routes (~400 KB initial gzipped); RBAC-gated via Spatie; sidebar visibility enforced server-side. **Since v8.8.2 a single unified, grouped + collapsible sidebar** (`nav-config.ts` SSOT — **28 sections in 5 groups** as of v8.12, the Knowledge group having gained the four Auto-Wiki admin screens) replaces the old primary-rail + secondary-`AdminShell`-rail double menu, and every admin surface now renders **center-only with no nested second admin shell** (cross-mounted sister-package admins drop their own sidebar/header into an in-content tab strip; the Flow surface launches its cockpit in a new tab) — so the host's unified rail is the only menu on any `/app/admin/*` page | v3.0 · v8.8.2 |
| Dashboard KPIs + health | 6 KPIs (docs / chunks / chats / p95 latency / cache hit rate / canonical coverage) + 6 health probes (db / pgvector / queue / kb-disk / embeddings / chat) + 3 code-split recharts cards (chat volume area, token burn stacked, rating donut) + top projects + activity feed; 30s `Cache::remember` layer keyed by kind+project+days | v3.0 |
| Users + Roles + Memberships | Filterable users table with soft-delete + restore; 3-tab edit drawer (Details / Roles / Memberships with `scope_allowlist` JSON editor); Spatie-backed role CRUD with grouped permission matrix; `project_memberships` rows scope canonical visibility per project | v3.0 |
| KB Explorer (tree + 5 right-panel tabs) | Memory-safe `chunkById(100)` tree walker with canonical-aware modes (`canonical \| raw \| all`, `with_trashed=0\|1`); right-panel tabs Preview (remark-rendered + frontmatter pills) / Meta (canonical grid + AI tags) / **Source** (CodeMirror 6 editor with PATCH `/raw` → validate → write → audit → re-ingest) / **Graph** (1-hop tenant-scoped subgraph, SVG radial, ≤ 50 nodes) / **History** (paginated `kb_canonical_audit`) | v3.0 |
| PDF export (Browsershot + Dompdf fallback) | `PdfRenderer` interface with `BrowsershotPdfRenderer` primary (full CSS / fonts / charts) and `DompdfPdfRenderer` fallback (no headless Chromium dependency); A4 print-optimised; renderer chosen at controller level (R23 registry mutex) | v3.0 |
| Log viewer (5 tabs) | Five deep-linkable tabs (`?tab=chat\|audit\|app\|activity\|failed`): chat logs with model/project/rating filters; canonical audit trail with event-type/actor filters; reverse-seek `SplFileObject`-powered application log tailer (whitelist regex, 2000-line cap, optional live polling via `?live=1`); Spatie activity log; failed-jobs read-only table | v3.0 |
| Maintenance command runner | Three-step React wizard (Preview → Confirm with type-in for destructive → Run → Result); whitelist + args_schema + confirm_token + Spatie gates + audit + throttle (see Security row); scheduler widget reports next run of every queued command | v3.0 |
| AI insights panel | Daily `insights:compute` (05:00 UTC) writes one row to `admin_insights_snapshots`; six widget cards (Promotion Suggestions / Orphan Docs / Suggested Tags / Coverage Gaps / Stale Docs / Quality Report) read from JSON columns; O(1) DB read, zero LLM calls per page load | v3.0 |
| Per-user notification feed (bell + panel + API) | Top-bar `<NotificationBell />` polls `/api/notifications/unread-count` every 30s (R11 `data-state` + `aria-busy`); `/app/admin/notifications` full panel with `unread\|read\|dismissed\|all` tabs, BE-derived event-type filter (R18 — `GET /api/notifications/event-types`), pagination, per-row mark-read/dismiss, bulk mark-all-read scoped to the active filter; HMAC-signed one-click email unsubscribe; channels (`in_app`, `email`) ship as part of v8.0/W1.3, joined by **W2.1** external channels `discord` + `slack` + `teams` + generic `webhook` (all default-OFF — opt in by setting the corresponding `NOTIFICATIONS_DISCORD_URL` / `NOTIFICATIONS_SLACK_URL` / `NOTIFICATIONS_TEAMS_URL` / `NOTIFICATIONS_WEBHOOK_URL` env var; the generic webhook channel additionally signs every request with `X-AskMyDocs-Signature: sha256=<hmac>` when `NOTIFICATIONS_WEBHOOK_SECRET` is set). External-channel sends route through the queueable `SendExternalNotificationJob` with `[5, 30, 120]s` backoff (R14 — terminal failure recorded on the row's `channel_dispatch_log`); 4xx responses (except 429) are surfaced as `failed` immediately without retry. Per-user `notification_preferences` matrix wired in v8.0/W2; daily `notifications:prune` 04:10 retains rows for `NOTIFICATIONS_RETENTION_DAYS` (default 90, set 0 to disable) — see env block below. R21 atomic mark-read + dismiss (`whereNull('read_at')->update(...)` + COALESCE); R30 cross-tenant isolation enforced on every endpoint including mutations; presenter strips forensic `channel_dispatch_log` + `tenant_id` + `user_id` from the FE feed. | v8.0 |
| Stale-doc review + weekly digest (KB lifecycle) | `kb:stale-review-sweep` (daily) fires a `kb_doc_stale_review` notification for any document untouched longer than `KB_HEALTH_STALE_REVIEW_MONTHS` (default 6, set 0 to disable) — time-based, every doc type, ACL-scoped to eligible reviewers, idempotent per content version via a `metadata.stale_review_notified_at` marker. `notifications:digest-weekly` (Monday) aggregates the week's `notification_events` per tenant into a `notification_digests` row and emails each email-opted-in user their OWN roundup (`WeeklyDigestMail`), stamping `sent_at` + `recipients_count` — so a user can keep noisy per-event email OFF and still get the Monday digest. Both slots are env-tunable (`SCHEDULE_KB_STALE_REVIEW_SWEEP_*` / `SCHEDULE_NOTIFICATIONS_DIGEST_WEEKLY_*`). | v8.7 |
| Cross-mounted admin SPAs (3 packages) | `padosoft/laravel-pii-redactor-admin` v1.0.2 at `/admin/pii-redactor` (cross-mount since v4.4/W2) + `padosoft/laravel-flow-admin` v1.0.0 at `/admin/flows` + `padosoft/eval-harness-ui` v1.0.0 at `/admin/eval-harness` non-prod-only (cross-mount since v4.4/W3, 3 fail-closed fences preserved). **Since v8.8.2 each package admin mounts center-only with no nested chrome (the host unified rail is the only menu):** the PII and Eval trees cross-mount their React panels directly; the Flow surface renders a native host panel (KPI probe of `/admin/flows/api/live` + section cards) that links out to the full Flow cockpit in a new tab (`target="_blank"`) — so no Blade+Alpine page is ever nested inside the host chrome. **This new-tab launcher supersedes ADR 0005's "flow-admin stays iframe-mounted" assumption** (the cockpit itself remains Blade+Alpine; only the host-side mounting changed) | v4.2 · v8.8.2 |
| Laravel scheduler (14+ entries) | `kb:prune-embedding-cache` 03:10 / `chat-log:prune` 03:20 / `kb:prune-deleted` 03:30 / `kb:rebuild-graph` 03:40 / `queue:prune-failed` 04:00 / **`notifications:prune` 04:10 (v8.0/W1.5, default 90d retention via `NOTIFICATIONS_RETENTION_DAYS`; set 0 to disable)** / `admin-audit:prune` 04:30 / `kb:prune-orphan-files` 04:40 / **`kb:wiki-maintain` 04:40 (v8.11/P9 — Auto-Wiki sweep: rebuild indices + lint + backfill enrichment)** / `admin-nonces:prune` 04:50 / `insights:compute` 05:00 / `eval:nightly` 05:30 (v4.3+, default OFF) / **`kb:stale-review-sweep` 03:55 + `notifications:digest-weekly` Mon 07:00 (v8.7/W2)** / **`kb:prune-staging-batches` (team switcher cycle — sweeps stale drag-and-drop upload batches + their staged files)**; all `onOneServer()->withoutOverlapping()`. **v8.0/W2.4 — every slot's cron + enabled flag is now env-tunable** via the 24 `SCHEDULE_*_CRON` / `SCHEDULE_*_ENABLED` knobs (see `.env.example` Tier-1 scheduler section); defaults preserve the overnight rotation above byte-for-byte. The `GET /api/admin/commands/scheduler-status` widget surfaces the effective cron times after env overrides. | v3.0 |
| Sidebar gating + R29 testid hierarchy | Sidebar entries always rendered, visibility enforced server-side via per-route fences (RequireRole + middleware `can:` + env `abort(404)`); every actionable element uses `feature-resource-{id}-{action[-substep]}` testid convention for Playwright stability | v3.0 |
| Connector admin SPA (`/app/admin/connectors`) | React DataTable with per-connector install/uninstall flow; OAuth callback handler at `/app/admin/connectors/$key/callback`; **credential connectors (v8.17) open a host-rendered schema-driven form** (`CredentialConnectorForm`) → `POST /api/admin/connectors/{name}/configure` instead of an OAuth redirect; per-installation `connector_installations` + `connector_credentials` rows (encrypted via `OAuthCredentialVault`); scheduler-driven `ConnectorSyncJob`; Spatie `manageConnectors` super-admin gate at controller + route layer | v4.5 · credential form v8.17 |
| Widget admin SPA (`/app/admin/widget`) | Manage the KITT embeddable widget: key CRUD + rotate (`pk_`/`sk_` returned once) + revoke, allowed-origins editor, theme designer (validated + sanitised), per-key `host_tools_enabled` toggle, copy-ready embed snippet, and a read-only sessions browser with PII-masked step replay. Key management is `manageWidgetKeys` (super-admin); session inspection is `viewWidgetSessions` (admin + super-admin); everything tenant-scoped. Sessions + steps pruned by `widget:prune-sessions` (daily, `WIDGET_SESSION_RETENTION_DAYS` default 90) which also prunes expired session tokens | v8.10 |
| Project registry (`/app/{team}/admin/projects`) | First-class CRUD over `project_key` — name + description, per-tenant `UNIQUE (tenant_id, project_key)` (R28), immutable key (422 on change), delete-guard when still referenced by a doc/membership (422), seeder backfill from existing keys; soft registry (the key works with or without a row). `/api/admin/projects/*`, admin / super-admin | team switcher cycle |
| KB drag-and-drop upload UI | Stage → review → commit (R21 atomic gate) → poll progress. Files buffer on a dedicated `kb-staging` disk under opaque UUID paths (no filename traversal/collision), then move to the `kb` disk on commit and dispatch the **same** `IngestDocumentJob` as every other ingest path; per-file progress reconciles via queue-lifecycle events; `kb:prune-staging-batches` sweeps stale batches. `/api/admin/kb/uploads/*`, admin / super-admin | team switcher cycle |

### Integrations & Extensibility

| Feature | Description | Since |
|---|---|---|
| MCP server (inward, 10 tools) | `enterprise-kb` server at `/mcp/kb` exposes the KB to Claude Desktop / Claude Code / any MCP-compatible agent (5 retrieval + 5 canonical/promote tools); `auth:sanctum` + `throttle:api` | v3.0 |
| GitHub composite action `ingest-to-askmydocs` (v2) | Reusable action with diff-mode (every push: `git diff --diff-filter=AMR` ingest + `D`+`R` delete batches via `DELETE /api/kb/documents`) and full-sync mode; canonical-folder aware; max 100 docs / batch; `--rawfile` for ARG_MAX safety (R5) | v3.0 |
| 9 registered Flow definitions (saga / compensation) | `kb.ingest` (5-step) / `kb.canonical-index` (3-step) / `kb.promote` (4-step approval-gated, first use of `approval-gate` primitive) / `kb.delete` (4-step) / `kb.prune-deleted` / `kb.prune-embedding-cache` (conditional approval gate) / `kb.prune-chat-logs` / `kb.rebuild-graph` / `kb.ingest-folder` (3-step fan-out). Reverse-order compensation chains; persisted to `flow_runs` + `flow_steps` + `flow_audit` + `flow_approvals` + `flow_webhook_outbox` | v4.2 |
| Multi-AI-provider abstraction | OpenAI / Anthropic / Gemini / OpenRouter / Regolo all on the `laravel/ai` SDK (ADR 0015); OpenAI + OpenRouter hybrid (raw `Http::` retained only for the MCP tool-calling turn); `FallbackStreaming` trait synthesises single-chunk SSE for providers without native streaming | v1.0 |
| Pluggable ingestion pipeline | 3 contracts (`ConverterInterface` / `ChunkerInterface` / `EnricherInterface`); `PipelineRegistry` with FQCN-validated-at-boot + `supports()` mutex (R23); add a new format = implement 3 interfaces + register in `config/kb-pipeline.php` | v3.0 |
| Pluggable chat-log driver | `ChatLogDriverInterface`; `database` driver shipped; BigQuery / CloudWatch are extension points via `ChatLogManager::resolveDriver()` | v1.0 |
| Sister `padosoft/*` package stack | `laravel-ai-regolo` v1.2.1 (Regolo provider for `laravel/ai`, on the 0.8 line since v8.19) + `laravel-ai-finops` v1.4.0 + `laravel-ai-guardrails` v1.1.0 (+ `-admin` v1.0.0; AI safety firewall enforcing on chat since v8.19) + `laravel-pii-redactor` v1.2 (PII detection with EU country packs: Italy + Germany + Spain) + `laravel-pii-redactor-admin` v1.0.2 + `laravel-flow` v1.0 (saga engine + approval gates + webhook outbox + replay) + `laravel-flow-admin` v1.0.0 + `eval-harness` v1.3 (golden datasets + 7 metrics + cohorts + adversarial + LLM-as-judge; since v8.18 a runtime `require` and the retrieval-metric source of truth via `PackageMetricAdapter`) + `eval-harness-ui` v1.0.0 — every package MIT, every architecture test enforces standalone-agnostic invariants (zero refs to `KnowledgeDocument` / `kb_*` tables / `lopadova/askmydocs` in `src/`); the whole stack runs on the `laravel/ai` **0.8** line (v8.19, ADR 0016) | v4.2 |
| External Patent Box dossier tool | `padosoft/laravel-patent-box-tracker` v0.1 generates audit-grade Italian Patent Box dossiers; **deliberately NOT in AskMyDocs `composer.json`** — operators install it in a separate Laravel project (R37 standalone-agnostic) and consume `tools/patent-box/2026.yml` from this repo. Commercialista-validated 2026-05-02 | v4.0 |
| Connector framework + 8 native connectors | Plugin/package architecture (`ConnectorInterface` 10-method contract + `BaseConnector` + `OAuthCredentialVault` + `ConnectorRegistry` with R23 FQCN-validated discovery via `config/connectors.php::built_in` OR `composer.json::extra.askmydocs.connectors`). 8 native connectors: 7 OAuth — `google-drive` + `notion` + `evernote` + `fabric` + `onedrive` + `confluence` + `jira` (inline v4.5; extracted to `padosoft/askmydocs-connector-*` packages v4.6 per ADR 0008 D1) — plus the credential-based `imap` (v8.17) | v4.5 · imap v8.17 |
| Credential-based connectors (generic, schema-driven) | `SupportsCredentialForm` capability (`connector-base` v1.2) lets a non-OAuth connector advertise a field schema (each field with a `target`). `ConfigureConnectorService` renders/validates it dynamically and routes the **secret → encrypted vault, never `config_json`**; `POST /api/admin/connectors/{name}/configure` (super-admin `can:manageConnectors`, R30 tenant-scoped, R32 matrix). Basic-auth pings + vaults (bad login → 422 + PENDING); XOAUTH2 reuses the existing OAuth callback. No `if (name==='imap')` anywhere — any future credential connector works unchanged | v8.17 |
| **MCP client framework** | AskMyDocs as MCP **CLIENT** (outward direction) — tenant-scoped `McpServerRegistry` + `McpToolCallingService` orchestrates multi-turn tool-calling loops (max 3 iterations, configurable); `McpToolAuthorizer` gates per-user/per-server/per-tool access; v7.0/W6.3.B retired the v5.0 Node sidecar and now drives JSON-RPC directly over native HTTP / SSE / stdio transports via `padosoft/askmydocs-mcp-pack`; `McpHandshakeService` persists initialize+tools/list under `mcp_servers.handshake_response_json`; immutable audit trail in `mcp_tool_call_audit` (with `transport_error` status when the upstream connection is unreachable but not timing out); admin API for server CRUD + handshake + tool-list management; `AI_AGENTIC_ENABLED` master switch; OpenAI + OpenRouter providers wire tool schemas automatically | v5.0 |
| **MCP admin web panel** (optional companion) | Standalone Laravel package `padosoft/askmydocs-mcp-pack-admin` ships a React SPA that cross-mounts under `/admin/mcp-pack` and surfaces every MCP-side capability above through 12 routes (Dashboard, Servers list + new-server wizard, per-server detail with 7 tabs, Tools matrix + try-it, Resources tree, Prompts playground, Audit log + drilldown, Circuit breakers, OpenAPI explorer, Settings, Help). **v1.1.0** (shipped 2026-05-18) drives the full live `padosoft/askmydocs-mcp-pack` v1.5+ REST surface end-to-end — 22 typed endpoints, 23 TanStack Query hooks across read+write paths, R21 two-call confirm-token protocol on tool invoke / audit replay / breaker reset, SSE live-feed consumer, 154 Vitest specs covering every binding. Composer-discoverable, RBAC-gated, dark+light themed — see [Optional: mount the MCP admin web panel](#optional-mount-the-mcp-admin-web-panel) | v7.0 |
| **KITT embeddable agentic widget** | A one-`<script>` embeddable, page-aware, agentic chat widget served at `/widget/askmydocs-widget.js` and driven by `/api/widget/*` (gated by the `widget.key` middleware — public `pk_` + `Origin` allowlist, `sk_` secret for server-to-server, or single-use origin-bound `wt_` session tokens consumed atomically per R21). Runs the first-party retrieval stack (grounded + cited, tenant/project resolved server-side from the key — R30) inside a bounded ReAct loop: the widget captures a structured page snapshot and the LLM emits tool calls run in the page DOM (~20 FE verbs: `click`/`type`/`select`/`navigate_to`/`submit_form`/`wait_for`/…) or server-side via `/exec-tool` (`search_knowledge_base`). **Skills** are JSON manifests (`resources/widget/skills/*`) declaring `tools_enabled` + `auto_annotation_rules` + `default_policies`; the **Host-Tools Protocol** lets a host app expose its own tools, double-gated per key (`host_tools_enabled`) **and** per skill. Pages annotate with stable verb-based `data-kitt-*` attributes; `data-kitt-sensitive`/`password`/`hidden` values are force-nulled server-side. Tool schemas are sent only to providers in `config('widget.tool_calling_providers')` (default `openai,openrouter,fake`); otherwise it degrades to plain grounded chat. See [`docs/kitt/INTEGRATION.md`](docs/kitt/INTEGRATION.md) | v8.10 |

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

For **PII-safe ingestion** (v8.23), redact connector content as it lands with
the package engine `PII_REDACTOR_ENABLED=true`, the host master switch
`KB_PII_REDACTOR_ENABLED=true` **and** `KB_CONNECTOR_INGEST_PII_REDACT=true`
(all three are required — `RedactorEngine::redact()` no-ops while the package
engine is off), then pick the strategy via
`KB_INGEST_PII_STRATEGY`: `mask` (default, one-way) or `tokenise` — the latter
writes reversible `[tok:…]` surrogates to the KB while the originals live in a
**per-tenant** vault, so the KB is PII-safe by default and an authorised
operator re-identifies on demand. `tokenise` requires `PII_REDACTOR_SALT`, and
for a persistent vault (the `pii_token_maps` table) set
`PII_REDACTOR_TOKEN_STORE=database` — the package default `memory` store is
process-local. The **inline** ingest path (HTTP `POST /api/kb/ingest` +
`kb:ingest-folder`) redacts too via `KB_INLINE_INGEST_PII_REDACT`, governed
per-(tenant, project) by the `kb_pii_settings` policy (`GET`/`PUT
/api/admin/pii/policy` · `kb:pii-policy` · `KbPiiPolicyTool`). An authorised
operator **re-identifies on demand** (`pii.detokenize`, dpo/super-admin) for a
chat-log or a KB document (`POST /api/admin/pii/documents/{id}/detokenize` ·
`kb:detokenize-document` · `KbDetokenizeTool`), exercises **GDPR Art.17
right-to-erasure** via crypto-shred (`pii.erase`; `POST
/api/admin/pii/erase-subject` · `kb:erase-subject` · `KbEraseSubjectTool`; also
wired into the AI-Act DSAR flow), and **re-embeds** a project after a policy
change (`POST /api/admin/pii/reembed` · `kb:reembed-project` ·
`KbReembedProjectTool`). Every unmask/erase is audited; the `ai.disclosure`
middleware emits the **EU AI Act Art.50(1)** `X-AI-Disclosure` header on every
chat route. See the deep [PII & compliance](https://padosoft.mintlify.app/pii-and-compliance)
doc + [ADR 0020](docs/adr/0020-v823-pii-safe-ingestion-reversible-vault.md).

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

**Auto-Wiki** (v8.11, ADR 0014) layers a second-class `auto` tier on top of the
same persistence + graph. After ingest, `AutoWikiCompilerJob` → `AutoWikiCompiler`
enriches `frontmatter_json._autowiki`; `AutoWikiGraphLinker` materialises the
inferred `kb_edges`; `ConceptSynthesizer` writes new `domain-concept` pages
through the *same* `DocumentIngestor::ingestMarkdown()` path; `WikiIndexBuilder`
maintains `kb_wiki_indices` (per-project roll-ups + per-tenant hub);
`WikiLinter`, `WikiNavigator` (multi-hop BFS), and `AutoWikiReviewer`
(cross-model audit) operate over the graph; `SuggestionApplier` turns
change/delete analyses into reversible mutations recorded in
`kb_doc_analysis_applications`; `WikiExplorerService` lists pages by tier and
**promotes auto→human** / discards them; and the daily `kb:wiki-maintain` sweep
(`WikiMaintainer`) keeps it all fresh. The **reranker firewall**
(`kb.canonical.auto_tier_penalty`) keeps human-`accepted` > `auto` > raw at
retrieval time, so the auto tier never silently outranks vouched knowledge.
Every capability is also exposed via the `enterprise-kb` **MCP** server (25
tools) and RBAC-gated HTTP endpoints under `/api/admin/kb/*`, and — since
**v8.12** — administered from the SPA (Wiki Health / Indices / Explorer /
Doc-Insights Apply / Auto-Wiki Settings + tier-badged chat citations).

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
| **v8.8.1** | ✅ shipped 2026-06-04 (v8.8.3 GA · PR #258) | **Live-verification patch.** Driving a REAL browser against live pgvector + a real OpenRouter key (not mocks) surfaced 4 bugs the mocked suites missed: (1) chat **citation `project_key`** must be read from the chunk, not the unselected `document` relation — the W6 chat **Related** panel was dead in production; (2) the primary sidebar dead-ended on `Coming in Phase…` **placeholders** while the real Dashboard / Knowledge / AI-Insights / Users / Maintenance views sat under `/app/admin/*` (e2e navigated there directly, so never caught it) → repointed + redirects + placeholder components deleted; (3) the sidebar **role label** was hardcoded from a seed constant → now reads the real auth-store role (least-privilege fallback); (4) the **AI Act** page had an **infinite iframe recursion** (a v6.0 redirect placeholder looped the iframe back into the host SPA) → replaced with a **native panel** on the real `/api/admin/ai-act-compliance/*` endpoints. Adds a gated `tests/Live/Rag` end-to-end suite (real pgvector + AI, `LIVE_RAG=1`, throwaway tenant, full teardown). New rule **R42** — on a transient external-API failure (429 / 5xx / stream-idle-timeout / no-connection) never stop: wait ~60 s and retry in a loop. |
| **v8.8.2** | ✅ shipped 2026-06-04 (v8.8.3 GA · PR #260) | **Unified admin navigation + center-only sister mounts.** Removes the confusing "double menu": the primary sidebar and a near-identical secondary `AdminShell` rail are merged into ONE grouped, collapsible sidebar driven by a single `nav-config.ts` source of truth (23 sections, 5 groups); `AdminShell` is reduced to a content-only wrapper. Each sister-package admin now mounts **center-only** — no second sidebar / nested chrome: **Flows** → a native host landing (live KPIs) + the full cockpit via `target=_blank` (no iframe); **PII Redactor** + **Eval Harness** cross-mounts drop their own sidebar/header into an in-content tab strip; **Eval** additionally probes its data API and shows a clean "unavailable" landing when it isn't wired (safe with the flag ON or OFF). New rule **R43** — a boolean feature flag is tested in BOTH states (OFF and ON), never just enabled; the OFF path must degrade cleanly (404 / disabled / unavailable), never a 500. 9-round Copilot R36 loop to 0 must-fix; the whole admin surface re-verified live (1 nav, 0 nested chrome, every backing API healthy). |
| **v8.8.3** | ✅ shipped 2026-06-04 (PR #262) | **Anonymous chat.** A "New anonymous chat" button opens a self-contained `/app/chat/anonymous` view that posts the stateless `POST /api/kb/chat` with `anonymous:true`: the turn is **never persisted** (no `conversations` / `messages` row — in-memory only, lost on refresh) and `chat_logs` are written **minimally or not at all** per `CHAT_LOG_ANONYMOUS_LEVEL` (`minimal` keeps only the by-norm operational fields — provider / model / token counts / latency / chunks / project — under a fresh per-request session id and strips question / answer / sources / user_id / client_ip / user_agent; `none` writes no row). PII is **force-redacted with a non-persistent mask strategy BEFORE** retrieval / LLM / minimal-log / content-gap, so an anonymous turn is *more* redacted than a normal stateless turn — never a bypass — and every other guard still applies (tenant isolation, RBAC, AI-Act disclosure/consent, the `no_relevant_context` refusal short-circuit R26, and the content-gap rollup records the **redacted** query). Feature-flagged `KB_ANONYMOUS_CHAT_ENABLED` (default **OFF**, R43 both-states): when off the BE rejects an `anonymous:true` turn with **422** and the SPA renders a clean disabled landing via the `GET /api/kb/chat/anonymous-config` probe — a probe **failure** surfaces as an error, not a silent off-state (R14). +13 PHPUnit (7 anon controller + existing chat suite) + 6 Vitest + 2 Playwright (R13 real-data; `KB_ANONYMOUS_CHAT_ENABLED=true` in the E2E web-server). The R40 local critic caught the R14 probe-error bug + an R30 test-scoping nit before the first push. |
| **v8.10** | ✅ shipped 2026-06-12 | **KITT embeddable agentic widget.** A one-`<script>` embeddable, page-aware, agentic chat widget (loader at `/widget/askmydocs-widget.js`, API under `/api/widget/*` behind the `widget.key` middleware). Runs the first-party grounded+cited retrieval stack (tenant/project resolved server-side from the key — R30) inside a bounded ReAct loop: captures a structured page snapshot, executes ~20 FE DOM tools (`click`/`type`/`select`/`navigate_to`/`submit_form`/…) and BE tools (`/exec-tool` → `search_knowledge_base`); JSON **skill** manifests (`tools_enabled` + `auto_annotation_rules` + `default_policies`); a double-gated **Host-Tools Protocol** (per key + per skill); `data-kitt-*` page annotation with server-side sensitive-value nulling. Three auth modes (public `pk_` + `Origin` allowlist / `sk_` server-to-server / single-use origin-bound `wt_` session tokens, R21-atomic); 4 tables (`widget_keys` / `widget_sessions` / `widget_session_steps` / `widget_session_tokens`); admin SPA at `/app/admin/widget` (key CRUD/rotate/revoke + origins + theme + host-tools toggle + embed snippet + PII-masked session replay); `widget:prune-sessions` retention. See [`docs/kitt/INTEGRATION.md`](docs/kitt/INTEGRATION.md). |
| **v8.10.1** | ✅ shipped 2026-06-12 (PR #268) | **KITT security hardening + threat-model docs.** A focused security pass on the embeddable widget. **Docs:** `docs/kitt/INTEGRATION.md` gains a full **Security & threat model** (enforced controls + *why* — tenant isolation, exact-match origin allowlist, no-credential CORS, no host-page XSS, credential-field + navigation guards — plus the residual/inherent risks of a public embeddable agent and best practices for the operator **and** the host site, incl. `data-kitt-skip` as the data-egress control), linked from the README KITT section. **Hardening:** the FE executor `type()` guard, which only refused `password`/`hidden`, now shares `snapshot.ts::isSensitiveInput()` so it also refuses `autocomplete` `cc-*` / `current-password` / `new-password` — the agent can neither **read** nor **fill** a credential/payment field via prompt injection. **Pentest-as-fixed-tests:** `WidgetSecurityTest` (origin allowlist exact-match vs look-alike/suffix/subdomain/scheme-downgrade/port/userinfo origins, empty-allowlist-denies-all, `secret_hash` never in `/setup`, anti-IDOR + cross-tenant session containment → 404) + `snapshot.security.test.ts` (`data-kitt-skip` excludes fields/actions/headings; sensitive values auto-nulled) + extended `executor.security.test.ts`. R40 local critic caught the executor-guard gap; +11 PHPUnit + 7 Vitest. |
| **v8.11.0** | ✅ shipped 2026-06-13 | **Auto-Wiki foundations** (Karpathy LLM-Wiki + AutoSci-inspired; cycle start). Introduces a second-class **`auto` tier** for AI-compiled knowledge: a new `knowledge_documents.generation_source ∈ {human, auto}` discriminator + an anti-hallucination **reranker firewall** (`kb.canonical.auto_tier_penalty`) so a human-`accepted` doc on the same topic always outranks an auto-compiled one (auto still outranks raw), threaded through `KbSearchService`/`GraphExpander`/`Reranker`. Adds the layered `AutoWikiGate` (config → tenant `*` → project via `kb_analysis_settings`, default-ON, R43 both-states), a **dedicated AI model override** for the upcoming auto-compile + agentic-retrieval LLM calls (`KB_AUTOWIKI_AI_PROVIDER`/`_MODEL`, `KB_AGENTIC_AI_PROVIDER`/`_MODEL` — empty falls back to the default chat provider), a **source-retention policy** (`full_copy`/`markdown_only`/`reference_only` + a `markdown_path` artifact column so the converted markdown is no longer only lossily re-derivable from chunks), and **ADR 0014** (extends ADR 0003 — the human tier keeps its human gate; the auto tier is reversible + audited + promotable auto→human). Foundation for the compiler (next), lint+index, agentic retrieval, and the apply engine. +10 PHPUnit. |
| **v8.11.1** | ✅ shipped 2026-06-13 | **Auto-Wiki Compiler** (Karpathy LLM-Wiki "ingest"). After a document is ingested, the async `AutoWikiCompilerJob` (dispatched by `IngestDocumentJob`, gated by `AutoWikiGate`, version-idempotent) runs the `AutoWikiCompiler` service which asks the LLM to auto-enrich its frontmatter — topical `tags`, a tight `summary`, `aliases`, and `cross_references` (slug + why + edge_type) to its closest existing neighbours — and merges them into `frontmatter_json._autowiki`, marking the doc `generation_source='auto'`. **Firewall:** it NEVER touches a human-curated canonical doc (the authoritative tier keeps its gate); audited to `kb_canonical_audit` (actor `system:autowiki`). Uses the dedicated model override (`KB_AUTOWIKI_AI_PROVIDER`/`_MODEL`) when set, else the default chat provider. Default-ON, R43 both-states. +12 PHPUnit. |
| **v8.11.2** | ✅ shipped 2026-06-13 | **Evidence-tier** (core; AutoSci [#67](https://github.com/skyllwt/AutoSci/issues/67) — the evidence-strength axis a community reviewer flagged as missing). A new `EvidenceTier` taxonomy (`guideline` > `peer_reviewed` > `official` > `preprint` > `news` > `blog` > `search_hint` > `unverified`) records *what kind* of external evidence a doc's claims rest on, on a nullable `knowledge_documents.evidence_tier` column. The **Auto-Wiki Compiler derives it in the same enrichment LLM call** (one extra field — cheap piggyback) and the **RAG prompt surfaces it** (a per-chunk `Evidence:` line + a weighting rule so the model flags low-confidence `blog`/`search_hint`/`unverified` claims). Exposed **tri-surface (R44)** over one `EvidenceTierService` core: the `kb:evidence-tier` Artisan command (PHP), `GET /api/admin/kb/evidence-tiers` + `PATCH …/documents/{id}/evidence-tier` (HTTP, RBAC-gated, R32 matrix), and the `KbSetEvidenceTierTool` MCP tool — a human-set tier is an audited override that the firewall trusts over the LLM's guess. The general risk-sweep/review-log engine stays OUT of core → sister packages `padosoft/laravel-evidence-risk-review` (+ `-admin`). +15 PHPUnit. |
| **v8.11.3** | ✅ shipped 2026-06-13 | **Graph canonicalization** (AutoSci edges — the auto tier becomes *navigable*). After a doc is enriched, the new `AutoWikiGraphLinker` materialises its allow-listed `_autowiki.cross_references` into real `kb_edges` (`provenance='inferred'`, typed + weighted via `EdgeType`) and the `kb_nodes` they connect (missing targets become `dangling` nodes) — so the LLM-discovered links join the same graph the canonical layer walks. **Enterprise scope:** every auto doc participates — a doc with no slug is given a **stable, collision-safe, per-project slug** (`auto-`-namespaced slugified title, suffixed on collision; never squats on the human canonical slug namespace) so the *whole* corpus is navigable, not just the canonical-shaped subset. It **replaces only its own `inferred` edges** (human frontmatter/wikilink edges are left intact), is tenant-scoped (R30), audited (`graph_rebuild`, `system:autowiki`), and idempotent. Exposed **tri-surface (R44)** over one `AutoWikiGraphLinker` core: `kb:wiki-link` (PHP), `POST /api/admin/kb/documents/{id}/wiki-link` (HTTP, RBAC-gated), `KbRebuildWikiLinksTool` (MCP, roster 15→16). Gated by `KB_AUTOWIKI_GRAPH_ENABLED` (default-ON, R43 both-states; OFF = enrichment only). +18 PHPUnit. |
| **v8.11.4** | ✅ shipped 2026-06-14 | **Concept-page synthesis** (Karpathy concept pages / AutoSci concept pages + entity index). A project sweep finds recurring concepts — the topical `tags` P1 derived, appearing across ≥ `KB_AUTOWIKI_CONCEPTS_MIN_FREQUENCY` docs — that don't yet have their own page, and the new `ConceptSynthesizer` asks the LLM to synthesize a concise **`domain-concept`** page grounded *only* in the docs that mention each concept. The page is written to the KB disk as canonical markdown (frontmatter `generation_source: auto`) and ingested through the **one execution path** (`DocumentIngestor::ingestMarkdown`) — so it gets chunks, embeddings, and graph nodes/edges (from its `related:` frontmatter) like any doc, but lands in the second-class **auto tier**. Ingest now honours an explicit **`generation_source: auto`** canonical frontmatter key (default `human`, unchanged for human docs). **AutoSci `/prefill` dedup:** a concept is skipped if a doc already owns its slug (human `{concept}` or `auto-{concept}`). Explicit trigger only (never per-ingest), capped per run, best-effort per concept, tenant-scoped (R30), audited. Exposed **tri-surface (R44)** over one `ConceptSynthesizer` core: `kb:synthesize-concepts {project}` (PHP), `POST /api/admin/kb/concepts/synthesize` (HTTP, RBAC-gated), `KbSynthesizeConceptsTool` (MCP, roster 16→17). Gated by `KB_AUTOWIKI_CONCEPTS_ENABLED` (default-ON, R43 both-states; OFF = clean no-op). +13 PHPUnit. |
| **v8.11.5** | ✅ shipped 2026-06-14 | **Wiki indices + operation log** (Karpathy `index.md` hub + AutoSci anchor map). The new `WikiIndexBuilder` materialises two index artifacts into a new `kb_wiki_indices` table (deterministic — no LLM, no disk write; a pure, rebuildable projection of the corpus): a **per-(tenant,project) roll-up** (page counts by type, concept count, auto/human split, recently-changed + a rendered markdown TOC) and a **per-tenant hub** (`project_key='*'` — the map across all the tenant's projects that the agentic retrieval will anchor on in P6). The **operation log** is a read over the immutable, append-only `kb_canonical_audit` filtered to the `system:autowiki` actor (no separate log table — audit already *is* the append-only log). Exposed **tri-surface (R44)** over one `WikiIndexBuilder` core: `kb:wiki-index` (PHP), `POST /api/admin/kb/wiki-index` (rebuild) + `GET /api/admin/kb/wiki-index` (hub) + `GET /api/admin/kb/wiki-operations` (log) (HTTP, RBAC-gated), and `KbBuildWikiIndexTool` + `KbWikiHubTool` (MCP, roster 17→19). Tenant-scoped (R30/R31 — new table joins the two completeness lists). +12 PHPUnit. |
| **v8.11.6** | ✅ shipped 2026-06-14 | **Wiki lint / health** (Karpathy "lint"). The new `WikiLinter` runs deterministic structural checks over a project's graph + docs — **dangling** nodes (wikilinked targets with no owning doc), **orphan** pages (real nodes with no incoming *and* no outgoing edges), **stale cross-references** (edges pointing at deprecated / superseded / archived / soft-deleted docs), and a **missing index** (project has pages but no `kb_wiki_indices` row) — reported grouped with counts + a `healthy` flag. `fix()` applies only **safe** auto-fixes (prunes leftover dangling nodes nothing references anymore; the rest is reported for human/AI follow-up), audited. Semantic contradiction detection is deferred to P7 (the LLM-comparison phase) so the linter stays deterministic, fast, and free. Exposed **tri-surface (R44)** over one `WikiLinter` core: `kb:wiki-lint {--project=} {--fix}` (PHP), `GET /api/admin/kb/wiki-lint` + `POST /api/admin/kb/wiki-lint/fix` (HTTP, RBAC-gated, R32 matrix), and `KbWikiLintTool` (MCP, roster 19→20). Tenant-scoped (R30). +13 PHPUnit. |
| **v8.11.7** | ✅ shipped 2026-06-14 | **Agentic graph-navigation retrieval** (Karpathy "query" / AutoSci BFS + anchor-driven discovery). The new `WikiNavigator` is a multi-hop, budget-bounded, cycle-safe **BFS over `kb_edges`** — the "navigate the wiki" primitive beyond the legacy 1-hop `GraphExpander`. Two modes: BFS from explicit **seed slugs**, or **anchor-driven** discovery that first reads the per-project index (P4) to pick the most relevant anchors (central/recently-changed pages) then BFS-expands from them — giving the walk a *map* instead of expanding blindly. Returns reached pages with hop distance, the edge type that reached each, resolved title/type, and an `exists` flag (dangling targets are still valid nav nodes). Read-only, deterministic, tenant-scoped (R30). Exposed **tri-surface (R44)** over one `WikiNavigator` core — the **MCP `KbWikiNavigateTool` is the primary agentic surface** (an agent can explore related knowledge beyond a single vector hit), plus `kb:wiki-navigate {project} {--seeds=} {--depth=}` (PHP) and `POST /api/admin/kb/wiki-navigate` (HTTP, RBAC-gated). Roster 20→21; depth bounded 1–5 (`KB_GRAPH_EXPANSION_DEPTH`, default 2). **Additive + safe:** the chat retrieval hot path is unchanged — wiring the navigator into chat + the benchmark-gated default-ON flip is a deliberate follow-up. +12 PHPUnit. |
| **v8.11.8** | ✅ shipped 2026-06-14 | **Cross-model review / novelty gate** (AutoSci cross-model review + novelty; folds in the contradiction detection deferred from P5). The new `AutoWikiReviewer` has an **independent review-LLM** audit an auto-tier page before it's trusted — checking **grounding** (claims supported by the page, not hallucinated), **cross-reference validity**, **novelty** (`novel` / `overlap` / `duplicate` vs the nearest existing pages), and **contradictions** (neighbours whose claims conflict — filtered to real neighbour slugs, anti-hallucination) — producing a `verdict` (`approved` / `flagged`) persisted to `frontmatter_json._autowiki.review` + audited (`system:autowiki-review`). **Cross-model by design:** a dedicated review-model override (`KB_AUTOWIKI_REVIEW_AI_PROVIDER`/`_MODEL`) — point it at a *different* model than the compiler for true cross-model diversity (empty → default chat). **Firewall:** only auto-tier docs are machine-reviewed; explicit trigger only (never per-ingest). Exposed **tri-surface (R44)** over one `AutoWikiReviewer` core: `kb:wiki-review {document}` (PHP), `POST /api/admin/kb/documents/{id}/wiki-review` (HTTP, RBAC-gated), `KbWikiReviewTool` (MCP, roster 21→22). Gated by `KB_AUTOWIKI_REVIEW_ENABLED` (default-ON, R43). Tenant-scoped (R30). +11 PHPUnit. |
| **v8.11.9** | ✅ shipped 2026-06-14 | **Apply engine** (change/delete suggestions → concrete mutations). The new `SuggestionApplier` turns a `kb_doc_analyses` suggestion into an audited, reversible mutation: a **cross_reference** suggestion adds an inferred edge from the analyzed doc to a suggested neighbour; an **impacted** suggestion marks an impacted doc `deprecated`. Every application is **validated against the analysis** (you can only apply a suggestion the analysis actually produced — no arbitrary mutation), recorded in a new **`kb_doc_analysis_applications`** table (before/after for reversibility) **and** `kb_canonical_audit`. **Firewall (ADR 0003):** a human-curated `accepted` canonical doc is **never** mutated by an *auto* apply; a *manual* apply is an explicit human action and may. **Opt-in auto-apply** (`KB_CHANGE_AUTOAPPLY_ENABLED`, **default-OFF**, R43): when on, the analysis job routes the safe subset (cross-refs from an auto-tier doc) through the applier. Exposed **tri-surface (R44)** over one `SuggestionApplier` core: `kb:apply-suggestion {analysis} {type} {target}` (PHP), `POST /api/admin/kb/analyses/{id}/apply` (HTTP, RBAC-gated), `KbApplySuggestionTool` (MCP, roster 22→23). New `kb_doc_analysis_applications` table joins both tenant-completeness lists (R31). Tenant-scoped (R30). +12 PHPUnit. |
| **v8.11.10** | ✅ shipped 2026-06-14 | **Scheduled wiki maintenance** (Karpathy lint cadence / AutoSci scheduled discovery — "knowledge improves over time"). The new `WikiMaintainer` is a periodic sweep that orchestrates the earlier phases over each (tenant, project): **rebuild the indices** (P4), **lint** wiki health (P5, optionally fix), and **backfill** enrichment — dispatch `AutoWikiCompilerJob` for un-enriched docs (no `_autowiki` block yet), bounded per run (`KB_AUTOWIKI_MAINTENANCE_BACKFILL_LIMIT`, default 25) — so the corpus converges toward full enrichment over time. Pure orchestration (every effect flows through the already-reviewed P1/P4/P5 services + their firewalls/audits). Registered as the daily **`kb_wiki_maintain`** Tier-1 scheduler slot (config-driven cron + kill-switch, default 04:40). Exposed **tri-surface (R44)** over one `WikiMaintainer` core: `kb:wiki-maintain {--project=} {--fix} {--backfill=}` (PHP + the cron entry), `POST /api/admin/kb/wiki-maintain` (HTTP, RBAC-gated), `KbWikiMaintainTool` (MCP, roster 23→24). Tenant-scoped (R30). +9 PHPUnit. |
| **v8.12.0** | ✅ shipped 2026-06-15 | **Auto-Wiki admin UI (P10 — epic close)** — the full web surface on the P1–P9 engine, shipped as 7 real-data-tested sub-PRs (#282..#288). **Wiki Health** (`/app/admin/kb/wiki-health` — lint findings + safe auto-fix), **Wiki Indices** (hub + per-project roll-ups + operation log + one-click rebuild), **Wiki Explorer** (browse typed pages by provenance tier, **promote** auto→human, **discard** auto — a new tri-surface `WikiExplorerService` capability: `kb:wiki-promote` + `GET /api/admin/kb/wiki-pages` + `POST …/documents/{id}/wiki-{promote,discard}` + `KbWikiPromoteTool`, MCP roster 24→25), **Doc Insights → Apply** (turn cross-reference / impacted suggestions into audited reversible mutations over the P8 engine, with a 200-refusal surfaced distinctly from a transport error per R14), **Auto-Wiki Settings** (`/app/admin/kb/autowiki-settings` — per-(tenant,project) auto-build gate over `AutoWikiGate`, tri-state Inherit/On/Off, R43 both-states), and **tier-badged chat citations** (every citation carries `generation_source`; auto pages get an `auto` badge, R27 additive). Every screen has Vitest + real-data Playwright (R13) + an `AdminAuthorizationMatrix` row for each new endpoint (R32). |
| **v8.13.0** | ✅ shipped 2026-06-15 | **Evidence & Risk Review integration (P11)** — the general risk-sweep / review-log engine deferred OUT of core at v8.11.2 lands as the standalone `padosoft/laravel-evidence-risk-review` (core, v1.1) + `-admin` (v1.0) sister packages, wired **tri-surface (R44)** into AskMyDocs over **one** shared core service: the package's Artisan command + MCP tools auto-register (PHP + MCP), the HTTP API mounts at `/api/admin/evidence-risk-review/*` (secured: `tenant.resolve` + `auth:sanctum` + `tenant.authorize` + `can:viewEvidenceRiskReview`, R32 matrix-locked), and a **native FE admin** at `/app/admin/evidence-risk-review` (Reviews log + detail / Profiles / Taxonomy / Try) cross-mounts against that API — the same convention as every sister admin (the `-admin` React bundle is composer-required but `dont-discover`ed). **R30:** a host `TenantResolver` binds the review log to the active tenant — a review is stamped on write and the read paths are forced to that tenant (a client `tenant` filter cannot widen it). **R43 both-states:** the whole admin surface is opt-in via `EVIDENCE_RISK_REVIEW_ADMIN_ENABLED` (default-OFF — routes unregistered → clean 404 + a clean FE "unavailable" landing, never a 500); the optional LLM semantic pass over `AiManager` is a second default-OFF flag (`EVIDENCE_RISK_REVIEW_LLM_ENABLED`). +7 integration PHPUnit (tenant isolation E2E + LLM adapter + R43 on/off) + 3 Vitest + real-data Playwright (R13). |
| **v8.15.0** | ✅ shipped 2026-06-17 | **Engagement & Intelligence Suite** (W1–W5) — the layer that turns the KB into a living system, surpassing Stack Overflow for Teams / Zendesk / Notion on packaging + delivery breadth. **W1** an append-only contribution log (`kb_contribution_events`, written from the existing ingest/promote/citation paths — never a new write path) + `EngagementMetricsService` (SQL-aggregated, R3) + daily `engagement:compute` snapshot (`kb_engagement_snapshots`). **W2** multi-channel rich digest — `DigestComposer` → `DigestRendererRegistry` (R23 mutex) → email (magazine HTML) / Discord embed / Slack Block Kit / Teams Adaptive Card, with an opt-in `AiDigestNarrator` on a **dedicated free OpenRouter model** (`KB_DIGEST_AI_MODEL=meta-llama/llama-3.3-70b-instruct:free`, default-ON, degrades to deterministic copy R14/R43); `digest:send {--frequency=weekly|monthly} {--dry-run} {--preview}`. **W3** per-user `digest_preferences` (frequency + per-section toggles) + in-app digest feed (`engagement_digest_feed` + `digest:prune-feed`) + monthly executive roll-up. **W4** a new personal **My KB** dashboard (`/app/me`) + admin engagement analytics (leaderboard / coverage / answer-rate / decision-debt trend), reusing `KpiCard`/`ChartCard`/recharts. **W5** opt-in **gamification** — config-driven badge catalog awarded over all-time engagement metrics (`kb_user_badges`, `gamification:recompute`, default-OFF `KB_GAMIFICATION_ENABLED`, R43 both-states). Every capability is **tri-surface** (R44): command + HTTP + MCP (`KbEngagementSummaryTool` / `KbDigestPreviewTool` / `KbUserBadgesTool`, roster 25→28) over one shared core; 5 tenant-aware tables (R30/R31). Deep doc-site pages: [Engagement Suite](https://padosoft.mintlify.app/engagement-suite) · [Digests](https://padosoft.mintlify.app/digests) · [Dashboards](https://padosoft.mintlify.app/dashboards) · [Gamification](https://padosoft.mintlify.app/gamification). |
| **v8.16.0** | ✅ shipped 2026-06-19 | **AI FinOps spend governance** (`padosoft/laravel-ai-finops` + `-admin`). A cross-provider AI-spend governance layer — immutable per-call usage **ledger**, N-scope **budgets**, declarative **policy DSL**, **chargeback**/cost-centers, **forecasting** + anomaly detection, cost-aware routing, price-watch and multi-channel **alerts** — attributed per tenant (R30) and host-secured under `api/admin/ai-finops` behind a **method-aware** gate (reads → `viewAiFinOps` super-admin+admin; writes → `manageAiFinOps` super-admin), R32-matrix-locked; package-served React cockpit at `/admin/ai-finops` (default-OFF → clean 404, R43). **W1** the `AiCallMeter` metering bridge. **W2** ([ADR 0015](docs/adr/0015-v816-provider-sdk-migration.md)) migrates provider transport onto the `laravel/ai` SDK so the finops lifecycle hook meters every provider natively (Anthropic + Gemini fully SDK; OpenAI + OpenRouter hybrid — raw `Http::` retained only for the MCP tool-calling turn the bridge still meters); OpenRouter's real billed `usage.cost` becomes capturable. **W3** real **server-side per-turn cost**: `ChatTurnCostResolver` resolves the finops pricing cascade at `ChatLogManager` time onto additive `chat_logs.{cost,cost_currency,trace_id}` (ledger join), replacing the old static client-side guess; surfaced additively in chat `meta` (R27) across stateless / conversation / streaming. **W4** the **R44 third surface**: three tenant-scoped, master-switch + table-aware (R43) MCP read tools (`FinOps{SpendSummary,TopModels,BudgetStatus}Tool`, roster 28→31) + a real-data Playwright E2E over the admin SPA + doc-site parity. RC sequence `v8.16.0-rc1`..`rc4`, then GA. |
| **v8.17.0** | ✅ shipped 2026-06-20 | **Credential-based connectors (IMAP)** — the first non-OAuth connector, activatable entirely from **Admin → Connectors** with the same "click → fill → activate" UX. A **generic, schema-driven** mechanism (no `if (name==='imap')` anywhere): a connector implements `SupportsCredentialForm` (`padosoft/askmydocs-connector-base` v1.2) and the host renders the form, validates it dynamically, splits each field by its `target` (**secret → encrypted vault, never `config_json`**), and activates via the connector's existing `initiateOAuth`/`handleOAuthCallback` contract — basic-auth pings + vaults (success → ACTIVE; bad creds → 422 with `error_json`), XOAUTH2 redirects through the unchanged callback. New `POST /api/admin/connectors/{name}/configure` (super-admin, `can:manageConnectors`, R32-matrix-locked + tenant-scoped R30); schema-driven React modal (R11/R15, honours `showIf`, secrets never pre-filled). Offline test seam (`CONNECTOR_IMAP_FAKE_PING`, hard-gated to testing/local, R43) + real-data Playwright happy+failure; deep [doc-site page](https://padosoft.mintlify.app/connectors-credential). Any future credential connector reuses the same form/endpoint unchanged. |
| **v8.18.0** | ✅ shipped 2026-06-21 | **Retrieval-quality, money-precision & AI coaching** (W1–W5). **W1.1** a real-data Playwright E2E proving the chat meter renders the **server-resolved** per-turn cost (not the old client guess). **W1.2** a deferral guard pinning `laravel/ai` to `^0.6` while `padosoft/laravel-ai-regolo` requires `^0.6` (the 0.7 bump stays blocked). **W1.3** `padosoft/laravel-ai-finops` **v1.3.0** — money also exposed as fixed-precision 8-dp decimal **strings** (additive `*_decimal` keys); host bumped to `^1.3` (the 3 FinOps MCP tools + `ChatTurnCostResolver` consume it). **W2** retrieval-metric math (MRR, nDCG@k) delegated to `padosoft/eval-harness` **v1.3.0** through a single anti-corruption adapter (`PackageMetricAdapter`) + new answer-containment@k; eval-harness moved from require-dev to **runtime `require` (`^1.3.0`)**. **W3** configurable chunk overlap in `MarkdownChunker` (`KB_CHUNK_OVERLAP_TOKENS`, paragraph-bounded tail carry; `0`=off; default `64`; re-ingest required to apply to existing docs). **W4.1** `KB_GAMIFICATION_ENABLED` flipped **default-ON**. **W4.2** a full **AI gamification insights** capability (tri-surface R44) — `GamificationQualityMetricsService` (curation-quality metrics) + `GamificationNarratorService` (LLM coaching cards + period titles, degrades to deterministic R14/R43) + `GamificationInsightsService` (compute→narrate→persist) + `kb_gamification_insights` table + weekly `gamification:narrate` command + `GET /api/me/coaching` + `GET /api/admin/engagement/insights` + super-admin `POST .../regenerate` + a new MCP tool `KbGamificationInsightsTool` (roster **31 → 32**) + React `CoachingCard` + admin `GamificationInsightsPanel`; config `kb.gamification.ai.*` (`KB_GAMIFICATION_AI_{ENABLED,PROVIDER,MODEL,MAX_TOKENS}`, default a free OpenRouter model). RC sequence then GA per R37/R39. |
| **v8.19.0** | ✅ shipped 2026-06-22 | **laravel/ai 0.8 platform migration + AI Guardrails + Agentic Knowledge Reports** (W1–W6). **W1** the platform-wide SDK migration ([ADR 0016](docs/adr/0016-v819-laravel-ai-0.8-platform-migration.md)): `padosoft/laravel-ai-regolo` **v1.2.1** + `padosoft/laravel-ai-finops` **v1.4.0** released onto `laravel/ai` **^0.8**, then the host bumped `^0.6.8 → ^0.8.1` in one coherent resolve (no version skew — the only 0.6→0.8 break, `TranscriptionGateway`, is unused). **W2** **AI Guardrails** core (`padosoft/laravel-ai-guardrails` v1.1.0): input-screening + output-sanitization actively **enforce** on the live chat path via the `ChatGuardrails` adapter — a blocked prompt becomes a refusal (R26, never a 500), the answer is exfil-defanged before it reaches the client; tri-surface (R44) with a `KbGuardrailsInsightsTool` (roster **32 → 33**); host-secured 14-endpoint API behind a method-aware gate (`viewAiGuardrails`/`manageAiGuardrails`, R32-matrix-locked); flags both-states (R43). **W3** the **guardrails admin SPA** (`-admin` v1.0.0) mounts at `/admin/ai-guardrails` (default-OFF → clean 404, R43) behind admin RBAC. **W4** **Agentic Knowledge Reports** backend: agentic column kinds (`extract`/`graph`/`verify`) on the Tabular Review engine — a deterministic `GovernanceColumnResolver` (10 canonical-graph metrics, LLM-free) + a bounded anti-hallucination `verify` pass + a flagship **"Canonical KB Governance Audit"** preset in a 16-template ready-made library; tri-surface with `KbRunReportTool` (roster **33 → 34**). **W5** the rich agentic-report FE: agentic column editor (graph→governance-metric picker), per-cell **evidence side-panel** (reasoning + cited chunks), and a one-click **template gallery**; Vitest + real-data Playwright (R12/R13). **W6** README every-section + deep doc-site pages ([AI Guardrails](https://padosoft.mintlify.app/ai-guardrails) · [Agentic Knowledge Reports](https://padosoft.mintlify.app/agentic-knowledge-reports), R45). RC sequence then GA per R37/R39. |
| **v8.20.0** | ✅ shipped 2026-06-23 | **Multi-account & project-scoped connectors** (Ciclo 1 of the connectors/observability/config/PII roadmap). A tenant now connects **N labelled accounts per connector** (multiple IMAP mailboxes, Drive accounts, Notion workspaces), each optionally **bound to a real KB project** (empty = the tenant default) — replacing the old one-account-per-connector cap + synthetic `connector-<key>` project. Data model in `padosoft/askmydocs-connector-base` **v1.3.1** (`label` + `project_key` columns, relaxed `UNIQUE(tenant_id, connector_name, label)`, `config_json`→column backfill, `resolveProjectKey()`); all 8 connectors adopted. Tri-surface (R44) over one core: **PHP** (`connectors:list` + `connectors:install` with a masked secret prompt), **HTTP** (`ConnectorAdminController` list/install/configure/**PATCH**/delete; `project_key` validated against the real project registry, R18; duplicate-label → 422, R21/R14), **MCP** (`ConnectorInstallationsTool`, roster **34 → 35**). Admin SPA becomes accounts-per-connector cards with label + project dropdown + add/edit/remove (R11/R15/R29). [ADR 0017](docs/adr/0017-v820-multi-account-connectors.md) + deep doc-site page ([multi-account connectors](https://padosoft.mintlify.app/connectors-multi-account), R45). |
| **v8.21.0** | ✅ shipped 2026-06-23 | **Ingestion & sync observability + queue baseline** (Ciclo 2 of the connectors/observability/config/PII roadmap). Connector sync is isolated onto a dedicated `connectors` queue (was `default`, which carried autowiki/change-analysis; `KB_INGEST_QUEUE` stays `kb-ingest`) and every `ConnectorSyncJob` run is recorded **host-side** into tenant-scoped `connector_sync_runs` (started/finished, duration, documents discovered, status running/success/partial/failed) via the Laravel **queue lifecycle** — no connector-package change (the package job emits no events). Operators get queue backlog + per-account sync history; tri-surface (R44) over one `IngestionObservabilityService`: **PHP** (`ingestion:status`), **HTTP** (`GET /api/admin/ingestion/queue` + `GET /api/admin/connectors/{installationId}/sync-runs`, R32, R30-scoped), **MCP** (`KbIngestionStatusTool`, roster **35 → 36**). New "Ingestion & Sync" admin screen (queue-depth cards + per-account run table, explicit loading/error/empty + retry, R14). Per-document status via `flow_runs` deferred (not tenant-aware yet). [ADR 0018](docs/adr/0018-v821-ingestion-sync-observability.md). |
| **v8.22.0** | ✅ shipped 2026-06-23 | **Runtime configuration governance** (Ciclo 3 of the connectors/observability/config/PII roadmap). A curated set of operational knobs becomes editable **per `(tenant, project)` at runtime — no deploy**, layered `config default ← tenant '*' ← exact-project` over one `AppSettingsResolver` (the `KbAnalysisSetting`/`ChangeAnalysisGate` pattern). Governable keys live in a closed `AppSettingRegistry`: `ai.provider` (per-tenant chat provider, wired into `AiManager` fully-guarded → falls back to `config('ai.default')`, R43), `connector.sync_cadence_minutes` (per-tenant **and** per-project), and the **deploy-managed** `ai_finops.enabled` (visible, read-only at runtime → 422). Reads honour scope like writes and **skip corrupt override rows** (no silent coercion, R14); secrets are never registered. Tri-surface (R44): **PHP** (`app-settings:list` / `app-settings:set`), **HTTP** (`GET`/`PUT /api/admin/app-settings`, `role:super-admin`, R32, R30-scoped), **MCP** (`AppSettingsTool`, roster **39 → 40**). New super-admin **Configuration** admin screen (per-row editor + provenance badge + project-scope selector). [ADR 0019](docs/adr/0019-v822-runtime-config-governance.md) + deep doc-site page ([runtime config governance](https://padosoft.mintlify.app/runtime-config-governance), R45). |
| **v8.22.0** | ✅ shipped 2026-06-23 (backend PR #363 + admin UI PR #366) | **Invite-by-code & referral suite** — integrates the standalone `padosoft/laravel-invitations` engine tri-surface (R44) into AskMyDocs over the package's vendor-neutral seams. Atomic, idempotent, concurrency-safe redemption (single conditional `UPDATE … WHERE current_uses < max_uses` + `UNIQUE(code_id, redeemer_id)`) drives campaigns / multi-use & vanity codes / referrals / rewards / waitlist / fail-open anti-abuse (HMAC'd PII) / funnel analytics — each invite carrying a per-tenant **grant** (Spatie role + KB project memberships). `App\Models\User` implements the `InvitedAccount` contract; the package `TenantResolver` binds to the host `TenantContext` (R30); a host `ProjectMembershipProvisioner` (GRANT-never-REVOKE, best-effort) joins the package's role provisioner under the `invitations.provisioners` tag so one code provisions role **and** project access across one or more tenants. Admin surface `/api/admin/invitations/*` (campaigns / code generation / metrics) gated by `can:manageInvitations` (super-admin + admin, R32-matrix-locked); user redeem surface `/api/invitations/*` behind the authenticated stack. Three package MCP tools (`Invite{ValidateCode,GenerateCodes,Metrics}Tool`, roster **36 → 39**). Signup gate `INVITE_REQUIRED` default-**OFF** (R43 both-states — existing registration unchanged). The **admin UI** lands as a cross-mount of the self-contained `padosoft/laravel-invitations-admin` SPA (its own prebuilt React panel served over a gated Blade route at `/admin/invitations`, `INVITATIONS_ADMIN_ENABLED` default-OFF → clean 404 R43, behind `can:manageInvitations`), surfaced through a native host **Invitations** landing (live funnel KPIs over the core `/api/admin/invitations/metrics` + a launcher to the panel) — same self-contained cross-mount model as ai-finops-admin / flow-admin; Vitest + real-data Playwright (R12/R13) + a role-access matrix row (R32). |
| **v8.23.0** | ✅ shipped 2026-06-25 | **PII-safe ingestion & reversible vault** (Ciclo 4 — the last of the connectors/observability/config/PII roadmap). The KB is **PII-safe by default**: detect→tokenise **before** embedding (only deterministic surrogates in the vector store), a **reversible per-tenant vault** (`pii_token_maps`, per-tenant salt, R30) outside the AI path, **JIT re-identification gated by role+scope** and fully audited, **right-to-erasure via crypto-shred** (GDPR Art.17) wired into the `laravel-ai-act-compliance` DSAR flow (Art.15 export + Art.17 delete), **re-embed on policy change** + the `rag-regression` recall gate guarding tokenisation drift, and **EU AI Act Art.50(1)** disclosure (`X-AI-Disclosure`) on every chat route. Per-(tenant, project) `kb_pii_settings` policy (`KbPiiPolicyResolver`, the `KbAnalysisSetting` pattern). Five sub-PRs (#368/#370/#371/#372/#373), each tri-surface (R44) over one core, default-OFF (R43); four MCP tools (roster **40 → 44**). [ADR 0020](docs/adr/0020-v823-pii-safe-ingestion-reversible-vault.md) + deep doc-site page ([PII & compliance](https://padosoft.mintlify.app/pii-and-compliance), R45). |
| **v8.24.0** | ✅ shipped 2026-06-25 | **IMAP folder selection + dev/test email-ingest harness.** Operators now pick exactly which mailbox folders an IMAP account syncs into the KB, straight from **Admin → Connectors**. A post-install **"Folders"** action opens a connection-settings modal that lists the mailbox's **real** folders (live) and multi-selects the sync whitelist. Live discovery is **host-side by design** — `ImapFolderListingService` reuses the connector's PUBLIC seams (`ImapClientFactoryInterface` + `OAuthCredentialVault` + the stored `config_json.connection`) to open a client and return mailbox paths **verbatim** (case-sensitive, round-tripping 1:1 with the whitelist), so no package change was needed beyond `padosoft/askmydocs-connector-imap` **^1.4**. `GET /api/admin/connectors/{installationId}/folders` is tenant-scoped (R30 → 404 cross-tenant) and surfaces an unreachable server / rejected credentials as a distinct **503** (R14 — never a misleading empty 200; a genuinely empty mailbox is a valid `200 []`). `UpdateConnectorInstallationRequest` gains `folders.include` (≤200 EXACT paths, trimmed + deduped + `distinct`; **empty = sync all non-excluded folders**) and `date_window_days`, both additive (R27) and pre-filled by `ConnectorInstallationResource`; the write is a read-modify-write inside `lockForUpdate` (R21). React `FolderSettingsForm` (R11/R15/R29). Plus a dev/test **email-ingest harness** for repeatable end-to-end QA of the ingest→chat→isolation flow across tenants: seedable IMAP mailboxes (`MailSeedImapCommand`, `ImapMailboxSeeder`, `EmailMessageBuilder`, `WebklexMailboxAppender` + a `FakeImapClientFactory` test seam), multi-company case-study fixtures (`CaseStudyUsersSeeder`, `ConnectorInstallationsSeeder`, per-company email JSON) and console drivers (`ConnectorImapInstallCommand`, `DemoListCompaniesCommand`, `InitCaseStudiesCommand`). R32 matrix row for the folders endpoint; real-data Playwright (`connectors-folders-super-admin.spec.ts`) + role-access. Also ships the `ios-testflight-release` skill documenting the Tauri → TestFlight desktop release flow. [ADR 0021](docs/adr/0021-v824-imap-folder-selection.md) + doc-site ([credential connectors → folder picker](https://padosoft.mintlify.app/connectors-credential), R45). |
| **v8.25.0** | ✅ shipped 2026-06-25 | **Schema-driven connector sync settings + first-class folder discovery.** Operators edit a connector account's **entire** safe sync surface — folders to **include AND exclude** as lists, the date window, body format, sender/recipient/subject filters, attachment policy, `only_unseen`/`reconcile_deletions`, `max_messages_per_sync` — from one **schema the connector advertises**, rendered + validated + persisted generically with **zero connector-specific host code** (R23). Two opt-in `padosoft/askmydocs-connector-base` **^1.4** interfaces: `SupportsConnectionSettings::connectionSettingsSchema()` (the editable field surface, each field a dotted `config_json` path) and `SupportsFolderDiscovery::listAvailableFolders()` (connector-owned live folder listing — **fixes XOAUTH2**, which the v8.24 host-side `ImapFolderListingService` could not do). Delivered **tri-surface over one core** (R44): `ConnectorSettingsService` + the HTTP resource/PATCH (dynamic per-field validation, unknown-key/typo → **422 not silent no-op** R14, null → clear-to-default), the **MCP** `ConnectorSettingsTool` (read, roster **44 → 45**) and the `connectors:configure` CLI — every surface enforcing the SAME constraints (R44 parity: bounds, list `distinct`/`min:1`/length, nullable-clear). A whitelisted folder that disappears upstream **never stops the sync** — `MailboxWalker::missingIncludedMailboxes()` records each missing one to `SyncResult.errors[]` + a `Log::warning` and ingests the rest (connector-imap **^1.4**). React `ConnectionSettingsForm` (schema-driven, `showIf`-aware, collision-safe testids, R11/R15/R29). [ADR 0022](docs/adr/0022-v825-schema-driven-connector-settings.md) + doc-site ([connector sync settings](https://padosoft.mintlify.app/connectors-sync-settings), R45). |
| **v8.26.0** | ✅ shipped 2026-06-29 | **Invite-only SPA registration + native Invitations admin + IMAP connection serialization.** The auth UI is now **100% React**: `/login`, `/register`, `/forgot-password` and `/reset-password` all render the SPA shell on a HARD page load (the legacy Blade auth views + the web `PasswordResetController` are deleted), so a cache-cleared reload no longer drops to an un-branded pre-SPA login. A new **invite-only sign-up** screen (`RegisterPage`) posts to **`POST /api/auth/register`** — a thin HTTP adapter over the shared invite core (R44): it **pre-validates** the code (`CodeValidator`) before touching `users`, creates the account, then **authoritatively redeems** it (`RedemptionService` — the atomic conditional `UPDATE … WHERE current_uses < max_uses` + tagged Spatie-role / project-membership provisioners), run **outside** a DB transaction by design so the package's PostgreSQL compensation path is not poisoned, force-deleting the brand-new account on an exhausted-between-checks race so the invite-only invariant always holds. Every code failure is a **422 field error on `invite_code`** (R14, localized R24); the route is **throttled `6/min` per IP** (`throttle:register`) so it can't brute-force codes. The **Invitations admin** becomes a **native in-app tabbed page** (Overview · Campaigns · Codes · Invite · Referrals · Rewards · Waitlist · Anti-abuse) over the same `/api/admin/invitations/*` core inside the unified admin chrome — superseding the v8.22 cross-mount panel, which stays as an optional **"Advanced"** launcher shown only when `INVITATIONS_ADMIN_ENABLED=true` (the host learns it from the additive `features.invitations_admin` field on `/api/auth/me`, R27/R43). Plus **per-mailbox IMAP connection serialization** (at most one live connection per account host+port+username, cross-tenant + cross-surface, so a server never returns *"Too many simultaneous connections"*; busy sync jobs re-queue — `CONNECTOR_IMAP_SERIALIZE_CONNECTIONS`, default-on, needs an atomic lock store), a connector **test-fetch / enable** admin surface (preview one email without ingest; re-activate a disabled account), an SQS-safe widening of `connector_sync_runs.queue`, and a **timezone-aware RAG prompt** (`KB_PROMPT_TIMEZONE`, default `Europe/Rome`) so the chatbot can reason about time-relative questions while the app keeps running on UTC. PRs #381–#387. |
| **Future** | ⏳ planned for v8.x or v9.0 | Auto-Wiki follow-ups: navigator→chat wiring + benchmark-gated default-ON, source-retention wiring (save the converted markdown artifact). Agentic Knowledge Reports follow-ups: SSE progressive-paint generate + the Glide canvas grid (deferred for per-cell testability/a11y). SSO / SCIM enterprise auth + content export/portability — surfaced by the v8.8 Affine gap audit; #1 Semantic Time Travel + #8 v2 (answer drift replay) — parked from v8.0 |

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
| [`docs/kitt/INTEGRATION.md`](docs/kitt/INTEGRATION.md) | KITT embeddable widget developer guide — embed snippet + config, the 3 auth modes, the full `/api/widget/*` API, skills + tools, `data-kitt-*` page annotation, the Host-Tools Protocol, admin SPA, tables, env vars, security model + troubleshooting |
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

*KITT embeddable agentic widget (on a host page)*
![AskMyDoc - KITT.jpeg](resources/screenshots/AskMyDoc%20-%20KITT.jpeg)

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
| `padosoft/laravel-ai-regolo` v1.2.1 | Regolo provider for `laravel/ai` (EU-based OpenAI-compatible REST) | ✅ wired since v4.0 W2 — `RegoloProvider` delegates to the SDK; **since v8.19 on the `laravel/ai` 0.8 line** (`^0.6\|^0.7\|^0.8.1`, [ADR 0016](docs/adr/0016-v819-laravel-ai-0.8-platform-migration.md)) | [github](https://github.com/padosoft/laravel-ai-regolo) |
| `padosoft/laravel-pii-redactor` v1.2 | PII detection + redaction with EU country packs (Italy + Germany + Spain), 6 checksum-validated detectors, 4 strategies, dual NER drivers | ✅ wired at 11 persistence touch-points since v4.3 W1 (was 4 in v4.1) | [github](https://github.com/padosoft/laravel-pii-redactor) |
| `padosoft/laravel-pii-redactor-admin` v1.0.2 | 7-screen admin SPA for PII operator workflows | ✅ cross-mounted at `/admin/pii-redactor` since v4.4 W2 (iframe in v4.2/W4) | [github](https://github.com/padosoft/laravel-pii-redactor-admin) |
| `padosoft/laravel-flow` v1.0 | In-process saga / compensation engine + approval gates + webhook outbox + replay lineage | ✅ wired since v4.2 W2 — 9 Flow definitions registered | [github](https://github.com/padosoft/laravel-flow) |
| `padosoft/laravel-flow-admin` v1.0.0 | Blade + Alpine cockpit SPA — runs / approvals / webhook outbox / definitions | ✅ at `/admin/flows`. **Since v8.8.2 the host shows a native center-only panel** (KPI probe of `/admin/flows/api/live` + section cards) that launches the full Blade+Alpine cockpit in a new tab (`target="_blank"`) — so the cockpit is never nested inside the host chrome. **This supersedes ADR 0005's "flow-admin stays iframe-mounted" assumption**; what remains true from ADR 0005 is that the cockpit itself stays Blade+Alpine, not React | [github](https://github.com/padosoft/laravel-flow-admin) |
| `padosoft/eval-harness` v1.3 | RAG / LLM evaluation framework — golden datasets, 7 metrics, cohorts, adversarial lane, LLM-as-judge regression detection, and (v1.3) reusable retrieval-metric primitives (MRR, nDCG@k) | ✅ wired since v4.2 W3 (CI gate) + v4.3 W3 (nightly cron) + v4.4 W4 (adversarial opt-in); **since v8.18 a runtime `require` (`^1.3.0`)** — the retrieval-metric source of truth, consumed via the `PackageMetricAdapter` anti-corruption layer | [github](https://github.com/padosoft/eval-harness) |
| `padosoft/eval-harness-ui` v1.0.0 | 8-page React + Vite admin SPA — read-only, non-prod-only | ✅ cross-mounted at `/admin/eval-harness` since v4.4 W3 (iframe in v4.2/W4); 3 fail-closed fences preserved | [github](https://github.com/padosoft/eval-harness-ui) |
| `padosoft/laravel-patent-box-tracker` v0.1 | Italian Patent Box dossier auto-generator | ❌ external by design — operators install in a separate Laravel project; AskMyDocs ships `tools/patent-box/2026.yml` as input | [github](https://github.com/padosoft/laravel-patent-box-tracker) |
| **Connectors** (9 packages, v4.6 extraction + v8.17 IMAP) — `padosoft/askmydocs-connector-base` v1.2 + `-google-drive` v1.0.1 + `-notion` v1.0.1 + `-evernote` v1.0.0 + `-fabric` v1.0.0 + `-onedrive` v1.0.0 + `-confluence` v1.0.0 + `-jira` v1.0.0 + `-imap` v1.2 | Framework primitives + 8 standalone external-source connectors — 7 OAuth2 (sync + source-aware markdown rendering) plus the **credential-based** `imap` (basic-auth / XOAUTH2). `-base` v1.2 adds the `SupportsCredentialForm` capability the host renders credential forms from. Each `composer require`-able; auto-discovered via `composer.json::extra.askmydocs.connectors`; talk to AskMyDocs through the `ConnectorIngestionContract` IoC bridge | ✅ wired since v4.6 W4 — `HostIngestionBridge` implements the contract (dispatch / path resolve / PII redact / audit / soft-delete by remote-id); `imap` added v8.17 (host renders the credential form, secret → encrypted vault) | [base](https://github.com/padosoft/askmydocs-connector-base) · [google-drive](https://github.com/padosoft/askmydocs-connector-google-drive) · [notion](https://github.com/padosoft/askmydocs-connector-notion) · [evernote](https://github.com/padosoft/askmydocs-connector-evernote) · [fabric](https://github.com/padosoft/askmydocs-connector-fabric) · [onedrive](https://github.com/padosoft/askmydocs-connector-onedrive) · [confluence](https://github.com/padosoft/askmydocs-connector-confluence) · [jira](https://github.com/padosoft/askmydocs-connector-jira) · [imap](https://github.com/padosoft/askmydocs-connector-imap) |
| `padosoft/askmydocs-mcp-pack` v1.5.0 | Framework-agnostic MCP (Model Context Protocol) plumbing for Laravel — 6 contracts + multi-turn tool-calling orchestrator + stdio/HTTP transports + hash-only audit + RBAC hooks + **full admin REST surface (22 endpoints): me/tenants/api-keys, server CRUD + handshake + tools/resources/prompts, R21-atomic tool invoke / audit replay / breaker reset, SSE events, OpenAPI 3.1 spec**; standalone, zero AskMyDocs dependencies; 325 PHPUnit tests across 7 CI cells (PHP 8.3 × Laravel 11/12/13, PHP 8.4 × Laravel 11/12/13, PHP 8.5 × Laravel 13 only) | ✅ shipped 2026-05-18 (v1.5.0) — full cycle v1.0→v1.5 closed: v1.0 contracts, v1.1 transports + SSE, v1.2 server-side, v1.3 circuit breaker + retry, v1.4 admin REST minimal (6 endpoints), v1.5 admin REST complete (22 endpoints with sub-interface BC-safe extensions: `McpHostBridgeIdentityContract` + `McpServerMutableRegistryContract`). AskMyDocs v7.1+ pins `^1.5` | [github](https://github.com/padosoft/askmydocs-mcp-pack) |
| `padosoft/askmydocs-mcp-pack-admin` v1.1.0 | Standalone React SPA companion — 12 routes covering server CRUD, handshake, tool catalog, paginated audit log, circuit-breaker dashboard, three-pane resources browser, prompt playground, OpenAPI explorer, settings + tour. Cross-mounts under `/admin/mcp-pack` exactly like `pii-redactor-admin` / `flow-admin` / `eval-harness-ui` | ✅ live wire-up GA 2026-05-18 (v1.1.0) — every page surface drives real `padosoft/askmydocs-mcp-pack` v1.5+ endpoints via TanStack Query: 22 typed endpoints + 19 hand-written types mirroring v1.5 OpenAPI, 13 read hooks + 10 mutation hooks, R21 two-call confirm-token protocol with second-leg expired-token guard on `useInvokeTool` / `useReplayAudit` / `useResetBreaker`, SSE live-feed consumer replacing prototype simulator, `<DataState>` shared wrapper enforcing R14+R11+R15 invariants. **154 Vitest specs across 22 test files** covering loading / error / empty / ready states + R21 happy + failure + ValidationError + SSE behaviour via MSW handlers shaped to the real wire schema. Full real-backend Playwright rewrite tracked for v1.1.x; v1.1.0 ships with a smoke spec only | [github](https://github.com/padosoft/askmydocs-mcp-pack-admin) |
| `padosoft/laravel-evidence-risk-review` v1.1 + `-admin` v1.0 | Answer-grounding risk firewall: a budget-bounded deterministic + optional-LLM sweep that labels evidence tiers, scores per-claim risk verdicts (keep / soften / flag / remove), and appends a tenant-scoped review log; domain risk profiles + a pluggable check registry; tenancy-agnostic via a `TenantResolver` contract | ✅ wired tri-surface since v8.13 P11 — core PHP command + MCP tools auto-register; HTTP API at `/api/admin/evidence-risk-review/*` (host-secured + tenant-scoped); **native FE admin** at `/app/admin/evidence-risk-review` cross-mounts the core API (the `-admin` React bundle is composer-required but `dont-discover`ed). Opt-in (`EVIDENCE_RISK_REVIEW_ADMIN_ENABLED`, default-OFF, R43) | [core](https://github.com/padosoft/laravel-evidence-risk-review) · [admin](https://github.com/padosoft/laravel-evidence-risk-review-admin) |
| `padosoft/laravel-ai-guardrails` v1.1.0 | AI safety firewall (offline-first): Tool Firewall, **Input Screening** + append-only audit, **Output Handler**/sanitizer, HITL; 14-endpoint HTTP API; modes `enforce`/`monitor`/`off`; 7-table persistence. Born on `laravel/ai` **^0.8** | ✅ wired since **v8.19 W2** — input-screening + output-sanitization **enforce** on the live chat path via a host `ChatGuardrails` adapter (blocked prompt → refusal R26, never a 500; answer exfil-defanged). Tri-surface (R44): the core API behind the authenticated admin stack (`can:viewAiGuardrails`, R32) + MCP `KbGuardrailsInsightsTool`. Tables are GLOBAL security infra (no `tenant_id`, like `embedding_cache`). R43 on `AI_GUARDRAILS_ENABLED` | [github](https://github.com/padosoft/laravel-ai-guardrails) |
| `padosoft/laravel-ai-guardrails-admin` v1.0.0 | 8-screen React SPA — a pure HTTP consumer of the guardrails core API (firewall log / audit / output stats / settings) | ✅ package-served at `/admin/ai-guardrails` since **v8.19 W3**, behind `can:viewAiGuardrails`; default-OFF → clean 404 via the host `GuardrailsAdminEnabled` middleware + `AI_GUARDRAILS_ADMIN_ENABLED` (R43) | [github](https://github.com/padosoft/laravel-ai-guardrails-admin) |
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

**v8.26.0 — Invite-only SPA registration + native Invitations admin + IMAP connection serialization (GA, shipped 2026-06-29).**
The authentication UI is now **entirely React**. `/login`, `/register`,
`/forgot-password` and `/reset-password` each render the SPA shell (`view('app')`
via `SpaController`) on a HARD page load — not only on in-app navigation — so a
cache-cleared reload of `/login` no longer drops to a second, un-branded Blade
login page. The legacy Blade auth views (`login` / `forgot-password` /
`reset-password`) and the web `PasswordResetController` are **removed**;
`/reset-password` now carries `?token=&email=` as query params (matching the SPA
reset route's search schema, so the framework's `ResetPassword` notification emits
exactly that query-string URL). A new **invite-only sign-up** screen (`RegisterPage`
— name, email, password + confirmation, invite code, with client-side validation
and field-level 422 surfacing) posts to the new **`POST /api/auth/register`**
endpoint. That endpoint is a thin HTTP adapter over the shared invite core (R44):
it **pre-validates** the invite code (`CodeValidator`) before creating the account
so a bad code never mints an orphan user, then **authoritatively redeems** it
(`RedemptionService` — the atomic conditional `UPDATE … WHERE current_uses <
max_uses` + the tagged Spatie-role / project-membership provisioners), run
**outside** a DB transaction by design (the package's compensation path issues
follow-up queries after a UNIQUE violation, which a wrapping transaction would
poison on PostgreSQL). On an exhausted-between-checks race the brand-new account is
**force-deleted** so the invite-only invariant always holds; the account is then
floored at `viewer` (layered on any grant role — GRANT-never-revoke) and the SPA
session is opened. Every invite-code failure is a **422 field error on
`invite_code`** (R14 — never 200-with-empty; localized via
`lang/{en,it}/register.php`, R24), and the route is **throttled `6/min` per IP**
(`throttle:register`) so it can't be used to brute-force codes. Sign-up is
**always** invite-only here — `invite_code` is required regardless of the
`INVITE_REQUIRED` gate.

The **Invitations admin** is now a **native, in-app tabbed page** at
`/app/{team}/admin/invitations` (Overview · Campaigns · Codes · Invite ·
Referrals · Rewards · Waitlist · Anti-abuse) over the same
`/api/admin/invitations/*` core, inside the unified admin chrome — **superseding
the v8.22 cross-mount panel**. That standalone `padosoft/laravel-invitations-admin`
panel remains an optional **"Advanced"** launcher shown **only** when
`INVITATIONS_ADMIN_ENABLED=true`, so it never links to the unregistered
`/admin/invitations` 404; the host learns the flag from the additive
**`features.invitations_admin`** field on `/api/auth/me` (R27/R43).

Also in this release: **per-mailbox IMAP connection serialization** — at most one
live IMAP connection per account (host+port+username), **cross-tenant** and across
every surface, so a server never returns *"Too many simultaneous connections"*; a
new connection waits for the mailbox to free and concurrent same-mailbox sync jobs
**re-queue** instead of blocking a worker (`CONNECTOR_IMAP_SERIALIZE_CONNECTIONS`,
default-on, needs an atomic lock store / Redis; `CONNECTOR_IMAP_MAILBOX_LOCK_*`
tunables). A connector **test-fetch / enable** admin surface
(`POST /api/admin/connectors/{id}/test-fetch` previews one email with no ingest;
`…/enable` re-activates a disabled / errored account) with a `TestFetchResultModal`
in **Admin → Connectors**. An SQS-safe widening of `connector_sync_runs.queue`.
And a **timezone-aware RAG prompt** (`KB_PROMPT_TIMEZONE`, default `Europe/Rome`):
the chatbot is told what "now" is in the system prompt so it can reason about
time-relative questions while the app itself keeps running on UTC. PRs #381–#387.

**v8.25.0 — Schema-driven connector sync settings + first-class folder discovery (GA, shipped 2026-06-25).**
Operators edit a connector account's **full** sync surface — folders to **include
AND exclude** as lists, the sync window, body format, sender/recipient/subject
filters, attachment handling, `only_unseen`/`reconcile_deletions`,
`max_messages_per_sync` — from a **schema the connector itself advertises**, with no
per-field host code. This generalises the v8.24 two-field folder picker: instead of
a bespoke request rule + resource key + form per knob, a connector implements
`SupportsConnectionSettings::connectionSettingsSchema()` (each field a dotted
`config_json` path, never a secret) and the host renders, validates, persists, reads
and CLI-edits **any** field for free (R23 — the host branches on `instanceof`, never
on connector name). Folder discovery moves **into the connector** via
`SupportsFolderDiscovery::listAvailableFolders()` (generic host
`ConnectorFolderListingService`: `404` for a non-discovering connector, `503` on an
unreachable source, R14) — which **fixes XOAUTH2 discovery** that the v8.24
host-side `ImapFolderListingService` could not reproduce. Delivered tri-surface over
one `ConnectorSettingsService` core (R44): the HTTP resource embeds
`connection_settings_schema` + `settings` and the PATCH validates the `settings`
object dynamically per field (an unknown/typo'd or mis-shaped key **422s rather than
200-OK-then-silently-does-nothing**, R14; a present `null` **clears the override**
back to the connector default by unsetting the key); the **MCP**
`ConnectorSettingsTool` reads the schema + current values + (opt-in) the live folder
list (roster **44 → 45**); the `connectors:configure` CLI shows + edits with the
SAME constraints the HTTP surface enforces (R44 parity — integer bounds, list
`distinct`/non-empty/length, nullable clear). A whitelisted folder deleted upstream
**never stops the sync**: the IMAP engine ingests the surviving folders and records
each missing one to `SyncResult.errors[]` + a `Log::warning`
(`MailboxWalker::missingIncludedMailboxes()`, connector-imap **^1.4**). React
`ConnectionSettingsForm` is schema-driven, honours `showIf`, surfaces element-level
422s and uses collision-safe testids (R11/R15/R29).
`padosoft/askmydocs-connector-base` **^1.4** + `padosoft/askmydocs-connector-imap`
**^1.4**. [ADR 0022].

**v8.24.0 — IMAP folder selection + dev/test email-ingest harness (GA, shipped 2026-06-25).**
Operators choose exactly which mailbox folders an IMAP account ingests, from
**Admin → Connectors**. A post-install **"Folders"** action opens a
connection-settings modal that lists the mailbox's **real** folders live and
multi-selects the sync whitelist. Live discovery is **host-side by design** —
`ImapFolderListingService` reuses the connector's PUBLIC seams
(`ImapClientFactoryInterface` + `OAuthCredentialVault` + the stored
`config_json.connection`) to open a client and return mailbox paths **verbatim**
(case-sensitive, round-tripping 1:1 with the whitelist), so the only dependency
move was bumping `padosoft/askmydocs-connector-imap` to **^1.4**. The read
endpoint `GET /api/admin/connectors/{installationId}/folders` is tenant-scoped
(R30 → 404 on a cross-tenant id) and maps an unreachable server / rejected
credentials to a distinct **503** (R14 — never a misleading empty 200; a
genuinely empty mailbox is a valid `200 []`). `UpdateConnectorInstallationRequest`
gains `folders.include` (≤ 200 EXACT, case-sensitive paths — trimmed, deduped,
`distinct`; **an empty list means "sync all non-excluded folders"**) and
`date_window_days`, both **additive** (R27) and pre-filled by
`ConnectorInstallationResource`; the write is a read-modify-write performed
inside `lockForUpdate` (R21) so concurrent edits never clobber `config_json`.
React `FolderSettingsForm` follows the testid/a11y conventions (R11/R15/R29). The
release also adds a dev/test **email-ingest harness** for repeatable end-to-end
QA of the ingest → chat → tenant-isolation flow: seedable IMAP mailboxes
(`MailSeedImapCommand`, `ImapMailboxSeeder`, `EmailMessageBuilder`,
`WebklexMailboxAppender`, plus a `FakeImapClientFactory` test seam),
multi-company case-study fixtures (`CaseStudyUsersSeeder`,
`ConnectorInstallationsSeeder`, per-company email JSON) and console drivers
(`ConnectorImapInstallCommand`, `DemoListCompaniesCommand`,
`InitCaseStudiesCommand`). As part of this, the `manageConnectors` gate is
**widened from super-admin-only to admin + super-admin** so an admin can run the
picker (consistently propagated across the gate, route group, FE role guards, the
R32 matrix and `role-access.spec.ts`; it still touches credential vaults).
Coverage: an R32 authorization-matrix row for the
folders endpoint, real-data Playwright (`connectors-folders-super-admin.spec.ts`)
and role-access. Finally it ships the `ios-testflight-release` skill that codifies
the Tauri → TestFlight desktop release flow. See
[ADR 0021](docs/adr/0021-v824-imap-folder-selection.md) and the doc-site
[credential-connectors folder-picker section](https://padosoft.mintlify.app/connectors-credential)
(R45). CI reliability: Playwright `retries` is now **1** in CI (was 0) so a
genuine intermittent flake in the streaming / admin-kb-edit specs auto-recovers
in-run instead of forcing a manual re-run, while a real regression still fails
twice; local stays 0 for fast, honest feedback.

**v8.23.0 — PII-safe ingestion & reversible vault (GA, shipped 2026-06-25).**
Ciclo 4 (the last) of the connectors / observability / config / PII roadmap.
Makes the knowledge base **PII-safe by default** while letting an authorised
operator re-identify on demand — the Presidio/Skyflow/DLP "detect → tokenise
before embedding, reversible per-tenant vault outside the AI path" pattern.
*PR1 (#368):* tenant-isolated reversible tokenisation at the **connector ingest
boundary** — `HostIngestionBridge::redactContent()` selects mask vs `tokenise`
via `KB_INGEST_PII_STRATEGY`, building the strategy through the package factory
so the host `TenantResolver` + per-tenant salt wire in (R30); originals land in
the per-tenant `pii_token_maps` vault, surrogates go to disk/chunks/embeddings.
*PR2:* extends tokenisation to the **inline** ingestion path (HTTP
`POST /api/kb/ingest` + `kb:ingest-folder` CLI, which run the `kb.ingest` Flow
saga). The Flow's `chunk-document` step redacts each **chunk's text** — via the
shared `ChunkRedactor` — so the downstream `embed-chunks` + `persist-chunks`
steps only ever see surrogates (the legacy direct `DocumentIngestor` path shares
the same `ChunkRedactor`). Raw markdown stays the idempotency anchor; canonical
frontmatter stays parseable; deterministic tokens keep re-ingest idempotent; a
dry-run preview forces the side-effect-free mask (no vault tokens). Gated by
`KB_INLINE_INGEST_PII_REDACT` (default OFF, R43). Adds a per-`(tenant, project)` **`kb_pii_settings`** policy
(`redact_enabled` + `strategy`) resolved most-specific-wins
(`config ← tenant '*' ← project`) by `KbPiiPolicyResolver`, delivered tri-surface
(R44): **HTTP** (`GET /api/admin/pii/policy` `viewPiiRedactorAdmin`; `PUT`
`manageKbPiiPolicy` — dpo / super-admin; R32 matrix rows), **CLI**
(`kb:pii-policy`), **MCP** (`KbPiiPolicyTool` read, roster **40 → 41**).
Tenant-aware (R30/R31), `UNIQUE(tenant_id, project_key)`.
*PR3:* **re-identification (detokenise)** of a tokenised KB document — JIT,
gated, audited — tri-surface (R44) over one `DetokenizeService`: **HTTP**
`POST /api/admin/pii/documents/{id}/detokenize` (`pii.detokenize` — dpo /
super-admin), **CLI** `kb:detokenize-document`, **MCP** `KbDetokenizeTool` (net
super-admin only — the LLM-facing PII surface carries the tightest gate; roster
**41 → 42**). Every surface enforces the `tokenise` preflight, is tenant-scoped
(R30 — no cross-tenant re-id by id), bypasses the per-project read ACL for this
privileged compliance op while keeping the tenant + permission gates, and audits
each completed unmask + permission-denied attempt (`admin_command_audit`,
`command='pii.detokenize'`; the strategy preflight + not-found are not audited —
no unmask is attempted).
*PR4:* **right-to-erasure (GDPR Art.17) via crypto-shred** — destroying a
subject's `pii_token_maps` entries makes every surviving `[tok:...]` surrogate
permanently unresolvable, no downstream rewrite needed. Tri-surface (R44) over
one `SubjectErasureService`: **HTTP** `POST /api/admin/pii/erase-subject`
(`pii.erase` — dpo / super-admin; new permission), **CLI** `kb:erase-subject`,
**MCP** `KbEraseSubjectTool` (write → super-admin only; roster **42 → 43**).
Tenant-scoped (R30), audited (`command='pii.erase'`, count-only — no raw PII in
the trail). Wired into the `laravel-ai-act-compliance` **DSAR** flow: Art.17
delete crypto-shreds the subject's vault (by email) in every tenant; Art.15
export adds a `pii_vault` snapshot.
*PR5:* **re-embed on policy change + recall gate + Art.50 disclosure.** Changing
the policy (mask⇄tokenise) leaves old chunks/embeddings stale; a **forced
re-embed** (`DocumentIngestor::ingest(forceReembed:true)` — skips the
version-hash no-op + replaces the chunk set) is exposed tri-surface (R44) over
one `ReembedProjectService` (one `ReembedDocumentJob` per live doc, R30/R3):
**HTTP** `POST /api/admin/pii/reembed` (`manageKbPiiPolicy`), **CLI**
`kb:reembed-project`, **MCP** `KbReembedProjectTool` (write → super-admin; roster
**43 → 44**). The policy `PUT` now returns `reembed_recommended` when the
effective policy changed. The existing `rag-regression` CI gate (golden Q&A set
through the live RAG pipeline) guards tokenisation embedding-drift; the
`ai.disclosure` middleware already emits the **EU AI Act Art.50(1)**
`X-AI-Disclosure` header on every chat route (API + SSE). The five sub-PRs added
four MCP tools (roster **40 → 44**, locked by `KnowledgeBaseServerRegistrationTest`).
[ADR 0020](docs/adr/0020-v823-pii-safe-ingestion-reversible-vault.md) + deep
doc-site page ([PII & compliance](https://padosoft.mintlify.app/pii-and-compliance), R45).

**v8.22.0 — Runtime configuration governance (GA, shipped 2026-06-23).**
Ciclo 3 of the connectors / observability / config / PII roadmap. A curated set
of operational knobs becomes editable **per `(tenant, project)` at runtime —
without a deploy** — layered `config default ← tenant '*' ← exact-project` over a
single `AppSettingsResolver` (the `KbAnalysisSetting` / `ChangeAnalysisGate`
pattern). Governable keys live in a closed `AppSettingRegistry`: `ai.provider`
(per-tenant chat provider, wired into `AiManager` and **fully guarded** — an
unknown/unconfigured value or any governance hiccup falls back to
`config('ai.default')`, so the OFF path equals the pre-v8.22 behaviour, R43),
`connector.sync_cadence_minutes` (per-tenant **and** per-project, `scope: both`),
and the **deploy-managed** `ai_finops.enabled` (surfaced read-only → runtime
writes 422). Reads honour scope exactly as writes do and **skip corrupt override
rows** rather than silently coercing them (R14); secrets are never registered
(vault-only). Delivered tri-surface (R44) over the one resolver: **PHP**
(`app-settings:list` / `app-settings:set`), **HTTP** (`GET`/`PUT
/api/admin/app-settings`, gated `role:super-admin`, R32 matrix row, R30-scoped),
**MCP** (`AppSettingsTool` read surface, roster **39 → 40**). A super-admin
**Configuration** admin screen ships the UI: a per-row editor (enum/int/bool),
a provenance badge (config / tenant / project), a project-scope selector, and
inline 422s; deploy-only and tenant-scoped-under-project rows are read-only.
`app_settings` is tenant-aware (`BelongsToTenant`, R30/R31) keyed
`(tenant_id, project_key, setting_key)`. [ADR 0019](docs/adr/0019-v822-runtime-config-governance.md).

**v8.22.0 — Invite-by-code & referral suite (GA, shipped 2026-06-23).**
Integrates the standalone **`padosoft/laravel-invitations`** engine tri-surface
(R44) into AskMyDocs as an additive feature — no existing behaviour changes
until an operator opts in. The package ships an enterprise invite system:
**atomic, idempotent, concurrency-safe redemption** (a single conditional
`UPDATE … WHERE current_uses < max_uses` backed by `UNIQUE(code_id,
redeemer_id)` — never a read-then-write), campaigns, multi-use + vanity codes,
**referrals + rewards + waitlist**, fail-open generic **anti-abuse** (a detector
fault degrades to no-block; PII is HMAC'd, never plaintext), and funnel /
virality analytics. (Originally landed as the parallel PR #363 "in progress"
entry; bundled into the v8.22.0 GA.) Each invite carries a per-tenant **grant** — a Spatie role
plus KB **project memberships** — so one code provisions access across one or
more tenants ("teams") at once. Host wiring over the package's vendor-neutral
seams: `App\Models\User` implements the `InvitedAccount` contract; the package
`TenantResolver` is bound to the host `App\Support\TenantContext` so every
invite query/write is tenant-scoped (R30); a host **`ProjectMembershipProvisioner`**
(GRANT-never-REVOKE — `firstOrCreate`, never downgrades an existing membership;
best-effort — a fault is logged, never thrown, since the redemption is already
committed) joins the package's role provisioner under the
`invitations.provisioners` tag. The admin surface `/api/admin/invitations/*`
(campaigns / code generation / metrics / direct invitations) is gated by a new
`can:manageInvitations` gate (super-admin + admin, R32-matrix-locked) wired
through `config/invitations.php`'s `admin_middleware`; the user redeem surface
`/api/invitations/*` rides the authenticated stack. Exposed on all three
surfaces: PHP (the package's services + `invite:*` console), HTTP (the routes
above), and **MCP** — `Invite{ValidateCode,GenerateCodes,Metrics}Tool` on
`KnowledgeBaseServer` (roster **36 → 39**). The closed-beta **signup gate**
`INVITE_REQUIRED` defaults **OFF** (R43 both-states — existing registration is
unchanged; flipping it on requires a valid code). The 9 invite tables are
package-owned + tenant-aware (R30/R31 enforced in the package's own CI). The
backend tri-surface landed first (PR #363, full host suite green — 3047 tests);
the **admin UI** follows as a cross-mount of the self-contained
**`padosoft/laravel-invitations-admin`** SPA — its own prebuilt React panel
(campaigns / codes / invitations / referrals / rewards / waitlist / anti-abuse /
settings) served over a gated Blade route at `/admin/invitations`
(`INVITATIONS_ADMIN_ENABLED` default-OFF → clean 404, R43; middleware
`web,auth,can:manageInvitations`; `api_base` → the core API), surfaced through a
native host **Invitations** landing (`/app/$teamHash/admin/invitations`) that
shows live funnel KPIs from the core `/api/admin/invitations/metrics` and
launches the panel in a new tab — the same self-contained cross-mount model as
ai-finops-admin / flow-admin (no iframe, no nested chrome). Vitest +
real-data Playwright (R12/R13: admin happy path over the real core metrics +
a 503 failure-injection + a viewer-denied path) + a `role-access` matrix row
(R32). Deep doc-site page: [Invitations & Referrals](https://padosoft.mintlify.app/invitations).

**v8.21.0 — Ingestion & sync observability + queue baseline (GA, shipped 2026-06-23).**
Ciclo 2 of the connectors / observability / config / PII roadmap. Connector sync
is isolated onto a dedicated **`connectors`** queue (was `default`, shared with
autowiki / change-analysis; `KB_INGEST_QUEUE` stays `kb-ingest`) — run a worker
per queue (the "default" queue name is resolved from the active connection, not
assumed). Every `ConnectorSyncJob` execution is now recorded **host-side** into
the tenant-scoped `connector_sync_runs` table (started/finished, duration,
documents discovered, status running / success / partial / failed) by observing
Laravel's queue lifecycle (`JobProcessing` / `JobProcessed` / `JobFailed`) — the
connector package emits no events, so the host watches it, with a `SyncRunContext`
counting discovered documents through `HostIngestionBridge`; recording is
best-effort and never breaks the sync path. Operators read it three ways (R44)
over one `IngestionObservabilityService`: the **`ingestion:status`** command,
**`GET /api/admin/ingestion/queue`** + **`GET /api/admin/connectors/{installationId}/sync-runs`**
(R32-matrix-locked, R30-scoped, cross-tenant 404), and the MCP
**`KbIngestionStatusTool`** (roster **35 → 36**). A new **"Ingestion & Sync"**
admin screen renders queue-depth cards + a per-account sync-run table with
explicit loading / error / empty states + retry (R14) and an R13-marked failure
E2E. Per-document status (derived from the Flow engine's `flow_runs`) is a
tracked follow-up — `flow_runs` is not tenant-scoped yet. [ADR 0018](docs/adr/0018-v821-ingestion-sync-observability.md).
RC then GA per R37/R39.

**v8.20.0 — Multi-account & project-scoped connectors (GA, shipped 2026-06-23).**
Ciclo 1 of the connectors / observability / config / PII roadmap. A tenant now
connects **N labelled accounts per connector** — multiple IMAP mailboxes, Drive
accounts, Notion workspaces — each optionally **bound to a real KB project**
(empty = the tenant default), replacing the one-account-per-connector ceiling and
the synthetic `connector-<key>` project. The data model lives in
`padosoft/askmydocs-connector-base` **v1.3.1** (`label` + `project_key` columns, a
relaxed `UNIQUE(tenant_id, connector_name, label)`, a `config_json`→column
backfill, and `BaseConnector::resolveProjectKey()`); all eight connectors adopted
it. Delivered tri-surface (R44) over one core service: **PHP** (`connectors:list`
read + `connectors:install` with a masked, non-echoing secret prompt), **HTTP**
(`ConnectorAdminController` list / install / configure / **PATCH** edit / delete —
`project_key` validated against the real project registry, R18; a duplicate label
is a **422** authorized by the DB unique, R21/R14), and **MCP**
(`ConnectorInstallationsTool`, roster **34 → 35**). The admin SPA becomes
accounts-per-connector cards with a label field, a project dropdown
("Global (tenant default)" + real projects), and add / edit / remove per account
(R11/R15/R29); `connector_credentials` cascade on account delete (R28); behaviour
verified in both bound and default states (R43). [ADR 0017](docs/adr/0017-v820-multi-account-connectors.md)
+ deep doc-site page. RC sequence then GA per R37/R39.

**Desktop client & token authentication (merged to main 2026-06-22).**
A native **Tauri v2 + React** desktop/iOS client (`desktop/`) plus the
stateless **Bearer-token** auth flow that powers any non-browser client.
`POST /api/auth/token` (`AuthController::token`, validated by `TokenRequest`)
verifies credentials with **no session / no CSRF** — it sits outside the `web`
middleware group so a token client without an `XSRF-TOKEN` cookie isn't rejected
with `419` — and returns `201 { token, token_type: "Bearer", user }`. The minted
Sanctum personal access token is scoped to least-privilege abilities
(`kb:read` + `kb:chat`, never `['*']`) and carries a **finite 30-day expiry** so a
leaked token self-revokes server-side; wrong/unknown credentials return `422`,
and a failure-only throttle (own bucket) returns `429` after 5 attempts.
`POST /api/auth/token/revoke` deletes the caller's PAT and returns `204` — it is
registered outside the `web` group (PAT-only in practice; cookie/session clients
use `POST /api/auth/logout`). The new `EnforceTokenAbility` middleware
(`token.ability:<ability>` alias in `bootstrap/app.php`) is a PAT-scoped gate on
the **dual-auth** routes `/api/kb/chat` (`kb:chat`), `/api/kb/documents/search`
and `/api/kb/documents/{documentId}/preview` (`kb:read`): it rejects a wrongly-scoped PAT
with `403 token_ability_forbidden` while being a **no-op for the cookie SPA**
(a `TransientToken`), so one route serves both transports without breaking
either. Ships the vendored `personal_access_tokens` migration. The Tauri client
demonstrates login, grounded chat with clickable markdown citations, document
search, and a full-page source-document viewer; conversation threads persist
**locally** (the Bearer client can't reach the session-guarded
`/conversations`), all calls route through the Tauri HTTP plugin (no CORS
change), and the same codebase targets **iOS** via Tauri v2 mobile. The desktop
project is self-contained (own `package.json` + Rust crate) and outside the
Laravel CI. See [`desktop/README.md`](desktop/README.md) and the deep doc-site
page.

**v8.19.0 — laravel/ai 0.8 platform migration + AI Guardrails + Agentic Knowledge Reports (GA, shipped 2026-06-22).**
Six waves. **W1** is the platform-wide SDK migration ([ADR 0016](docs/adr/0016-v819-laravel-ai-0.8-platform-migration.md)):
the two padosoft packages that touch the `laravel/ai` SDK in code — `padosoft/laravel-ai-regolo` (**v1.2.1**) and
`padosoft/laravel-ai-finops` (**v1.4.0**) — are released onto `laravel/ai` **^0.8** first, then the host bumps
`^0.6.8 → ^0.8.1` in a single coherent `composer update` (no mixed versions, no skew). The only 0.6→0.8 breaking
change (`TranscriptionGateway::generateTranscription()` gaining `$providerOptions`) is on a surface AskMyDocs does not
use (chat + embeddings only), so the host SDK code is unchanged; the old `LaravelAiPinTest` deferral guard flips to
assert the 0.8 line. **W2** integrates **AI Guardrails** core (`padosoft/laravel-ai-guardrails` v1.1.0). The chat path
isn't an agent loop, so a host `ChatGuardrails` adapter mirrors the package controls: the user query is **screened
before** retrieval/model (a blocked prompt becomes a localized refusal — R26, never a 500 — with an append-only audit
row) and the answer is **sanitized after** generation (exfil-link neutralized before it reaches the client). Modes are
`enforce`/`monitor`/`off`, every store wrapped in try/catch (logging never breaks the user path). Tri-surface (R44):
PHP (package commands + adapter), HTTP (the core 14-endpoint API behind the authenticated admin stack + an R32 matrix
row), MCP (`KbGuardrailsInsightsTool`, roster **32 → 33**). The `ai_guardrails_*` tables are GLOBAL security infra
(no `tenant_id`, like `embedding_cache`) — isolation is admin RBAC. R43 both-states on `AI_GUARDRAILS_ENABLED`.
**W3** mounts the **guardrails admin SPA** (`-admin` v1.0.0) at `/admin/ai-guardrails`, default-OFF → clean 404 via a
host `GuardrailsAdminEnabled` middleware (the package mounts its catch-all unconditionally, so the host owns the flag),
behind `can:viewAiGuardrails`; Playwright happy + viewer-403 + flag-OFF-404. **W4** promotes the v4.7 Tabular Review
engine to **Agentic Knowledge Reports**: a column-level `agent` dimension (`extract` = today's RAG default · `graph` =
a deterministic, LLM-free governance metric from the canonical graph · `verify` = a bounded second pass that can only
**downgrade** a flag when the value isn't supported by the cited evidence, R14). `GovernanceColumnResolver` computes 10
metrics (evidence tier, frontmatter completeness, canonical status, in/out edges, graph connectivity, orphan,
supersession, staleness) tenant-scoped (R30) from the real `EvidenceTier`/`CanonicalStatus` enums. A flagship
**"Canonical KB Governance Audit"** preset ships in a now-16-template ready-made library (`BuiltInWorkflowSeeder`).
Tri-surface with `KbRunReportTool` (roster **33 → 34**, COUNT(DISTINCT)+LIMIT-bounded, R30/R43). **W5** ships the rich
agentic-report FE on the accessible DOM matrix: an agentic **column editor** (the graph metric picker appears only for
`graph` columns; submit is gated so a graph-without-metric can't 422), a per-cell **evidence side-panel** (summary +
flag + reasoning + cited KB chunks), and a one-click **template gallery** of the built-in system reports (malformed
seed rows are sanitized, never blind-cast). Vitest + real-data Playwright (R12/R13); the SSE progressive-paint generate
and the Glide canvas grid are documented v8.19.x follow-ups (deferred for per-cell testability/a11y). **W6** refreshes
the README every section and ships the two deep doc-site pages (R45). RC sequence then GA per R37/R39.

**Team switcher, KB upload UI & project registry (merged to main 2026-06-21).**
A multi-tenant front-end + KB-governance feature set. **Team switcher** —
`/api/auth/me` now returns a `teams` array; every authenticated SPA screen lives
under `/app/{teamHash}/…`; the shared axios client auto-stamps `X-Tenant-Id`
(omitting the `default` sentinel so sister-package mounts fall back instead of
404ing); switching team clears the TanStack Query cache + remounts the outlet so no
tenant's data leaks across a switch; `AuthorizeTenantHeader` gains a
membership-in-requested-tenant branch (scoped to both tenant and user — no
escalation). **KB drag-and-drop upload** — stage → review → commit (R21 atomic gate)
→ poll progress, files buffered on a dedicated `kb-staging` disk under opaque UUID
paths then moved to the `kb` disk and ingested through the **same**
`IngestDocumentJob` as every other path; per-file progress reconciles via
queue-lifecycle events; `kb:prune-staging-batches` sweeps stale batches. **First-class
project registry** — `projects` table with per-tenant `UNIQUE (tenant_id,
project_key)`, immutable key, delete-guard, seeder backfill, CRUD at
`/api/admin/projects/*`. **In-chat source preview** — open a cited document in a modal,
reconstructed from chunks and tenant/access-scope isolated, via
`GET /api/kb/documents/{documentId}/preview`. **Automated isolation testing** — an
executable `IsolationMatrix` shared by a live E2E, the `case-study:verify-isolation`
CLI, and a CI membership-axis test, separating HARD breaches from SOFT
refusal-ideal misses. Three new tenant-aware models (`Project`, `KbIngestBatch`,
`KbIngestBatchItem`) join the R30/R31 architecture gates.

**v8.18.0 — Retrieval-quality, money-precision & AI coaching (GA, shipped 2026-06-21).**
Five waves. **W1.1** ships a real-data Playwright E2E proving the chat meter shows the
**server-resolved** per-turn cost. **W1.2** adds a deferral guard pinning `laravel/ai`
to `^0.6` while `padosoft/laravel-ai-regolo` requires `^0.6` (the 0.7 bump stays
blocked). **W1.3** consumes `padosoft/laravel-ai-finops` **v1.3.0**, which now also
exposes money as fixed-precision 8-dp decimal **strings** under additive `*_decimal`
keys; the host bumps to `^1.3` (the three FinOps MCP read tools + `ChatTurnCostResolver`
read the new shape). **W2** delegates the retrieval-metric math (MRR, nDCG@k) to
`padosoft/eval-harness` **v1.3.0** behind a single anti-corruption adapter
(`PackageMetricAdapter`) and adds answer-containment@k; eval-harness moves from
require-dev to a runtime **`require` (`^1.3.0`)** because it is now production code, not
just the CI gate. **W3** makes chunk overlap configurable in `MarkdownChunker` via
`KB_CHUNK_OVERLAP_TOKENS` (paragraph-bounded tail carry; `0`=off; default `64`; a
re-ingest is required to apply it to already-stored docs since ingest is idempotent on
`sha256(markdown)`). **W4.1** flips `KB_GAMIFICATION_ENABLED` **default-ON**. **W4.2**
adds a full **AI gamification insights** capability, tri-surface (R44) over one shared
core: `GamificationQualityMetricsService` (curation-quality metrics) +
`GamificationNarratorService` (LLM coaching cards + period titles, degrading to
deterministic copy when off/unreachable, R14/R43) + `GamificationInsightsService`
(compute→narrate→persist) write to a new `kb_gamification_insights` table; a weekly
`gamification:narrate` command, `GET /api/me/coaching`, `GET /api/admin/engagement/insights`,
a super-admin `POST .../regenerate`, and a new MCP tool `KbGamificationInsightsTool`
(roster **31 → 32**) cover all three surfaces, with a React `CoachingCard` + admin
`GamificationInsightsPanel` on the UI. Config lives under `kb.gamification.ai.*`
(`KB_GAMIFICATION_AI_{ENABLED,PROVIDER,MODEL,MAX_TOKENS}`, default a free OpenRouter
model). `feature/v8.18` merges to `main` as **v8.18.0** (R37) after the RC sequence.

**v8.17.0 — Credential-based connectors (IMAP) (GA, shipped 2026-06-20).** Adds the
first **credential-based** connector (IMAP) to the connector framework, activatable
entirely from **Admin → Connectors** like the OAuth ones — but through a **generic,
schema-driven** mechanism with **no IMAP-specific branch** in the host, so every
future credential connector (SMTP, API-key sources, …) works unchanged. A connector
implements the new `SupportsCredentialForm` capability (`padosoft/askmydocs-connector-base`
v1.2) and advertises a field schema (each field carries a `target`); the host renders
the form from that schema, validates it **dynamically** (rules derived from the schema,
defaults merged so `required_if` stays correct), and routes each value by its `target` —
the **secret goes to the encrypted vault and never to `config_json`**, logs, or the API
response. Activation reuses the connector's existing `initiateOAuth` / `handleOAuthCallback`
contract (no new connector method): **basic-auth** replays a single-use state to ping +
vault the credential (success → ACTIVE; a failed login → **422** with the row left PENDING +
`error_json`, never a silent 200), while **XOAUTH2** (Gmail / M365) persists PENDING and
redirects through the **unchanged** OAuth callback. New endpoint
`POST /api/admin/connectors/{name}/configure` is super-admin-only (`can:manageConnectors`),
tenant-scoped (R30) and locked into the R32 authorization matrix; the schema-driven React
modal honours `showIf`, never pre-fills secrets, exposes `data-state`/`aria-busy` (R11/R15),
and surfaces per-field 422s inline. Because the IMAP server is a **backend TCP dependency**
Playwright can't stub, an offline `FakeImapClientFactory` seam (`CONNECTOR_IMAP_FAKE_PING`,
**hard-gated to testing/local** so it can never bypass real auth in production, R43
both-states tested) makes the happy + failure E2E deterministic. Pulls in
`padosoft/askmydocs-connector-imap` v1.2 (+ `-base` v1.2); deep doc-site page
[Credential-based connectors](https://padosoft.mintlify.app/connectors-credential).

**v8.16.0 — AI FinOps spend-governance integration (GA, shipped 2026-06-19).** Installs
`padosoft/laravel-ai-finops` (core) + `padosoft/laravel-ai-finops-admin` (React
cockpit): cross-provider usage ledger, N-scope budgets, declarative policies,
chargeback, forecasting/anomaly detection, cost-aware routing and alerts,
attributed per tenant (R30) and host-secured under `api/admin/ai-finops` behind
the admin stack + a **method-aware** gate (reads → `viewAiFinOps`
super-admin+admin; writes → `manageAiFinOps` super-admin), locked by the R32
authorization matrix; the SPA mounts at `/admin/ai-finops` (default OFF → clean
404, R43). Tier-1 crons: `ai-finops:capture-prices` / `:check-alerts` / `:prune`.
Across this cycle every AI provider moves onto the `laravel/ai` SDK so FinOps can
meter chat, streaming and embeddings through native lifecycle events, and the
static client-side cost table is replaced with authoritative server-side per-model
cost (W3). **W1** (`v8.16.0-rc1`) lands the integration foundation: the
`App\FinOps\AiCallMeter` bridge meters synchronous chat + embeddings for all
providers (streaming not yet metered). **W2** (`v8.16.0-rc2`, ADR 0015) migrates
all four `Http::`-based providers onto the native `laravel/ai` SDK drivers —
Anthropic + Gemini fully SDK; OpenAI + OpenRouter hybrid (SDK no-tools chat +
embeddings, raw `Http::` retained only for the MCP tool-calling turn the SDK
can't host) — so the finops lifecycle hook meters every provider natively. The
`AiCallMeter` bridge shrinks to the residual with-tools turn, and OpenRouter's
real billed `usage.cost` becomes capturable (`AI_FINOPS_ACTUAL_COST`, default OFF).
**W3** (`v8.16.0-rc3`) replaces "token cost set arbitrarily" with a real
**server-side per-turn cost**: resolved server-side from the finops pricing cascade
(`ChatTurnCostResolver`, called by the controllers for the response `meta` and by
the chat-log driver when persisting), persisted on `chat_logs.cost` (decimal 18,8)
+ `cost_currency` (ISO-4217), and correlated to the turn's usage-ledger row(s)
(a tool loop spans several) via a shared `trace_id`. Surfaced additively in the
chat response `meta` (R27) and rendered by the FE `TokenCostMeter` in the configured
base currency — replacing the old client-side compute from static rates — across the
stateless, conversation and streaming chat endpoints. The resolver is metering-gated
so it never adds a price-feed HTTP fetch to the response path.
**W4** (`v8.16.0-rc4`) completes the third **R44** surface and ships the cycle to GA:
three tenant-scoped (R30), OFF-path-safe (R43) MCP read tools on the `enterprise-kb`
server — `FinOpsSpendSummaryTool` (window spend + per-(provider, model) breakdown),
`FinOpsTopModelsTool` (costliest models with cost-share) and `FinOpsBudgetStatusTool`
(per tenant-scoped budget limit/spend/state, delegating to the package `Budget::status()`
core) — MCP roster **28→31**, each honouring both the `ai-finops.enabled` master switch
and table presence so a disabled deployment reads nothing over MCP. Plus a real-data
Playwright E2E over the package-served `/admin/ai-finops` admin SPA (admin reaches the
shell; a viewer is denied **403** via the `viewAiFinOps` gate), with the package's
prebuilt assets published + verified in CI; and a `docs-site` + CLAUDE.md parity pass
(ADR 0015). `feature/v8.16` then merges to `main` as **v8.16.0** (R37).

**v8.15.0 — Engagement & Intelligence Suite.** The layer that turns a knowledge
base from a passive store into a living system — proactive digests, contributor
analytics, dashboards and opt-in gamification — designed to surpass Stack Overflow
for Teams / Zendesk / Notion on packaging and delivery breadth. It rests on one
append-only primitive: the **contribution event** (`kb_contribution_events`),
written from the *existing* ingest / promotion / citation paths (never a new write
path, so the log stays a rebuildable projection). `EngagementMetricsService`
aggregates those events **in SQL** (R3) into contributor stats, leaderboards,
coverage %, answer rate and trends; a daily `engagement:compute` snapshot
(`kb_engagement_snapshots`) makes dashboards and digests read O(1).

The **digest** is a composition, not a query: `DigestComposer` assembles typed
sections (newly created/promoted docs · stale review queue · top unanswered ·
health trend · "your attention needed"; modified-doc activity shows in the
headline metrics rather than as a per-doc list) and a `DigestRendererRegistry`
(R23 boot-validated mutex) renders one card per channel — a magazine-grade HTML
email plus Discord embed / Slack Block Kit / Teams Adaptive Card, reusing the
existing notification channel adapters for transport, plus an in-app feed
(`engagement_digest_feed`). An opt-in `AiDigestNarrator` adds a "what changed &
why it matters" summary on a **dedicated free OpenRouter model**
(`KB_DIGEST_AI_MODEL`, default `meta-llama/llama-3.3-70b-instruct:free`) so it
never competes with the primary chat model and costs ≈$0, degrading to
deterministic copy when off or unreachable (R14/R43). `digest:send
{--frequency=weekly|monthly} {--tenant=} {--channel=} {--dry-run} {--preview}`
drives it; per-user `digest_preferences` (frequency + per-section toggles) and a
monthly executive roll-up complete the delivery matrix.

Two dashboards read the same metrics so the numbers always agree: a personal
**My KB** dashboard at `/app/me` (your score, rank, authored docs, citation impact,
review queue) and an admin engagement panel (leaderboard / coverage / answer-rate /
decision-debt trend), both on the existing `KpiCard`/`ChartCard`/recharts
primitives. Finally, **gamification** awards a config-driven badge catalog over
all-time engagement metrics (`kb_user_badges`, `gamification:recompute`) —
`KB_GAMIFICATION_ENABLED` **default-ON since v8.18** (set it to `false` to turn
the whole layer, badges + AI insights, off), tested in both states (R43); when
off the badges section is absent, not an empty box.

Every capability is **tri-surface** (R44) over one shared core: Artisan command +
HTTP endpoint + MCP tool (`KbEngagementSummaryTool` / `KbDigestPreviewTool` /
`KbUserBadgesTool`; MCP roster 25→28). Five new tenant-aware tables join both
completeness lists (R30/R31). Deep doc-site pages ship for the suite, digests,
dashboards and gamification (R45).

**v8.13.0 — Evidence & Risk Review integration (P11).** The general
risk-sweep / review-log engine that v8.11.2 deliberately kept OUT of core lands
as the standalone `padosoft/laravel-evidence-risk-review` (core, v1.1) +
`-admin` (v1.0) sister packages and is wired **tri-surface (R44)** into AskMyDocs
over **one** shared core service. The core package's Artisan command + MCP tools
auto-register (PHP + MCP). The HTTP API mounts at
`/api/admin/evidence-risk-review/*`, host-secured with the same admin stack as
the rest of `/api/admin/*` (`tenant.resolve` + `auth:sanctum` +
`tenant.authorize` + `can:viewEvidenceRiskReview`) and locked by the R32
authorization matrix. A **native FE admin** at `/app/admin/evidence-risk-review`
(Reviews log + detail drill-down / Profiles / Taxonomy / Try-a-review)
cross-mounts that API — the same convention as PII Redactor / Flow / Eval Harness
/ AI Act; the separate `-admin` React bundle is composer-required but
`dont-discover`ed. **R30:** a host `TenantResolver` binds the review log to the
active tenant — every review is stamped on write and the read paths are forced
to that tenant, so a client-supplied `tenant` filter can never widen the scope.
**R43 both-states:** the whole admin surface is opt-in via
`EVIDENCE_RISK_REVIEW_ADMIN_ENABLED` (default-OFF → the package routes never
register, the FE shows a clean "unavailable" landing, and there is never a 500);
the optional LLM semantic pass over the host `AiManager` is a second default-OFF
flag (`EVIDENCE_RISK_REVIEW_LLM_ENABLED`). Coverage: +7 integration PHPUnit
(cross-tenant isolation E2E + LLM-adapter mapping + R43 on/off via Mockery
`shouldNotReceive`/`shouldReceive`, R26) + the flag-OFF clean-404 test, +3 Vitest
(pending / ready / unavailable), and a real-data Playwright happy path + a marked
R13 failure injection. Full suite green (2736 PHPUnit).

**v8.12.0 — Auto-Wiki admin UI (P10 — epic close).** The web surface on the
P1–P9 engine, shipped as seven real-data-tested sub-PRs (#282..#288): **Wiki
Health** (lint + safe auto-fix), **Wiki Indices** (hub + per-project roll-ups +
operation log + rebuild), **Wiki Explorer** (browse by provenance tier +
**promote** auto→human + **discard** — a new tri-surface `WikiExplorerService`
capability: `kb:wiki-promote` + `GET /api/admin/kb/wiki-pages` +
`POST …/documents/{id}/wiki-{promote,discard}` + `KbWikiPromoteTool`, lifting the
`enterprise-kb` MCP roster **24 → 25 tools**), **Doc Insights → Apply** (the P8
apply engine surfaced per-suggestion, a 200-refusal rendered distinctly from a
transport error per R14), **Auto-Wiki Settings** (per-(tenant,project) auto-build
gate over `AutoWikiGate`, tri-state Inherit/On/Off, R43 both-states), and
**tier-badged chat citations** (every citation carries `generation_source`; auto
pages get an `auto` badge, R27 additive). Every screen ships Vitest + real-data
Playwright (R13), and every new endpoint joins the `AdminAuthorizationMatrix`
(R32). This **closes the Auto-Wiki epic** (P0–P10): a default-ON, tenant-safe,
reversible, fully-audited self-compiling knowledge tier behind the
anti-hallucination firewall, exposed PHP + HTTP API + MCP and now fully
administrable from the SPA. Every sub-PR went through an independent
code-reviewer pass + green CI before merge.

**v8.11.2 → v8.11.10 — Auto-Wiki cycle complete (the self-compiling agentic
knowledge tier).** Nine incremental releases finished the Auto-Wiki engine on top
of the v8.11.0/v8.11.1 foundations, each merged + tagged + released on its own:
**v8.11.2 evidence-tier** (an evidence-strength axis the compiler derives + the
RAG prompt weights, AutoSci #67), **v8.11.3 graph canonicalization** (auto
cross-refs → real `kb_edges`/`kb_nodes`, every auto doc gets an `auto-`-namespaced
slug so the whole corpus is navigable), **v8.11.4 concept-page synthesis** (new
`domain-concept` pages for recurring concepts, ingested via the one execution
path; ingest now honours a `generation_source: auto` frontmatter key),
**v8.11.5 wiki indices + operation log** (per-project roll-ups + a per-tenant
index hub in `kb_wiki_indices`), **v8.11.6 wiki lint/health** (dangling / orphan
/ stale-cross-ref / missing-index + safe auto-fix), **v8.11.7 agentic
graph-navigation** (multi-hop cycle-safe BFS, anchor-driven from the index —
`WikiNavigator`, the primary agentic surface), **v8.11.8 cross-model review /
novelty gate** (an independent review-LLM audits grounding / novelty /
contradictions before an auto page is trusted), **v8.11.9 apply engine**
(change/delete suggestions → audited, reversible mutations; manual + opt-in
auto-apply default-OFF), and **v8.11.10 scheduled maintenance** (a daily sweep
that rebuilds indices, lints, and backfills enrichment). Throughout, the
**anti-hallucination firewall holds** (human-`accepted` > `auto` > raw), every
capability is **tri-surface (R44)** — PHP command + HTTP API + MCP tool over one
shared core — and the `enterprise-kb` MCP server grew **14 → 24 tools**. The
remaining phase is the admin **Wiki Explorer / Health / Indices / Apply /
Settings** SPA (v8.12.0). ~115 PHPUnit across the cycle; every sub-PR went through
an independent code-reviewer pass + green CI before merge.

**v8.11.1 — Auto-Wiki Compiler** is the first functional increment of the
Auto-Wiki cycle: it actually *auto-builds* wiki metadata on ingest. After a
document is persisted, `IngestDocumentJob` dispatches the async
`AutoWikiCompilerJob` (gated by `AutoWikiGate`; version-idempotent so an
unchanged doc is never re-compiled) which asks the LLM — reading the document
plus its closest existing neighbours — to derive topical **tags**, a tight
**summary**, **aliases**, and **cross-references** (each with a `slug` + `why` +
`edge_type`), merged into `frontmatter_json._autowiki` with the doc marked
`generation_source='auto'`. The **firewall holds**: a human-curated canonical
document is never auto-edited (the authoritative tier keeps its human gate, ADR
0014), and every auto write is audited to `kb_canonical_audit` (actor
`system:autowiki`). The LLM call honours the **dedicated model override**
(`KB_AUTOWIKI_AI_PROVIDER`/`_MODEL`, empty → default chat). Default-ON
(`KB_AUTOWIKI_ENABLED`, R43 both-states — off ⇒ no enrichment, behaviour
unchanged). +12 PHPUnit (compiler enrichment / human-curated skip / model-override
/ empty-doc; job version-idempotency + gate composition).

**v8.11.0 — Auto-Wiki foundations** opens the Auto-Wiki / Agentic Knowledge
Compilation cycle (a Karpathy LLM-Wiki + AutoSci-inspired knowledge layer). It
lays the substrate for **auto-compiling** the typed canonical wiki on ingest
while preserving the platform's anti-hallucination moat. New: a second-class
**`auto` tier** (`knowledge_documents.generation_source ∈ {human, auto}`,
default `human`) for AI-compiled content; an anti-hallucination **reranker
firewall** (`kb.canonical.auto_tier_penalty`, default 0.02) so a human-`accepted`
doc on the same topic always outranks an auto-compiled one — and an `auto` doc
still outranks raw — wired through `KbSearchService` / `GraphExpander` /
`Reranker`; the layered **`AutoWikiGate`** (config → tenant `*` → project via
`kb_analysis_settings`, default-ON, R43 both-states); a **dedicated AI model
override** for the upcoming auto-compile + agentic-retrieval LLM calls
(`KB_AUTOWIKI_AI_PROVIDER`/`_MODEL`, `KB_AGENTIC_AI_PROVIDER`/`_MODEL` — empty
falls back to the default chat provider, so you can point auto-compilation at a
cheaper/smarter model than interactive chat); the **schema + config foundation for a source-retention policy**
(`KB_SOURCE_RETENTION` = `full_copy` | `markdown_only` | `reference_only`, global
+ per-connector) plus a `markdown_path` artifact column — the ingest wiring that
acts on the mode and writes the markdown artifact lands with the compiler in a
later v8.11.x release;
and **ADR 0014**, which *extends* (does not revoke) ADR 0003 — the human tier
keeps its human-gated promotion, while the `auto` tier is reversible, audited,
and promotable `auto → human`. This is the foundation release of the cycle; the
`AutoWikiCompiler` (frontmatter enrichment + concept-page synthesis), wiki
lint + per-tenant index hub, agentic graph-navigation retrieval, the apply
engine, and the admin Wiki surfaces ship as the subsequent releases. +10 PHPUnit.

**v8.10.1 — KITT security hardening + threat-model docs** is a focused
security patch on the embeddable widget. It adds a full **Security & threat
model** to [`docs/kitt/INTEGRATION.md`](docs/kitt/INTEGRATION.md) — what is
enforced and *why* (server-side tenant isolation, exact-match origin allowlist,
no-credential CORS, no host-page XSS, credential-field and navigation guards),
the **residual/inherent risks** of a public embeddable + agentic surface
(public-key abuse from a forged-origin script, prompt-injection-driven actions,
data egress to the LLM), and the **best practices the AskMyDocs operator and the
host site must follow to mitigate them** — including `data-kitt-skip` as the
host's data-egress control (keep sensitive page regions out of the snapshot);
the README KITT section links to it. It also **hardens the widget executor**:
`type()` previously refused only `password`/`hidden`, and now shares
`snapshot.ts::isSensitiveInput()` so it equally refuses `autocomplete`
`cc-*` / `current-password` / `new-password` — the agent can neither read nor
fill a credential/payment field via prompt injection. Finally it turns the
pentest scenarios into **fixed regression tests** (local + CI):
`WidgetSecurityTest` (exact-match origin allowlist vs look-alike / suffix /
subdomain / scheme-downgrade / port / userinfo origins; empty-allowlist
denies-all; `secret_hash` never serialized in `/setup`; anti-IDOR and
cross-tenant session containment → 404), `snapshot.security.test.ts`
(`data-kitt-skip` excludes fields / actions / headings, sensitive values
auto-nulled), and an extended `executor.security.test.ts`. The R40 local critic
caught the executor-guard gap pre-push. +11 PHPUnit + 7 Vitest.

**v8.10.0 — KITT Embeddable Agentic Widget** ships a one-`<script>`
embeddable, page-aware, agentic chat widget. A customer pastes a snippet
(`window.AskMyDocsWidget = { key: 'pk_…', apiBase: '…' }` + the async loader
at `/widget/askmydocs-widget.js`) and gets the **first-party retrieval stack**
— grounded, cited, tenant/project resolved server-side from the key (R30) —
inside a **bounded ReAct loop**: the widget captures a structured snapshot of
the host page (regions / fields / actions / messages / outline) and the LLM
emits tool calls run in the page DOM (~20 FE verbs — `click` / `type` /
`select` / `navigate_to` / `submit_form` / `wait_for` / …) or server-side via
`/exec-tool` (`search_knowledge_base`). Behaviour is governed by JSON **skill**
manifests (`resources/widget/skills/*`: `tools_enabled` + `auto_annotation_rules`
+ `default_policies`) and a double-gated **Host-Tools Protocol** (per key
`host_tools_enabled` **and** per skill) that lets a host app expose its own
tools; pages annotate with stable verb-based `data-kitt-*` attributes, and
`data-kitt-sensitive` / `password` / `hidden` values are force-nulled
server-side so secrets never reach the LLM or the step log. **Security**: three
auth modes (public `pk_` + exact-match `Origin` allowlist, `sk_` secret for
server-to-server, single-use **origin-bound** `wt_` session tokens consumed
atomically under a lock per R21 + hashed at rest + rate-limit checked *before*
burn); cross-key / cross-tenant session access is `404` (anti-IDOR); snapshot
byte + count caps; `javascript:` / `data:` / protocol-relative navigation
blocked on both server and client; PII masked on every persisted step (Italian
VAT masking checksum-validated so non-PII codes stay readable). 4 tables
(`widget_keys` / `widget_sessions` / `widget_session_steps` /
`widget_session_tokens`), a super-admin **admin SPA** at `/app/admin/widget`
(key CRUD / rotate / revoke + allowed-origins + theme designer + host-tools
toggle + copy-ready embed snippet + PII-masked session replay), and a
`widget:prune-sessions` retention sweep. Developer guide:
[`docs/kitt/INTEGRATION.md`](docs/kitt/INTEGRATION.md); local demo at
`/widget-demo` (`WIDGET_DEMO_ENABLED=true`).

**v8.9.0 — Tenant & Project Isolation Hardening** is a security-review
release: a deep audit of every content-surfacing path (chat answer, search,
JSON API, MCP tools, admin GUI) confirmed **tenant isolation is absolute** —
a user in one tenant can never see, retrieve, or have cited any document,
chunk, citation or graph node from another tenant — and fixed the handful of
residual gaps it surfaced: the SPA chat routes (`POST /conversations/{id}/messages`
+ the SSE variant) now carry `tenant.authorize` (closing an `X-Tenant-Id`
cross-tenant retrieval hole), the MCP read-by-id tools (`KbReadDocumentTool` /
`KbReadChunkTool`) are tenant-scoped, the admin Users CRUD enforces a
role-assignment privilege ceiling (an `admin` can no longer grant
`super-admin`), an admin-insights IDOR is closed, and the legacy Blade chat
sanitises Markdown through DOMPurify. It also introduces **opt-in per-project
isolation** (`KB_PROJECT_ISOLATION_ENABLED`, default **OFF** — no behaviour
change for existing deployments): when ON, the "see all projects" capability
moves from the blanket `kb.read.any` to a dedicated `kb.read.all_projects`
permission (admin/super-admin), and every other user is constrained to their
assigned `project_memberships` set (1..N projects), enforced uniformly across
chat, search, autocomplete and the admin KB surface so cross-project content
never appears in answers or citations. The admin Users → Project memberships
editor now derives its project picker from the live tenant project list. Both
flag states are covered by tests (R43).

See [`CHANGELOG.md`](CHANGELOG.md) for detailed release notes from
v1.0 through v8.8.3. **v8.8.3** is the **KB Lifecycle Intelligence — Plus**
cycle plus three follow-on releases: **v8.8.0** added the delete-trigger
deep-analysis, a per-(tenant, project) analysis gate, content-gap analytics,
per-query multilingual FTS, and the chat-side Related graph panel; **v8.8.1**
was a live-verification patch (real browser + live pgvector/OpenRouter caught
four bugs the mocked suites missed, incl. the dead chat Related panel and an
AI-Act iframe recursion → native panel); **v8.8.2** unified the admin navigation
into one grouped sidebar and made every sister-package admin mount center-only
(no nested chrome); and **v8.8.3** adds **anonymous chat** — a non-persisted,
authenticated chat that force-redacts PII (non-persistent mask) before
retrieval/LLM/log, writes only a minimal-or-no chat-log, and is feature-flagged
default-OFF (`KB_ANONYMOUS_CHAT_ENABLED`, both states defined per R43).
**v8.7.0** is the **KB Lifecycle Intelligence** cycle:
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
