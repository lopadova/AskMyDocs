# AskMyDocs Changelog

All notable changes per major release cycle. Follows the v4.x train per
[R37](CLAUDE.md): integration branches `feature/vX.Y` merge to `main` once
per major. Weekly milestones get `vX.Y.0-rcN` tags per R39 (see
[docs/v4-platform/STATUS-2026-05-02-week8-rc-acceptance.md](docs/v4-platform/STATUS-2026-05-02-week8-rc-acceptance.md)
for the W8 acceptance template).

For platform overview, feature breakdown, architecture diagrams,
moats and roadmap, see [README.md](README.md).

---

### v4.7.0 — 2026-05-12 (GA — Tabular Review + Workflows + AI-suggest)

**v4.7.0** is the integration of three weekly milestones — W1 (Tabular
Review backend), W2 (Workflows backend + AI-suggester), and W3 (Admin
SPA + SSE streaming + GA prep). The combined surface is a
spreadsheet-style document-extraction feature plus a reusable
prompt-template catalogue with KB-aware AI-suggested workflows.

**What's new in AskMyDocs v4.7.0 GA (cycle summary):**

W1 (rc1, see below for full detail):
- 2 new domain tables — `tabular_reviews` + `tabular_cells` — with
  tenant_id mandatory + composite unique
  `(tenant_id, review_id, document_id, column_index)` + FK cascade on
  review_id + document_id.
- 17 format types (Mike's 9 + 8 AskMyDocs-new), `enum` validated
  against `enum_values`, plus the LLM-free `json_path` shortcut.
- `TabularReviewExtractor` — batched multi-column LLM call per
  document, cost `O(documents)` not `O(documents × columns)`.
- 41 PHPUnit feature tests.

W2 (rc2):
- 3 new domain tables — `workflows` + `workflow_shares` +
  `hidden_workflows` — tenant-aware with R30/R31 isolation.
- `WorkflowService` for CRUD + share + hide; `WorkflowSuggester` +
  `MetadataPatternAnalyzer` for AI-driven proposals against the
  tenant's KB metadata distribution.
- 15 system-shipped templates spanning legal review, GDPR DPIA, DPA,
  commercial agreement triage, privacy policy audit, vendor due
  diligence, employment policy review, regulatory mapping, risk
  register, litigation timeline, NDA review, IP-licensing review,
  consent record audit, processor-list extraction, contract-clause
  comparison.
- 39 PHPUnit feature tests.

W3 (this rc3 / GA):

- **SSE streaming extractor** — `POST /api/admin/tabular-reviews/{id}/generate-stream`
  wraps `TabularReviewExtractor::extract($onCell)` in a
  `text/event-stream` response so the admin grid paints cells as they
  land instead of waiting for the whole batch to finish. Wire format:
  one SSE message per event (`start` / `document` / `cell` / `done` /
  `error`). The synchronous `/generate` endpoint stays in place as
  the test + CLI path. Per-cell payload carries
  `{document_id, column_index, summary, reasoning, citations, flag, status}`.
- **Admin SPA — Tabular Reviews** at `/app/admin/tabular-reviews`:
  list view (paginated + project filter), create dialog (title +
  project_key + columns config builder with per-column name/prompt/
  format dropdown + add/remove rows), show page (grid view with
  flag-tinted cells + per-cell hover tooltip on reasoning + Generate
  / Clear actions).
- **Admin SPA — Workflows** at `/app/admin/workflows`: scope tabs
  (Mine / Shared / System), card grid layout, create dialog
  (assistant or tabular type, markdown prompt + practice), AI-suggest
  gallery (clicks `/suggest`, renders proposals as save-able cards),
  per-card hide action.
- **Admin rail** entries for "Tabular Reviews" + "Workflows" wired
  into `AdminShell` per the standing rule
  `feedback_admin_ui_panel_alignment_per_release.md` (every cycle
  shipping domain capabilities also ships an admin SPA menu entry).
- **TanStack Router** routes for the two new admin views, guarded by
  `RequireRole(['admin', 'super-admin'])` matching the BE Gate
  contract.
- **Tests (W3)**: 6 PHPUnit feature tests for the SSE controller
  (`TabularReviewStreamControllerTest` — happy stream + 404 + 401 +
  403 viewer + error event + max_documents cap), 13 Vitest react
  tests (6 + 7 across the two list components covering loading /
  empty / ready / error states + create dialog happy + failure +
  AI-suggest gallery save-this), 8 Playwright specs (4 + 4 across
  Tabular Reviews + Workflows covering the list shell + create dialog
  ARIA + full CRUD round-trip + 422 validation surfacing).

**Test count delta**:
- PHPUnit: 1885 → 1891 (+6 W3 SSE controller).
- Vitest react: 384 → 397 (+13 W3 admin SPA).
- Playwright: +8 (4 tabular + 4 workflows specs).
- W1 ships +41 PHPUnit, W2 ships +39 PHPUnit (already in main as rc1
  + rc2). Total cycle delta ≈ +94 PHPUnit + 13 Vitest + 8 Playwright
  ≈ **+115 tests**.

**R37 once-per-major** — `feature/v4.7` merges to `main` as a single
GA event after all three W milestones close. The integration branch
is preserved for v4.7.x patches.

**R39 RC tag pinned at the W3 closure SHA** — `v4.7.0-rc3` tagged on
the W3 merge commit; the GA `v4.7.0` tag pins on the integration→main
merge commit.

**Honesty pass**:
- The Glide Data Grid canvas integration referenced in the v4.7
  design doc is shipped as a plain HTML table in the W3 GA. The
  cell-tint + tooltip + reasoning side-panel UX is identical; the
  canvas-backed performance lift was deferred to a v4.7.x polish in
  exchange for ship velocity and reduced JS bundle weight. See ADR
  0010 D1 for the rationale.
- The Tabular Reviews show page renders a simple HTML grid; column
  reordering / row pagination / column-resize handles are deferred.
- Workflow edit + share modal + use-as-template path are scaffolded
  in the W2 backend but not surfaced in the W3 admin SPA shell; they
  are reachable through the JSON API and ship in v4.7.x.
- One pre-existing test failure
  (`Tests\Feature\Kb\Chunking\JiraIssueChunkerTest::comments_section_aggregates_into_separate_chunk`)
  on the `feature/v4.7` baseline — unrelated to W3, parked for v4.7.1.

---

### v4.7.0-rc1 — 2026-05-12 (W1 closure — Tabular Review backend)

**v4.7.0-rc1** marks the W1 milestone of the v4.7 cycle (Tabular Review +
Workflows + AI-suggest). W1 ships the backend foundation for the
spreadsheet-style document-extraction feature inspired by
[github.com/willchen96/mike](https://github.com/willchen96/mike) and
generalised beyond the legal vertical. **No UI surface yet** — the admin
SPA + Glide Data Grid + citation popover + SSE streaming land in W3.

**What's new in AskMyDocs v4.7.0-rc1:**

- **2 new domain tables** — `tabular_reviews` + `tabular_cells`:
  - `tabular_reviews` carries the review header + `columns_config` JSON
    (the list of extraction columns + format + prompt + enum/json-path
    config) + `workflow_id` (FK lands in W2 when the workflows table
    appears) + `shared_with`.
  - `tabular_cells` carries `(review, document, column)` extractions:
    `content` JSON `{summary, flag, reasoning, citations[]}` + `flag`
    (string column, application-enforced via the PHP `App\Support\TabularReview\CellFlag`
    enum — values `green` / `grey` / `yellow` / `red`) + `status`
    (string column, application-enforced via `CellStatus` — values
    `pending` / `generating` / `ready` / `failed`).
  - Both tables: R31 tenant_id mandatory + standalone index +
    composite unique `(tenant_id, review_id, document_id, column_index)`.
  - FK cascade on review_id + document_id keeps the grid orphan-free.

- **17 format types** (Mike has 9): text, bulleted_list, number,
  percentage, monetary_amount, currency, yes_no, date, tag (Mike's 9),
  plus **enum** (validated against the column's `enum_values`),
  **enum_status** (semantic palette), **rating** (1-5), **url**,
  **person**, **tags_multi**, **relation**, **json_path** (LLM-free
  shortcut — reads directly from chunk metadata via JSON-path lookup,
  free + instant, leveraging v4.5/W5.5 source-aware ingestion).

- **`TabularReviewExtractor`** — batched multi-column LLM call per
  document (Mike's pattern, cost `O(documents)` not `O(documents
  × columns)`). Streaming hook (`$onCell` callback) ready for W3's
  SSE transport. R14 loud refusal: red flag + reasoning when no
  chunks above the relevance threshold, when the LLM omits a JSON
  line, when JSON parse fails, when JSON encode fails on bad UTF-8.
  DB-level upsert via `Model::upsert()` keyed on the composite UNIQUE
  `(tenant_id, review_id, document_id, column_index)` prevents
  duplicate rows under concurrent generate/regenerate. Cell content
  itself is last-writer-wins — there is no row-level lock, so the
  later writer's `summary`/`flag`/`reasoning`/`citations` overwrite
  the earlier writer's. The narrow guarantee is "no duplicate rows".

- **`ColumnPromptSuggester`** — `POST /api/admin/tabular-reviews/prompt`
  drafts a 1-2 sentence extraction prompt from a column name + format.
  Throws on empty completion (R14 — no silent empty prompt).

- **Admin API surface** under `can:viewTabularReviews` (admits
  super-admin, admin RW within tenant, viewer RO):
  `GET / POST /api/admin/tabular-reviews`,
  `GET / PATCH / DELETE /api/admin/tabular-reviews/{id}`,
  `POST /{id}/generate` (sync, capped via `max_documents`, returns
  `truncated` flag), `POST /{id}/regenerate-cell`,
  `POST /{id}/clear-cells`, `POST /prompt`.

- **41 new PHPUnit tests, all green** across:
  - Controller (CRUD + validation + viewer ACL + cascade + json_path
    required_if + suggest-prompt 403 for viewer + 401 guest + 403
    unrole'd user).
  - Extractor (batched single LLM call verified via call counter,
    json_path shortcut + `assertNothingSent`, R14 refusal paths +
    `assertNothingSent` on no-chunks, upsert idempotence, HTTP-error
    → red, invalid-JSON-line skipping, empty columns_config no-op).
  - Tenant isolation (cross-tenant 404 + auto-fill via TenantContext
    via `X-Tenant-Id` header → ResolveTenant).
  - ColumnPromptSuggester (happy path, wrapping-quote strip, empty
    column → `InvalidArgumentException`, empty LLM completion →
    `RuntimeException`).
  - Architecture (`BelongsToTenant` trait + `tenant_id` in `$fillable`
    on both models; TenantIdMandatoryTest enumeration extended).

- **R36 Copilot loop** — 6 review iterations, every must-fix
  addressed; final iteration generated 0 inline comments.

**Deferred to W2/W3:**
- `workflow_id` FK to `workflows.id` (W2).
- SSE transport + per-cell `cell` events (W3 — wires onto the existing
  `$onCell` extractor callback).
- Admin SPA (TanStack route + Glide Data Grid + citation side-panel
  + AI-suggest gallery + 15 built-in workflows seeder — W3).

**Closure SHA**: `38c0dce` (PR #160 merged into `feature/v4.7`).

---

### v4.6.0 — 2026-05-12 (GA — Connector package extraction + IoC bridge + composer-extra discovery)

**v4.6.0 GA** is the architectural cleanup cycle on top of v4.5.0 GA.
The seven inline connectors built during v4.5/W1–W6 are now extracted
into 8 standalone composer packages under the `padosoft/askmydocs-
connector-*` family. The host's `app/Connectors/BuiltIn/` tree and
most of `app/Connectors/` (the framework primitives) is **deleted**;
the host's only remaining connector code is the new
`App\Connectors\HostIngestionBridge` — the IoC implementation that the
packages call back into via `Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract`.

**No new end-user features.** Every connector behaves identically under
the same `/admin/connectors` SPA. The change is architectural (package
boundary + IoC bridge) and operational (community adoption path —
each connector is `composer require`-able by any Laravel app).

**What's new in AskMyDocs v4.6.0 GA:**

- **8 new composer packages** (each v1.x, each CI-green, each with its
  own README + tests + docs):
  - `padosoft/askmydocs-connector-base` v1.1.1 — framework primitives:
    `ConnectorInterface`, `BaseConnector`, `ConnectorRegistry`,
    `ConnectorSyncJob`, `OAuthCredentialVault`, `SyncScheduler`,
    `ConnectorIngestionContract` IoC + `NullConnectorIngestionContract`
    fail-loud default + 2 framework migrations.
  - `padosoft/askmydocs-connector-google-drive` v1.0.1 — Drive OAuth2 + Drive API v3 sync.
  - `padosoft/askmydocs-connector-notion` v1.0.1 — Notion OAuth2 + page/block sync + `NotionBlockToMarkdown`.
  - `padosoft/askmydocs-connector-evernote` v1.0.0 — Evernote OAuth2 + Cloud API + ENEX bulk-import (`EnexImporter` + `EnmlToMarkdown`).
  - `padosoft/askmydocs-connector-fabric` v1.0.0 — Fabric.so API-key + OAuth-ready.
  - `padosoft/askmydocs-connector-onedrive` v1.0.0 — Microsoft Graph + MS Identity v2 + `MicrosoftGraphPaginator`.
  - `padosoft/askmydocs-connector-confluence` v1.0.0 — Atlassian OAuth 2.0 3LO + Confluence Cloud + `ConfluenceStorageToMarkdown` + `AtlassianPaginator`.
  - `padosoft/askmydocs-connector-jira` v1.0.0 — Atlassian OAuth 2.0 3LO + Jira Cloud + `JiraAdfToMarkdown` + `JqlBuilder` + `JiraPaginator`.
- **`HostIngestionBridge`** (`app/Connectors/HostIngestionBridge.php`)
  is the single host-side connector class — implements 5 methods:
  - `dispatchIngestion(...)` dispatches `IngestDocumentJob` with the
    tenant captured at dispatch time (R30 / R31 preserved).
  - `resolveKbSourcePath($relative)` calls `KbPath::normalize()` (R1)
    and honours `KB_FILESYSTEM_DISK` + `KB_PATH_PREFIX`.
  - `redactContent($content)` applies `RedactorEngine` (Mask strategy)
    when `KB_PII_REDACTOR_ENABLED=true` AND
    `KB_CONNECTOR_INGEST_PII_REDACT=true` (R26 — both default OFF;
    existing connector users see no behaviour change).
  - `emitAudit($connectorKey, $eventType, ...)` writes to
    `kb_canonical_audit` under `actor='connector:<key>'` and
    `event_type='connector_<eventType>'` (auto-namespaced if not
    prefixed). Wrapped in try/catch so audit failures never break
    the connector hot path.
  - `softDeleteByRemoteId($installation, $metadataKey, $remoteId)`
    looks up `knowledge_documents` tenant-scoped (R30) +
    `metadata->$metadataKey == $remoteId` then routes through
    `DocumentDeleter::delete(force: false)`. Idempotent on already-
    trashed rows.
- **Composer-extra discovery** — every connector package declares its
  FQCNs under `composer.json::extra.askmydocs.connectors`; the
  framework registry walks `composer.lock` at boot, reads each
  package's `extra` block, and registers every FQCN with R23
  validation. Mirrors Laravel's `extra.laravel.providers` convention.
  Adding a new connector to AskMyDocs is now `composer require
  padosoft/askmydocs-connector-<new>` — zero further wiring.
- **Inline-code deletion** — the host repo's connector footprint
  shrank from ~30 files to **1 new `HostIngestionBridge.php` + its
  12-test feature suite**. Removed:
  - `app/Connectors/BuiltIn/` (entire tree: 7 connectors + 5 helper subdirectories).
  - `app/Connectors/{BaseConnector,ConnectorInterface,ConnectorRegistry,HealthStatus,SyncResult}.php` + `Auth/` + `Scheduling/` + `Support/` + `Exceptions/`.
  - `app/Models/{ConnectorInstallation,ConnectorCredential}.php`.
  - `app/Jobs/ConnectorSyncJob.php`.
  - `database/migrations/2026_05_15_000001_create_connector_installations_table.php` + `2026_05_15_000002_create_connector_credentials_table.php` (the package supplies them auto-loaded by `ConnectorServiceProvider::boot()`).
  - 14 inline-connector unit-test files (each is now owned by its
    package's own CI; the host keeps the admin controller / gate /
    architecture / bridge tests).
- **Chunkers stay in host** — `app/Services/Kb/Chunkers/{AtomicNoteChunker,ConfluencePageChunker,JiraIssueChunker,NotionBlockChunker,OfficeDocChunker,PdfPageChunker}.php` and `app/Services/Kb/MarkdownChunker.php` are UNCHANGED in v4.6. They depend on host types (`ChunkerInterface`, `ChunkDraft`, `ConvertedDocument`, `TokenCounter`, `DerivedMetadataReader`) that the standalone-agnostic packages cannot reference. ADR 0009 decision (e) records this trade-off.
- **New env knob** `KB_CONNECTOR_INGEST_PII_REDACT=false` (default OFF)
  added to `.env.example` + `config/kb.php::pii_redactor.redact_before_ingest`.
  Closes the R26 boundary-coverage gap for connector-ingested
  documents.
- **8 new `repositories[]` annotation rows** in `composer.json` for
  the connector packages — same VCS-pre-Packagist posture as
  `padosoft/laravel-pii-redactor`.
- **ADR 0009 — `docs/adr/0009-v46-connector-package-extraction.md`** —
  5 architecture decisions LOCKED: (a) composer-extra discovery,
  (b) per-connector source-type helpers in package, (c) IoC bridge,
  (d) VCS-`repositories[]` pre-Packagist workaround, (e) chunkers
  stay in host.
- **Test gates** — full PHPUnit run: 1548 tests, 4817 assertions,
  only 1 pre-existing unrelated failure (`JiraIssueChunkerTest::comments_section_aggregates_into_separate_chunk` from v4.5/W6 commit `c60047c` — deferred to v4.6.x). Vitest react: 384/384 green. Architecture: 20/20 green.

**Pull requests merged on `feature/v4.6` for v4.6.0 GA:**
- W1..W3 — 8 connector packages shipped externally on their own GitHub
  repos under `padosoft/askmydocs-connector-*` with their own CI.
- W4 — Host-side wire-up + inline-code deletion + ADR 0009 + closure
  status doc + this entry (this PR).

**Acceptance:** see `docs/v4-platform/STATUS-2026-05-12-v46-week4-rc-acceptance.md`. Tagged `v4.6.0-rcN` at each W closure per R39; `v4.6.0` GA at the integration-merge SHA per R37 + R39.

**Deferred to v4.6.x patches:**
- Fix `JiraIssueChunkerTest::comments_section_aggregates_into_separate_chunk` (pre-existing regression, unrelated to v4.6).
- Packagist submission for all 8 connector packages (operational task).

---

### v4.5.0 — 2026-05-12 (GA — Universal Connectors + Source-Aware Ingestion + Modern Chat Surface)

**v4.5.0 GA** closes the v4.5 cycle. Seven new external-source connectors + per-source chunking framework + Vercel AI SDK UI Tier 1 + partial Tier 2 ship on top of v4.4.0 GA. NO new sister packages or sister-package version bumps in the host this cycle (host-side); two side-quest releases shipped in the upstream `padosoft/*` ecosystem.

**What's new in AskMyDocs v4.5.0 GA:**

- **W1 — Connector framework core + Google Drive reference** (PR #149 → `d2b83c2`). `App\Connectors\ConnectorInterface` (10-method contract) + `BaseConnector` + `OAuthCredentialVault` + `ConnectorRegistry` (R23 FQCN-validated, dual-channel discovery: built-in array OR composer-package `extra.askmydocs.connectors`) + `ConnectorSyncJob` (scheduler-driven incremental sync) + `App\Connectors\BuiltIn\GoogleDriveConnector` (OAuth2 + delta-query). New migrations: `connector_installations` + `connector_credentials` (OAuth state tokens live in the application cache with TTL `oauth_state_ttl_seconds` default 600s — no DB table).
- **W2 — Notion connector** (PR #150 → `9c6f510`). `App\Connectors\BuiltIn\NotionConnector` + `App\Connectors\BuiltIn\Notion\NotionBlockToMarkdown` (block-to-markdown converter) + `App\Connectors\BuiltIn\Notion\NotionPaginator` + `App\Services\Kb\Chunkers\NotionBlockChunker` (W5.5 source-aware chunker). OAuth2. Framework helper refinements extracted from the second-connector experience.
- **W3 — Admin React SPA `/app/admin/connectors`** (PR #151 → `87a81c6`). React DataTable (shadcn) + OAuth callback handler at `/app/admin/connectors/$key/callback` + per-installation install/uninstall flow + Spatie `manageConnectors` super-admin gate at controller + route layer + Playwright E2E (`admin-connectors-super-admin.spec.ts`).
- **W4 — Evernote + Fabric reference connectors** (PR #152 → `02e7ad2`). `EvernoteConnector` ships dual-mode (OAuth Evernote API **or** `.enex` bulk-import for offline migration; `Evernote\EnmlToMarkdown` + `Evernote\EnexImporter`). `FabricConnector` ships API-key auth (OAuth pending upstream provider availability).
- **W5 — OneDrive + Confluence connectors** (PR #153 → `f2c1967`). `OneDriveConnector` (Microsoft Graph delta-query — supports `text/markdown` / `text/plain` / `application/pdf`; MS Office formats `.docx`/`.xlsx`/`.pptx` ingestion deferred to a future cycle once the Office extractors ship). `ConfluenceConnector` (Atlassian OAuth 2.0 3LO; `cloud_id` persisted in tenant-scoped `connector_credentials.extra_json.cloud_id`, may be shared between Confluence + Jira installs within the same tenant/workspace + storage-format-to-markdown converter). +83 PHPUnit tests.
- **W5.5 — Source-aware ingestion + live-test recording** (PR #154 → `7ea9d47`). `PipelineRegistry::resolveChunker($sourceType)` (R23 FQCN + `supports()` mutex) dispatches per source to **4 new chunkers** + `PdfPageChunker` now routed through the registry: `Chunkers\NotionBlockChunker`, `Chunkers\ConfluencePageChunker`, `Chunkers\OfficeDocChunker`, `Chunkers\AtomicNoteChunker` (new in v4.5); `Chunkers\PdfPageChunker` (existed in v3.0; W5.5 lifted it from a direct call in `DocumentIngestor` into the registry). 6 connector rich-frontmatter capture (`source`, `connector_key`, native ID + URL + timestamps, ACL hint, tags, status, preamble-path). `Reranker` Layer-4 signals: `tag_overlap_weight=0.05` + `preamble_match_weight=0.05` + `recency_weight=0.02` + `status_active_weight=0.02` additive on top of base `0.55·vec + 0.25·kw + 0.05·heading` (max score ~1.44). `KbSearchService::searchWithContext()` now accepts optional `facets` param + emits `facets[source]` + `facets[tag]` counts. New PostgreSQL-only `knowledge_chunks.metadata` indexes: 2 GIN-on-`jsonb` (`source_type`, `search_tags`) + 1 B-tree (`recency_bucket` text projection — fixed-set ordinal data warrants B-tree, not GIN). SQLite is a no-op. Opt-in live-test recording infrastructure under `tests/Live/Connectors/` + `tests/Live/Support/` (env-var guard skips entire tree in default CI) + junior-proof per-provider runbook (`docs/v4-platform/RUNBOOK-live-fixture-recording.md`) for all 6 W5.5 providers.
- **W6 — Jira Cloud connector + ADF-to-markdown + JqlBuilder + JiraIssueChunker** (PR #155 → `c60047c`). `JiraConnector` (Atlassian OAuth 2.0 3LO) + `Jira\JiraAdfToMarkdown` (Atlassian Document Format → markdown) + `Jira\JqlBuilder` (injection-safe fluent JQL builder) + `Jira\JiraPaginator` (auto-detects `startAt+total` vs `nextPageToken` modes). `Chunkers\JiraIssueChunker` with property preamble + comment aggregation per issue.
- **W7 — Vercel AI SDK UI Tier 1 + partial Tier 2** (PR #156 → `c8a25c6`). Tier 1 complete: stop-streaming button (`AbortController`-backed), regenerate-last-assistant, branch-from-message endpoint, inline-edit user message, token+cost meter (BE `config('ai.cost_rates')`), enhanced per-message provider+model+timestamp badge, copy-code-block. Tier 2 partial: `App\Services\Chat\SuggestedFollowupGenerator` ships follow-up pill chips. Tier 2 stretch (tool-result rendering, streaming source-document parts, conversation export, image attachments, artifact panel) **deferred to v5.0** per ADR 0008 D4 — designed alongside the v5.0 MCP **client** dispatcher to share one persistence contract.
- **W8 — RC acceptance + GA prep (this PR).** README hero refresh with two killer-feature sections ("Universal Connectors" + "Modern Chat Surface"), feature-table rows under five categories, roadmap checklist tick, ADR 0008, closure status doc `docs/v4-platform/STATUS-2026-05-12-v45-week8-rc-acceptance.md`, ROADMAP refresh.

**Side-quests (released upstream during the v4.5 window):**

- `padosoft/laravel-ai-regolo` **v1.0.1** — caught up to `laravel/ai` v0.6.8 `EmbeddingGateway` 6-param contract change.
- `padosoft/laravel-ai-chat` **v1.0.0** — bumped regolo dep to v1.0.1, raised PHP min to 8.4, added new CI matrix.

**Pull requests merged on `feature/v4.5` for v4.5.0 GA:**
- #149 v4.5/W1 — Connector framework core + Google Drive reference (`d2b83c2`)
- #150 v4.5/W2 — Notion connector + framework refinements (`9c6f510`)
- #151 v4.5/W3 — Admin React SPA + OAuth callback (`87a81c6`)
- #152 v4.5/W4 — Evernote + Fabric reference connectors (`02e7ad2`)
- #153 v4.5/W5 — OneDrive + Confluence connectors (`f2c1967`)
- #154 v4.5/W5.5 — Source-aware ingestion + chunking + retrieval boost + live-test recording (`7ea9d47`)
- #155 v4.5/W6 — Jira Cloud connector + ADF-to-markdown + JqlBuilder + JiraIssueChunker (`c60047c`)
- #156 v4.5/W7 — Vercel AI SDK UI Tier 1 + Tier 2 — stop/regenerate/branch/edit/token-meter/message-parts/suggested-followups (`c8a25c6`)
- (this PR) v4.5/W8 closure — README hero + CHANGELOG + ADR 0008 + closure status doc + ROADMAP refresh
- (W8 GA-merge follow-up PR) `feature/v4.5` → `main` GA merge per R37 + `v4.5.0` GA tag at the merge SHA

**Changed**

- `KbSearchService::searchWithContext()` now accepts optional `facets` parameter (additive — no breaking change). Existing callers continue to receive the same shape.
- Chunking dispatch is now routed through `PipelineRegistry::resolveChunker($sourceType)` instead of direct `MarkdownChunker` instantiation in `DocumentIngestor`. The fallback for un-typed sources remains `MarkdownChunker` so v4.4 hosts ingesting plain markdown see byte-identical behaviour.
- `Reranker` formula extended with four additive Layer-4 deltas (`tag_overlap` + `preamble_match` + `recency` + `status_active`). Base 4 signals still sum to 1.0; Layer-4 adds up to ~0.14 ceiling on top, so max score is ~1.44 — documented in `config/kb.php`.

**Deferred to v5.0+ (per ADR 0008 D4)**

- Vercel SDK UI Tier 2 stretch: tool-result rendering, streaming source-document parts, conversation export, image attachments, artifact panel. Parked because the message-parts persistence shape should be designed alongside the v5.0 MCP **client** dispatcher so the artifact panel and the tool-result panel share one storage contract.

**v4.5 cycle test count delta:** PHPUnit 1423 (start of v4.5 from v4.4.0 GA) → **1885** (end of W7) — **+462 BE tests** (W1: +112 framework + Google Drive; W2: +35 Notion; W3: +12 admin controllers; W4: +56 Evernote + Fabric; W5: +83 OneDrive + Confluence; W5.5: +52 chunkers + reranker + live-test infra; W6: +60 Jira + ADF + JqlBuilder; W7: +52 SDK UI BE — token meter, branch endpoint, suggested-followup, refusal contracts). Vitest react 321 → **384** (+63 react scenarios). Vitest legacy unchanged at 18. Playwright spec file count grew to 36. All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest (react + legacy) + Playwright E2E + the RAG regression workflow.

**Forward-looking — v4.6 backlog (parked, NOT v4.5 blockers):**

- Extract 7 connectors + shared base into 8 `padosoft/askmydocs-connector-*` packages.
- Delete inline `app/Connectors/BuiltIn/*` code; `ConnectorRegistry` discovers exclusively via composer-lock `extra.askmydocs.connectors`.
- 8 packages tagged `v1.0.0` on Packagist with junior-proof READMEs (same standard as [`docs/v4-platform/RUNBOOK-live-fixture-recording.md`](docs/v4-platform/RUNBOOK-live-fixture-recording.md) — exact URLs, sidebar paths, button labels, scopes + rationale per scope, env var names produced, verification one-liner with expected output, common errors + fixes).
- `padosoft/askmydocs-connector-template` repo as scaffold for community contributors.

---

### v4.4.0 — 2026-05-11 (GA — Tailwind v4 + admin SPA cross-mount + adversarial nightly opt-in)

**v4.4.0 GA** closes the v4.4 cycle. Three host-side mount-mode migrations + one operational opt-in shipped on top of v4.3.0 GA. NO new sister packages, NO sister-package version bumps — only `react-router-dom` + `lucide-react` + Tailwind v4 + `@tailwindcss/vite` added on the FE. Default-off invariant preserved across both new env knobs.

**What's new in AskMyDocs v4.4.0 GA:**

- **W1 — Tailwind v3 → v4 host migration** (PR #136). `tailwindcss` `^3.4.14` → `^4.0.0` + `@tailwindcss/vite` plugin. Drops `autoprefixer` + `postcss`. Hard prerequisite for W2/W3 cross-mount per ADR 0005.
- **W2 — `padosoft/laravel-pii-redactor-admin` cross-mount** (PR #138). Iframe → cross-mount at `/admin/pii-redactor`. Vendored SPA sharing host React 19 + Sanctum cookie + axios. New dep: `lucide-react@^1.14.0`.
- **W3 — `padosoft/eval-harness-ui` cross-mount** (PR #140). Iframe → cross-mount at `/admin/eval-harness` (non-prod-only). 8-page SPA. NEW BE bootstrap config endpoint. 3 fail-closed fences PRESERVED. New dep: `react-router-dom@^6.30.1`.
- **W4 — eval-harness adversarial nightly opt-in** (PR #142). 2 NEW env knobs (default OFF). Baseline-gates-adversarial; advisory-only summary sidecar. ADR 0007.
- **W4.A closure docs PR** v4.4 W4 closure + GA prep — adds this Changelog entry, the v4.4.0 GA ribbon under `### Key Features`, the closure status doc `docs/v4-platform/STATUS-2026-05-11-v44-week4-rc-acceptance.md`, and the `INTEGRATION-ROADMAP-sister-packages.md` v4.4 GA refresh.

**Pull requests merged on `feature/v4.4` for v4.4.0 GA:**
- #136 v4.4/W1 — Tailwind v4 host migration
- #137 v4.4/W1 closure — rc1 ribbon + status doc
- #138 v4.4/W2 — cross-mount pii-redactor-admin
- #139 v4.4/W2 closure — rc2 ribbon + status doc
- #140 v4.4/W3 — cross-mount eval-harness-ui
- #141 v4.4/W3 closure — rc3 ribbon + status doc
- #142 v4.4/W4 — adversarial nightly opt-in + ADR 0007
- (W4.A closure docs PR) v4.4 W4 closure + GA prep — Changelog + Key Features + RC acceptance doc + INTEGRATION-ROADMAP refresh
- (W4.B follow-up PR) `feature/v4.4` → `main` GA merge per R37 + `v4.4.0` GA tag at the merge SHA

**v4.4 cycle test count delta:** PHPUnit 1408 (start of v4.4 from v4.3.0 GA) → **1423** (end of W4) — **+15 BE tests** (W3: +8 bootstrap-config; W4: +7 adversarial nightly). Vitest react 304 → **321** (+17 react scenarios: W2: +5+3 = +8; W3: +9). Vitest legacy unchanged at 18. All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest (react + legacy) + Playwright E2E + the RAG regression workflow.

**v4.4 cycle weekly RC tags (preserved):**

| Tag | Closure SHA | Milestone | GitHub release |
|---|---|---|---|
| `v4.4.0-rc1` | `ac3bd49` | W1 closure (Tailwind v4 host migration) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.4.0-rc1 |
| `v4.4.0-rc2` | `76f4d85` | W2 closure (cross-mount pii-redactor-admin) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.4.0-rc2 |
| `v4.4.0-rc3` | `c74fc1b` | W3 closure (cross-mount eval-harness-ui) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.4.0-rc3 |
| **`v4.4.0` GA** | _filled in on W4.B merge_ | `feature/v4.4` → `main` | https://github.com/lopadova/AskMyDocs/releases/tag/v4.4.0 |

**Forward-looking — v4.5 backlog (parked, NOT v4.4 blockers):**

- Per-lane adversarial alerting (small operational follow-up; gated on a few weeks of stable adversarial nightly baseline data).
- TanStack Router unification for the cross-mounted eval-harness-ui (cosmetic polish; zero functional impact).

### v4.4.0-rc3 — 2026-05-11 (W3 milestone — cross-mount eval-harness-ui)

Third release candidate of the **v4.4 cycle**. W3 ships the **iframe → cross-mount migration** of `padosoft/eval-harness-ui` at `/admin/eval-harness` (non-prod-only). The 8-page admin SPA now renders directly inside the host React tree via vendored source under `frontend/src/features/admin/eval-harness/cross-mount/`, sharing the host's React 19 + Sanctum cookie + axios client. Replaces the v4.2/W4 ADR 0004 D5 iframe-mount workaround. The 3 fail-closed fences (env flag + APP_ENV + Gate) are PRESERVED end-to-end.

**What's new in AskMyDocs v4.4.0-rc3 (W3 — cross-mount eval-harness-ui):**

- **W3 / sub-PR (#140)** — NEW `frontend/src/features/admin/eval-harness/cross-mount/` (29 files / ~3300 LOC vendored from `vendor/.../resources/js/` plus host-scoped `eval-harness-ui.css` + new `main-entry.tsx` wrapper + adapted `services/evalHarnessApi.ts`). REWRITTEN `EvalHarnessView.tsx` (drops iframe + readiness probe; fetches bootstrap config from new BE endpoint; drives `data-state="loading|ready|error"`; mounts SPA in degraded mode on error so `<ErrorPanel />` surfaces failures). NEW `app/Http/Controllers/Api/Admin/EvalHarnessUiBootstrapController.php` returning `config('eval-harness-ui')` JSON gated by `auth:sanctum` + `can:eval-harness.viewer`. REWRITTEN `frontend/e2e/admin-eval-harness.spec.ts` (strips iframe locators; preserves 3-fence assertions). UPDATED `INTEGRATION-ROADMAP-sister-packages.md` (eval-harness-ui row: iframe → cross-mount). NEW dep `react-router-dom@^6.30.1` (~14 KB; package's internal `BrowserRouter` continues to own sub-page navigation). Iter 2 fixed 6 Copilot findings (HIGH R30 tenant header bypass + HIGH R9 hard-coded bootstrap + 4 medium/low). +8 PHPUnit tests + +9 vitest react scenarios (312 → 321 cycle-wide: +5 iter 1 + +4 iter 2).
- **W3 closure docs PR (#141)** v4.4/W3 closure docs — adds this Changelog entry, the W3 ribbon under `### Key Features`, and the closure status doc.

**Pull request merged on `feature/v4.4` for v4.4.0-rc3:**
- #140 v4.4/W3 — iframe → cross-mount of eval-harness-ui
- #141 v4.4/W3 closure — Changelog entry + Key Features + closure status doc

**Test count:** PHPUnit 1408 → **1416** (+8 BE scenarios for the new `/api/admin/eval-harness/bootstrap-config` endpoint). Vitest react 312 → **321** (+9 cycle-wide: +5 in iter 1 + +4 in iter 2). Vitest legacy unchanged at 18. All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest (react + legacy) + Playwright E2E + the RAG regression workflow.

**v4.4 cycle preview (subsequent RCs):**

| Wn | Scope | Closure RC |
|---|---|---|
| W1 | Tailwind v3 → v4 host migration | `v4.4.0-rc1` ✅ |
| W2 | Iframe → cross-mount of `padosoft/laravel-pii-redactor-admin` | `v4.4.0-rc2` ✅ |
| W3 (this) | Iframe → cross-mount of `padosoft/eval-harness-ui` | `v4.4.0-rc3` ✅ |
| W4 | eval-harness adversarial nightly opt-in + GA closure + GA merge | **`v4.4.0` GA** |

### v4.4.0-rc2 — 2026-05-10 (W2 milestone — cross-mount pii-redactor-admin)

Second release candidate of the **v4.4 cycle**. W2 ships the **iframe → cross-mount migration** of `padosoft/laravel-pii-redactor-admin` at `/admin/pii-redactor`. The admin SPA now renders directly inside the host React tree via vendored source under `frontend/src/features/admin/pii-redactor/cross-mount/`, sharing the host's React 19 + Sanctum cookie + axios client. Replaces the v4.2/W4 ADR 0004 D5 iframe-mount workaround.

**What's new in AskMyDocs v4.4.0-rc2 (W2 — cross-mount pii-redactor-admin):**

- **W2 / sub-PR (#138)** — NEW `frontend/src/features/admin/pii-redactor/cross-mount/` (App.tsx + adminApi.ts + types.ts + cross-mount.css + App.test.tsx). REWRITTEN `PiiRedactorView.tsx` (drops iframe + readiness probe; derives package config host-side from `useAuthStore`). REWRITTEN `frontend/e2e/admin-pii-redactor.spec.ts` (strips iframe locators; asserts `data-mount="cross-mount"` + page-level testids). UPDATED `INTEGRATION-ROADMAP-sister-packages.md` (pii-redactor-admin row: iframe → cross-mount). Single new dep: `lucide-react@^1.14.0`. Iter 2 fixed 2 Copilot findings (R14 Overview tri-state + R15 Ctrl+K aria-label). +3 vitest scenarios.
- **(this PR)** v4.4/W2 closure docs — adds this Changelog entry, the W2 ribbon under `### Key Features`, and the closure status doc.

**Pull request merged on `feature/v4.4` for v4.4.0-rc2:**
- #138 v4.4/W2 — iframe → cross-mount of pii-redactor-admin
- (this PR) v4.4/W2 closure — Changelog entry + Key Features + closure status doc

**Test count:** 1408 (start of W2 from v4.4.0-rc1) → **1411** (+3 vitest scenarios for the cross-mount tri-state + a11y assertions; PHPUnit unchanged at 1408 — cross-mount is purely FE-side). All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest (react + legacy) + Playwright E2E + the RAG regression workflow.

**v4.4 cycle preview (subsequent RCs):**

| Wn | Scope | Closure RC |
|---|---|---|
| W1 | Tailwind v3 → v4 host migration | `v4.4.0-rc1` ✅ |
| W2 (this) | Iframe → cross-mount of `padosoft/laravel-pii-redactor-admin` | `v4.4.0-rc2` ✅ |
| W3 | Iframe → cross-mount of `padosoft/eval-harness-ui` | `v4.4.0-rc3` |
| W4 | eval-harness adversarial nightly opt-in + GA closure + GA merge | **`v4.4.0` GA** |

### v4.4.0-rc1 — 2026-05-10 (W1 milestone — Tailwind v4 host migration)

First release candidate of the **v4.4 cycle**. W1 migrates the AskMyDocs frontend host SPA from Tailwind v3.4 (PostCSS pipeline) to Tailwind v4 + `@tailwindcss/vite` plugin. **Hard prerequisite** for v4.4/W2 + v4.4/W3 cross-mount of the sister-package admin SPAs per ADR 0005 (the admin packages ship Tailwind v4 + React 19 internally; cross-mounting on a v3 host would force two CSS engines on the same page).

**What's new in AskMyDocs v4.4.0-rc1 (W1 — Tailwind v4 host migration):**

- **W1 / sub-PR (#136)** — `tailwindcss` `^3.4.14` → `^4.0.0`; drops `autoprefixer` + `postcss` runtime deps; adds `@tailwindcss/vite` plugin. `tailwind.config.ts` + `postcss.config.js` deleted. `globals.css` uses `@import "tailwindcss"` + `@theme` block (font + accent tokens) + `@custom-variant dark` (preserves v3 `darkMode: ['class', '[data-theme="dark"]']` contract — both `[data-theme="dark"]` AND `.dark` class selectors). `frontend/tsconfig.node.json` purged of deleted-file references. `package.json` declares `engines.node >=20` (Tailwind v4's transitive `@tailwindcss/oxide` requirement).
- **(this PR)** v4.4/W1 closure docs — adds this Changelog entry, the W1 ribbon under `### Key Features`, and the closure status doc.

**Pull request merged on `feature/v4.4` for v4.4.0-rc1:**
- #136 v4.4/W1 — Tailwind v4 host migration
- (this PR) v4.4/W1 closure — Changelog entry + Key Features + closure status doc

**Test count:** unchanged from v4.3.0 GA (1408 PHPUnit) — the migration is dependency + build-config only and existing tests cover the React 19 + Tailwind utility surface. All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest (react + legacy) + Playwright E2E + the RAG regression workflow.

**v4.4 cycle preview (subsequent RCs):**

| Wn | Scope | Closure RC |
|---|---|---|
| W1 (this) | Tailwind v3 → v4 host migration | `v4.4.0-rc1` ✅ |
| W2 | Iframe → cross-mount of `padosoft/laravel-pii-redactor-admin` | `v4.4.0-rc2` |
| W3 | Iframe → cross-mount of `padosoft/eval-harness-ui` | `v4.4.0-rc3` |
| W4 | eval-harness adversarial nightly opt-in + GA closure + `feature/v4.4` → `main` GA merge | **`v4.4.0` GA** |

### v4.3.0 — 2026-05-10 (GA — host-side hardening cycle complete)

**v4.3.0 GA** closes the v4.3 cycle. Three host-side hardening surfaces shipped on top of v4.2.0 GA's full sister-package integration. NO new sister packages, NO version bumps — every constraint inherited from v4.2.0 GA's locked stable line. Default-off invariant preserved across all 9 new env knobs; a v4.2.0 host upgrading to v4.3.0 sees byte-identical behaviour until they explicitly opt in.

**What's new in AskMyDocs v4.3.0 GA:**

- **W1 — PII redactor comprehensive boundary coverage** (PR #127). 11 persistence-boundary touch-points (was 4 in v4.1) + 6 admin-readiness inspectors wired into existing AskMyDocs admin surfaces. New observers + listeners + Monolog log channel processor + Flow `CurrentPayloadRedactorProvider` contract binding. 5 new env knobs all default OFF.
- **W2 — React 19 host bump** (PR #129). `react` 18.3.1 → 19.2.6 + `react-dom` + `@types/*`. Pre-flight grep confirmed zero breaking patterns. ADR 0005 documents the deferral of Tailwind v4 + cross-mount migration to v4.4.
- **W3 — eval-harness LLM-as-judge nightly cron + ops polish** (PR #131). New `eval:nightly` Artisan command + Laravel scheduler entry at 05:30 UTC, default-OFF. Three-fence cost guard. Regression detection + alert sidecar. 3 ops flags. ADR 0006.
- **(this PR — W4.A)** v4.3 W4 closure docs + GA prep — adds this Changelog entry, the v4.3.0 GA ribbon under `### Key Features`, the closure status doc `docs/v4-platform/STATUS-2026-05-10-v43-week4-rc-acceptance.md`, and the `INTEGRATION-ROADMAP-sister-packages.md` v4.3 GA refresh. The `feature/v4.3` → `main` GA merge + `v4.3.0` GA tag itself land in a follow-up **W4.B** PR after this closure PR merges.

**Pull requests merged on `feature/v4.3` for v4.3.0 GA:**
- #127 v4.3/W1 — sub-PR 4.5 — PII redactor comprehensive boundary coverage
- #128 v4.3/W1 closure — Changelog entry + Key Features + closure status doc
- #129 v4.3/W2 — React 19 host bump + ADR 0005
- #130 v4.3/W2 closure — Changelog entry + Key Features + closure status doc
- #131 v4.3/W3 — eval-harness nightly cron + ops polish + ADR 0006
- #132 v4.3/W3 closure — Changelog entry + Key Features + closure status doc
- (this PR — W4.A) v4.3 W4 closure + GA prep — Changelog entry + Key Features + RC acceptance doc + INTEGRATION-ROADMAP refresh
- (W4.B follow-up PR) `feature/v4.3` → `main` GA merge per R37 + `v4.3.0` GA tag at the merge SHA

**v4.3 cycle test count delta:** 1371 (start of v4.3 from v4.2.0 GA) → **1408** (end of W3). +37 PHPUnit tests across the cycle (W1: +26 boundary-coverage tests; W2: +0 dependency-only bump; W3: +11 nightly-cron tests including 1 R26 defense-in-depth test). All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest (react + legacy) + Playwright E2E + the RAG regression workflow.

**v4.3 cycle weekly RC tags (preserved):**

| Tag | Closure SHA | Milestone | GitHub release |
|---|---|---|---|
| `v4.3.0-rc1` | `9f7aa47` | W1 closure (PII boundary coverage) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.3.0-rc1 |
| `v4.3.0-rc2` | `d83b95e` | W2 closure (React 19 host bump) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.3.0-rc2 |
| `v4.3.0-rc3` | `897c33f` | W3 closure (eval nightly cron) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.3.0-rc3 |
| **`v4.3.0` GA** | _filled in on W4.B merge_ | `feature/v4.3` → `main` | https://github.com/lopadova/AskMyDocs/releases/tag/v4.3.0 |

**Forward-looking — v4.4 backlog (parked, NOT v4.3 blockers):**

- Tailwind v3 → v4 host migration (separate scope per ADR 0005).
- Iframe → cross-mount of pii-redactor-admin + eval-harness-ui (gated on Tailwind v4 landing first).
- eval-harness adversarial-lane nightly opt-in (small operational follow-up once nightly cron has stable baseline data).

### v4.3.0-rc3 — 2026-05-10 (W3 milestone — eval-harness LLM-as-judge nightly cron + ops polish)

Third release candidate of the **v4.3 cycle**. W3 ships the host-level nightly eval-harness regression sentinel: a new `eval:nightly` Artisan command + Laravel scheduler entry at 05:30 UTC that runs the seeded golden baseline through the full RAG pipeline once per day, optionally with `EVAL_NIGHTLY_LIVE=true` (plus a provider key) to catch real-provider drift the PR-time `Http::fake()` CI gate cannot see by design. (The PR-time CI gate's separate `EVAL_LIVE_AI=1` knob remains unrelated to the nightly cron's `EVAL_NIGHTLY_LIVE` opt-in.) Default-OFF; three independent fences guard cost.

**What's new in AskMyDocs v4.3.0-rc3 (W3 — eval-harness nightly cron + ops polish):**

- **W3 / sub-PR (#131)** — new `app/Console/Commands/EvalNightlyCommand.php` (4 ops flags: `--dry-run`, `--status`, `--prune-only`, plain run; two-fence cost guard `EVAL_NIGHTLY_ENABLED` scheduler gate + `EVAL_NIGHTLY_LIVE` provider-key check; writes dated JSON+MD report to `storage/app/eval-harness/nightly/`; computes delta vs prior baseline; fires `Log::alert()` + sidecar `<date>.alert.json` on regression > `EVAL_NIGHTLY_REGRESSION_THRESHOLD` default 0.05; auto-prunes beyond `EVAL_NIGHTLY_RETENTION_DAYS` default 90). New `app/Eval/Support/NightlyDeltaCalculator.php` (pure-PHP delta computation). New `app/Eval/Support/EvalHarnessRunner.php` (thin wrapper enabling test-time substitution). 4 new env knobs all default OFF/safe-default. New scheduler entry in `bootstrap/app.php` at 05:30 UTC, gated by `EVAL_NIGHTLY_ENABLED`. ADR 0006 documents cost guard, alerting choice (`Log::alert` over Notification), retention, host/package boundary rationale.
- **(this PR)** v4.3/W3 closure docs — adds this Changelog entry, the W3 ribbon under `### Key Features`, and the closure status doc.

**Pull request merged on `feature/v4.3` for v4.3.0-rc3:**
- #131 v4.3/W3 — eval-harness nightly cron + ops polish + ADR 0006
- (this PR) v4.3/W3 closure — Changelog entry + Key Features + closure status doc

**Test count:** 1397 (start of W3 from v4.3.0-rc2) → 1408 (+11 PHPUnit: 4 unit tests for `NightlyDeltaCalculator` + 6 feature tests for `EvalNightlyCommand` + 1 R26 defense-in-depth test added in iter 2 for the two-fence cost guard). All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest (react + legacy) + Playwright E2E + the RAG regression workflow.

**v4.3 cycle preview (subsequent RCs):**

| Wn | Scope | Closure RC |
|---|---|---|
| W1 | sub-PR 4.5 — PII redactor comprehensive boundary coverage | `v4.3.0-rc1` ✅ |
| W2 | React 19 host bump + ADR 0005 | `v4.3.0-rc2` ✅ |
| W3 (this) | eval-harness LLM-as-judge nightly cron + ops polish + ADR 0006 | `v4.3.0-rc3` ✅ |
| W4 | RC acceptance + `feature/v4.3` → `main` GA merge | **`v4.3.0` GA** |

### v4.3.0-rc2 — 2026-05-10 (W2 milestone — React 19 host bump)

Second release candidate of the **v4.3 cycle**. W2 bumps the host SPA from React 18.3.1 to React 19.2.6 to enable the future v4.4 cross-mount of admin SPAs (currently iframe-mounted per ADR 0004 D5). Bump is dependency-only — pre-flight grep confirmed zero React 18-specific patterns, so no code changes required.

**What's new in AskMyDocs v4.3.0-rc2 (W2 — React 19 host bump):**

- **W2 / sub-PR (#129)** — `react` 18.3.1 → 19.2.6 + `react-dom` + `@types/react` + `@types/react-dom`. ADR 0005 documents the decision + the deferred Tailwind v3 → v4 migration (separate scope) + iframe → cross-mount migration (v4.4 deliverable, gated on Tailwind v4 landing first). Vitest (react + legacy) green; full PHPUnit + Playwright + RAG regression all green.
- **(this PR)** v4.3/W2 closure docs — adds this Changelog entry, the W2 ribbon under `### Key Features`, and the closure status doc.

**Pull request merged on `feature/v4.3` for v4.3.0-rc2:**
- #129 v4.3/W2 — React 19 host bump + ADR 0005
- (this PR) v4.3/W2 closure — Changelog entry + Key Features + closure status doc

**Test count:** unchanged from v4.3.0-rc1 (1397 PHPUnit) — bump is dependency-only and existing tests cover the React 19 surface. All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest (react + legacy) + Playwright E2E + the RAG regression workflow.

**v4.3 cycle preview (subsequent RCs):**

| Wn | Scope | Closure RC |
|---|---|---|
| W1 | sub-PR 4.5 — PII redactor comprehensive boundary coverage | `v4.3.0-rc1` ✅ |
| W2 (this) | React 19 host bump + ADR 0005 | `v4.3.0-rc2` ✅ |
| W3 | eval-harness LLM-as-judge nightly cron + ops polish | `v4.3.0-rc3` |
| W4 | RC acceptance + `feature/v4.3` → `main` GA merge | **`v4.3.0` GA** |

### v4.3.0-rc1 — 2026-05-10 (W1 milestone — PII redactor comprehensive boundary coverage)

First release candidate of the **v4.3 cycle**. W1 ships sub-PR 4.5 — the comprehensive boundary-coverage extension of `padosoft/laravel-pii-redactor` v1.2 that was scoped during v4.2 but parked until this cycle. AskMyDocs now has **11 persistence-boundary touch-points + 6 admin-readiness inspectors wired**.

**What's new in AskMyDocs v4.3.0-rc1 (W1 — PII boundary coverage):**

- **W1 / sub-PR 4.5** — 7 new persistence-boundary touch-points (Monolog log processor; failed-jobs payload sanitiser via `JobFailed` listener with deterministic `failed_jobs.uuid` matching; `Conversation` + `Message` + `ChatLog` + `AdminCommandAudit` + `AdminInsightsSnapshot` Eloquent observers; `AskMyDocsFlowPayloadRedactor` bound to `Padosoft\LaravelFlow\Contracts\CurrentPayloadRedactorProvider` — one wire covers run input + step results + audit + webhook outbox + approvals). 6 admin-readiness inspectors wired into existing AskMyDocs admin surfaces. 5 new env knobs all default OFF. PR #127.
- **(this PR)** v4.3/W1 closure docs — adds this Changelog entry, the W1 ribbon under `### Key Features`, and the closure status doc.

**Pull request merged on `feature/v4.3` for v4.3.0-rc1:**
- #127 v4.3/W1 — sub-PR 4.5 — PII redactor comprehensive boundary coverage
- (this PR) v4.3/W1 closure — Changelog entry + Key Features + closure status doc

**Test count:** 1371 (start of v4.3) → 1397 (+26 PHPUnit). All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest + Playwright E2E + the RAG regression workflow.

**v4.3 cycle preview (subsequent RCs):**

| Wn | Scope | Closure RC |
|---|---|---|
| W1 (this) | sub-PR 4.5 — PII redactor comprehensive boundary coverage | `v4.3.0-rc1` |
| W2 | React 19 host bump (with ADR — unlocks cross-mount of admin SPAs) | `v4.3.0-rc2` |
| W3 | eval-harness LLM-as-judge nightly cron + ops polish | `v4.3.0-rc3` |
| W4 | RC acceptance + `feature/v4.3` → `main` GA merge | **`v4.3.0` GA** |

### v4.2.0 — 2026-05-10 (GA — full v4.2 cycle closed)

GA release of the **v4.2 sister-package alignment cycle**. Brings AskMyDocs onto the v1.0+ stable line of every in-scope `padosoft/*` sister package over four weekly milestones (W1 = bumps; W2 = laravel-flow integration; W3 = eval-harness CI gate; W4 = three admin SPAs). Patent Box stays external per ADR 0004 D1.

**Cycle-wide deliverables:**

- **W1** (PRs #111-#113) — `padosoft/laravel-ai-regolo` `^0.2` → `^1.0`. `padosoft/laravel-pii-redactor` `^1.1` → `^1.2`. RC tag `v4.2.0-rc1`.
- **W2** (PRs #114-#118) — `padosoft/laravel-flow` v1.0 graduated from `require-dev` (vendored, zero call sites) to `require` (9 Flow definitions orchestrating every multi-step background pipeline: `kb.ingest`, `kb.canonical-index`, `kb.promote` (approval-gated), `kb.delete`, 5 scheduled-command flows). Closure: `STATUS-2026-05-10-week2-flow-integration.md`. RC tag `v4.2.0-rc2`.
- **W3** (PRs #119-#120) — `padosoft/eval-harness` `^0.1.0` → `^1.2.0` (`require-dev`). RAG regression CI gate (`.github/workflows/rag-regression.yml`) gates every PR touching the RAG hot path with 4 datasets × per-lane metric stacks × 4 cohorts × 3 batch profiles. Cost guard via `Http::fake()`. Closure: `STATUS-2026-05-10-week3-eval-harness-ci-gate.md`. RC tag `v4.2.0-rc3`.
- **W4** (PRs #121-#124) — Three admin SPAs mounted: `padosoft/laravel-pii-redactor-admin` v1.0.2 at `/admin/pii-redactor` (3 Gates + new `dpo` role + R30 supplementary migration), `padosoft/laravel-flow-admin` v1.0.0 at `/admin/flows` (1 outer Gate + 8 row-scoped `ActionAuthorizer` methods + R30 row-scoped tenant lookup), `padosoft/eval-harness-ui` v1.0.0 at `/admin/eval-harness` non-prod-only (1 read-only Gate + 3 fail-closed fences + R30 HTTP header injection). All iframe-mounted. Closure: `STATUS-2026-05-10-week4-admin-spas.md`. RC tag `v4.2.0-rc4`.
- **W5** (this release) — RC acceptance audit + ADR 0004 + INTEGRATION-ROADMAP refresh + once-per-major `feature/v4.2` → `main` merge per R37 + `v4.2.0` GA tag at the merge SHA.

**Architecture decisions** captured in `docs/adr/0004-v42-sister-package-integration.md`:
- D1 — Patent Box stays EXTERNAL.
- D2 — eval-harness stays in `require-dev`.
- D3 — laravel-flow is the canonical multi-step orchestrator.
- D4 — Three R30 strategies for the three admin SPAs.
- D5 — Iframe mount across all three admin SPAs.
- D6 — Strict mixed-import Playwright pattern for admin specs.

**Test count:** 1082 (start of v4.2) → **1371** (GA) — +289 PHPUnit tests across cycle. All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest + Playwright E2E + the new RAG regression workflow.

**v4.3 backlog** (parked, not blockers): sub-PR 4.5 (pii-redactor comprehensive boundary coverage); React 19 host bump (would unlock cross-mount of pii-redactor-admin, deserves its own ADR); flow-admin ⌘K palette polish; eval-harness LLM-as-judge live-mode nightly cron.

### v4.2.0-rc4 — 2026-05-10 (W4 milestone — three admin SPAs mounted)

Fourth release candidate of the **v4.2 cycle**. W4 mounts **three operator-facing admin consoles** inside the AskMyDocs admin shell, one per stable-line `padosoft/*-admin` package: `padosoft/laravel-pii-redactor-admin` v1.0.2 at `/admin/pii-redactor`, `padosoft/laravel-flow-admin` v1.0.0 at `/admin/flows`, and `padosoft/eval-harness-ui` v1.0.0 at `/admin/eval-harness` (non-prod only). All three iframe-mounted, all three R30-tenant-scoped via three different strategies (supplementary migration, Authorizer-level filter, HTTP header injection), all three deny-by-default behind explicit Spatie-role-backed Gates.

**What's new in AskMyDocs v4.2.0-rc4 (W4 — three admin SPAs):**

- **W4 / sub-PR 5** — `padosoft/laravel-pii-redactor-admin` v1.0.2 mounted under `/admin/pii-redactor`. Iframe (React 19 + Tailwind v4 isolated from React 18 host). 3 Gates: viewPiiRedactorAdmin / detokenisePiiRedactor / viewPiiRedactorRawSamples. New `dpo` role added to `RbacSeeder` (5 roles total). R30 supplementary migration adds `tenant_id` to package's audit table + `creating` Eloquent observer. PR #121.
- **W4 / sub-PR 6** — `padosoft/laravel-flow-admin` v1.0.0 mounted under `/admin/flows`. Iframe (Blade + Alpine). 1 outer-fence Spatie-role Gate `viewFlowAdmin` + 8 row-scoped `ActionAuthorizer` methods (`canViewKpis` / `canViewRuns` / `canViewRunDetail` / `canReplayRun` / `canCancelRun` / `canApproveByToken` / `canRejectByToken` / `canRetryWebhook`) implemented in `AskMyDocsFlowAuthorizer`. R30 via the same authorizer (row-scoped tenant lookup). `FlowAdminEnabled` middleware aborts 404 when `FLOW_ADMIN_ENABLED=false` (default). Operators visualise the 9 Flow definitions registered by sub-PRs 3a-3d live in the cockpit. PR #122.
- **W4 / sub-PR 7** — `padosoft/eval-harness-ui` v1.0.0 mounted under `/admin/eval-harness` (non-prod only). Iframe (React + Vite isolated bundle). 1 read-only Gate `eval-harness.viewer` (super-admin + admin + dpo + editor). Three independent fail-closed fences (env flag + APP_ENV + Gate). R30 via `EvalHarnessUiTenantHeader` middleware injecting `X-Eval-Harness-Tenant` from `TenantContext`. `class_exists()` guard in `bootstrap/providers.php` so `composer install --no-dev` deploys don't crash. Package lives in `require-dev` per the v4.2 plan. PR #123.
- **(this PR)** v4.2/W4 closure docs — adds this Changelog entry, the W4 ribbon under `### Key Features`, and the closure status doc `docs/v4-platform/STATUS-2026-05-10-week4-admin-spas.md`.

**Pull requests merged on `feature/v4.2` for v4.2.0-rc4:**
- #121 v4.2/W4 — sub-PR 5 — pii-redactor-admin v1.0.2
- #122 v4.2/W4 — sub-PR 6 — flow-admin v1.0.0
- #123 v4.2/W4 — sub-PR 7 — eval-harness-ui v1.0.0
- (this PR) v4.2/W4 closure — Changelog entry + Key Features + closure status doc

**Test count:** 1328 → 1371 (+43 PHPUnit + 3 new Playwright specs). All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest + Playwright E2E + the RAG regression workflow.

### v4.2.0-rc3 — 2026-05-10 (W3 milestone — `padosoft/eval-harness` v1.2 RAG regression CI gate)

Third release candidate of the **v4.2 cycle**. W3 bumps
`padosoft/eval-harness` from `^0.1.0` (require-dev, vendored, zero call
sites in v4.0 / v4.1) to `^1.2.0` (GA stable line on Packagist) and
wires **a real RAG regression gate into CI** that exercises the full
AskMyDocs RAG pipeline against a 42-sample golden dataset on every PR
touching the RAG hot path — backed by 4 metrics, 4 cohorts, 3 advisory
adversarial manifests, and 3 batch profiles. Cost guard via
`Http::fake()` by default; live-AI mode opt-in via `workflow_dispatch`.

**What's new in AskMyDocs v4.2.0-rc3 (W3 — eval-harness v1.2 CI gate):**

- **W3 / sub-PR 4** — `padosoft/eval-harness` constraint moved from
  `require-dev` `^0.1.0` to `require-dev` `^1.2.0`. Obsolete VCS
  repository entry removed from `composer.json` (the package is now on
  Packagist). New `App\Eval\EvalRegistrar` registers 4 datasets — 1
  baseline (4 metrics: `contains` + `cosine-embedding` +
  `CosineGroundednessMetric` + `CitationGroundednessMetric`) and 3
  adversarial (3 metrics: `contains` + `refusal-quality` +
  `CitationGroundednessMetric`). 2 custom AskMyDocs
  metrics: `CosineGroundednessMetric` (cosine similarity between
  answer text and cited chunks' text — proves grounding-in-citations)
  and `CitationGroundednessMetric` (strict matching with
  phantom-cap@0.5 + refusal-fabrication-zero). 42 baseline + 12
  adversarial Q&A samples in `tests/Eval/golden/`. New
  `.github/workflows/rag-regression.yml` triggered on PR + push to
  main / feature/v4.* + manual dispatch — baseline gates the build,
  adversarial steps are advisory (`continue-on-error: true`) since
  `Http::fake()` canned responses cannot perfectly mimic the
  production model's refusal behavior. `tests/Feature/Eval/RegressionDetectionTest`
  proves the gate **actually catches** regressions (R16). PR #119.
- **(this PR)** v4.2/W3 closure docs — adds this Changelog entry,
  promotes the W3 ribbon under `### Key Features` to "rc3 shipped",
  and lands `docs/v4-platform/STATUS-2026-05-10-week3-eval-harness-ci-gate.md`.

**Pull request merged on `feature/v4.2` for v4.2.0-rc3:**
- #119 v4.2/W3 — sub-PR 4 — eval-harness v1.2 RAG regression CI gate
- (this PR) v4.2/W3 closure — Changelog entry + ribbon promotion + closure status doc

**Test count:** 1306 → 1328 (+22 PHPUnit). All green across PHPUnit
(PHP 8.3 / 8.4 / 8.5) + Vitest + Playwright E2E + the new RAG
regression workflow itself.

### v4.2.0-rc2 — 2026-05-10 (W2 milestone — `padosoft/laravel-flow` v1.0 integration)

Second release candidate of the **v4.2 cycle**. W2 graduates
`padosoft/laravel-flow` from `require-dev` `^0.1` (vendored, zero call
sites in v4.0 / v4.1) to `require` `^1.0` and migrates **the entire
multi-step background pipeline surface** of AskMyDocs onto **8 Flow
definitions** orchestrated by the engine — saga / compensation,
approval gates, dry-run mode, idempotency keys, persisted forensic
audit per step, all under R30/R31 tenant scoping.

**What's new in AskMyDocs v4.2.0-rc2 (W2 — laravel-flow v1.0 integration):**

- **W2 / sub-PR 3a** — `padosoft/laravel-flow` constraint moved from
  `require-dev` `^0.1` to `require` `^1.0`. Auto-discovered;
  `FlowServiceProvider` registers definitions on every boot. 4 published
  migrations + supplementary `tenant_id` migration adding the column to
  all 5 Flow tables and replacing the engine's global
  `UNIQUE(idempotency_key)` with composite `UNIQUE(tenant_id,
  idempotency_key)` on `flow_runs`. `FlowRunRecord` / `FlowStepRecord` /
  `FlowAuditRecord` `creating` hooks stamp `tenant_id` from
  `TenantContext`. PR #114.
- **W2 / sub-PR 3b** — `IngestDocumentJob` becomes a thin Flow
  dispatcher of `kb.ingest`. 5-step saga (parse-markdown →
  chunk-document → embed-chunks → persist-chunks →
  maybe-dispatch-canonical-indexer); `RollbackChunksCompensator` calls
  `DocumentDeleter::deleteDbOnly()` so the source-of-truth file is
  preserved on transient failures. Try/finally `TenantContext` restore
  in the job's `handle()` prevents tenant leak across queue jobs.
  Idempotency key shape `{tenant}:{project}:{source_path}:{version_hash}`.
  PR #115.
- **W2 / sub-PR 3c** — 3 new Flow definitions: `kb.canonical-index`
  (3 steps + `RollbackCanonicalNodesCompensator`), `kb.promote` (4
  steps with `approval-gate` primitive — first use of approval token in
  AskMyDocs; `KbPromotionController::approve` / `reject` endpoints with
  full token validation: status='pending' + `consumed_at` NULL +
  `decided_at` NULL + `expires_at` not past + tenant_id match + step_name
  match + `flow_runs.definition_name = PromotionFlow::NAME` to prevent
  cross-flow token replay; `WriteCanonicalMarkdownStep` writes file +
  audit atomically — file deleted on audit insert failure;
  `DeleteCanonicalMarkdownCompensator` removes the file if
  dispatch-ingest fails), `kb.delete` (4 steps +
  `RestoreSoftDeletedCompensator`). `flow_audit` →
  `kb_canonical_audit` bridge writes `rejected_promotion` rows when an
  operator rejects a promotion. PR #116.
- **W2 / sub-PR 3d** — 5 scheduled commands + `kb:ingest-folder`
  fan-out migrated to Flow definitions: `kb.prune-deleted`,
  `kb.prune-embedding-cache` (with conditional approval gate via
  `paused()` return — pauses only when projected evictions >
  `KB_EMBEDDING_CACHE_APPROVAL_THRESHOLD`, default 5000; auto-resolves
  under threshold; dry-run always bypasses), `kb.prune-chat-logs`,
  `kb.rebuild-graph` (3-step fan-out using `forceReindex=true` to bypass
  engine-level idempotency cache after truncate), `kb.ingest-folder`
  (3-step fan-out with optional orphan prune). Per-tenant fan-out:
  each command queries DISTINCT `tenant_ids` for eligible rows and
  dispatches one Flow execute per tenant. `DocumentDeleter::deleteOrphans()`
  extended with `?string $tenantId` parameter (R30 cross-tenant orphan
  isolation). All CLI signatures preserved verbatim. PR #117.
- **(this PR)** v4.2/W2 closure docs — adds this Changelog entry, the
  Key Features section above, and the closure status doc
  `docs/v4-platform/STATUS-2026-05-10-week2-flow-integration.md`.

**Pull requests merged on `feature/v4.2` for v4.2.0-rc2:**
- #114 v4.2/W2 — sub-PR 3a — laravel-flow v1.0 install + migrations
- #115 v4.2/W2 — sub-PR 3b — IngestDocumentJob → IngestDocumentFlow refactor
- #116 v4.2/W2 — sub-PR 3c — Flow-orchestrate canonical pipelines (CanonicalIndexer + CanonicalWriter promotion + DocumentDeleter)
- #117 v4.2/W2 — sub-PR 3d — Flow-orchestrate 5 scheduled commands + folder fan-out
- (this PR) v4.2/W2 closure — Changelog entry + Key Features + closure status doc

**Test count:** 1198 → 1306 (+108 PHPUnit). All green across PHPUnit
(PHP 8.3 / 8.4 / 8.5) + Vitest + Playwright E2E.

### v4.2.0-rc1 — 2026-05-09 (W1 milestone — sister-package alignment kickoff)

First release candidate of the **v4.2 cycle** — a focused alignment
window that brings every `padosoft/*` sister package onto its
freshly-shipped stable v1.0+ line. Between 2026-05-05 and 2026-05-09,
all nine sister packages graduated to v1.0+ on GitHub; AskMyDocs was
on the v0.2 / v1.1 era. v4.2 follows R37 (dedicated
`feature/v4.2` integration branch, sub-branches per package) and R39
(per-Wn weekly RC tag at the immutable closure SHA). Patent Box
tracker stays external per the v4.1.0 GA decision documented in PR #110; the cycle
covers seven packages (regolo, pii-redactor + admin, flow + admin,
eval-harness + admin).

**What's new in AskMyDocs v4.2.0-rc1 (W1 — version bumps for
already-wired packages):**

- **W1 / sub-PR 1** — `padosoft/laravel-ai-regolo` `^0.2` → `^1.0`.
  Pure stability promotion: regolo v1.0.0 ships the **same commit
  SHA** as v0.2.3 (zero file diff,
  `gh api repos/padosoft/laravel-ai-regolo/compare/v0.2.3...1.0.0`
  returns `total_commits: 0`). v0.2.3 release notes confirm "public
  API surface unchanged from v0.2.2". `RegoloProvider`,
  `RegoloAnonymousAgent`, `AiManager`'s regolo `match` arm all
  unchanged. Full PHPUnit suite (1082 tests / 3343 assertions) green.
  PR #111.
- **W1 / sub-PR 2** — `padosoft/laravel-pii-redactor` `^1.1` → `^1.2`.
  Drop-in upgrade per upstream CHANGELOG (zero breaking changes, no
  new config knobs, no new middleware, no new artisan commands). v1.2
  only **adds** 6 admin-readiness inspector classes that the upcoming
  W4 sub-PR 5 (`laravel-pii-redactor-admin` SPA wiring) will consume:
  `RedactorAdminInspector`, `RedactionStrategyFactory`,
  `DetectionReportFormatter`, `TokenResolutionService` +
  `DetokeniseResult`, `CustomRulePackInspector`. Existing four
  touch-points (chat middleware, embedding pre-redact, AI-insights
  snippet sanitiser, operator detokenize endpoint) keep working
  unchanged. PR #112.
- **(this PR)** v4.2/W1 closure docs — adds this Changelog entry,
  cleans up the v4.0-era stale `Sister packages composer constraints`
  JSON snippet so it matches the actual current `composer.json`
  (pii-redactor moved from `require-dev` `^0.1.0` to `require` `^1.2`
  during the v4.1 cycle; regolo bumped to `^1.0` in W1).

**Pull requests merged on `feature/v4.2` for v4.2.0-rc1:**
- #111 v4.2/W1 — bump `padosoft/laravel-ai-regolo` `^0.2` → `^1.0`
- #112 v4.2/W1 — bump `padosoft/laravel-pii-redactor` `^1.1` → `^1.2`
- (this PR) v4.2/W1 closure — Changelog entry + composer-snippet
  doc-rot fix

**v4.2 cycle preview (subsequent RCs):**

| Wn | Scope | Closure RC |
|---|---|---|
| W1 (this) | regolo v1.0 + pii-redactor v1.2 | `v4.2.0-rc1` |
| W2 | `padosoft/laravel-flow` v1.0 + `IngestDocumentJob` refactor onto Flow definition (saga / compensation) | `v4.2.0-rc2` |
| W3 | `padosoft/eval-harness` v1.2 + RAG regression CI gate | `v4.2.0-rc3` |
| W4 | Three admin SPAs (`laravel-pii-redactor-admin`, `laravel-flow-admin`, `eval-harness-admin`) | `v4.2.0-rc4` |
| W5 | RC acceptance + `feature/v4.2` → `main` GA merge | **`v4.2.0` GA** |

### v4.1.0-rc1 — 2026-05-03 (W4.1 milestone — `padosoft/laravel-pii-redactor` integration)

First release candidate of the v4.1 cycle. Wires
`padosoft/laravel-pii-redactor` v1.1+ into AskMyDocs at the four
observable touch-points where chat-content PII can leak — chat-message
persistence, embedding-cache key + provider call, AI-insights snippet
sanitiser, and operator-driven detokenisation. Every knob defaults
OFF; existing v4.0 hosts upgrading to v4.1.0-rc1 see byte-identical
behaviour until they explicitly opt in via the `KB_PII_*` env vars.

The companion package shipped its v1.0 community-grade core
(checksum-validated detectors, four redaction strategies, custom-rule
loader, database token store, dual NER drivers, audit-trail event)
plus the v1.1 EU country-pack architecture (Italy + Germany + Spain
packs, with the `PackContract` interface ready for community PRs that
add France / Netherlands / Portugal / etc.).

**What's new in AskMyDocs v4.1.0-rc1:**

- **W4.1.A** — composer integration: `padosoft/laravel-pii-redactor:^1.1`
  moved from `require-dev` (v4.0.x spike) to `require`. New
  `pii_redactor` block in `config/kb.php` with five default-false
  knobs. Explicit SP registration in `bootstrap/providers.php` as a
  Windows / Herd auto-discovery safety net. PR #103.
- **W4.1.B** — `App\Http\Middleware\RedactChatPii` bound to
  `POST /conversations/{conversation}/messages` (sync) +
  `/messages/stream` (SSE) only. Architecture test pins the binding
  scope so curator / admin / promotion / delete routes are NEVER
  redacted (would silently corrupt the canonical KB pipeline). 5
  feature tests + 2 architecture tests. PR #104.
- **W4.1.C** — `EmbeddingCacheService::generate()` masks PII out of
  every input BEFORE the SHA-256 cache hash AND BEFORE the embedding
  provider's HTTP call when both gate knobs are on. Mask strategy
  preserves cache hit-rate. 3 feature tests. PR #105.
- **W4.1.D** — `AiInsightsService::coverageGaps()` masks chat sample
  questions before clustering; new `POST /api/admin/logs/chat/{id}/detokenize`
  operator endpoint with 422/403/200 contract gated by Spatie
  permission `kb.pii_redactor.detokenize_permission` (default
  `pii.detokenize`); every 200/403 writes an `admin_command_audit`
  row. R30 tenant-scoped on every new `chat_logs` read. 6 feature
  tests. PR #106.
- **W4.1.E** — closure status doc + end-to-end architecture test
  (`PiiRedactorIntegrationScopeTest`) pinning all four touch-points
  + their gates + their R30 tenant-scoping markers + the audit-row
  contract on the detokenize endpoint. README "Key Features" + this
  Changelog entry refreshed.

**Pull requests merged on `feature/v4.1` since v4.0.2:**
- #103 v4.1/W4.1.A — composer + config + .env scaffold
- #104 v4.1/W4.1.B — `redact-chat-pii` middleware + chat-route binding
- #105 v4.1/W4.1.C — embedding-cache pre-redact (mask before hash + provider call)
- #106 v4.1/W4.1.D — AI-insights snippet redact + LogViewer detokenize action
- (this PR) v4.1/W4.1.E — closure status doc + end-to-end architecture test + README/Changelog refresh

Closure: `docs/v4-platform/STATUS-2026-05-03-week4.1.md`

### v4.0.2 — 2026-05-03 (Docs honesty pass — sister packages integration roadmap)

Docs-only patch correcting an accuracy gap in the v4.0.0 GA / W5–W7 closure narratives. The original release notes described `padosoft/laravel-flow`, `padosoft/eval-harness`, and `padosoft/laravel-pii-redactor` as "shipped engines" when in reality they ship as **v0.1.0 scaffold packages published as Git tags (pending Packagist submission, resolved via VCS `repositories` entries in `composer.json`)** and AskMyDocs's `composer.json` declares them in `require-dev` without any `use Padosoft\…` import in `app/`. Only `padosoft/laravel-ai-regolo` (W2) reaches the runtime today via `RegoloProvider`.

**What this patch changes:**
- Sister packages tables in the README's Main components section + the dedicated v4.0 section now carry an explicit **Integration status** column distinguishing **integrated** vs **scaffold on Packagist** vs **external runner by design**.
- New top-level integration roadmap doc at [`docs/v4-platform/INTEGRATION-ROADMAP-sister-packages.md`](docs/v4-platform/INTEGRATION-ROADMAP-sister-packages.md) — per-package timelines aligned with each upstream package's enterprise plan (`docs/ENTERPRISE_PLAN.md` for laravel-flow, `docs/ROADMAP_IMPLEMENTATION_PLAN.md` for eval-harness), with concrete `app/` touch points for each integration.
- Roadmap ordering for v4.1: **(1) pii-redactor first** (largest production risk surface — GDPR exposure on chat retention), **(2) laravel-flow second** (saga conversion of `IngestDocumentJob` + canonical promotion + bulk delete), **(3) eval-harness third** (RAG regression CI gate). Each integration is gated on the upstream package shipping its v0.2.
- v4.0.0 / v4.0.1 historical changelog entries are preserved verbatim — this patch documents reality without rewriting history.

**Pull requests merged on `main` since v4.0.1:**
- #102 v4.0.2 — sister packages integration roadmap + README honesty pass

### v4.0.1 — 2026-05-03 (Patch release — embedding_cache + W3.4 cleanup)

First v4.0.x patch closing the two follow-ups parked in the v4.0.0 GA release notes. Drop-in replacement for v4.0.0 — no breaking changes, no migration data dedupe, no consumer code change required.

**What landed:**
- `embedding_cache` schema fix — composite UNIQUE on `(text_hash, provider, model)` (migration `2026_05_03_000001_change_embedding_cache_unique_to_composite.php`). Switching embedding model no longer requires a pre-flush; multi-model deployments can coexist.
- `EmbeddingCacheService::resolveModelName()` regolo bug fix (surfaced during Copilot's review on the v4.0.1 PR) — pre-fix, the resolver returned `'unknown'` for the `regolo` provider while inserts stored the real model name; cache lookups never matched their own writes for regolo. v4.0.1 ships both fixes together.
- W3.4 cleanup — drops the dual `'source'` / `'source-url'` discriminator from the FE adapter (BE emits `source-url` exclusively post-PR #90); 16 test fixtures renamed; stale "differs from W3.1 BE wire format" notes corrected in `stub-chat.ts`.

**Pull requests merged on `main` since v4.0.0:**
- #101 v4.0.1 — `embedding_cache` composite UNIQUE + regolo resolver fix + W3.4 source-url cleanup

### v4.0.0 — 2026-05-02 (GA — full v4.0 cycle closed)

> **v4.0.2 honesty correction** — the per-week deliverable narrative below describes the W5/W6/W7 sister packages (`laravel-flow`, `eval-harness`, `laravel-pii-redactor`) as "shipped" features, which is accurate at the **Packagist scaffold** level (the v0.1.0 tags exist with interfaces + ServiceProviders + foundational tests) but overstates the **AskMyDocs `app/` runtime integration**, which doesn't exist yet for those three. Only `padosoft/laravel-ai-regolo` (W2) is wired into the runtime. v4.0.1 / v4.0.2 patches preserve the historical narrative below verbatim and add a [sister packages integration roadmap](docs/v4-platform/INTEGRATION-ROADMAP-sister-packages.md) documenting the v4.1+ plan honestly.

The v4.0.0 GA closes the **8-week v4.0 cycle**. `feature/v4.0` was merged into `main` once per R37 with all W1..W8 work landing as a single squashed integration commit (PR #98). Stable consumers can now pin to `^4.0`; the release-candidate channel (`^4.0.0-rc1` … `^4.0.0-rc4`) stays available as preserved Git tags but is no longer the recommended consumer constraint.

**Deliverables shipped across the v4.0 cycle**
- **W1** — Multi-tenant foundation: `BelongsToTenant` trait + `TenantContext` singleton + `ResolveTenant` HTTP middleware + `--tenant=X` CLI option; 17 tenant-aware tables carry `tenant_id` (default `'default'` preserves v3.x backward compatibility); R30/R31 architecture tests gate every new tenant-aware model. `embedding_cache` is intentionally **NOT** tenant-scoped — the cache is a cross-tenant reuse layer keyed on `text_hash` UNIQUE.
- **W2** — Provider federation via `laravel/ai` SDK: `padosoft/laravel-ai-regolo` v0.2.x published on Packagist (historical pin — graduated to v1.0.0 in v4.2; see "Sister packages composer constraints" below); AskMyDocs `RegoloProvider` rewritten to delegate through `laravel/ai`; multi-step finishReason fix; chat-history validation tightened. R36 + R37 + R38 codified during W2.
- **W3** — Vercel AI SDK chat migration (design fidelity 1:1): new `POST /conversations/{conversation}/messages/stream` SSE endpoint with `auth.sse` middleware (returns JSON 401, never HTML); `useChatStream()` hook + transport + message-shape adapters; legacy `use-chat-mutation` deleted in a single atomic commit (PR #89); 60+ stable testids preserved across the swap; 22 pixel-level `toHaveScreenshot({ maxDiffPixels: 0 })` assertions in `chat-visual.spec.ts` (15 core states + 7 supplementary); wire format aligned to SDK v6 `UIMessageChunk` shape (`start` / `text-start` / `text-delta(id, delta)` / `text-end` / `source-url`; `data-confidence` + `data-refusal` nested under `data:{}`; `finish` constrained via `normalizeFinishReason()`). First-token latency dropped from ~2.8 s (synchronous JSON wait) to ~400 ms (first SSE chunk) on Lighthouse baseline.
- **W4** — `padosoft/laravel-patent-box-tracker` v0.1.0 on Packagist + `tools/patent-box/2026.yml` dogfood template; **commercialista-validated** dossier output for the Italian Patent Box (110% R&D super-deduction, regime `documentazione_idonea`); tagged `v4.0.0-rc1`.
- **W5** — `padosoft/laravel-flow` v0.1.0 on Packagist (saga / compensation engine; 32 Unit + 2 Architecture tests on Laravel 13); tagged `v4.0.0-rc2`.
- **W6** — `padosoft/eval-harness` v0.1.0 on Packagist (RAG / LLM evaluation framework; 87 Unit + 3 Architecture tests; deterministic-by-default execution); tagged `v4.0.0-rc3`.
- **W7** — `padosoft/laravel-pii-redactor` v0.1.0 on Packagist (six checksum-validated detectors including Italian Codice Fiscale + Partita IVA + IBAN mod-97 + Luhn; four redaction strategies — Mask, Hash, Tokenise reversible, Drop; 68 Unit + 2 Architecture tests; zero LLM dependency) + `padosoft/askmydocs-pro` foundation seed (private BSL-1.1 commercial sister package; foundation-only); tagged `v4.0.0-rc4`.
- **W8** — RC acceptance gates audit (`docs/v4-platform/STATUS-2026-05-02-week8-rc-acceptance.md`) + `feature/v4.0` → `main` once-per-major merge (PR #98) + `v4.0.0` GA tag.

**Sister packages composer constraints (v4 release train)** — `padosoft/laravel-ai-regolo:^1.0` and `padosoft/laravel-pii-redactor:^1.2` are load-bearing in `require` (chat path + v4.1 PII redactor integration respectively). `padosoft/laravel-flow:^1.0` is also in `require` since v4.2/W2 sub-PR 3a (PR #114) — installed as a runtime dependency with its 4 published migrations applied + an AskMyDocs supplementary migration adding tenant_id (R30/R31) — but its actual orchestration of AskMyDocs's pipelines (`IngestDocumentJob` etc.) is **pending** in follow-up sub-PRs 3b/3c/3d. `padosoft/eval-harness` remains in `require-dev` (CI-only, now pinned to `^1.2.0`). `padosoft/laravel-patent-box-tracker` is intentionally NOT declared in AskMyDocs's `composer.json` — operators install it in their own Laravel project per R37 (see [Patent Box dossier](#patent-box-dossier-v40-dogfood)).
```json
{
    "require": {
        "padosoft/laravel-ai-regolo":          "^1.0",
        "padosoft/laravel-pii-redactor":       "^1.2",
        "padosoft/laravel-flow":               "^1.0"
    },
    "require-dev": {
        "padosoft/eval-harness":               "^1.2.0"
    }
}
```
All five sister packages named in this paragraph (`laravel-ai-regolo`, `laravel-pii-redactor`, `laravel-flow`, `eval-harness`, `laravel-patent-box-tracker`) are **standalone-agnostic** — zero references to `KnowledgeDocument`, `KbSearchService`, `kb_*` tables, `lopadova/askmydocs`, or any other sister Padosoft package in their own `src/`. Architecture tests enforce this on every CI run.

**Pull requests merged on `feature/v4.0` since v3.0.0** (W5..W8 additions on top of the rc1 list below)
- #96 W7.G — RC2/RC3/RC4 cuts + W5+W6+W7 closure docs + README + dogfood YAML refresh
- #97 W8.A — RC acceptance gates audit + closure status doc
- #98 W8.B — `feature/v4.0` → `main` integration merge + `v4.0.0` GA tag (this release)
- #99 W8.B pre-GA — Copilot must-fix on PR #98 (composer GA pin + `embedding_cache` cross-tenant correction + 5 minor)

**Known follow-ups parked for v4.0.x / v4.1**
- `embedding_cache` schema follow-up — surfaced during the PR #99 audit. The schema enforces `UNIQUE(text_hash)` alone, but `EmbeddingCacheService` queries by `text_hash + provider + model`. Switching the embedding model without first calling `EmbeddingCacheService::flush($provider)` triggers a duplicate-key error on `text_hash`. A v4.0.x patch will add a composite UNIQUE on `(text_hash, provider, model)` plus a data migration.
- Optional W3.4 cleanup — drop the dual `'source'` / `'source-url'` discriminator from `frontend/src/features/chat/message-shape-adapters.ts::getCitations` (the BE now emits `source-url` exclusively after PR #90); drop the `NOTE: stub vs BE shape divergence` block from `frontend/e2e/helpers/stub-chat.ts`. Zero-functional-change diff, kept parked indefinitely if not requested.

**Cycle metadata**
- Length: 8 weeks (2026-04-26 → 2026-05-02 — W4/W5/W6/W7 closed inside a 24-hour window).
- R36 cycles consumed across the cycle: ~70 across all PRs (W3 PR #89 set the high-water mark at 13 cycles for a single PR).
- Auto-merge convention applied throughout (`feedback_auto_merge_when_ready`).
- 4 prerelease tags (rc1..rc4) pinned to exact closure SHAs preceded this GA per R39.

### v4.0.0-rc4 — 2026-05-02 (W7 milestone)

- `padosoft/laravel-pii-redactor` v0.1.0 published on Packagist — 6 checksum-validated detectors (Email, IBAN with mod-97 over ~75 ISO 13616 countries, Credit Card with Luhn, Italian Phone +39 mobile + landline, Codice Fiscale with the DM 23/12/1976 CIN checksum, Partita IVA with Luhn-IT + zero-payload sentinel) + 4 redaction strategies (Mask, Hash deterministic SHA-256 namespaced per-detector, Tokenise reversible, Drop). Regex + checksum based — zero LLM dependency. PR #3 → `956089b`.
- `padosoft/askmydocs-pro` foundation seeded (private BSL-1.1). LICENSE with the four canonical BSL parameters (Change Date 4 years from each release date, Change License Apache-2.0); composer manifest declares the v4 sister-package dependency train _(historical W7 snapshot of askmydocs-pro's own composer pins: `lopadova/askmydocs ^4.0.0-rc1` + `padosoft/laravel-ai-regolo ^0.2` + `padosoft/laravel-flow ^0.1` + `padosoft/eval-harness ^0.1` + `padosoft/laravel-pii-redactor ^0.1`; AskMyDocs's own constraints have advanced — see "Sister packages composer constraints" section)_; `.claude/` vibe-coding pack imported; CI lint loop wired up with the empty-`src/` tolerance branch. Product code lands in v4.1+. PR #1 → `085a89c` (foundation) + PR #2 → `53577ce` (Copilot review fix-up — BSL "Change License" canonical-US-spelling, CONTRIBUTING foundation-only-state caveat, CHANGELOG surface-decision).
- Closure status doc: `docs/v4-platform/STATUS-2026-05-02-week7.md`.

### v4.0.0-rc3 — 2026-05-02 (W6 milestone)

- `padosoft/eval-harness` v0.1.0 published on Packagist — RAG / LLM evaluation framework. Pluggable golden-dataset YAML loader (strict-schema with 11 distinct validation failure modes) + R23 metric registry with FQCN validation at boot + `supports()` mutex check + 3 first-party metrics (ExactMatch byte-equality, CosineEmbedding `1 - cosine_distance` with dimensionality + zero-vector guards, LlmAsJudge with deterministic seed + temp 0 + `response_format=json_object` + strict-JSON parser with code-fence fallback) + 2 report renderers (Markdown diff-friendly, canonical JSON additive-only per R27) + `eval-harness:run` Artisan command exiting non-zero on captured failures + opt-in Live testsuite (`EVAL_HARNESS_LIVE_API_KEY` gate per `feedback_package_live_testsuite_opt_in`). PR #3 → `7012aa2`.
- Closure status doc: `docs/v4-platform/STATUS-2026-05-02-week6.md`.

### v4.0.0-rc2 — 2026-05-02 (W5 milestone)

- `padosoft/laravel-flow` v0.1.0 published on Packagist — saga / compensation engine. Fluent definition API (`FlowDefinitionBuilder` with `withInput` / `step` / `compensateWith` / `withDryRun` / `register`) + reverse-order compensation chain (best-effort with aggregated `FlowCompensationException` carrying every individual compensator's failure) + native dry-run mode (`FlowStepResult::dryRunSkipped`) + 4 Laravel events (`FlowStepStarted` / `FlowStepCompleted` / `FlowStepFailed` / `FlowCompensated`) + readonly DTOs (`FlowDefinition`, `FlowStep`, `FlowContext`, `FlowStepResult`, `FlowRun`) + `Flow` Facade alias + non-final `FlowException` parent class per the W4.C lesson. PR #3 → `208a9d1`.
- Closure status doc: `docs/v4-platform/STATUS-2026-05-02-week5.md`.

### v4.0.0-rc1 — 2026-05-02 — W1..W4 milestone (release candidate)

The v4.0 series promotes AskMyDocs onto the Vercel AI SDK chat surface,
extracts shared infrastructure into a family of standalone `padosoft/*`
Composer packages, and lays the architectural foundation for true
multi-tenant deployments. This is a **release candidate** — `v4.0.0` GA
fires at the end of W8 when `feature/v4.0` merges into `main` per R37.
Stable consumers stay on v3.x; opt into the rc with
`composer require lopadova/askmydocs:^4.0.0-rc1`.

**W1 — `tenant_id` foundation + R30/R31 architecture invariants**
- `tenant_id` column added across every tenant-aware table
  (knowledge_documents, knowledge_chunks, embedding_cache, chat_logs,
  conversations, messages, kb_nodes, kb_edges, kb_canonical_audit,
  project_memberships, kb_tags, knowledge_document_tags,
  knowledge_document_acl, admin_command_audit, admin_command_nonces,
  admin_insights_snapshots, chat_filter_presets) with
  `default 'default'` so every v3.x row stays addressable
- `TenantContext` singleton + `BelongsToTenant` trait (auto-fills
  `tenant_id` on `creating` from the active tenant) + `ResolveTenant`
  HTTP middleware + `--tenant=X` CLI option
- **R30** — every Eloquent query against tenant-aware tables MUST be
  scoped to the active tenant via `forTenant()` or
  `where('tenant_id', $ctx->current())`; cross-tenant leak = GDPR
  catastrophe
- **R31** — every tenant-aware model MUST `use BelongsToTenant;` and
  list `'tenant_id'` in `$fillable`; architecture test
  `tests/Architecture/TenantIdMandatoryTest.php` enumerates the model
  list and gates new entries
- Composer path repositories wired for the four new `padosoft/*` v4
  packages (PR #78); Padosoft company Claude pack imported under
  `.claude/` (PR #80)

**W2 — `padosoft/laravel-ai-regolo` v0.2 + AskMyDocs adopts `laravel/ai` SDK** _(historical milestone — package now at v1.0 since v4.2)_
- `padosoft/agent-llm` renamed to `padosoft/laravel-ai-regolo` (PR #81);
  the package now ships as a standalone Apache-2.0 Composer package
- AskMyDocs's `RegoloProvider` delegates to the new package which
  itself sits on top of the `laravel/ai` SDK (PRs #83/#84) — one
  shared SDK abstraction across all five chat providers
- Driver test parity ported from the upstream Python SDK into PHP, with
  added robustness scenarios (timeouts, retries, malformed JSON)

**W3 — Vercel AI SDK chat migration (token-streaming end-to-end)**
- Backend SSE endpoint + 8-scenario PHPUnit suite (PR #87)
- React foundation + tests for the new streaming chat surface (PR #88)
- Atomic chat swap onto `useChatStream()` — drops the legacy mutation
  flow; `ChatView` + `MessageThread` + `Composer` + `MessageBubble` all
  refactored in a single commit (PR #89)
- BE wire format aligned to SDK v6 `UIMessageChunk` shape; FE adapter
  supports both `'source'` and `'source-url'` chunk types for forward
  compat (PR #90)

**W4 — Patent Box auto-tracker (`padosoft/laravel-patent-box-tracker` v0.1)**
- Standalone Apache-2.0 Composer package shipped to Packagist
  (`padosoft/laravel-patent-box-tracker:^0.1`) — first of its kind, no
  equivalent Italian-Patent-Box-aware Laravel package exists on
  Packagist as of 2026-05
- 8-section architecture: evidence collectors (`Sources\*`),
  classifier with deterministic seed (`Classifier\*`), audit-trail
  models + migrations, dossier renderers (PDF via Browsershot +
  DomPDF fallback, JSON canonical output), per-commit hash chain,
  `Console\TrackCommand` + `Console\CrossRepoCommand`, fluent builder
  `PatentBoxTracker::for(...)->coveringPeriod()->classifiedBy()->run()`,
  Italian Blade dossier template
- ~5,800 LOC, 163 PHPUnit tests / 1079 assertions, opt-in Live
  testsuite, CI matrix PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13
- Standalone-agnostic invariant enforced by architecture test — zero
  references to `KnowledgeDocument`, `KbSearchService`, `kb_*` tables,
  or `lopadova/askmydocs` in package source
- Real-world dogfood: AskMyDocs ships `tools/patent-box/2026.yml` —
  Lorenzo's FY2026 Italian Patent Box dossier (Padosoft ditta
  individuale, regime `documentazione_idonea`) is generated from a
  separate Laravel runner project that has the tracker installed
  (`composer require padosoft/laravel-patent-box-tracker`) by running
  `php artisan patent-box:cross-repo /path/to/AskMyDocs/tools/patent-box/2026.yml`
  against AskMyDocs `feature/v4.0` plus the five sister Padosoft
  repositories — the YAML lives under AskMyDocs but the artisan
  command runs from the consumer Laravel project, never from
  AskMyDocs itself
- Closure status doc at `docs/v4-platform/STATUS-2026-05-01-week4.md`
- **R39** — tag `vX.Y.0-rcN` at every Wn milestone closure on AskMyDocs
  `feature/vX.Y` — codified in `CLAUDE.md` + skill
  `.claude/skills/rc-tag-per-week-milestone/`

**Pull requests merged on `feature/v4.0` since v3.0.0**
- #78 W1.B — Composer path repositories for 4 new padosoft/* packages
- #79 W1.C+D+E — `tenant_id` foundation: migration + `TenantContext` + R30/R31
- #80 W1.H — import Padosoft company Claude pack
- #81 W2.A.0 — rename `padosoft/agent-llm` → `padosoft/laravel-ai-regolo`
- #82 W2 — `PHP_CLI_SERVER_WORKERS=4` + 24s retry for artisan-serve flake (e2e)
- #83 W2.B prep — R36 9-step flow + `laravel/ai` SDK foundation
- #84 W2.B refactor — `RegoloProvider` delegates to `laravel/ai` + `padosoft/laravel-ai-regolo`
- #86 W3 — Vercel AI SDK chat migration design doc + W2 closure
- #87 W3.1 — backend SSE streaming endpoint + 8-scenario PHPUnit suite
- #88 W3.2 — Vercel AI SDK chat migration (foundation + tests; SDK swap to follow)
- #89 W3.2 — atomic chat swap onto `useChatStream()` — drop legacy mutation flow
- #90 W3.3 — align BE SSE wire format to SDK v6 `UIMessageChunk` shape
- #91 W4 — W3 closure + Patent Box tracker design doc
- #92 — copilot-pr-review-loop skill: codify dual-bot polling pattern
- #93 W4 — W4 closure status + Patent Box tracker dogfood YAML config
- #94 — R39 rule (tag `vX.Y.0-rcN` at every Wn milestone closure)
- #95 W4.F — README + STATUS-week4 + dogfood YAML refresh for `v4.0.0-rc1`

**Roadmap — still pending in v4.0**
- ~~**W5** — `padosoft/laravel-flow` v0.1 (saga / workflow orchestration)~~ — shipped 2026-05-02 (`v4.0.0-rc2`); closure under `STATUS-2026-05-02-week5.md`
- ~~**W6** — `padosoft/eval-harness` v0.1 (LLM evaluation harness)~~ — shipped 2026-05-02 (`v4.0.0-rc3`); closure under `STATUS-2026-05-02-week6.md`
- ~~**W7** — `padosoft/laravel-pii-redactor` v0.1 (PII redaction layer) + `padosoft/askmydocs-pro` foundation~~ — shipped 2026-05-02 (`v4.0.0-rc4`); closure under `STATUS-2026-05-02-week7.md`
- ~~**W8** — final v4.0.0 GA + merge `feature/v4.0` → `main` per R37~~ — shipped 2026-05-02 (`v4.0.0` GA via PR #98); closure under `STATUS-2026-05-02-week8-rc-acceptance.md`

### v3.0.0 (2026-04-27) — Enterprise platform: pluggable pipeline + filters + anti-hallucination

The v3.0 series turns AskMyDocs from "RAG chat with admin" into an
enterprise knowledge platform with a **pluggable ingestion pipeline**
(markdown / text / PDF / DOCX), **rich chat retrieval filters** (10
dimensions + saved presets + @mention pinning), and **anti-hallucination
tier-1** (deterministic refusal + composite confidence score).

**M1 — Pluggable ingestion pipeline (PRs #36–#44)**
- `ConverterInterface` + `ChunkerInterface` + `EnricherInterface`
  contracts; `PipelineRegistry` with FQCN validation at boot
- 4 source types shipped: markdown, text, PDF (`smalot/pdfparser`),
  DOCX (`phpoffice/phpword`); one ingestion execution path
  (`DocumentIngestor::ingest(SourceDocument)`)
- `PdfPageChunker` (page-aware) co-exists with `MarkdownChunker`
  via first-match-wins `supports()` mutex (R23 — codified)
- `SourceType` enum (helper-only — column stays string for back-compat)
- Two ingest entrypoints (CLI + HTTP) converge on the same path

**M2 — Enterprise chat filters (PRs #45–#52, #67–#72)**
- `RetrievalFilters` DTO with **10 dimensions**: `project_keys`,
  `tag_slugs`, `source_types`, `canonical_types`, `connector_types`,
  `doc_ids`, `folder_globs`, `date_from/to`, `languages`
- Per-user **saved presets** (`/api/chat-filter-presets`) — RESTful
  CRUD, 404-not-403 cross-user isolation, lossless round-trip
- **@mention pinning**: `/api/kb/documents/search` autocomplete +
  FE `MentionPopover` with cursor-context detection
- React SPA: `Composer` redesigned with persistent `FilterBar` +
  removable `FilterChip`s + tabbed `FilterPickerPopover` + saved
  presets dropdown + admin Tags CRUD (`/app/admin/kb/tags`)
- Folder globs support `**` cross-segment via in-house glob→regex
  translator (PHP fnmatch + FNM_PATHNAME doesn't)
- LIKE escape with explicit `ESCAPE '\\'` clause for SQLite + pgsql
  portability (R19 reaffirmed in v3.0 context)

**M3 — Anti-hallucination tier-1 (PRs #54–#65, #67)**
- Deterministic **refusal short-circuit**: if no chunks pass the
  similarity floor, `KbChatController` returns `refusal_reason='no_relevant_context'`
  + `confidence=0` + empty citations **WITHOUT calling the LLM**
  (proven via Mockery's `shouldNotReceive('chat')` — R26)
- LLM **self-refusal sentinel** (`__NO_GROUNDED_ANSWER__` in the
  prompt) → controller converts to `refusal_reason='llm_self_refusal'`;
  exact-match-after-trim, never substring (preserves partial answers)
- **Composite confidence score** 0..100 = `0.40·mean_top_k_sim +
  0.20·threshold_margin + 0.20·chunk_diversity + 0.20·citation_density`
  (`ConfidenceCalculator`, producer-side clamped, schema column
  nullable for legacy rows)
- API response shape: `confidence` + `refusal_reason` at the top
  level; `meta.search_strategy` + `meta.retrieval_stats` +
  `meta.latency_ms_breakdown` (R27 additive-only — `meta.latency_ms`
  stays a flat int sibling)
- Per-reason **i18n hierarchy** in `lang/{en,it}/kb.php`:
  `kb.refusal.{reason}` with `kb.no_grounded_answer` fallback (R24)
- FE: `ConfidenceBadge` (high/moderate/low/refused tiers) +
  `RefusalNotice` (`role="status"`, NOT alert — refusal is a quality
  signal, not an error)

**M4 — Consolidamento (PR #75 — this release closure)**
- 7 new permanent rules in CLAUDE.md (R23..R29)
- 3 new skills under `.claude/skills/`:
  `pluggable-pipeline-registry`, `optimistic-mutation-dedupe`,
  `refusal-not-error-ux`
- Full LESSONS digest at `docs/v3-platform/LESSONS-v3.0-digest.md`
- COPILOT-FINDINGS.md updated with v3.0 PR cohort

**Numbers**
- ~30 sub-tasks executed across 4 milestones
- ~25 PRs merged (sub-PRs + macro PRs + closeouts + recovery)
- **PHPUnit: 985 tests / 3017 assertions** (was 905/2630 at start of v3.0 → +80 tests / +387 assertions)
- **Vitest: 224 tests** (was 149 at start → +75 cases)
- **Playwright: 28 spec scenarios** across `chat-filters`, `chat-mention`, `chat-refusal`, `admin-tags`
- 28 LESSONS entries (T1.x..T2.9 date-stamped + L17..L28 numbered)

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
  (`admin_command_audit` row flips around the `Artisan::call()`), (6) per-user
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
