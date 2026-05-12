# ADR 0009 — v4.6 Connector package extraction + IoC bridge + composer-extra discovery

**Status**: Accepted
**Date**: 2026-05-12
**Cycle**: v4.6 W1..W4

## Context

v4.5 (ADR 0008 D1) deliberately shipped the seven connectors INLINE
under `app/Connectors/BuiltIn/` so the cycle could focus on getting
them functionally complete, well-tested, and observably equivalent
across the seven providers before paying the cost of package
extraction. The same ADR (D1 §"v4.6 follow-up") wrote down the
extraction plan as the explicit v4.6 deliverable.

v4.6 is the execution of that plan. Eight composer packages now exist:
one shared framework (`padosoft/askmydocs-connector-base` v1.1.1) and
seven connector packages (`*-google-drive`, `*-notion`, `*-evernote`,
`*-fabric`, `*-onedrive`, `*-confluence`, `*-jira`, each at v1.0.0 or
v1.0.1). The host repo's own `app/Connectors/BuiltIn/` tree and most
of `app/Connectors/` (the framework primitives) is now deleted; the
host's only remaining connector code is `HostIngestionBridge.php` —
the IoC implementation that the packages call back into.

This ADR records the five architectural decisions that shaped the
extraction.

## Decision (a) — Composer-extra discovery for connector registration

Every connector package declares its FQCNs under
`composer.json::extra.askmydocs.connectors`. The framework registry
(`Padosoft\AskMyDocsConnectorBase\ConnectorRegistry`) walks
`composer.lock` at boot, reads each package's `extra.askmydocs.connectors`
array, and registers every FQCN — exactly mirroring Laravel's own
`extra.laravel.providers` auto-discovery convention.

### Alternatives considered

- **Per-package service-provider that calls `ConnectorRegistry::register()`
  in its `boot()`**: rejected because it ties the package's runtime
  registration to a specific framework version + class method
  signature. Composer-extra is data-driven, version-stable, and
  inspectable without booting the Laravel application.
- **Static FQCN list in `config/connectors.php::built_in` per host
  app**: rejected because consumers would have to edit
  `config/connectors.php` every time they composer-require a new
  connector. Auto-discovery zeroes the configuration tax.
- **Spider `vendor/` for `composer.json::extra.askmydocs.connectors`
  blocks**: rejected — the lockfile is the source-of-truth for what's
  actually installed AND it copies the `extra` block from each
  package's `composer.json` verbatim. No disk-walking needed.

### Consequence

Adding a new connector to AskMyDocs is now: `composer require
padosoft/askmydocs-connector-<new>` — zero further wiring. The
package author owns the FQCN list in their own `composer.json`. R23
enforcement (FQCN must implement `ConnectorInterface`) runs at boot
in the registry constructor; misconfigured packages fail loudly
during `php artisan` instead of producing "undefined method" at the
first admin call.

## Decision (b) — Per-connector source-type support helpers ship in their connector package

Every connector ships its own support classes for source-format
translation:

- `padosoft/askmydocs-connector-notion`: `NotionBlockToMarkdown`, `NotionPaginator`.
- `padosoft/askmydocs-connector-confluence`: `ConfluenceStorageToMarkdown`, `AtlassianPaginator`.
- `padosoft/askmydocs-connector-jira`: `JiraAdfToMarkdown`, `JiraPaginator`, `JqlBuilder`.
- `padosoft/askmydocs-connector-evernote`: `EnmlToMarkdown`, `EnexImporter`, `EnexImportResult`, `InvalidEnexException`.
- `padosoft/askmydocs-connector-onedrive`: `MicrosoftGraphPaginator`.

### Alternatives considered

- **Centralise in `padosoft/askmydocs-connector-base`**: rejected.
  Notion's block model, Confluence's storage format, Jira's ADF, and
  Evernote's ENML are connector-specific knowledge. Forcing the base
  package to know all five would (1) inflate the base package's
  dependency surface, (2) couple every base release to every
  connector's source-format changes, and (3) prevent third-party
  connector authors from following the same package shape.
- **Centralise in AskMyDocs host (`app/Connectors/Support/<Provider>/`)**:
  rejected because consumer apps that compose-require ONE connector
  (e.g. `padosoft/askmydocs-connector-notion` standalone, without
  AskMyDocs) would have to copy the host's Support classes — which is
  the package-extraction anti-pattern this ADR is built around.

### Consequence

Each connector package is self-contained: a consumer with NO other
package can `composer require padosoft/askmydocs-connector-notion`
and immediately have Notion ingestion fully functional through the
`ConnectorIngestionContract` they implement.

## Decision (c) — `ConnectorIngestionContract` IoC bridge between package and host

The connector package never imports AskMyDocs internals (no
`App\Jobs\IngestDocumentJob`, no `App\Services\Kb\DocumentDeleter`,
no `App\Models\KnowledgeDocument`). Instead, every package resolves
`Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract`
from the container and calls one of five methods:

1. `dispatchIngestion(...)` — hand the freshly-written document off
   to the host's queued ingest job.
2. `resolveKbSourcePath($relative)` — translate a relative path into
   `{relative, absolute, disk}` honouring the host's path-prefix
   config (R1 normalisation included).
3. `redactContent($content)` — apply the host's PII redactor at the
   ingest boundary (R26 opt-in via `KB_CONNECTOR_INGEST_PII_REDACT`).
4. `emitAudit(...)` — write an immutable audit row for admin log
   visibility.
5. `softDeleteByRemoteId(...)` — route a provider-side deletion event
   (Notion `archived: true`, Drive `removed: true`) to the host's
   document deletion service.

The host binds `App\Connectors\HostIngestionBridge` as the singleton
implementation in `AppServiceProvider::register()`.

### Alternatives considered

- **Event-bus pattern (connector dispatches `DocumentIngestRequested`
  event, host listens)**: rejected because events lose return values.
  `softDeleteByRemoteId()` returns a `bool` indicating whether the
  caller should retry / log a different audit type — losing that
  return value would force the connector to re-query the documents
  table, defeating the purpose of the IoC.
- **Make the host's `IngestDocumentJob` a public FQCN inside the
  base package**: rejected. The package would have to depend on
  AskMyDocs to even define the type — circular dependency.
- **Connector packages directly instantiate Laravel Queue jobs by
  string FQCN**: rejected. Magic strings, no compile-time checks,
  and consumer apps with a different ingest queue strategy
  (e.g. immediate sync ingest) couldn't swap implementations.

### Consequence

Connector packages are framework-agnostic — they work in any Laravel
13+ app that binds a `ConnectorIngestionContract` implementation.
AskMyDocs binds `HostIngestionBridge`; downstream consumer apps with
different ingest pipelines bind their own. The package's
`NullConnectorIngestionContract` is the fail-loud default — calling
a method without a host binding raises a `LogicException` with the
exact remediation message.

## Decision (d) — VCS-`repositories[]` pre-Packagist workaround

Every new package gets a VCS `repositories[]` entry in the host's
`composer.json` until each package is submitted to Packagist:

```json
{
    "type": "vcs",
    "url": "https://github.com/padosoft/askmydocs-connector-base"
}
```

### Alternatives considered

- **Block the v4.6 GA on Packagist submission of all 8 packages**:
  rejected. Packagist submission is a separate operational step
  (each package needs `vendor:publish` round-trips + the Packagist
  UI submission) that doesn't gate the host-side wire-up landing.
  Mirrors the existing `padosoft/laravel-pii-redactor` posture
  (still VCS-resolved from v4.1 through today's main).
- **Use a private Composer satis instance**: rejected. Open-source
  posture is the priority — every consumer can clone the connector
  package's GitHub repo if they want to inspect / fork. Satis adds
  infrastructure cost for marginal benefit.

### Consequence

Each connector's `composer.json::repositories[]` entry stays in the
host until Packagist submission completes. The entries are
self-documenting (a "Pending Packagist submission" comment is
attached to each).

## Decision (e) — Chunkers STAY in the AskMyDocs host repo

Every chunker in `app/Services/Kb/Chunkers/` (NotionBlockChunker,
ConfluencePageChunker, JiraIssueChunker, OfficeDocChunker,
PdfPageChunker, AtomicNoteChunker) stays exactly where it was in
v4.5. The connector packages do NOT ship chunkers.

### Alternatives considered

- **Pull each chunker into its matching connector package**:
  initially considered. Rejected after closer inspection of the
  chunker dependencies:
  - `ChunkerInterface` lives in `app/Services/Kb/Contracts/`.
  - `ChunkDraft`, `ConvertedDocument` live in `app/Support/Kb/`.
  - `TokenCounter`, `DerivedMetadataReader` live in `app/Services/Kb/`.
  - All four are host types that the standalone-agnostic packages
    cannot reference without re-introducing a circular dependency on
    AskMyDocs.
- **Promote the chunker contracts into the connector-base package**:
  rejected. `ChunkerInterface` operates on `ConvertedDocument` which
  carries host-specific `_derived` metadata, `extractionMeta` shapes,
  and pipeline conventions. Promoting it would bloat the base
  package's surface for marginal benefit — no third-party connector
  author has yet asked for it.

### Consequence

The chunker library carries a clean host-side contract surface, and
the connector packages remain framework-agnostic. Trade-off: the
host repo grows by ~6 chunker files compared to a hypothetical "100%
package extraction" outcome; in exchange the packages stay reusable
by any Laravel app, not just AskMyDocs.

## Consequences (cycle-wide)

- **Host repo footprint shrunk dramatically**: ~30 deleted files
  (entire `app/Connectors/BuiltIn/` + most of `app/Connectors/` + 2
  models + 1 job + 2 migrations + ~17 inline-class unit tests) →
  **a single new `HostIngestionBridge.php` + its 12-test feature
  suite**.
- **Consumer adoption path opens**: a third-party Laravel app can
  now `composer require padosoft/askmydocs-connector-notion` AND
  `padosoft/askmydocs-connector-base` and have Notion ingestion
  functional with a 10-line `HostIngestionBridge`-equivalent
  implementation. AskMyDocs is no longer the only consumer.
- **Test ownership shifts**: connector-specific tests now live in
  each package's own CI (already green at v1.x). The host repo's
  test suite focuses on the host integration: bridge correctness,
  admin controller wiring, R23 boot validation, R30/R31 tenant
  scoping.
- **No FE / admin SPA changes**: the `/admin/connectors` SPA reads
  `ConnectorRegistry::all()` — and composer-extra discovery
  surfaces the seven packages automatically, identical UX to v4.5.
- **R26 PII redaction at the connector boundary**: new env knob
  `KB_CONNECTOR_INGEST_PII_REDACT=false` (default off) closes the
  v4.3/W1 sub-PR 4.5 boundary-coverage gap for connector-ingested
  documents.
- **Migrations relocate without data loss**: the
  `connector_installations` + `connector_credentials` tables now
  live under `padosoft/askmydocs-connector-base/database/migrations/`
  (auto-loaded by the package SP). Existing rows on production
  databases are unaffected — same table names, same columns. The
  host's `tests/database/migrations/` mirror is preserved as the
  SQLite test schema.

## Follow-ups parked for v4.6.x / v5.0

- **Packagist submission for all 8 packages** — operational task,
  unrelated to the host wire-up.
- **`JiraIssueChunkerTest::comments_section_aggregates_into_separate_chunk`
  failure** carried over from v4.5/W6 (commit `c60047c`) is unrelated
  to v4.6 changes — fix in a v4.6.x patch.
- **v5.0 — MCP pack** is the next user-facing milestone. The MCP
  pack wraps every connector under a unified MCP tool surface; the
  IoC contract this ADR codified is the integration point.
- **v6.0 — AI Act compliance pack** stays scheduled (see
  `docs/v4-platform/DESIGN-SPEC-v6.0-ai-act-compliance-admin.md`).
