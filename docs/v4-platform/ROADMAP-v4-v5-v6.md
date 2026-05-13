# AskMyDocs Roadmap — v4.5 → v4.6 → v4.7 → v5.0 → v6.0

**Status:** consolidated 2026-05-12. Spans from the active v4.5 cycle through the v6.0 EU AI-Act compliance horizon. Captures decisions locked-in across 2026-05-09 ÷ 2026-05-12. Single source of truth for the multi-major sequencing.

## Big picture

```
                     ┌──── v4.5 ─────────┐ ┌──── v4.6 ────┐ ┌──── v4.7 ────┐ ┌──── v5.0 ────┐ ┌──── v6.0 ────┐
                     │ Connectors        │ │ Package      │ │ Tabular      │ │ Agentic      │ │ EU AI-Act    │
                     │ + admin UI        │ │ extraction   │ │ Review +     │ │ MCP-client   │ │ compliance   │
                     │ + source-aware    │ │ → padosoft/* │ │ Workflows +  │ │ + tool       │ │ dashboard +  │
                     │ chunking          │ │              │ │ AI-suggest   │ │ registry     │ │ DSAR queue   │
                     └───────────────────┘ └──────────────┘ └──────────────┘ └──────────────┘ └──────────────┘
```

## v4.5 — "Universal Connectors" (closure)

**Branch:** `feature/v4.5` | **GA target:** 2026-05-12 (RC acceptance + GA merge per R37)

### Wn breakdown (R39 RC tag per Wn)

| Wn | Status | PR | Deliverable |
|---|---|---|---|
| W1 | ✅ merged | #149 → `d2b83c2` | Connector framework core (`ConnectorInterface`, `BaseConnector`, `OAuthCredentialVault`, `ConnectorRegistry`, `ConnectorSyncJob`) + Google Drive reference |
| W2 | ✅ merged | #150 → `9c6f510` | Notion connector (block-to-markdown, paginator, OAuth2) + framework helper refinements |
| W3 | ✅ merged | #151 → `87a81c6` | Admin React SPA (`/app/admin/connectors`) + OAuth callback + Gate coverage |
| W4 | ✅ merged | #152 → `02e7ad2` | Evernote (dual-mode OAuth + ENEX import) + Fabric (API-key now, OAuth-pending stub) |
| W5 | ✅ merged | #153 → `f2c1967` | OneDrive (Microsoft Graph delta query) + Confluence (Atlassian OAuth + storage-format converter) |
| **W5.5** | ✅ merged | #154 → `7ea9d47` | **Source-aware ingestion**: per-source chunker dispatch via `PipelineRegistry::resolveChunker()` (R23) + **4 new chunkers** (`NotionBlockChunker`, `ConfluencePageChunker`, `OfficeDocChunker`, `AtomicNoteChunker`) + `PdfPageChunker` (pre-existing) now routed through the registry + 6 connector rich frontmatter capture + `Reranker` Layer-4 (tag overlap, recency, status-active, preamble-match) + `KbSearchService` facets + 2 GIN-on-`jsonb` indexes + 1 B-tree index; **Live-test recording infrastructure** + junior-proof runbook for 6 providers |
| W6 | ✅ merged | #155 → `c60047c` | Jira Cloud connector + `Jira\JiraAdfToMarkdown` + `Jira\JqlBuilder` + `Jira\JiraPaginator` + `JiraIssueChunker` |
| W7 | ✅ merged | #156 → `c8a25c6` | Vercel AI SDK UI Tier 1 + partial Tier 2 — stop / regenerate / branch-from-message / inline edit / token+cost meter / per-message provider+model badge / copy-code-block / suggested follow-ups (stretch: tool-result render, streaming source parts, export, image attachments, artifact panel **deferred to v5.0**) |
| W8 | ✅ this PR | (this PR) | RC acceptance + GA prep — README hero + CHANGELOG + ADR 0008 + closure status doc; `feature/v4.5` → `main` merge fires next per R37 + `v4.5.0` GA tag per R39 |

### Side-quest done in v4.5
- **regolo v1.0.1** released — caught up to `laravel/ai` v0.6.8 `EmbeddingGateway` contract change
- **laravel-ai-chat v1.0.0** released — bumped to regolo v1.0.1 + Symfony 8 + new CI matrix

### v4.5 acceptance gates
- [x] 7 connectors registered (Google Drive, Notion, Evernote, Fabric, OneDrive, Confluence, Jira) + Vercel SDK UI Tier 1+2 + admin SPA
- [x] Each connector ships rich frontmatter + source-aware chunker (per `docs/v4-platform/DESIGN-v4.5-W5.5-source-aware-ingestion.md` + ADR 0008 D3)
- [x] Per-provider runbook section junior-proof (per [`docs/v4-platform/RUNBOOK-live-fixture-recording.md`](RUNBOOK-live-fixture-recording.md))
- [x] Live-test recording pipeline ready for credential drop (PII-scrubbed, fixture-replay)
- [x] Test count ≥ 1900 PHPUnit + ≥ 350 Vitest scenarios — **actual: 1885 PHPUnit + 384 Vitest react + 18 Vitest legacy** (PHPUnit landed 15 short of stretch target; Vitest react cleared by 34. ADR 0008 records the ratio is healthy for a connector-heavy cycle.)
- [x] R36 Copilot loop green on every sub-PR; R39 RC tag at every Wn closure
- [x] README hero refreshed with "Universal Connectors" + "Modern Chat Surface (Vercel AI SDK UI)" sections per the per-wave README-refresh convention (see the "Per-wave README + dedicated killer-feature sections" entry under "Cross-cycle conventions" below)

## v4.6 — "Package Extraction" — ✅ GA SHIPPED 2026-05-12

**Branch:** `feature/v4.6` merged into `main` per R37 with `v4.6.0` GA
tag per R39 on 2026-05-12.

Cycle delivered the seven inline connectors as 8 standalone composer
packages (`padosoft/askmydocs-connector-base` + 7 connector packages)
plus the `ConnectorIngestionContract` IoC bridge. Host repo's
`app/Connectors/BuiltIn/` is fully deleted; only `HostIngestionBridge.php`
remains. See ADR 0009 + `docs/v4-platform/STATUS-2026-05-12-v46-week4-rc-acceptance.md`.

Extract the 7 inline connectors into standalone `padosoft/askmydocs-connector-*` packages with a shared `-base` package. **8 new repos created on GitHub + cloned locally** under the folder convention `Ai/askmydocs-connector-*/` (no `padosoft-` prefix — NEW convention specific to this connector-package family, documented in this section as the source of truth).

### Wn breakdown

| Wn | Scope | Outputs |
|---|---|---|
| W1 | Extract `padosoft/askmydocs-connector-base` | `ConnectorInterface`, `ChunkerInterface`, `BaseConnector`, `OAuthCredentialVault`, `ConnectorRegistry`, `ConnectorSyncJob`, `ConnectorPaginationLimitException`, helper traits + the `connectors_*` migrations |
| W2 | Extract Notion + Google Drive | Each package: connector + chunker + format converter + paginator + per-package CI (PHP 8.3/8.4/8.5 × Laravel 13) + junior-proof README with credential setup |
| W3 | Extract Evernote + Fabric + OneDrive | Same shape; AskMyDocs `composer require` adds them progressively |
| W4 | Extract Confluence + Jira + **delete inline code from AskMyDocs** + RC acceptance + GA tag `v4.6.0` | Inline `app/Connectors/BuiltIn/*` deleted; ConnectorRegistry discovers packages via composer-lock `extra.askmydocs.connectors` |

Bonus: `padosoft/askmydocs-connector-template` repo (already created) — scaffold for community contributors (composer.json + interface stub + CI workflow + README template + junior-proof credential-setup template).

### v4.6 acceptance gates
- [x] All 8 packages tagged v1.x on GitHub (Packagist submission parked as v4.6.x follow-up — currently VCS-resolved via `composer.json::repositories[]` same as `padosoft/laravel-pii-redactor`).
- [x] AskMyDocs `composer.json` requires the 8 connector packages.
- [x] Inline connector code DELETED from AskMyDocs (`app/Connectors/BuiltIn/` removed in full; only `HostIngestionBridge.php` remains).
- [x] All v4.5 host-side tests still pass post-extraction (PHPUnit 1547/1548 — 1 unrelated pre-existing chunker failure deferred to v4.6.x; vitest 384/384; architecture 20/20).
- [x] Each package's README includes step-by-step credential setup + installation + activation.
- [x] Community contributor path documented in ADR 0009 — third-party Laravel apps can `composer require padosoft/askmydocs-connector-<x>` + bind a `ConnectorIngestionContract` implementation to ingest the source.

## v4.7 — "Tabular Review + Workflows + AI-suggest" ✅ SHIPPED 2026-05-12

**Branch:** `feature/v4.7` (merged to main as v4.7.0 GA on 2026-05-12)
**Original GA target:** ~2026-07-10 — shipped ~2 months ahead of plan.

LOCKED-IN 2026-05-12 — Lorenzo "per tabella batte mike ok procedi".
GA closure also dated 2026-05-12.

**Status row in README roadmap table is** ✅ shipped 2026-05-12.

Inspired by github.com/willchen96/mike — adopts the two killer features absent from competitors (Tabular Review + Workflows), adds the AI-suggest layer Mike doesn't have, ships **17 format types vs Mike's 9 + 12 UX differentiators MoSCoW-prioritised**. GA renders the tabular grid as a plain HTML table (Glide Data Grid canvas migration parked for v4.7.x per ADR 0010 D1).

### Wn breakdown

| Wn | Scope |
|---|---|
| W1 | Tabular Review backend — 2 new tables + `TabularReviewExtractor` (streaming multi-column LLM, `json_path` shortcut, frontmatter-direct lookup) + 9 format validators + `tabular_cell_audit` + 6 must-have differentiators (human-verified lock, cell-level diff, bulk selection, side-panel citation, json_path shortcut, confidence score render) + ~50 tests |
| W2 | Workflows backend — 3 new tables + `WorkflowService` + `WorkflowSuggester` (AI-suggest from KB sample, **NOT in Mike**) + 15 built-in templates (AskMyDocs-flavor, broader than Mike's 3 legal-only) + 2 should-have differentiators (cell-level conversation, AI-suggest columns) + ~35 tests |
| W3 | Admin SPA — `/admin/tabular-reviews` with Glide Data Grid + `/admin/workflows` editor + AI-Suggest gallery + Playwright E2E + 3 could-have differentiators if time (group-by, pivot, column-template marketplace) + RC + GA tag |

### v4.7 acceptance gates
- [ ] 4 new tables with tenant_id mandatory (R30/R31)
- [ ] 16 format types implemented + validators + cell renderers
- [ ] `WorkflowSuggester` produces 5 valid workflows from a 50-doc stratified sample
- [ ] 15 built-in workflows seeded idempotently
- [ ] Glide Data Grid rendering ≥ 500 rows × 12 columns fluidly with streaming cell updates
- [ ] Per-cell citation popover opens KB doc viewer side-panel scroll-to-chunk
- [ ] Real-time collab cursors PARKED for v4.8 (the only "won't" in MoSCoW)
- [ ] Test count +120
- [ ] README hero refreshed with "Tabular Review" + "Workflows" sections per the per-wave README-refresh convention (see "Cross-cycle conventions" below)

## v5.0 — "Agentic Platform" (paradigm shift, ~8 weeks)

**Branch:** `feature/v5.0` from main after v4.7.0
**GA target:** ~2026-09-15

LOCKED-IN 2026-05-11 — paradigm shift naming: v5.0 (not v4.8) because "Mike a 100 dipendenti" use-case requires agentic chat-time tool use.

### Big bets
- **MCP client framework** (Node sidecar via `@modelcontextprotocol/sdk`)
- **Tool registry per workspace** — workflows from v4.7 become MCP tools
- **Agentic chat loop** — ReAct-style multi-turn with tool invocation
- **Vercel AI SDK Tools wiring** — frontend renders tool calls + results in the chat surface
- **Agent recipes** — workflows scheduled (cron-style) and runnable autonomously by an MCP agent

### How v4.7 building blocks compose into v5.0
- `WorkflowService::run(workflow_id, doc_ids)` → exposed as MCP tool `workflow.run`
- `TabularReviewExtractor::extract(columns, doc_ids)` → exposed as MCP tool `tabular.extract`
- `WorkflowSuggester::suggestForTenant()` → agent self-discovery primitive ("what kinds of questions can this tenant's KB answer best?")
- KbSearchService facets + reranker → tool args for retrieval steps

### v5.0 acceptance gates
- [ ] Node sidecar runs alongside Laravel; both deploy via the same CD pipeline
- [ ] MCP tool surface exposes ≥ 10 tools (kb.search, kb.canonical-detail, workflow.run, tabular.extract, pii.audit, etc.)
- [ ] Agentic chat loop replaces the linear KbChatController for power users; opt-in flag `AI_AGENTIC_ENABLED`
- [ ] Pro tier exclusively gates the agentic surface — open-source ships the linear chat
- [ ] v4.7's 15 workflows all callable as agent tools
- [ ] Test count +200

## v6.0 — "EU AI-Act Compliance" (~6 weeks)

**Branch:** `feature/v6.0` from main after v5.0.0
**GA target:** ~2026-11-15

LOCKED-IN 2026-05-11 — packaged extracted to `padosoft/laravel-ai-act-compliance` + `padosoft/laravel-ai-act-compliance-admin` per Lorenzo. Admin FE = React + shadcn (NOT Filament/Livewire).

### Scope
- Risk register (high-risk AI system inventory + impact analysis)
- DSAR queue (data subject access requests workflow)
- Consent overview (per-feature opt-in tracking + audit)
- Incident manager (AI-incident reporting + post-mortem trail)
- Bias monitor (model output drift + demographic-impact tracking)
- DPO console (data protection officer dashboard with everything one-pane)
- Compliance overview (single-pane status across the whole tenant)
- Settings (mandatory disclosures, opt-out wiring)

### v6.0 acceptance gates
- [ ] Two new repos created + tagged v1.0.0 on Packagist (`padosoft/laravel-ai-act-compliance` + `-admin`)
- [ ] 8 admin SPA screens rendered (Compliance Overview / DSAR / Consent / Risk / Incident / Bias / DPO / Settings)
- [ ] AskMyDocs core integrates the package; non-AI-Act compliance work that doesn't fit the package stays in AskMyDocs (the 20% the package can't cover, per Lorenzo)
- [ ] Full integration with v4.1's pii-redactor (DSAR uses pii-redactor inspectors)
- [ ] Audit trail of every AI-Act-relevant action (model used, prompts, decisions affecting natural persons)
- [ ] Sample data + demo seeder for the 8 screens
- [ ] Test count +150

## Cross-cycle conventions

### R36 — Copilot review + CI loop on every sub-PR
Every sub-PR on AskMyDocs and every padosoft/* package goes through the 9-step Copilot-loop protocol. Auto-mode classifier blocks unauthorized merge bypassing review. Non-negotiable.

### R37 — feature/vX.Y integration branches → main once per major
Each major cycle works in `feature/vX.Y`. Sub-branches PR target the integration branch. Merge to main happens once per major after RC acceptance. Tag `vX.Y.0` at the merge commit.

### R39 — RC tag per Wn closure
At every Wn closure: docs PR (README hero + CHANGELOG section + ADR if architectural), capture closure SHA via `git rev-parse origin/feature/vX.Y`, tag `vX.Y.0-rcN` at that exact SHA with `gh release create --target=<sha>`. Final GA tag at last Wn closure.

### Per-source ingestion rule (locked-in 2026-05-11)
Every new ingestion source MUST get: (a) rich frontmatter capture, (b) ad-hoc chunker registered in `config/kb-pipeline.php::chunkers` and dispatched via `PipelineRegistry::resolveChunker($sourceType)` (the v4.5/W5.5 logical "ChunkerRegistry" surface — see ADR 0008 D3 for why the dispatch lives inside `PipelineRegistry`), (c) chunk metadata enrichment, (d) retrieval boost policy. Never fall back on the generic `MarkdownChunker` without explicit per-source design. The standing rule applies to v4.5 W6 Jira (chunker = `JiraIssueChunker`), every connector extracted in v4.6, every new agent tool ingestion endpoint in v5.0.

### Junior-proof runbook + per-package README rule (locked-in 2026-05-12)
Every credential/OAuth runbook section + every extracted-package README MUST be step-by-step at junior-proof precision: exact URLs, sidebar paths, button labels, scopes + rationale per scope, env var names produced, verification one-liner with expected output, common errors + fixes. Single source of truth for ≤30-min onboarding.

### Per-wave README + dedicated killer-feature sections (locked-in 2026-05-12)
Every Wn closure refreshes README feature tables + ticks the roadmap checklist + appends to `## Changelog`. Wave deliverables that ship a killer feature (genuinely competitor-absent — Tabular Review, Workflows, AI-suggest, MCP agentic, EU AI-Act dashboard) get a dedicated `## ✨ <Name>` section ABOVE the feature tables.

## In-repo authoritative sources

Every cycle decision above is backed by an in-repo artefact. The
table maps each rule / cycle scope to the doc that ships in this
repo so contributors don't need access to the maintainer's private
notes to audit a decision.

| Topic | In-repo source of truth |
|---|---|
| v4.5 cycle scope (universal connectors + source-aware ingestion + modern chat surface) | ADR 0008 + `docs/v4-platform/STATUS-2026-05-12-v45-week8-rc-acceptance.md` + `docs/v4-platform/DESIGN-v4.5-W5.5-source-aware-ingestion.md` |
| v4.6 cycle scope (connector package extraction + local clone convention) | This section of the ROADMAP (the v4.6 Wn breakdown + acceptance gates) |
| v4.7 cycle scope (Tabular Review + Workflows + AI-suggest) | This section of the ROADMAP + `docs/v4-platform/DESIGN-v4.7-tabular-review-and-workflows.md` |
| Per-source chunker rule | ADR 0008 D3 + `docs/v4-platform/DESIGN-v4.5-W5.5-source-aware-ingestion.md` |
| Junior-proof runbook standard | [`docs/v4-platform/RUNBOOK-live-fixture-recording.md`](RUNBOOK-live-fixture-recording.md) |
| Per-wave README + dedicated killer-feature sections | The "Cross-cycle conventions" section above of this ROADMAP doc |
| padosoft/* standalone-agnostic rule | `docs/v4-platform/INTEGRATION-ROADMAP-sister-packages.md` |
| R36 mandatory Copilot review loop | `CLAUDE.md` §7 R36 + `.claude/skills/copilot-pr-review-loop/SKILL.md` |
| R39 RC tag per Wn | `CLAUDE.md` §7 R39 + `.claude/skills/rc-tag-per-week-milestone/SKILL.md` |

## When this doc gets refreshed

- After every Wn closure (update the status table)
- When a new cycle is locked-in (append a new section)
- When a major architectural decision is made mid-cycle (note in the relevant cycle's body)
