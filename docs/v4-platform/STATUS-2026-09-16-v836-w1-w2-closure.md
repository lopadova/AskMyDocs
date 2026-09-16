# STATUS — AskMyDocs v8.36.0 GA — Document Intelligence W1+W2

**Cycle:** v8.36 (Document intelligence + portable LLM Wiki export — first two of five workstreams;
W3–W5 continue as v8.37/v8.38/v8.39, each its own release per the updated per-Wn versioning).
**Closed:** 2026-09-16. **GA tag:** `v8.36.0` (merge `feature/v8.36 → main`, R37 per-release).
**Origin:** a competitor audit ([Annota AI](AUDIT-2026-09-11-annota-ai-gap.md)) found the one seam
where a data-preparation platform was ahead of us — *file → reviewed Markdown*: no OCR
(`PdfConverter` read only the text layer, `SourceType` accepted no `image/*`) and converted
Markdown was never stored (`source_retention`/`markdown_path` were still the ADR 0014 schema
foundation). Plan: [`PLAN-v8.36-document-intelligence-and-llm-wiki-export.md`](PLAN-v8.36-document-intelligence-and-llm-wiki-export.md).

## Sub-tasks (merged into `feature/v8.36`, each with R40 local-critic + R36 cloud loop to 0 must-fix)

| Wn | Feature | PR | Base |
|---|---|---|---|
| W0 | ADR 0029 + ADR 0030 + PLAN doc | #477 | `main` |
| W1 | `OcrConverter` — drivers, run reuse, pre-egress controls (ADR 0029) | #478 | `feature/v8.36` |
| W2 | Conversion artifacts on the Cloud Time Machine (ADR 0030) | #479 | `feature/v8.36` |
| W6 | RC + GA close (this doc + README roadmap-row flip + Changelog + GA merge + `v8.36.0` tag) | — | `feature/v8.36 → main` |

PR #479 ran an unusually long review cycle — round 1 through round 51 of the R36/R40 Copilot loop,
0 must-fix outstanding at merge — because the artifact/source lifecycle it introduces sits directly
on top of every existing delete/prune/backfill path (`DocumentDeleter`, `PruneArchivedVersionsCommand`,
`PruneOrphanFilesCommand`, `ReembedDocumentJob`, `kb:artifacts-backfill`), and each of those paths
had its own latent TOCTOU window once a *shared* file (the original source, or an `.ocr/{run}`
directory) could legitimately be deleted by one writer while another was still reading it. See
"Concurrency hardening" below.

## New schema

None (both W1 and W2 are additive on existing columns/config — `knowledge_documents.version_actor`,
`version_reason`, `content_hash`, `markdown_path` all predate this cycle as ADR 0014/0030 schema
foundations; this cycle is what *wires* them).

## New events / commands / endpoints

- **OCR (W1):** `KB_OCR_ENABLED` gate; `OcrConverter` for `image/png`/`jpeg`/`tiff`/`webp` +
  text-layer-probe fallback inside `PdfConverter`; drivers `tesseract`/`docling`/`mistral-ocr`/`vision-llm`
  (remote drivers gated behind `KB_OCR_ALLOW_REMOTE`); tri-surface `kb:ocr` /
  `POST …/documents/{id}/ocr` / `KbOcrStatusTool`; FinOps `ocr` metering + pre-commit cost estimate
  (`GET /api/admin/kb/uploads/{batch}/estimate`).
- **Conversion artifacts (W2):** `KB_CONVERSION_ARTIFACTS_ENABLED` gate; `ConversionArtifactStore`
  (`.artifacts/{tenant}/{project}/{source}.versions/{version_hash}.md`); `GET
  …/versions/{versionId}/content` (R32 matrix row); `KB_SOURCE_RETENTION` (`full_copy` /
  `markdown_only` / `reference_only`) wired for the first time; `kb:artifacts-backfill
  {--project=} {--tenant=} {--dry-run}` (history repair, hash-verified, never overwrites a version
  with a reconversion under a newer converter); `kb:prune-archived-versions` now also removes the
  pruned row's artifact and purges its OCR run through the shared
  `DocumentDeleter::documentReferencingOcrRun()` gate; MCP `KbDocumentVersionsTool` metadata surface.

## Defaults / cost posture

- `KB_OCR_ENABLED` default **OFF** (R43, both states tested). Remote drivers additionally gated
  behind `KB_OCR_ALLOW_REMOTE` (bytes leave the tenant before the PII seam sees the text).
- `KB_CONVERSION_ARTIFACTS_ENABLED` default **OFF** (R43, both states tested). Requires a
  lock-capable cache store (`CACHE_STORE=redis` in production) — a store that cannot lock refuses
  publishes/removals rather than running unguarded; the `null` store (which grants every lock
  without excluding anyone) is detected and refused identically.
- OCR run reuse (`KB_OCR_REUSE_ENABLED`, default ON): content-addressed on bytes × driver ×
  figure-switch, so an identical re-ingest through the same engine never pays twice.

## Concurrency hardening (the bulk of PR #479's review cycle)

Every fix below follows the same **acquire-and-hold** shape (`SEC-RACE-001`): reserve a resource
once, hold it, and re-assert it is *still* held immediately before the irreversible step — never
probe, release, then act on the stale answer.

- `SourceInFlight` reservations serialize a fresh ingest's read/convert/commit window against every
  deleting consumer (`DocumentDeleter`, `PruneOrphanFilesCommand`, `kb:artifacts-backfill`'s
  retention drop) that might otherwise remove the same original out from under it.
- `OcrFigureStore::purgeRun()` takes an optional `$referencedCheck` callback invoked under the run's
  own reservation immediately before deletion, closing the window between a batch snapshot and the
  actual delete in both `PruneArchivedVersionsCommand` and `PruneOrphanFilesCommand`.
- `DocumentIngestor::finalizeSourceRetention()` accepts the caller's held reservation and asserts it
  right before the destructive drop, threaded through `IngestDocumentJob` (via a container-bound
  `ActiveSourceReservation` carrier, since `Flow::execute()` only accepts serializable step input)
  and through `kb:artifacts-backfill`'s own `SourceInFlight::acquireForRemoval()`.
- `kb:artifacts-backfill`'s reconversion now persists `converted->extractionMeta` onto the row
  atomically with the artifact pointer (mirroring `DocumentIngestor::ingest()`), so a freshly created
  or reused OCR run is never indistinguishable from an orphan the next prune deletes.
- `ReembedDocumentJob` reads its row as `active`, then spends unlocked time (a disk read, chunking,
  embedding) before ever writing. A concurrent soft/hard delete landing in that window is now refused
  rather than silently undone: an optional `requireActiveDocumentId` guard, threaded through
  `DocumentIngestor::ingest()`/`persistFromDrafts()` and passed unconditionally by
  `reembedFromMarkdown()`, re-checks the exact row under `lockForUpdate()` inside the same
  transaction as the write.

Deliberately **not** closed in this cycle (documented, not silently dropped): the narrow window
between `DocumentIngestor::underSourceKeyLock()`'s in-transaction lock-TTL assertion and Laravel's
own post-closure `COMMIT` — closing it properly needs a database-level advisory key lock serializing
the storage key with row visibility, which the code's own docblock (`DocumentIngestor::reconcileIfKeyLockLapsedAcrossCommit()`)
records as a follow-up rather than a correctness boundary this cycle claims. The current mitigation
(post-commit reconciliation: probe, stamp `source_dropped` if gone, log at `error`) is a best-effort
narrowing, not a close.

## Deferred (documented)

- Storage-namespace backfill for rows ingested before the per-row `metadata.disk`/`metadata.prefix`
  namespace existed (tracked in the W1→W2 hand-off, `docs/handoff/v836-document-intelligence-cycle-kickoff.md`).
- Retroactive Copilot review of PR #478 (W1) — it shipped clean at the time but predates the
  round-by-round pattern library this cycle's W2 review built up; not re-opened for its own sake.
- The database-level advisory key lock noted above (`DocumentIngestor::underSourceKeyLock()` /
  `reconcileIfKeyLockLapsedAcrossCommit()`).

## Pre-existing issue observed (not introduced this cycle)

None newly observed; PR #479's review surfaced only issues in code this cycle itself introduced.

Cycle plan: [`PLAN-v8.36-document-intelligence-and-llm-wiki-export.md`](PLAN-v8.36-document-intelligence-and-llm-wiki-export.md).
Kickoff hand-off: [`docs/handoff/v836-document-intelligence-cycle-kickoff.md`](../handoff/v836-document-intelligence-cycle-kickoff.md).
