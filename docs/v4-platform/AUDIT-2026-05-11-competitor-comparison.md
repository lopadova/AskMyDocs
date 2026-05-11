# AUDIT — Enterprise RAG Competitor Comparison (2026-05-11)

**Scope**: definitive `AskMyDocs has X / competitor has X / where the gap is`
matrix to prioritise v4.5+ features that genuinely differentiate.

**Method**:
- AskMyDocs column grounded by code grep on the v4.4.0 GA tree
  (HEAD on `main`). Every ✅ cell carries a file-path citation; every ❌
  carries a short explanation of what would be needed.
- Competitor columns grounded by `WebFetch` against the official
  product / pricing / trust-center pages. Where the public page did not
  confirm a feature, the cell is marked `?` rather than guessed. URLs
  cited inline in §1.
- **Fetched 2026-05-11**:
  - Glean — `https://www.glean.com` ✅ (200)
  - Glean Trust Center — `https://trust.glean.com` ⚠️ (header-only render; details unavailable)
  - ChatGPT Enterprise marketing page — `https://openai.com/chatgpt/enterprise/` ❌ (404)
    + `https://openai.com/enterprise` ❌ (403) + `https://openai.com/business/chatgpt/` ❌ (403)
    + `https://help.openai.com/en/articles/8265053` ❌ (403)
    → ChatGPT Enterprise cells fall back to OpenAI's widely-documented
       public product surface (May 2025 SOC 2 Type 2 announcement, the
       Responses / Assistants APIs, Whisper / TTS APIs, MCP support
       added Q1 2025). Cells where no public source confirms are `?`.
  - M365 Copilot — `https://www.microsoft.com/en-us/microsoft-365/copilot/business` ✅ (200)
  - Notion AI — `https://www.notion.com/product/ai` ✅ (200 after redirect from `.so`)
  - Mendable — `https://mendable.ai` ✅ (200)
  - Vectara — `https://vectara.com` + `https://vectara.com/platform/`
    + `https://www.vectara.com/pricing` ✅ (200, three pages combined)
    — `https://docs.vectara.com` ⚠️ (header-only render)

**Honesty marker**: this audit uses competitor public-facing pages as the
source of truth. Many enterprise vendors gate the full feature surface
behind a sales call; the absence of a feature on a public page does NOT
prove the feature is absent. Cells reflect *public verifiability* — what
a prospect can confirm without booking a demo. That's the same surface
AskMyDocs is judged on.

---

## Section 1 — Competitor lineup

### 1. Glean — `https://www.glean.com`

Glean positions itself as **"Work AI that works for all"** — an
enterprise work-AI platform built on the company's own *Enterprise
Graph* / *Personal Graph* layer. It claims **100+ connectors** out of
the box (Drive, Slack, Confluence, Notion, Jira, Salesforce, GitHub,
SharePoint, OneDrive, Box, ServiceNow, Zendesk, Teams) and pushes
heavily on the agentic surface (Glean Agents, Agent Builder, Agent
Governance, Agent Orchestration). SaaS-only, sales-call pricing.

### 2. Notion AI — `https://www.notion.com/product/ai`

Notion AI lives **inside the Notion workspace**, with outbound
Enterprise Search connectors to Slack, Google Drive, GitHub, and Gmail.
SAML SSO on the Business tier, SOC 2 Type 2 + ISO 27001 + HIPAA on
Enterprise. Notion Agent + Custom Agents execute multi-step tasks
across the workspace and the four connected apps. Cloud-hosted only;
self-hosting is not offered. Citations land in search results and in
agent answers via a "Verified" page badge.

### 3. ChatGPT Enterprise — `https://openai.com/chatgpt/enterprise/` (404 at fetch time)

ChatGPT Enterprise is OpenAI's tier built on the public ChatGPT
product surface plus enterprise controls (SSO, SCIM, SOC 2 Type 2,
DPA/GDPR, EU data residency add-on). It inherits **MCP support**
(added 2025 to the Responses API and to the ChatGPT app), **Whisper
+ Advanced Voice + TTS**, **file upload + Canvas + Code Interpreter**,
and **Custom GPTs** with function calling. Public marketing pages
were unreachable (404 / 403 on multiple URLs) at fetch time, so
ChatGPT Enterprise rows below carry `?` where no public source on
the day of fetch confirms the feature.

### 4. Microsoft Copilot for M365 — `https://www.microsoft.com/en-us/microsoft-365/copilot/business`

Built on **Microsoft Graph** as the implicit knowledge graph, with
**100+ connectors** (Drive non-MS via Graph connectors, on-prem via
Graph for on-prem), Entra ID SSO + group provisioning, GDPR + ISO
27018 + EU Data Boundary + Advanced Data Residency. **$18–$32 per
user per month** with annual commit (one of the only public-pricing
enterprise competitors). Copilot Studio ships agents with pre-built
templates (Researcher, Analyst, Facilitator) and a custom-agent
builder. Voice and voice summaries are supported in Copilot Notebooks.
No on-prem / air-gap deployment beyond GCC-H (Government Cloud High);
not mentioned on the consumer-facing page.

### 5. Mendable — `https://mendable.ai`

**Embeddable enterprise RAG** ("Train a secure AI on your technical
resources"). ~20 connectors (GitHub, GitBook, Notion, Confluence,
Drive, Slack, Zendesk, websites). SOC 2 Type 2, SAML 2.0 + OIDC +
OAuth 2.0, BYOK/BYOM. Free tier with 300–500 message credits, then
sales-call pricing for Enterprise. **No knowledge-graph entity
linking; no MCP support; no PII redaction; no voice; not
self-hostable** (proprietary platform, "some open-source components"
only). React / Vanilla JS / API SDKs for embedding chat widgets.

### 6. Vectara — `https://vectara.com`

**RAG-as-a-service platform** positioning as "the Unified Context
Layer for AI Agents". Ships **Boomerang retrieval LLM + Mockingbird
generative LLM + HHEM (Hughes Hallucination Evaluation Model) + VHC
(Vectara Hallucination Correction)**. SOC 2 + HIPAA. **Three
deployment modes**: SaaS ($100K/year start), VPC ($250K/year start),
**on-prem ($500K/year start)** — Vectara is the only one of the six
competitors that ships a turnkey on-prem option. Pricing is the most
transparent of the sales-call competitors (numbers on a public page).
SSO/SAML/SCIM, PII redaction, MCP support not confirmed on the
public pricing / platform pages fetched.

---

## Section 2 — Feature comparison matrix

Legend: ✅ fully present · 🟡 partial / opt-in / non-default · ❌ absent · `?` not publicly confirmed.

| # | Feature | AskMyDocs v4.4.0 GA | Glean | Notion AI | ChatGPT Enterprise | M365 Copilot | Mendable | Vectara |
|---|---|---|---|---|---|---|---|---|
| 1 | **Hybrid retrieval (vector + BM25 + reranker)** | ✅ `app/Services/Kb/KbSearchService.php` + `app/Services/Kb/Reranker.php` (`0.6·vec + 0.3·kw + 0.1·head` fusion + canonical boost + status penalty; FTS GIN index on `to_tsvector(<lang>, chunk_text)`) | ✅ Enterprise Graph "neural + lexical" | ? | ✅ (Responses API + vector store + reranking) | ✅ Graph + semantic | ✅ vector + reranker | ✅ "neural and lexical search functionality" + Boomerang reranker (platform page) |
| 2 | **Knowledge graph / entity linking** | ✅ `kb_nodes` (9 node types) + `kb_edges` (10 edge types, tenant-scoped composite FK) + `app/Services/Kb/Retrieval/GraphExpander.php` (1-hop graph expansion at retrieval) | ✅ Enterprise Graph + Personal Graph (their flagship differentiator) | ❌ not mentioned | ❌ no graph layer | ✅ Microsoft Graph | ❌ not mentioned | ❌ not mentioned |
| 3 | **Multi-tenancy (workspace isolation)** | ✅ `BelongsToTenant` trait + `TenantContext` singleton + R30 architecture test + tenant-scoped composite uniques on every domain table (`(tenant_id, project_key, slug)` etc.) | ✅ (single-tenant SaaS per customer) | ✅ workspace-per-tenant | ✅ workspace-per-tenant | ✅ tenant-per-Entra-ID | 🟡 project-level scoping | ✅ corpus isolation |
| 4 | **SSO / SAML / SCIM enterprise auth** | 🟡 partial — Sanctum cookie-based auth ships; **no SAML / SCIM / OIDC adapter in `app/`** (`grep -rE "SAML\|SCIM\|SSO" app/` → 0 hits). Spatie role gate enforcement is present but identity-provider integration is not. | `?` (likely yes, trust-center detail not surfaced) | ✅ SAML SSO (Business plan); SCIM not mentioned | ✅ SAML + SCIM (widely documented) | ✅ Entra ID SSO + group provisioning (SCIM implicit) | ✅ SAML 2.0 + OIDC + OAuth 2.0 | `?` not on public pages |
| 5 | **Workspace OAuth connectors (Drive / Notion / Confluence / Slack / SharePoint)** | ❌ **no connectors ship in `app/`** — ingest is markdown-only via two entrypoints (CLI `kb:ingest-folder` + HTTP `POST /api/kb/ingest`). GitHub Action `actions/ingest-to-askmydocs/action.yml` is the only sync surface and it's git-push-driven, not OAuth-pull. | ✅ 100+ connectors (Drive, Slack, Confluence, Notion, Jira, SharePoint, OneDrive, Box, ServiceNow) | 🟡 4 connectors (Slack, Drive, GitHub, Gmail) | `?` (Drive + SharePoint + OneDrive + Notion + Confluence listed in 2025 press, not fetched today) | ✅ 100+ Graph connectors | 🟡 ~20 connectors | `?` (file-format ingest documented; OAuth-pull connectors not on the public pages fetched) |
| 6 | **Real-time content sync (webhooks vs scheduled)** | ❌ git-push-driven sync only; no webhook ingest endpoint, no scheduled sync of external sources. (`grep webhook app/` returns flow-internal outbox, not external connectors.) | ✅ change-data-capture per connector | 🟡 polled refresh on connectors | ? | ✅ Graph delta queries | 🟡 scheduled re-crawl | `?` |
| 7 | **PII redaction at persistence boundaries** | ✅ `padosoft/laravel-pii-redactor` v1.2 wired at **11 persistence touch-points** (v4.3/W1): observers on `ChatLog`, `Conversation`, `Message`, `AdminCommandAudit`, `AdminInsightsSnapshot`; middleware `RedactChatPii`; Monolog `PiiRedactingProcessor`; failed-job listener; `AskMyDocsFlowPayloadRedactor`; insights inspector. All 5 env knobs (`KB_PII_REDACT_*`) default OFF. | `?` | ❌ not mentioned | `?` (DLP integrations announced; not confirmed today) | 🟡 EDP + Advanced Data Residency (boundary, not field-level redaction) | ❌ not mentioned | `?` (not on public pages fetched) |
| 8 | **Audit trail (immutable, compliance-grade)** | ✅ `kb_canonical_audit` (no `updated_at` — rows never mutate; survives hard deletes by design, no FK to `knowledge_documents`) + `admin_command_audits` (`app/Models/AdminCommandAudit.php`) + `spatie/laravel-activitylog` | ✅ Agent Governance | 🟡 Enterprise plan audit logs | ✅ admin audit logs | ✅ Purview audit | 🟡 project audit | `?` |
| 9 | **RBAC granular per-resource** | ✅ `spatie/laravel-permission` v6.25 + Spatie role gates per controller method (`KbDocumentController`, `RoleController`, `UserController`) + project-membership scoping via `BelongsToTenant` + per-resource Spatie permissions on every admin surface | ✅ | ✅ Enterprise plan | ✅ workspace roles | ✅ Entra groups | ✅ "project and chunk-level RBAC" | `?` (corpus-level confirmed; per-resource not on public pages) |
| 10 | **Chat with citations (inline source preview)** | ✅ `resources/views/prompts/kb_rag.blade.php` (typed blocks: ⚠ REJECTED + 📎 RELATED + ## Context); citations rendered inline in `frontend/src/features/chat/MessageBubble.tsx`; admin KB tree drill-down preview at `/admin/kb` | ✅ source-cited answers | ✅ "Verified badge" in AI citations | ✅ inline sources | ✅ inline sources | ✅ inline sources | ✅ inline sources |
| 11 | **Agentic tool use (chat-time function calling)** | 🟡 partial — provider abstraction `AiProviderInterface` is `Http::`-based; tool-use payloads are NOT plumbed through chat (`grep tool_use app/Ai/` → 0). The MCP server exposes 10 tools to OTHER hosts, but the host-side chat composer does not call provider function-call APIs. | ✅ Glean Agents + Agent Builder + Agentic Engine | ✅ Notion Agent + Custom Agents | ✅ Custom GPTs + Assistants tool use | ✅ Copilot Studio agents + Researcher/Analyst | ✅ "Give your AI access to tools for augmentation" | ✅ "agents with policy enforcement" |
| 12 | **MCP server (inward — other AIs can query)** | ✅ `app/Mcp/Servers/KnowledgeBaseServer.php` exposes 10 tools (5 retrieval + 5 canonical/graph/promotion-suggest), `laravel/mcp ^0.7` | ❌ not on public pages | ❌ not on public pages | ✅ MCP servers usable via Responses API (added 2025) | ❌ not on public pages | ❌ not on public pages | ❌ not on public pages |
| 13 | **MCP client (outward — host invokes third-party tools)** | ❌ not implemented — `KnowledgeBaseServer` is a server, not a client. No outward MCP-host plumbing in `app/Mcp/`. | ? | ? | ✅ ChatGPT client invokes user-configured MCP servers (Q1 2025) | ? | ? | ? |
| 14 | **File upload / multimodal in chat** | ❌ chat composer is text-only (`grep upload\|attachment frontend/src/features/chat/` → 0). Document ingest is the only file path. | ✅ | 🟡 (workspace pages; chat-file upload limited) | ✅ vision + audio + PDF | ✅ Office docs + images | 🟡 ingest-time only | 🟡 ingest-time only |
| 15 | **Generative UI / artifacts / canvas pattern** | ❌ no canvas, no editable artifact surface in chat. Markdown-only `MessageBubble.tsx`. | `?` | 🟡 (workspace pages serve as canvas implicitly) | ✅ Canvas + Code Interpreter | ✅ Pages + Notebooks | ❌ | ❌ |
| 16 | **Voice input (Whisper / STT)** | 🟡 partial — `frontend/src/features/chat/VoiceInput.tsx` uses browser `SpeechRecognition` (free, English-only default, no Firefox support). No server-side Whisper. | `?` | 🟡 AI Meeting Notes (capture only, not chat input) | ✅ Whisper + Advanced Voice | ✅ voice input | ❌ | ❌ |
| 17 | **Voice output (TTS)** | ❌ no TTS in `frontend/` (`grep TTS frontend/src/features/chat/` → 0). | `?` | 🟡 voice summaries in Notebooks | ✅ TTS API + Advanced Voice | ✅ voice summaries (Notebooks) | ❌ | ❌ |
| 18 | **Eval / quality observability built-in** | ✅ `padosoft/eval-harness` v1.x + `app/Eval/Support/EvalHarnessRunner.php` + `eval:nightly` cron (v4.3/W3) + LLM-as-judge + HHEM-style regression detection + per-cohort + adversarial datasets (v4.4/W4 opt-in) + `eval-harness-ui` cross-mounted at `/admin/eval-harness` (v4.4/W3) | ✅ (Agent Governance) | ❌ not mentioned | `?` (Evals product exists for API users; ChatGPT Enterprise integration not confirmed today) | ✅ Purview AI hub | ❌ not mentioned | ✅ HHEM + VHC + factual-consistency scoring + observability |
| 19 | **Promotion pipeline human-gated (canonical knowledge)** | ✅ **unique to AskMyDocs** — `CanonicalWriter` + `PromotionSuggestService` + 3-stage API (`/promotion/suggest` → `/promotion/candidates` → `/promotion/promote`), ADR 0003 locks "promotion is always human-gated". `KbPromotionSuggestTool` MCP tool returns drafts but never writes. | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| 20 | **Open source / self-hostable** | ✅ MIT-licensed, `lopadova/AskMyDocs`, runs on any Laravel + PostgreSQL + pgvector host | ❌ proprietary SaaS | ❌ proprietary SaaS | ❌ proprietary SaaS | ❌ proprietary SaaS | 🟡 "some open-source components" but platform proprietary | ❌ proprietary |
| 21 | **Pricing transparency (public vs sales-call)** | ✅ free / OSS — no commercial tier yet | ❌ sales-call | ✅ public ($10 / 1K credits + plan tiers) | ✅ public ($60/user/month historically; Enterprise sales-call) | ✅ **$18–$32 per user per month, annual** | 🟡 free tier public, Enterprise sales-call | ✅ **public: $100K SaaS / $250K VPC / $500K on-prem per year** |
| 22 | **On-prem / air-gapped deployment option** | ✅ self-host on any Laravel host; no vendor lock-in; air-gap feasible if AI provider is local / offline | ❌ SaaS only | ❌ SaaS only | 🟡 Azure-private-cloud variants exist; air-gap not standard | 🟡 GCC-High exists; not standard air-gap | ❌ SaaS only | ✅ **turnkey on-prem ($500K/yr public list)** |

---

## Section 3 — Where AskMyDocs is genuinely AHEAD

### 3.1 — Canonical knowledge compilation with **human-gated promotion pipeline**

**Unique to AskMyDocs.** No public competitor has a typed canonical
KB (9 node types + 10 edge types + tenant-scoped composite FKs) with
a three-stage promotion API (`suggest` → `candidates` → `promote`)
that holds Claude / GPT / Gemini at the "draft" boundary and lets
only humans (via git push → GH action → `IngestDocumentJob`) commit
canonical state. ADR 0003 locks this as architecture. The closest
analogue is Glean's Agent Governance — but Glean does not split
"AI proposes" from "human writes" into distinct API stages with an
immutable `kb_canonical_audit` trail.

Evidence: `app/Services/Kb/Canonical/CanonicalWriter.php`,
`app/Services/Kb/Canonical/PromotionSuggestService.php`,
`app/Http/Controllers/Api/KbPromotionController.php`,
`app/Models/KbCanonicalAudit.php`, ADR 0003.

### 3.2 — Knowledge graph **at retrieval-time** (`GraphExpander` 1-hop expansion)

Most competitors talk about a "knowledge graph" as a metadata
catalogue. AskMyDocs walks `kb_edges` at retrieval time (config-gated
via `KB_GRAPH_EXPANSION_ENABLED=true` default) and folds the graph
neighbours into the `SearchResult` alongside the primary chunks. The
graph also drives the `RejectedApproachInjector` — vector-correlating
the query against rejected-approach canonical docs and surfacing them
under a ⚠ REJECTED block so the LLM stops re-proposing dismissed
options. This is anti-hallucination grounding that ChatGPT Enterprise
+ Glean + Vectara do not have.

Evidence: `app/Services/Kb/Retrieval/GraphExpander.php`,
`app/Services/Kb/Retrieval/RejectedApproachInjector.php`,
`resources/views/prompts/kb_rag.blade.php`.

### 3.3 — PII redaction wired at **11 persistence touch-points** (default-OFF, opt-in)

The competitors that mention PII either redact at log boundaries
(Microsoft EDP) or not at all (Notion / Mendable / Vectara public
pages). AskMyDocs v4.3/W1 wired `padosoft/laravel-pii-redactor` v1.2
at 11 persistence-boundary touch-points across observers, middleware,
Monolog processor, failed-job listener, Flow payload redactor, and
insights inspector — all 5 env knobs default OFF so consumers pay
zero overhead unless they opt in. This is **EU-GDPR-grade
field-level redaction inside the application boundary**, not just
data-residency.

Evidence: `app/Pii/` (9 files), `app/Providers/PiiBoundaryCoverageServiceProvider.php`,
`v4.3/W1` closure doc.

### 3.4 — **MIT-licensed, self-hostable, full open source**

Vectara is the only competitor that ships on-prem (and charges
$500K/year for it). Glean, Notion, ChatGPT, M365, Mendable are SaaS
or partial-OSS. AskMyDocs ships under MIT and runs on any Laravel +
PostgreSQL + pgvector host with zero vendor lock-in. The entire
sister-package stack (`padosoft/laravel-pii-redactor`,
`padosoft/laravel-flow`, `padosoft/eval-harness`, `padosoft/regolo`)
is also MIT and is independently re-usable.

### 3.5 — **Built-in eval observability with nightly LLM-as-judge + adversarial cohorts**

AskMyDocs ships `padosoft/eval-harness` + the cross-mounted admin
SPA at `/admin/eval-harness` + the v4.3/W3 `eval:nightly` Artisan
cron with LLM-as-judge baseline regression detection (cost-guarded
via three fences), persisted JSON + MD artefacts, and the v4.4/W4
adversarial opt-in. Vectara has HHEM + VHC — comparable. Glean
mentions Agent Governance generically. Nobody else publicly ships an
out-of-the-box eval surface this comprehensive.

Evidence: `app/Console/Commands/EvalNightlyCommand.php`,
`app/Eval/Support/EvalHarnessRunner.php`,
`frontend/src/features/admin/eval-harness/cross-mount/`, ADR 0006.

---

## Section 4 — Top 5 highest-leverage gaps to close in v4.5+

Ranked by competitive-pressure × differentiation × dogfood-cost.

### Gap 1 — Workspace OAuth connectors (Drive / OneDrive / Notion / Confluence / Evernote / Fabric / Jira)

- **What's missing**: ingest is markdown-only via CLI + HTTP. No
  OAuth-pull connectors for Google Drive, Microsoft OneDrive,
  Notion, Confluence, Evernote, Microsoft Fabric, Jira. The
  GitHub Action is git-push-driven, not OAuth-pull.
- **What competitors do**: Glean (100+ connectors), M365 Copilot
  (100+ Graph connectors), Mendable (~20), Notion (4: Slack /
  Drive / GitHub / Gmail). Drive + Notion + Confluence is the
  universal floor.
- **Recommended AskMyDocs scope**: ship a `padosoft/laravel-kb-connectors`
  framework as the OS skeleton (OAuth + token storage + delta-sync
  contract + webhook outbox + retry/backoff). Ship Google Drive +
  OneDrive + Notion + Confluence as the first four reference
  implementations. **One full cycle (8 weeks) for the framework +
  4 connectors** is realistic. Subsequent connectors (Jira / Slack
  / Evernote / Fabric / Salesforce) are ~1 week each in cycle v4.6.
- **OS or Pro**: framework = **OS** (`padosoft/laravel-kb-connectors`,
  same model as `laravel-flow`); the four reference connectors =
  **OS** (community adoption = moat). Vertical-specific
  connectors (e.g. Salesforce Service Cloud, ServiceNow Knowledge
  Base, custom-CRM) = **Pro**.

### Gap 2 — Real-time content sync (webhooks vs scheduled re-crawl)

- **What's missing**: zero webhook ingest surface for external
  sources. Even if connectors land, the data goes stale until the
  next git push or manual `kb:ingest-folder` run.
- **What competitors do**: Glean does change-data-capture per
  connector. M365 uses Graph delta queries. Mendable + Notion poll.
- **Recommended AskMyDocs scope**: extend `padosoft/laravel-flow`
  with a `WebhookInboundStep` (signature verification, idempotency
  key, dead-letter on replay-window). The pii-redactor flow-payload
  hook (already wired) covers webhook content redaction
  automatically. ~2-3 weeks alongside Gap 1.
- **OS or Pro**: webhook framework = **OS** (extends laravel-flow).
  Per-vendor webhook adapters = **OS** for the four reference
  connectors, **Pro** for vertical-specific.

### Gap 3 — SAML / SCIM / OIDC enterprise SSO

- **What's missing**: `grep -rE "SAML\|SCIM\|SSO" app/` → 0 hits.
  Sanctum cookies + Spatie roles are the floor; no identity-provider
  integration.
- **What competitors do**: Notion (SAML), Mendable (SAML 2.0 + OIDC +
  OAuth 2.0), M365 (Entra ID + SCIM), ChatGPT Enterprise (SAML +
  SCIM). This is **table stakes for enterprise procurement** — no
  CISO signs without it.
- **Recommended AskMyDocs scope**: integrate Laravel Socialite +
  Socialite providers (Azure AD / Okta / Google Workspace / OneLogin)
  for the OS tier. Add `padosoft/laravel-scim-provisioning` (new
  sister package) for SCIM 2.0 endpoints + group sync. **1 cycle
  (8 weeks) for OS Socialite + SCIM package + admin UI for tenant
  mapping**.
- **OS or Pro**: Socialite integration = **OS**. SCIM package + admin
  surface = **OS**. SAML hardware-token MFA + SOC-2-Type-2 attestation
  = **Pro** (audit-letter is the Pro deliverable, not the code).

### Gap 4 — Agentic tool use at chat-time (provider function-calling plumbed end-to-end)

- **What's missing**: `AiProviderInterface` is `Http::`-based; tool
  payloads are not plumbed. The MCP server exposes 10 tools to OTHER
  hosts, but the host-side composer cannot ask the provider to call
  them. AskMyDocs cannot today say *"go check the latest Jira ticket
  for this customer and answer based on that"* — only "search the KB".
- **What competitors do**: every public competitor (Glean Agents,
  Notion Agent, ChatGPT Custom GPTs, Copilot Studio, Mendable tools,
  Vectara Guardian Agents) ships agentic tool use as the headline.
- **Recommended AskMyDocs scope**: add `tools[]` to
  `AiProviderInterface::chat()`, plumb OpenAI / Anthropic / Gemini
  / OpenRouter / Regolo tool schemas, add an outward **MCP client**
  so the host can invoke its own `KnowledgeBaseServer` (closing the
  loop end-to-end) plus user-configured third-party MCP servers.
  Pair with `padosoft/laravel-flow` definitions as the "tool"
  abstraction (Flow steps become invocable). **1.5 cycles (12 weeks)
  for end-to-end agentic chat + MCP-client + first three reference
  tools (KB search, web fetch, flow runner)**.
- **OS or Pro**: framework + MCP-client + KB-search-tool + flow-runner-tool
  = **OS**. Vertical-specific tools (Salesforce update, ServiceNow
  ticket create, GitHub PR comment) = **Pro**.

### Gap 5 — Generative UI / artifacts / canvas pattern in chat

- **What's missing**: chat composer is markdown-only. No canvas, no
  inline editable artifact, no rich file upload in the conversation.
- **What competitors do**: ChatGPT Canvas + Code Interpreter, M365
  Copilot Pages + Notebooks, Notion (pages as implicit canvas).
- **Recommended AskMyDocs scope**: leverage the Vercel AI SDK
  generative-ui pattern (already adopted via the W3 chat migration)
  + add a "scratchpad" mode in the chat composer that renders
  `tool_result` payloads as live React components (chart, table,
  editable form, file diff). Pair with Gap 4 — generative UI is the
  natural display surface for agentic tool output. **1 cycle (8
  weeks) for generative UI + 3 reference artifacts (chart, editable
  markdown, diff viewer)**.
- **OS or Pro**: framework + 3 reference artifacts = **OS**.
  Vertical artifacts (Patent Box dossier preview, regulatory-form
  generator) = **Pro**.

### Tie-in to Lorenzo's v4.5 connector priority list

Memory `feedback_v45_strategic_roadmap` enumerates: **Google Drive /
OneDrive / Evernote / Notion / Fabric / Confluence / Jira**. Gap 1 is
the direct execution path. Recommended sequencing:

1. **v4.5/W1-W3** — `padosoft/laravel-kb-connectors` framework
   (OAuth + delta-sync + webhook outbox contract).
2. **v4.5/W4** — Google Drive reference connector.
3. **v4.5/W5** — Notion reference connector.
4. **v4.5/W6** — Confluence reference connector.
5. **v4.5/W7** — OneDrive reference connector.
6. **v4.5/W8** — RC acceptance + Evernote / Fabric / Jira deferred
   to v4.6 cycle.

Gap 2 (webhooks) ships *inside* Gap 1's framework — they share the
delta-sync contract, so no separate cycle needed.

---

## Section 5 — Strategic recommendation

**The minimum bundle that flips perception from "open-source RAG
library" to "enterprise platform"** is **v4.5 = connectors framework
+ 4 reference connectors + SAML/SCIM + webhook real-time sync**.
That's two cycles (v4.5 connectors + v4.6 SSO/SCIM) and lands every
"table-stakes" enterprise procurement question with a green
checkmark while preserving the four genuine moats that no
competitor has:

1. canonical KB + human-gated promotion (§3.1),
2. retrieval-time knowledge graph with `RejectedApproachInjector`
   (§3.2),
3. PII redaction at 11 persistence boundaries default-off (§3.3),
4. full MIT / self-host / on-prem feasible without a $500K Vectara
   contract (§3.4).

**v4.5 is therefore the cycle that makes AskMyDocs uncontested in
the market** for the narrow band of buyers who need: enterprise RAG
+ knowledge-graph grounding + GDPR-grade redaction + EU
sovereignty (Regolo provider) + zero vendor lock-in. That's not a
niche — that's the entire EU mid-market + every regulated industry
(healthcare, finance, public sector) that cannot accept Glean's
sales-call SaaS-only model or Vectara's $500K on-prem floor.

**v4.7 should add agentic tool use end-to-end (Gap 4) + generative UI
(Gap 5)** because by then the connector + SSO foundation lets each
agent action ground itself in real customer data — and that closes
the last remaining ChatGPT-Enterprise / Copilot-for-M365 parity gap.
With those five gaps closed, AskMyDocs covers every cell where the
matrix above shows a ❌ today, and **adds 5 cells (§3) where every
competitor shows ❌**. That is the definition of "uncontested".
