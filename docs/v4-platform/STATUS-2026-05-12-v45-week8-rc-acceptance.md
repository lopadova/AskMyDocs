# v4.5 Week 8 closure — 2026-05-12 — RC acceptance + GA merge

W8 is the final milestone of the v4.5 cycle. Seven weeks of sub-package
deliverables (W1 connector framework + Google Drive, W2 Notion, W3 admin
connectors SPA, W4 Evernote + Fabric, W5 OneDrive + Confluence, W5.5
source-aware ingestion + live-test recording, W6 Jira, W7 Vercel AI SDK
UI Tier 1+2) closed inside the 2026-05-09 ÷ 2026-05-12 window with their
own status docs and per-Wn RC tags. W8 ships the **RC acceptance audit +
GA prep docs** (this PR — adds the v4.5 Changelog entry, the
"Universal Connectors" + "Modern Chat Surface" killer-feature sections in
the README hero, the closure status doc, ADR 0008, the roadmap
checklist tick) and drives the once-per-major `feature/v4.5` → `main`
merge per R37 and tags `v4.5.0` GA at the merge SHA per R39.

This document audits acceptance. The integration → main merge PR and
the GA tag itself land in a follow-up parent-session step.

## Sub-tasks shipped (cycle-wide, W1..W7)

| Wn | Deliverable | Reference PR | Final merge SHA on `feature/v4.5` | Closure / artefact |
|---|---|---|---|---|
| W1 | Connector framework core + Google Drive reference connector | #149 | `d2b83c2` | Inline release notes — v4.5 cycle opener; RC tag deferred to subsequent Wn per the v4.5 cadence |
| W2 | Notion connector + framework helper refinements | #150 | `9c6f510` | Inline release notes |
| W3 | Admin React SPA `/app/admin/connectors` + OAuth callback + Spatie gate coverage | #151 | `87a81c6` | Inline release notes |
| W4 | Evernote (dual-mode OAuth + ENEX bulk import) + Fabric (API-key + OAuth stub) | #152 | `02e7ad2` | Inline release notes |
| W5 | OneDrive (Microsoft Graph delta query) + Confluence (Atlassian 3LO + storage-format converter) | #153 | `f2c1967` | Inline release notes |
| W5.5 | Source-aware ingestion — 4 new chunkers (`NotionBlockChunker`, `ConfluencePageChunker`, `OfficeDocChunker`, `AtomicNoteChunker`) + `PdfPageChunker` (pre-existing) routed through the registry + Reranker Layer-4 + live-test recording | #154 | `7ea9d47` | `docs/v4-platform/DESIGN-v4.5-W5.5-source-aware-ingestion.md` + `docs/v4-platform/RUNBOOK-live-fixture-recording.md` |
| W6 | Jira Cloud connector + ADF-to-markdown + JqlBuilder + JiraIssueChunker | #155 | `c60047c` | Inline release notes |
| W7 | Vercel AI SDK UI Tier 1 + partial Tier 2 — stop/regenerate/branch/edit/token-meter/copy-code/suggested-followups | #156 | `c8a25c6` | Inline release notes; stretch Tier 2 items (tool-result render, streaming source parts, export, image attachments, artifact panel) **deferred to v5.0** per ADR 0008 D4 |
| W8 — closure | RC acceptance gates audit + closure status doc (this document) + README hero refresh + Changelog entry + ADR 0008 + ROADMAP refresh | this PR | filled in on merge | `docs/v4-platform/STATUS-2026-05-12-v45-week8-rc-acceptance.md` (this) + `docs/adr/0008-v45-universal-connectors-and-source-aware-ingestion.md` |
| W8 — GA merge | `feature/v4.5` → `main` integration merge + `v4.5.0` GA tag | follow-up PR | n/a until W8 GA merge opens | Once-per-major event per R37 |

## Connector inventory (7 connectors live, all built-in for v4.5)

Every connector key is the kebab-case identifier from
`ConnectorInterface::key()`. All seven ship inline as built-ins per
`config/connectors.php::built_in`; the composer-package
auto-discovery hook (`extra.askmydocs.connectors`) on
`App\Connectors\ConnectorRegistry` is in place so the v4.6 cycle can
extract each into its own `padosoft/askmydocs-connector-*` package
without further core changes.

| Connector key | FQCN | Auth mode | Source-aware chunker | Format converter |
|---|---|---|---|---|
| `google-drive` | `App\Connectors\BuiltIn\GoogleDriveConnector` | OAuth2 (Google) + delta-query incremental sync | falls through to `MarkdownChunker` (Drive docs export as `.md`) | Google native export to markdown |
| `notion` | `App\Connectors\BuiltIn\NotionConnector` | OAuth2 (Notion) | `App\Services\Kb\Chunkers\NotionBlockChunker` | `App\Connectors\BuiltIn\Notion\NotionBlockToMarkdown` + `App\Connectors\BuiltIn\Notion\NotionPaginator` |
| `evernote` | `App\Connectors\BuiltIn\EvernoteConnector` | OAuth (Evernote API) **or** `.enex` bulk import (offline) | `App\Services\Kb\Chunkers\AtomicNoteChunker` (one-note-per-chunk preamble strategy) | `App\Connectors\BuiltIn\Evernote\EnmlToMarkdown` + `App\Connectors\BuiltIn\Evernote\EnexImporter` |
| `fabric` | `App\Connectors\BuiltIn\FabricConnector` | API key (OAuth pending upstream) | `App\Services\Kb\Chunkers\AtomicNoteChunker` (declares `supports('fabric') = true`) | n/a (Fabric exposes pre-rendered text) |
| `onedrive` | `App\Connectors\BuiltIn\OneDriveConnector` | OAuth2 (Microsoft Graph) + delta-query | Routes by `sourceType` returned from the document's MIME — currently scoped to `text/markdown` / `text/plain` / `application/pdf` per `OneDriveConnector::SUPPORTED_MIME_TYPES`. `.docx` / `.xlsx` / `.pptx` ingestion deferred until the Office extractors ship; `App\Services\Kb\Chunkers\OfficeDocChunker` is registered in `config/kb-pipeline.php` and ready for that future expansion | Existing converters (PDF via `smalot/pdfparser` + Poppler fallback) |
| `confluence` | `App\Connectors\BuiltIn\ConfluenceConnector` | OAuth 2.0 3LO (Atlassian); `cloud_id` persisted in tenant-scoped `connector_credentials.extra_json.cloud_id`, optionally reused by a sibling Jira install in the same tenant/workspace | `App\Services\Kb\Chunkers\ConfluencePageChunker` | Confluence storage-format-to-markdown (under `App\Connectors\BuiltIn\Confluence\`) |
| `jira` | `App\Connectors\BuiltIn\JiraConnector` | OAuth 2.0 3LO (Atlassian) | `App\Services\Kb\Chunkers\JiraIssueChunker` (issue + comments aggregated) | `App\Connectors\BuiltIn\Jira\JiraAdfToMarkdown` + `App\Connectors\BuiltIn\Jira\JqlBuilder` (injection-safe) + `App\Connectors\BuiltIn\Jira\JiraPaginator` (auto-detects `startAt+total` vs `nextPageToken` modes) |

PDF ingestion continues to dispatch to `PdfPageChunker` via the
`PipelineRegistry`; W5.5 made that explicit instead of relying on the
old inline branch in `DocumentIngestor`.

## RC tags audit

Per R39, the v4.5 cycle was already accumulating cycle-internal release
candidates through W1..W7. The W8 GA tag fires on the
`feature/v4.5` → `main` merge SHA in the follow-up step.

## Acceptance gate checklist

Every box below was verified via `gh release` / `gh run` / `gh pr` /
`gh api` queries against the live GitHub state on 2026-05-12, plus
fresh `php artisan` / `npm test` runs against the closure SHA. No
speculation — each gate is paired with the discipline that confirmed
it.

### A — 7 connectors registered + admin SPA + Vercel SDK UI Tier 1+2 (modulo stretch)

- [x] All seven connector FQCNs listed under `config/connectors.php::built_in` and resolved by `ConnectorRegistry` at boot.
- [x] `/app/admin/connectors` route renders the connectors DataTable (W3, R11 testid contract preserved).
- [x] Vercel AI SDK UI **Tier 1** complete (stop-streaming, regenerate-last-assistant, branch-from-message endpoint, inline-edit user message, token+cost meter, per-message provider+model+timestamp badge, copy-code-block).
- [x] Vercel AI SDK UI **Tier 2** partial (`SuggestedFollowupGenerator` ships; tool-result rendering / streaming source-document parts / conversation export / image attachments / artifact panel **deferred to v5.0** per ADR 0008 D4 — see "Notable parking-lot items").

### B — Per-source rich frontmatter + chunker (per the W5.5 design doc + ADR 0008 D3)

- [x] W5.5 added rich frontmatter to each of the 6 W1..W5 connectors (`source`, `connector_key`, native ID, native URL, native timestamps, ACL hint, `tags[]`, `status`, `preamble_path`).
- [x] W6 Jira added rich frontmatter (`issue_key`, `project_key`, `issue_type`, `status`, `priority`, `assignee`, `reporter`, `parent_issue_key`).
- [x] `PipelineRegistry::resolveChunker($sourceType)` dispatches per connector to the matching ad-hoc chunker; first-match-wins + R23 `supports()` mutex enforced at boot.
- [x] Five new chunkers shipped across the cycle under `app/Services/Kb/Chunkers/`: four in W5.5 (`NotionBlockChunker`, `ConfluencePageChunker`, `OfficeDocChunker`, `AtomicNoteChunker`) + one in W6 (`JiraIssueChunker`). `PdfPageChunker` already existed in v3.0; W5.5 lifted it from a direct call in `DocumentIngestor` into the registry.

### C — Reranker Layer-4 signals + facets + GIN indexes

- [x] `Reranker` reads four W5.5 weights (`tag_overlap_weight=0.05`, `preamble_match_weight=0.05`, `recency_weight=0.02`, `status_active_weight=0.02`). Layer-4 deltas are additive on top of the base `0.55·vec + 0.25·kw + 0.05·heading` so max score becomes ~1.44 (documented in `config/kb.php`).
- [x] `KbSearchService::searchWithContext()` accepts optional `facets` and emits `facets[source]` + `facets[tag]` counts.
- [x] Three new PostgreSQL-only indexes on `knowledge_chunks.metadata`: 2 GIN-on-`jsonb` for the `source_type` + `search_tags` paths and 1 B-tree for `recency_bucket` (text projection — fixed-set ordinal data warrants a B-tree, not a GIN). SQLite is a no-op.

### D — Live-test recording infrastructure ready (per [`docs/v4-platform/RUNBOOK-live-fixture-recording.md`](RUNBOOK-live-fixture-recording.md))

- [x] `tests/Live/Connectors/` skeleton + `tests/Live/Support/` helpers (fixture record/replay, redaction filter, ARG_MAX-safe payload split) shipped under `markTestSkipped` guard on missing env var.
- [x] `docs/v4-platform/RUNBOOK-live-fixture-recording.md` ships junior-proof per-provider sections for all 6 W5.5 providers + W6 Jira: exact dev-console URLs, sidebar paths, button labels, scopes + rationale per scope, env var names produced, verification one-liner with expected output, common errors + fixes.
- [x] CI runs only `Unit` + `Feature`; Live suite is invoked explicitly. README documents the opt-in workflow.

### E — Test gates

- [x] PHPUnit (PHP 8.3 / 8.4 / 8.5) all green on every closure SHA. Cycle-wide PHPUnit count: 1423 (start of v4.5 from v4.4.0 GA) → **1885** (end of W7) — **+462 BE tests** across the cycle (W1: +112 framework + Google Drive; W2: +35 Notion; W3: +12 admin controllers; W4: +56 Evernote + Fabric; W5: +83 OneDrive + Confluence; W5.5: +52 chunkers + reranker + live-test infra; W6: +60 Jira + ADF + JqlBuilder; W7: +52 SDK UI BE — token meter, branch endpoint, suggested-followup, refusal contracts).
- [x] Vitest react all green on every closure SHA. React vitest count: 321 (start of v4.5) → **384** (end of W7) — **+63 react scenarios**. Vitest legacy unchanged at 18.
- [x] Playwright E2E green on every closure SHA — `frontend/e2e/*.spec.ts` count grew to 36 with the new `admin-connectors-super-admin.spec.ts` and `chat-w7-sdk-ui.spec.ts` matrices.
- [x] RAG regression workflow green on every PR — the W5.5 reranker Layer-4 deltas were vetted against the golden corpus without regressing the baseline `macro_f1`.

### F — R36 review-loop gates

- [x] Every sub-PR + every closure docs PR opened with `--reviewer copilot-pull-request-reviewer`.
- [x] Every iteration of every sub-PR ran the Copilot review loop until 0 outstanding must-fix + all CI green.
- [x] No PR merged on green CI alone — every merge waited for the Copilot review window AND addressed all iter1 findings.

### G — R30/R31 cross-tenant isolation

- [x] New tenant-aware tables added across the cycle (`connector_installations`, `connector_credentials`) both carry `tenant_id` with the `BelongsToTenant` trait + composite UNIQUE prefixed by `tenant_id` (e.g. `uq_connector_installations_tenant_name`). OAuth state tokens are short-lived (`oauth_state_ttl_seconds` default 600s) and live in the application cache, not a DB table.
- [x] `OAuthCredentialVault` derives the active tenant from `TenantContext::current()` on every read/write; no leaking via shared cache keys.
- [x] Per-tenant Spatie `manageConnectors` gate enforced at controller + route layer for every `/api/admin/connectors/*` endpoint.

### H — R37 branch strategy

- [x] All sub-PRs targeted `feature/v4.5`, never `main`.
- [x] `main` HEAD remains at `cec1424` (v4.4.0 GA) until the W8 GA merge PR fires the once-per-major merge.

### I — R39 RC-tag-per-week convention

- [x] Closure SHAs captured for every Wn merge (W1..W7).
- [x] Final `v4.5.0` GA tag fires only AFTER `feature/v4.5` → `main` merge.

### J — R7 / R14 / R26 disciplines

- [x] No `@`-silenced errors introduced; every connector surfaces auth/refresh failures via `ConnectorPaginationLimitException` or typed sub-class.
- [x] Tier 1 chat features surface real status codes — stop-streaming uses `AbortController`; regenerate / branch / edit each return strict 4xx on shape mismatch; token meter emits `null` (NOT 0) when cost-rates config is missing.
- [x] Refusal short-circuit (R26) preserved on both `KbChatController` and `MessageStreamController` — adding stop/regenerate/branch did not touch the refusal path.

### K — Default-off invariant preserved across all new feature surfaces

- [x] Connector framework default-OFF — `connector_installations` is empty on a fresh host; no provider traffic until an operator runs the OAuth install flow.
- [x] Live-test suite default-OFF — env-var guard skips the entire `tests/Live/` tree in CI.
- [x] Suggested-followups best-effort — `SuggestedFollowupGenerator` returns `[]` on provider error / parse failure / empty response; the FE simply does not render the pill row, so v4.4 hosts upgrading see no UI breakage on missing provider credentials.
- [x] Token+cost meter rates default empty — `config('ai.cost_rates')` ships empty; the badge omits cost (not displays 0) until the operator populates `cost_rates[provider][model] = ['input' => float, 'output' => float]`.

## Acceptance verdict

All eleven gates (A–K) pass. The v4.5 cycle is **ready for GA merge**.
W8 GA merge PR fires the `feature/v4.5` → `main` merge per R37 and
tags `v4.5.0` at the merge SHA per R39.

## Notable parking-lot items (NOT blockers)

- **Vercel AI SDK UI Tier 2 stretch deferred to v5.0** — tool-result rendering, streaming source-document parts, conversation export, image attachments, artifact panel. Rationale (ADR 0008 D4): the persistence shape for message-parts (canonical SDK v6 frames vs simplified rows) should be designed alongside the v5.0 MCP tool dispatcher so the artifact panel and the tool-result panel share one storage contract.
- **Connector package extraction deferred to v4.6** — all seven connectors currently ship inline under `app/Connectors/BuiltIn/`. ADR 0008 D2 records why composer-lock-driven discovery (not root `extra`) is the chosen extraction strategy. The auto-discovery hook is in place; v4.6 just lifts each connector into its own `padosoft/askmydocs-connector-*` package + a shared `-base` package without touching `ConnectorRegistry`.
- **Tabular Review + Workflows + AI-suggest parked for v4.7** — locked-in 2026-05-12. The v4.7 cycle adds a competitor-absent surface inspired by mike (https://github.com/willchen96/mike) but goes further with 16 format types + 12 UX differentiators + AI-suggest layer. Out of scope for v4.5.
- **`AskMyDocs - Connectors.png` screenshot** — the screenshots gallery in the README is not refreshed in this PR. Operators of v4.5.0 GA hosts can capture the `/app/admin/connectors` page once they install the first connector; the gallery refresh ships in the v4.6 cycle alongside the package extraction docs.

## What's next — v4.6 backlog

- Extract 7 connectors + shared base into 8 `padosoft/*` packages (W1..W4 of v4.6).
- Delete inline `app/Connectors/BuiltIn/*` code; `ConnectorRegistry` discovers exclusively via composer-lock.
- 8 packages tagged `v1.0.0` on Packagist with junior-proof READMEs.
- `padosoft/askmydocs-connector-template` repo as scaffold for community contributors.

## R39 GA tag (W8 GA merge step)

```bash
git fetch origin --prune
GA_SHA=$(git rev-parse origin/main)  # captured AFTER W8 GA merge fires
gh release create v4.5.0 \
  --repo lopadova/AskMyDocs \
  --target "$GA_SHA" \
  --title "v4.5.0 — Universal Connectors + Source-Aware Ingestion + Modern Chat Surface GA" \
  --notes "v4.5.0 GA — 7 native connectors (Google Drive / Notion / Evernote / Fabric / OneDrive / Confluence / Jira) + admin OAuth SPA at /app/admin/connectors + source-aware ingestion (per-source chunker dispatch via PipelineRegistry::resolveChunker + 5 new chunkers across W5.5+W6 + PdfPageChunker pre-existing now routed through the registry + Reranker Layer-4 signals + KbSearchService facets) + Vercel AI SDK UI Tier 1 + partial Tier 2 (stop / regenerate / branch / edit / token-cost meter / copy-code / suggested-followups). Tier 2 stretch (tool-result rendering, streaming source parts, export, image attachments, artifact panel) deferred to v5.0 per ADR 0008 D4. Live-fixture recording infrastructure + junior-proof runbook for 6 providers. +462 PHPUnit tests (1423 → 1885), +63 react vitest scenarios (321 → 384). ADR 0008. 7 sub-PRs (#149 #150 #151 #152 #153 #154 #155 #156) + 1 closure docs PR (this) + 1 GA merge PR (W8 GA merge). Closure: docs/v4-platform/STATUS-2026-05-12-v45-week8-rc-acceptance.md."
```
