# v4.6 Week 4 closure — 2026-05-12 — RC acceptance + GA prep

The v4.6 cycle is a focused 4-week extraction series that converts the
seven inline connectors built during v4.5/W1–W6 into standalone
`padosoft/askmydocs-connector-*` composer packages plus one shared
`padosoft/askmydocs-connector-base` framework. v4.6 ships **no new
end-user features**: every connector continues to behave identically
under the same admin SPA — the change is architectural (package
boundary + IoC bridge) and operational (community + downstream
adoption paths).

W4 is the final closure step before the once-per-major
`feature/v4.6` → `main` merge per R37 and the `v4.6.0` GA tag per R39.
W1–W3 shipped the 8 packages themselves (each with its own CI green
state); W4 is the **host-side wire-up + inline-code deletion + docs
refresh** PR — which this status document audits.

## Sub-tasks shipped (cycle-wide, W1..W4)

| Wn | Deliverable | Outcome |
|---|---|---|
| W1 | `padosoft/askmydocs-connector-base` v1.1.1 — framework primitives, `ConnectorInterface`, `BaseConnector`, `ConnectorRegistry`, `ConnectorSyncJob`, `OAuthCredentialVault`, `SyncScheduler`, `ConnectorIngestionContract` IoC, two framework migrations | Shipped + CI green |
| W2.A | `padosoft/askmydocs-connector-notion` v1.0.1 — Notion OAuth2 + page/block sync + native markdown rendering | Shipped + CI green |
| W2.B | `padosoft/askmydocs-connector-google-drive` v1.0.1 — Google Drive OAuth2 + Drive API v3 sync | Shipped + CI green |
| W2.C | `padosoft/askmydocs-connector-evernote` v1.0.0 — Evernote OAuth2 + Cloud API + ENEX bulk-import | Shipped + CI green |
| W3.A | `padosoft/askmydocs-connector-fabric` v1.0.0 — Fabric.so API-key + OAuth-ready | Shipped + CI green |
| W3.B | `padosoft/askmydocs-connector-onedrive` v1.0.0 — Microsoft Graph + MS Identity v2 | Shipped + CI green |
| W3.C | `padosoft/askmydocs-connector-confluence` v1.0.0 — Atlassian OAuth 2.0 3LO + Confluence Cloud | Shipped + CI green |
| W3.D | `padosoft/askmydocs-connector-jira` v1.0.0 — Atlassian OAuth 2.0 3LO + Jira Cloud + ADF rendering | Shipped + CI green |
| W4 | Host-side cleanup PR — wire 8 packages into `composer.json` + `bootstrap/providers.php` + `HostIngestionBridge` IoC implementation + delete `app/Connectors/BuiltIn/`, `app/Connectors/{Auth,Scheduling,Support,Exceptions,BaseConnector,ConnectorInterface,ConnectorRegistry,HealthStatus,SyncResult}`, `app/Models/Connector{Installation,Credential}`, `app/Jobs/ConnectorSyncJob`, `database/migrations/2026_05_15_*_connector_*` + ADR 0009 + this status doc | This PR |

## Architectural decisions LOCKED in v4.6

ADR 0009 records five decisions:

1. **Composer-extra auto-discovery** — every connector package declares
   its FQCNs under `composer.json::extra.askmydocs.connectors`; the
   framework registry walks `composer.lock` and registers each on
   boot. Mirrors Laravel's `extra.laravel.providers` convention.
2. **Per-connector source-type support helpers** stay in the connector
   package (Notion `NotionBlockToMarkdown` / Confluence
   `ConfluenceStorageToMarkdown` / Jira `JiraAdfToMarkdown` /
   Evernote `EnmlToMarkdown` etc.) because they are connector-specific
   knowledge.
3. **`ConnectorIngestionContract` IoC bridge** is the only place a
   connector package talks to the host. Five methods —
   `dispatchIngestion()`, `resolveKbSourcePath()`, `redactContent()`,
   `emitAudit()`, `softDeleteByRemoteId()`. The host binds
   `HostIngestionBridge` as the singleton; consumer apps with different
   ingest pipelines bind their own.
4. **VCS-`repositories[]` pre-Packagist workaround** stays in
   `composer.json` until each package is submitted to Packagist.
   Mirrors the existing `padosoft/laravel-pii-redactor` posture from
   v4.1 (still resolved via VCS today).
5. **Chunkers STAY in the AskMyDocs host repo** (`app/Services/Kb/Chunkers/`)
   because they depend on host types (`ChunkerInterface`,
   `ChunkDraft`, `ConvertedDocument`, `TokenCounter`,
   `DerivedMetadataReader`) that the standalone-agnostic packages
   cannot reference. Trade-off: the host repo carries the chunker
   library; in exchange the connector packages remain framework-
   agnostic and reusable by ANY Laravel app, not just AskMyDocs.

## Acceptance gate checklist

### A — Dependency alignment

- [x] `padosoft/askmydocs-connector-base` constrained at `^1.1` (resolves `v1.1.1`).
- [x] `padosoft/askmydocs-connector-notion` constrained at `^1.0` (resolves `v1.0.1`).
- [x] `padosoft/askmydocs-connector-google-drive` constrained at `^1.0` (resolves `v1.0.1`).
- [x] `padosoft/askmydocs-connector-evernote` constrained at `^1.0` (resolves `v1.0.0`).
- [x] `padosoft/askmydocs-connector-fabric` constrained at `^1.0` (resolves `v1.0.0`).
- [x] `padosoft/askmydocs-connector-onedrive` constrained at `^1.0` (resolves `v1.0.0`).
- [x] `padosoft/askmydocs-connector-confluence` constrained at `^1.0` (resolves `v1.0.0`).
- [x] `padosoft/askmydocs-connector-jira` constrained at `^1.0` (resolves `v1.0.0`).
- [x] Every v4.5 sister-package constraint preserved unchanged (`laravel-ai-regolo` `^1.0`, `laravel-flow` `^1.0`, `laravel-flow-admin` `^1.0`, `laravel-pii-redactor` `^1.2`, `laravel-pii-redactor-admin` `^1.0.2`, `eval-harness` `^1.2.0`, `eval-harness-ui` `^1.0`).
- [x] 8 new `repositories[]` VCS entries added to `composer.json`, each annotated with the Packagist-pending rationale.

### B — Test gates

- [x] PHPUnit cycle-wide test count: 1885 (v4.5.0 GA baseline) → **1548** active tests (deleted ~349 inline-connector-class tests now covered by package CI + added 12 new `HostIngestionBridge` tests + adjusted `ConnectorRegistryTest` for v4.6 wiring).
- [x] PHPUnit (PHP 8.4 local Windows + Herd shim) **1547/1548 passing, 1 PRE-EXISTING failure on `JiraIssueChunkerTest::comments_section_aggregates_into_separate_chunk`** that landed on v4.5/W6 (commit `c60047c`) and is unrelated to this PR — to be fixed in a v4.6.x follow-up.
- [x] Vitest react: **384/384 passing** (no FE changes in this PR).
- [x] Architecture tests (`tests/Architecture/`): **20/20 green** — covers R23 connector-FQCN validation, R31 tenant-mandatory enumerable models, R26 PII boundary middleware scope, PII boundary integration knob coverage, TenantContext singleton round-trip.
- [x] New `HostIngestionBridgeTest` (12 tests): bind-as-singleton, dispatch-ingestion-with-tenant, path normalisation, path traversal rejection, redact no-op (master-switch-off + per-boundary-off), audit emission with auto-namespacing, audit no double-namespace, soft-delete by remote-id (happy path), soft-delete returns false on no match, tenant-scoped (R30) cross-tenant block, idempotent on already-trashed.

### C — Inline-code deletion

- [x] `app/Connectors/BuiltIn/` — **entirely removed** (7 connectors + 5 helper subdirectories: `Notion/`, `Confluence/`, `Evernote/`, `Jira/`, `OneDrive/`).
- [x] `app/Connectors/Auth/OAuthCredentialVault.php` — removed; package supplies `Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault`.
- [x] `app/Connectors/Scheduling/SyncScheduler.php` — removed; package supplies `Padosoft\AskMyDocsConnectorBase\Scheduling\SyncScheduler` (referenced in `bootstrap/app.php`).
- [x] `app/Connectors/Support/{SourceAwareMetadataBuilder,VendorMimeSelector}.php` — removed; package supplies `Padosoft\AskMyDocsConnectorBase\Support\Metadata\*`.
- [x] `app/Connectors/Exceptions/*` (4 exception classes) — removed; package supplies them.
- [x] `app/Connectors/{BaseConnector,ConnectorInterface,ConnectorRegistry,HealthStatus,SyncResult}.php` — removed; package supplies all five.
- [x] `app/Models/{ConnectorInstallation,ConnectorCredential}.php` — removed; package supplies them.
- [x] `app/Jobs/ConnectorSyncJob.php` — removed; package supplies `Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob`.
- [x] `database/migrations/2026_05_15_000001_create_connector_installations_table.php` + `2026_05_15_000002_create_connector_credentials_table.php` — removed; package supplies them under its own `database/migrations/` (auto-loaded by `ConnectorServiceProvider::boot()`).
- [x] **New** `app/Connectors/HostIngestionBridge.php` — the ONLY host-side connector code left. Implements `ConnectorIngestionContract` with R26 (PII redaction opt-in) + R30 (tenant scoping) + audit-log integration.

### D — Chunkers stay in host (ADR 0009 decision e)

- [x] `app/Services/Kb/Chunkers/{AtomicNoteChunker,ConfluencePageChunker,JiraIssueChunker,NotionBlockChunker,OfficeDocChunker,PdfPageChunker}.php` — UNCHANGED.
- [x] `app/Services/Kb/MarkdownChunker.php` — UNCHANGED.
- [x] `app/Services/Kb/Contracts/ChunkerInterface.php` — UNCHANGED.
- [x] No `App\Connectors\*` import in any chunker source / test (verified via Grep).

### E — Admin UI verification (no FE changes expected)

The `/admin/connectors` SPA shipped in v4.5/W3 reads `ConnectorRegistry`
contents to surface every discovered connector. Composer-extra
discovery surfaces the 7 packages automatically — no FE changes needed
for v4.6. The Playwright `frontend/e2e/admin-connectors.spec.ts`
remains green (no diff in the spec; same UX, different underlying
package boundary).

### F — Docs (R36 / R37 / R39)

- [x] `README.md` — sister-packages table refreshed with single
  "Connectors" row inline-listing all 8 packages + pre-declared
  "Coming soon in v5.0 — MCP pack" and "Coming soon in v6.0 — AI Act
  compliance" rows per the user's 2026-05-12 directive.
- [x] `CHANGELOG.md` — new `v4.6.0` entry covering package extraction
  + IoC bridge + composer-extra discovery + inline-code deletion +
  test count delta.
- [x] `docs/adr/0009-v46-connector-package-extraction.md` — five
  architecture decisions LOCKED.
- [x] `docs/v4-platform/STATUS-2026-05-12-v46-week4-rc-acceptance.md`
  — this document.

### G — R37 / R39 conformance

- [x] Working in `feature/v4.6` integration branch (cut from main
  `2a8b247` = v4.5.0 GA SHA).
- [x] `main` will receive the integration merge AT THE END of v4.6
  (this PR or follow-up GA-merge PR per the orchestrator's R37
  protocol).
- [x] `v4.6.0-rc1` tag will fire at this PR's closure SHA once
  Copilot review + CI green converge (R36 loop).
- [x] `v4.6.0` GA tag fires at the integration-merge SHA per R37 +
  R39 once `feature/v4.6` → `main` lands.

## Deferred to v4.6.x patches

| Item | Cause | Plan |
|---|---|---|
| `JiraIssueChunkerTest::comments_section_aggregates_into_separate_chunk` failure | Pre-existing regression from v4.5/W6 (commit `c60047c`) — chunker logic for the `## Comments` section. Unrelated to v4.6 package extraction. | Open a v4.6.x patch PR with the chunker fix + the test stays green. |

## Open questions / future work

- **Packagist submission for all 8 connector packages** — currently
  resolved via VCS-`repositories[]`. Each one needs the
  `vendor:publish` + Packagist UI submission round-trip; tracked
  externally to AskMyDocs.
- **v5.0 — MCP pack** is the next milestone (community-facing MCP
  tools that wrap every connector — see
  `docs/v4-platform/ROADMAP-v4-v5-v6.md`).
- **v6.0 — `padosoft/laravel-ai-act-compliance` + `-admin`** for EU AI
  Act compliance — design spec lives at
  `docs/v4-platform/DESIGN-SPEC-v6.0-ai-act-compliance-admin.md`.

## Sign-off

This PR satisfies R37 (one feature/v4.x → main merge per major), R39
(rc tag + final GA tag wiring), R30 + R31 (tenant scoping preserved
through every IoC method), R26 (PII redaction default-off opt-in at
the connector ingest boundary), and R23 (every connector FQCN
validated by the registry at boot).

The 8-package set is now Composer-resolved + auto-discovered + boot-
validated; the host repo's connector footprint shrunk from ~30 files
to **a single `HostIngestionBridge.php`** plus its 12-test feature
suite. The next major milestone (`v5.0` — MCP pack) builds on this
boundary; the bridge IoC contract is the integration point.
