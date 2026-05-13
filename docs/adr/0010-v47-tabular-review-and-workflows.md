# ADR 0010 — v4.7 Tabular Review + Workflows + AI-suggest

- **Status**: accepted
- **Date**: 2026-05-12
- **Cycle**: AskMyDocs v4.7 (W1 + W2 + W3, three-week cycle, GA on 2026-05-12)
- **Authors**: Lorenzo + Claude orchestration

## Context

v4.7 introduces two new domain capabilities and an AI-assisted
discovery layer on top of the v4.5 source-aware ingestion + v4.6
package extraction substrate:

- **Tabular Review** — spreadsheet-style document extraction. The
  user defines a list of *columns* (one extraction prompt + format
  per column) and runs the review over N documents in a project. The
  result is a 2D grid of cells, each carrying a structured
  `{summary, flag, reasoning, citations[]}` payload.
- **Workflows** — reusable prompt templates (assistant or tabular)
  with email-based sharing + per-user hidden-on-this-account
  preference, plus an AI-suggester that mines the tenant's KB for
  candidate workflow proposals.

The design baseline was [github.com/willchen96/mike](https://github.com/willchen96/mike)
— a legal-vertical review tool inspired by Harvey AI. The v4.7
generalisation lifts the feature out of the legal vertical so it is
usable by every project_key in a tenant.

## Decisions

### D1 — Glide Data Grid → plain HTML table for GA

**Decision**: ship the v4.7 GA grid as a plain HTML `<table>` rather
than the canvas-based `@glideapps/glide-data-grid`.

**Rationale**:
- Glide Data Grid is a heavy dependency (~250 KB minified + a
  significant peer-dep tree). Adding it to the FE bundle before the
  grid surface has user adoption inverts the cost/benefit: most
  v4.7-GA tenants will not ship reviews with thousands of cells where
  canvas-grid performance dominates.
- The flag-tint + reasoning tooltip + side-panel UX is identical
  between an HTML table and a canvas grid for the GA-visible use
  cases. Column-resize / virtualised scrolling / drag-to-reorder are
  the affordances Glide adds — all deferred to v4.7.x.
- Shipping HTML first lets us measure real tenant grid sizes BEFORE
  paying the bundle-weight tax of the canvas grid. The migration to
  Glide (or to a virtualised-row alternative) becomes a data-driven
  v4.7.1 decision.

**Consequences**:
- Performance ceiling at ~5k cells per page before the
  HTML-table-with-data-attributes-on-every-cell approach starts
  hitting paint budgets. Mitigated by the `cell_limit` / `cell_offset`
  paging on `GET /api/admin/tabular-reviews/{id}` (default cap 2000,
  hard ceiling 10000) — the FE pages cells before the grid even
  renders.
- Column-resize, drag-to-reorder, frozen first column are deferred to
  v4.7.x.

### D2 — `json_path` LLM-free shortcut

**Decision**: a column with `format: json_path` and a non-null
`json_path` value short-circuits the LLM call and reads the cell
value directly from the document's chunk metadata at the given path.

**Rationale**:
- v4.5/W5.5 source-aware ingestion populates rich `metadata` on each
  ingested document (connector type / external_id / native
  timestamps / tags / status / preamble path). Re-asking the LLM to
  extract data that the ingestion path already harvested is
  wasteful — both cost and latency.
- A reviewer building a tabular review of (say) Jira issues that
  already has `status` + `priority` + `assignee` in the document
  metadata gets these columns for free, instantly, with `flag = grey`
  (sourced-from-metadata, not generated). The LLM-backed columns
  layer on top of the shortcut columns.

**Consequences**:
- The `enum FormatType::isLlmFree()` predicate gates the dispatch.
  Other formats (text / number / date / etc.) always go through the
  LLM batch path.
- A new format requiring shortcut behaviour adds itself to
  `isLlmFree()` AND surfaces `json_path` in its column config UI.

### D3 — `WorkflowSuggester` as AskMyDocs differentiator vs Mike

**Decision**: ship `WorkflowSuggester` as a first-class v4.7 feature,
not an experimental flag.

**Rationale**:
- Mike has 15 hard-coded legal workflows. AskMyDocs has the
  *metadata* of the tenant's actual KB — connector type, document
  count distribution, recurring practice tags, recurring entity
  patterns — so it can *propose* workflows aligned with the tenant's
  data.
- The proposals are draft-only. The user always reviews + accepts
  via `/from-proposal`. No silent auto-creation.

**Consequences**:
- `MetadataPatternAnalyzer` is now a public API surface — third
  parties (or v5.0 MCP tools) can consume the same analysis to feed
  agentic flows.
- Adversarial input on the KB metadata (a malicious doc with a 100KB
  metadata blob) is constrained at the analyzer layer — only the top
  N most-frequent patterns are passed to the LLM.

### D4 — Email-based sharing model for Workflows

**Decision**: `workflow_shares.shared_with_email` is the share key,
NOT `shared_with_user_id`.

**Rationale**:
- A common case is sharing a workflow with an invitee who is not yet
  a user on the platform. Forcing a user-id linkage means we cannot
  pre-share — share-then-invite-then-join becomes
  invite-then-join-then-share.
- The email is matched against the recipient's primary email at sign-
  in time; the share materialises as a "Shared with me" row the
  first time the recipient logs in.
- R30 tenant isolation is preserved because the workflow itself
  carries `tenant_id`; the share is tenant-scoped via the workflow's
  FK.

**Consequences**:
- The share table holds email strings indefinitely. A future "GDPR
  redact-email-shares" job sweeps unmatched shares after a retention
  window.
- Mis-typed emails surface as `Shared with me` rows that never
  resolve. Acceptable — the workflow owner sees who they shared with
  and can re-share if a typo is suspected.

### D5 — AI-suggest trigger paths

**Decision**: the AI-suggest gallery is on-demand only in v4.7 GA.
First-run + weekly + recurring-query trigger paths are scaffolded in
the W2 backend but not surfaced in the W3 SPA.

**Rationale**:
- First-run is meaningless on a brand-new tenant with no KB yet — the
  analyzer has nothing to suggest from. The user clicking "Get
  suggestions" after their first ingest run is the right trigger.
- Weekly + recurring-query depend on a scheduler job + a user-prefs
  table that does not yet exist. v4.7.x adds both with no GA risk.
- Manual trigger is the lowest-blast-radius integration of the LLM
  cost path.

**Consequences**:
- The `WorkflowSuggester` service is callable from any future trigger
  path (Artisan command + queued job + middleware) — the trigger
  surface is mechanical.
- The "fresh suggestions" indicator on the admin shell is deferred
  to v4.7.x.

## R36 / R37 / R39 compliance

- R37 — `feature/v4.7` merges to `main` once at GA; W1 + W2 + W3
  sub-PRs target the integration branch.
- R39 — `v4.7.0-rc1` (W1) + `v4.7.0-rc2` (W2) + `v4.7.0-rc3` (W3
  closure) prerelease tags; `v4.7.0` GA tag at the integration→main
  merge SHA.
- R36 — Copilot review loop on every sub-PR. GA-merge PR also runs
  the Copilot pass.

## Deferred to v4.7.x

- Glide Data Grid migration (D1).
- Column-resize / drag-to-reorder / frozen first column on the
  Tabular Review grid.
- Workflow edit + share modal + use-as-template path in the admin
  SPA shell.
- AI-suggest first-run / weekly / recurring-query trigger paths
  (D5).
- `Tests\Feature\Kb\Chunking\JiraIssueChunkerTest::comments_section_aggregates_into_separate_chunk`
  fix — pre-existing on `feature/v4.7` baseline, unrelated to the v4.7
  cycle.
