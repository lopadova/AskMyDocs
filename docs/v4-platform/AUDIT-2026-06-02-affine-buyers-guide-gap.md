# AUDIT — Affine "Knowledge Base Software Buyer's Guide" (2026) — line-by-line gap map

**Source:** `affine.pro/blog/knowledge-base-software` ("Knowledge Base Software
Buyer's Guide: Evaluate Tools in 2026", published 2025-11-07). Lorenzo supplied
the full clipped text (`obsidianlore/.../Knowledge Base Software Buyer's Guide…md`)
after the live page returned HTTP 402 to the automated fetch.

**Method:** every concrete evaluation criterion / checklist item in the guide is
mapped to AskMyDocs **v8.7.0 GA** (the cycle that just shipped Synonyms, weekly
digest + stale-review, AI deep-analysis-on-change, and Cloud Time Machine).
Legend: ✅ covered · 🟡 partial / opt-in · ❌ gap.

> **Headline:** the v8.7 cycle landed squarely on three of this guide's marquee
> criteria — **Synonym Expansion** is the guide's verbatim line *"Enter
> industry-specific terms and their synonyms—does the AI connect them?"* (§AI
> Search Relevance); the **Review Cadence / archival policy / automated review
> reminders** governance section is exactly our `kb:stale-review-sweep`; and
> **versioning + rollback** is the Cloud Time Machine. So this audit mostly
> confirms strong coverage and isolates the few real remaining gaps.

---

## §Core Capabilities You Should Expect

| Guide criterion | AskMyDocs v8.7.0 | Notes |
|---|---|---|
| Content authoring & management (collaborative create/edit/organize, templates) | 🟡 | RAG-over-markdown by design: ingest via git/CLI/HTTP/7 connectors + an inline admin source editor (`SourceTab`/`updateRaw`). NOT a collaborative WYSIWYG authoring suite with templates/multimedia. **By positioning** (we're a grounded-answer engine, not a wiki editor) this is a deliberate non-goal, not a defect. |
| Advanced search (keyword + semantic + intent) | ✅ | Hybrid pgvector + FTS + reranker; **+ Synonym Expansion (v8.7)**. |
| Permissions & roles | ✅ | Spatie RBAC + per-doc ACL + project membership + tenant isolation. |
| Versioning & audit trails | ✅ | **Cloud Time Machine (v8.7)** + immutable `kb_canonical_audit` + activity log. |
| Analytics & reporting (usage, **content gaps**, satisfaction) | 🟡 | AiInsights + admin dashboard + `repeat_questions_30d` + chunk-feedback (thumbs). **No explicit "content-gap / search-failure → backlog" report** (see Gap 2). |
| Integrations (CRM, helpdesk) | 🟡 | 7 OAuth connectors (Drive/OneDrive/Notion/Confluence/Jira/Evernote/Fabric). **No CRM/helpdesk** (Zendesk/Salesforce/Slack-surface) — Pro-tier (Gap 3). |

## §Evaluation Criteria table (Decision Criteria That Matter Most)

| Criterion | Status | Evidence / gap |
|---|---|---|
| Search Quality — supports **synonyms** | ✅ | `kb_synonyms` + `SynonymExpander` (v8.7), Admin → Synonyms. |
| Authoring Experience — drafts, review, approval | 🟡 | The **human-gated promotion pipeline** (ADR 0003: suggest → candidates → promote) IS the draft→review→approve gate; no template/multimedia editor. |
| Permissions & Security — granular, RBAC, approval flows, audit | ✅ | RBAC + ACL + promotion approval + immutable audit. |
| Analytics & Reporting — content gaps | 🟡 | See Gap 2. |
| Scalability & Extensibility — integrations, API, import/export | 🟡 | REST API + connectors ✅; **bulk content export (CSV/JSON/XML/HTML)** is thin (Gap 4). |

## §Governance and Content Lifecycle Playbook

| Guide item | Status | Notes |
|---|---|---|
| RACI / article ownership | 🟡 | Roles + project membership exist; no explicit per-article RACI owner field/workflow. **Owner-notify on stale/analysis is approximated** by the v8.7 reviewer notifications. |
| Editorial standards + versioning | ✅ | Canonical YAML frontmatter standards + Time Machine versioning/rollback. |
| **Review Cadence + Archival Policy** (scheduled reviews, **automated reminders when due**, **archive not delete**) | ✅✅ | **Direct v8.7 match**: `kb:stale-review-sweep` = automated "needs review" reminders past `KB_HEALTH_STALE_REVIEW_MONTHS`; archived versions retained (not deleted) = Time Machine; soft-delete + retention. |
| Lifecycle states (Draft→Review→Approve→Publish→Periodic Review→Archive) | ✅ | `canonical_status` (draft/review/accepted/superseded/deprecated/archived) + promotion gate + stale-review periodic trigger. |

## §AI Search Relevance and Hallucination Control

| Guide item | Status | Notes |
|---|---|---|
| Embedding-based search ("reset password" ↔ "change login credentials") | ✅ | Semantic vector retrieval. |
| Query intent handling (ambiguous/multi-part) | ✅ | Rerank + graph expansion. |
| **Synonym Expansion** (industry terms + synonyms — "does the AI connect them?") | ✅✅ | **Verbatim guide line → shipped in v8.7/W1.** |
| Multilingual scenarios | 🟡 | Per-doc `language` column exists but FTS uses a single config language; per-query language detection is roadmap **R24** (Gap 5). |
| Grounded generation (cite sources every answer) | ✅ | Inline citations + evidence chunks. |
| Content scope controls (limit AI to verified/up-to-date) | ✅ | Canonical boost + status penalty + retrieval filters. |
| Threshold scoring (cosine cutoff, escalate if not confident) | ✅✅ | Anti-hallucination **refusal gate** (`RetrievalGrounding`). |
| Prompt engineering (role/task/output) | ✅ | `kb_rag.blade.php` typed-block prompt. |
| Show source + flag when no relevant source | ✅ | Citations + refusal reason. |
| Feedback loops (thumbs, **missed queries**) | 🟡 | Chunk thumbs ✅ + `repeat_questions`; **missed/failed-query analytics** is Gap 2. |
| Admin controls (customizable synonyms) | ✅ | Admin → Synonyms CRUD (v8.7). |

## §Integrations, APIs, and SSO

| Guide item | Status | Notes |
|---|---|---|
| API rate limits | 🟡 | Sanctum; no documented per-route rate-limit policy. |
| Webhook coverage (create/**update**/delete events) | 🟡 | **Outbound** webhook notification channel ✅; **inbound** webhook real-time connector sync = Gap 6 (competitor-audit Gap 2). |
| SDK availability | ❌ | No client SDKs (Gap 7, minor). |
| Export/Import (CSV/JSON/XML/HTML) | 🟡 | Ingest markdown; export PDF/JSON per-doc; **bulk content export** thin (Gap 4). |
| **SSO/SCIM (SAML, OAuth, SCIM)** | ❌ | **Biggest enterprise gap** — flagged in Integrations AND Security AND Provisioning sections. Competitor-audit Gap 3, Pro-tier (Gap 1). |
| Native connectors (Slack, Zendesk, Salesforce, SharePoint) | 🟡 | Have Drive/OneDrive/Notion/Confluence/Jira/Evernote/Fabric; Slack is a *notification* channel; **Zendesk/Salesforce/SharePoint-as-source** = Gap 3. |
| Provisioning / Backup / Portability | 🟡 | Self-host = portability ✅; SCIM provisioning ❌; backup = ops concern. |

## §Security, Compliance, Data Governance

| Guide item | Status | Notes |
|---|---|---|
| Encryption at rest/in transit | 🟡 (ops) | HTTPS + DB-level encryption are deployment concerns. |
| RBAC | ✅ | |
| SSO/SCIM | ❌ | Gap 1. |
| Audit logs (immutable) | ✅ | `kb_canonical_audit` (never mutated) + `admin_command_audits`. |
| Incident response / monitoring | 🟡 | `padosoft/laravel-ai-act-compliance` ships an incident state machine. |
| Regular backups | 🟡 (ops) | |
| SOC 2 / ISO 27001 / GDPR | 🟡 | **GDPR field-level PII redaction ✅** (`laravel-pii-redactor`, 11 touch-points); SOC2/ISO = attestation (Pro). |
| DLP | 🟡 | PII redactor ≈ field-level DLP. |
| Data residency | ✅ | Self-host → choose region; Regolo provider for EU sovereignty. |

## §Metrics Dashboards That Prove Value

| Guide KPI | Status | Notes |
|---|---|---|
| Ticket deflection rate | ❌ | Needs helpdesk integration (Gap 3). |
| Article helpfulness score (thumbs) | ✅ | Chunk feedback. |
| Search success / **failure** rate | 🟡 | Per-query retrieval stats in `meta`; no dashboard KPI for success/failure (Gap 2). |
| Article update frequency | ✅ | KbHealthService age + stale-review (v8.7). |
| Contributor activity | 🟡 | Partial via audit/activity log. |
| Average time-to-answer | 🟡 | `latency_ms` tracked; not a dashboard KPI. |
| **Content gaps** (search failures → backlog items) | ❌ | **Recurring gap** — "track search failures weekly → backlog" (Gap 2). |

## §Implementation / Migration & §Zettelkasten

| Guide item | Status | Notes |
|---|---|---|
| Content audit & mapping (freshness, duplicates, canonical tag) | 🟡→✅ | Canonical tagging ✅; freshness ✅ (stale-review); **duplicate/obsolescence detection ✅ NEW** (v8.7 deep-analysis impacted-docs). |
| Migration tools | ✅ | Connectors + GH ingest action. |
| Atomic notes | ✅ | Chunking + `domain-concept` canonical type. |
| **Bidirectional links** | ✅ | `kb_edges` + wikilinks + `GraphExpander` (retrieval-time graph). |
| Emergent topics / **graph view** | 🟡 | Admin KB graph viewer ✅; **chat-side graph panel** = roadmap R10 (Gap 8). |
| Connection density | ✅ | Graph + rejected-approach injection. |

---

## Prioritised remaining gaps (what the guide surfaces that v8.7 does NOT cover)

| # | Gap | Why it matters (guide section) | Suggested tier / effort |
|---|---|---|---|
| **1** | **SSO / SCIM (SAML / OAuth / SCIM)** | Flagged in Integrations + Security + Provisioning — table-stakes for enterprise procurement ("no CISO signs without it") | Socialite (Azure AD/Okta/Google) = **OS**; SCIM package + admin = **OS**; attestation = Pro. ~1 cycle |
| **2** | **Content-gap / search-failure analytics** ("track search failures weekly → backlog") | Metrics + AI-feedback + Analytics sections all flag it; we already log refusals + repeat-questions, so the data exists | **OS**, ~1 week — a `search_failures` rollup (refused/zero-result queries) → admin "Content Gaps" panel → one-click "draft article" via the existing promotion-suggest |
| **3** | **Helpdesk/CRM integrations + ticket-deflection metric** (Zendesk/Salesforce/Slack-surface/SharePoint-source) | Integrations + Metrics | Vertical connectors = **Pro** |
| **4** | **Bulk content export / portability** (CSV/JSON/XML/HTML) + scheduled backup | Provisioning/Portability "red flag: export blocked" | **OS**, ~few days — a `kb:export` command + admin download |
| **5** | **Per-query/per-doc multilingual FTS** | "ask in different languages, check consistency" | Roadmap **R24**, ~1 week |
| **6** | **Inbound webhook real-time connector sync** | "Webhook coverage: create/update/delete events" | Extends `laravel-flow` `WebhookInboundStep` (competitor-audit Gap 2), ~2-3 weeks alongside connectors |
| **7** | Client SDKs + documented API rate-limits | "SDK availability / API rate limits" | minor / OS |
| **8** | **Chat-side related-graph panel** | "graph views for easier navigation" | Roadmap **R10**, ~L |

**Deliberate non-goals (positioning, not gaps):** collaborative WYSIWYG authoring + templates +
multimedia editing (we are a grounded-answer RAG engine, not a wiki editor); offline/local-first
(server app).

**Recommendation:** Gaps **2** (content-gap analytics — cheap, the data already exists) and **1**
(SSO/SCIM — the enterprise unlock) are the two highest-leverage next moves. Both are OS-tier and
differentiate without touching the four moats (canonical+human-gated promotion, retrieval-time graph,
field-level PII redaction, MIT self-host).
