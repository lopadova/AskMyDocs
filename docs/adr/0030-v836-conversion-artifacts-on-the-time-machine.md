# ADR 0030 — Conversion artifacts on the Time Machine

- **Status:** Accepted
- **Date:** 2026-09-12
- **Cycle:** v8.36 (W2 of the Document Intelligence cycle); consumed by W3 (v8.37) and W4 (v8.38)
- **Builds on:** [ADR 0014](0014-v811-auto-wiki-tier.md) (source-retention
  policy + `markdown_path` declared as schema foundation),
  [ADR 0020](0020-v823-pii-safe-ingestion-reversible-vault.md) (one core, both
  ingest paths; crypto-shred), [ADR 0029](0029-v836-ocr-converter-drivers-and-ocr-provenance.md)
  (run-scoped OCR figures), the v8.7/W5 *Cloud Time Machine*
  (`DocumentVersionService`, `KbDocumentVersionController`,
  `kb:prune-archived-versions`), R21 atomic invariants, R30/R31 tenant scoping,
  R43 both-state branches, R44 tri-surface.
- **Plan:** [PLAN v8.36 → v8.40](../v4-platform/PLAN-v8.36-document-intelligence-and-llm-wiki-export.md) §W2;
  audit [AUDIT-2026-09-11-annota-ai-gap](../v4-platform/AUDIT-2026-09-11-annota-ai-gap.md) §2.1 (corrected row *Immutable document versions*).

## Context

AskMyDocs already has a version model. Every re-ingest archives the prior
`knowledge_documents` row as `archived` **with its chunks**
(`DocumentIngestor::archivePreviousVersions`); `DocumentVersionService` lists
the `(tenant, project_key, source_path)` family, diffs two versions through
`App\Support\MarkdownDiff` and restores one under a `lockForUpdate()` with a
`kb_canonical_audit` row; **Admin → Time Machine** shows it;
`kb:prune-archived-versions` caps the family. The first draft of the
competitor audit missed this because it grepped the migrations for a versions
*table*; the model is the archived *row*. The audit was corrected before the
cycle started and this ADR is sized to the corrected gap, which is narrow and
precise:

1. **The version body is a reconstruction.** `reconstructContent()` rebuilds
   the text from `knowledge_chunks` — no frontmatter, no images, and text the
   chunker has already transformed. The Time Machine therefore diffs two
   reconstructions, and a restore brings back an index, not a document.
   `config/kb.php::source_retention` and `knowledge_documents.markdown_path`
   were declared in v8.11 (ADR 0014) as the foundation for the faithful
   artifact; the ingest wiring never landed.
2. **A version is born only on re-ingest.** Nothing else can create one
   because nothing else can write — there is no correction surface. W3 will
   need one.
3. **A version has no actor and no reason.** The Time Machine can say *what*
   changed, not *who* changed it or *why* — and the existing field list of
   `versionsFor()`, the controller mapping and `timemachine.api.ts` would hide
   the columns even once they exist.

Three properties of the code constrain the design: the family (not a table) is
the version model and every read of it is tenant-scoped through `forTenant()`;
PII redaction is wired **once, in both ingest paths** (ADR 0020 D3) — the
artifact must be wired the same way or the two paths diverge on the first
release; and a database transaction cannot roll back a filesystem write.

## Decision

### 1. The family stays the version model — no versions table

A version *is* a `knowledge_documents` row of the family. This ADR adds no
table. Everything below is one flag, three columns, one artifact per row, and
the service reading them.

### 2. `KB_CONVERSION_ARTIFACTS_ENABLED` — default OFF, both states tested

The artifact write is behind `KB_CONVERSION_ARTIFACTS_ENABLED=false`
(`config('kb.conversion_artifacts.enabled')`). With the flag off nothing is
written, `markdown_path` stays `null`, and `diff` / `restore` / the versions
endpoint behave exactly as v8.35 (the three new columns are simply `null` and
the FE renders them as *unknown*). With the flag on, `source_retention`
(ADR 0014) is finally wired: in `full_copy` (the default) and `markdown_only`
the converter output is stored; in `reference_only` it is not. Both states
are tested on both ingest paths (R43).

### 3. The converted Markdown is a stored artifact — one core, both paths, compensated

The exact string the chunker receives — after conversion and **before**
chunking — is written to the `kb` disk (under `KB_PATH_PREFIX`) at
`.artifacts/{tenant_id}/{project_key}/{source_path}.versions/{version_hash}.md`
and its path recorded in `knowledge_documents.markdown_path`, in
`DocumentIngestor::persistDocumentAndChunks()`,
which both the Flow saga (`ParseMarkdownStep` converts → `PersistChunksStep` →
`persistDrafts()`) and the direct path (`ingest()` → `persistFromDrafts()`)
already share — **one core, both paths**, exactly as `ChunkRedactor` is wired.

`source_path` is prefix-free and the prefix is one global setting, so tenant
and project are part of the key explicitly — **as safe segments, never
verbatim**: each is admitted only when it matches
`^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$` (no `/`, no `..`) and is otherwise
replaced by `h-` + the first 24 hex of its SHA-256; the composed path is
normalised with `KbPath::normalize()` and must resolve **inside** the artifact
root (`realpath` containment where the disk is local). The `.artifacts/` root
is a generated-asset subtree (`KbPath::isGeneratedAsset()`, ADR 0029): the
folder walker and the orphan sweeps never read it back as a source.

A database transaction cannot roll back a filesystem write, so the publish is
**compensated, not "inside" the transaction**, and race-safe against two
concurrent identical ingests: each writer writes to its own temporary name
(`{final}.{uuid}.tmp`), commits the row with the **final** path recorded, and
only after commit moves its temp file into place (`exists()` on the final path
→ the identical bytes are already there, drop the temp). The loser of the
unique-constraint race never touches the final path: its failure branch
deletes **its own temp file only**. A crash between commit and move leaves a
row whose artifact is missing — `contentFor()` falls back to reconstruction
and says so (§5), and `kb:artifacts-backfill` repairs it. Today's database
uniqueness is `uq_kb_doc_version = (project_key, source_path, version_hash)`
— the tenant migration deferred rebuilding the composite uniques with
`tenant_id` — so identical content at one path cannot be stored for two
tenants today (a pre-existing limitation this ADR neither introduces nor
fixes); the path already carries `tenant_id`, so the day the unique is rebuilt
the artifact identity matches. There is nothing to reference-count.
`kb:prune-archived-versions` additionally sweeps `.tmp` leftovers older than
one hour and artifacts whose `(tenant, project, path, version_hash)` no row
(trashed rows included, R2) references, only after that authoritative check.
Failure, idempotency and a genuinely concurrent identical-ingest test cover
all of it. In `markdown_only` the original binary is deleted only after the
artifact commit (R4 return checked).

Turning the flag on populates nothing by itself, so `kb:artifacts-backfill
{--project=} {--tenant=} {--dry-run}` — **operator-only maintenance, a
documented R44 exception** (a storage repair that can re-run OCR and spend;
its authorization boundary is the console, `--tenant` validated as
`kb:reembed-project` does) — re-converts every live row without an artifact
whose effective retention mode retains Markdown (`full_copy`, `markdown_only`)
and whose source is still on disk, and writes the artifact **without** creating
a version when the converted bytes hash to the stored `document_hash`; a
`hash_mismatch` (an engine changed since) is reported and **nothing is
written** — an artifact must agree with the version's chunks; a missing source
is reported, not invented; and a row whose effective mode is `reference_only`
is reported as `intentionally_missing` even when a legacy source file is still
on disk — the flag never changes a tenant's retention policy.

The artifact is the **raw** converted Markdown, not the redacted chunks: the
raw markdown is already the `document_hash` idempotency anchor and already
lives on disk as the user's source of truth (ADR 0020 Consequences); the
vector store is the protected surface, the artifact is not a new one. OCR
figures (ADR 0029) are **not** copied under the artifact: they stay at
`{source_path}.ocr/{run}/images/`, and the artifact's `metadata.converter.ocr.run`
names the run whose images it references.

### 4. Three columns on `knowledge_documents` — and the seams that surface them

| Column | Type | Meaning |
|---|---|---|
| `version_actor` | `string(191) null` | Who created this version: `system:ingest`, `system:ocr`, `system:autowiki`, `user:{id}`, `agent:{id}` (when an agent acts for a user through `DelegationContext`; today the authenticated principal yields `user:{id}`). Set by the ingestor from the ingestion metadata (`version_actor` key) with `system:ingest` as the default. |
| `version_reason` | `string(1024) null` | Free text supplied by the caller: `re-ingest`, `ocr`, `correction: page 2`, `restore of #123`. |
| `content_hash` | `string(64) null` | SHA-256 of the stored artifact bytes. Equals `document_hash` today (both hash the converted markdown) and is kept separate on purpose: W3 corrections change the artifact without re-running conversion, and the export manifest (W4) hashes the artifact, not the conversion. |

All three are nullable so every existing row is a valid "unknown actor"
version; a backfill is deliberately not run (an invented actor is worse than
none). The migration is **mirrored in `tests/database/migrations/`** — the
SQLite suite loads only that directory — or the feature tests run against a
stale schema. The columns are surfaced end to end in the same PR: added to the
`DocumentVersionService::versionsFor()` field list, mapped by
`KbDocumentVersionController::index()`, typed on `DocVersion` in
`frontend/src/features/admin/time-machine/timemachine.api.ts` and rendered in
the Time Machine list (additive fields only, R27).

### 5. `diff` prefers artifacts, falls back to reconstruction

`DocumentVersionService::contentFor(KnowledgeDocument $v): string` returns
the artifact when `markdown_path` is set and the file exists, otherwise
`reconstructContent()`. `diff()` uses `contentFor()` on both sides and reports
which source each side came from (`from_source` / `to_source` ∈
`artifact | reconstruction`) so the UI can say when a diff is faithful and when
it is an index diff. Both branches are tested (R43): two versions with
artifacts diff the artifacts; two without fall back; a mixed pair falls back
on the side that lacks one and says so. A missing file behind a non-null
`markdown_path` is logged and falls back — it is not a 500 (R14 applies to
silent success, not to a documented degrade).

### 6. `restore` re-activates the artifact with the row

Restoring an archived version already flips status and transfers canonical
identity inside one transaction; it now also records
`version_actor = user:{id}` (the restoring user) and
`version_reason = "restore of #{id}"` on the re-activated row, and leaves
`markdown_path` untouched — the artifact was never deleted with the archive,
only with the prune. Nothing is re-embedded; the retained chunks are reused as
before.

### 7. Versions born from a correction go through the same service

W3 will create a version from a saved correction. It does so by calling
`DocumentIngestor` with the corrected markdown, `version_actor = user:{id}`,
`version_reason = "correction: page N"`; the document is re-chunked and
re-embedded as a whole, and the cost of the unchanged pages is bounded by the
**embedding cache** (`embedding_cache`, keyed on text hash), not by a partial
update — the `## Page N` boundaries make the untouched chunks byte-identical,
so their embeddings are cache hits. This ADR fixes the contract; W3 implements
the caller.

### 8. Retention and erasure cover the artifact and the OCR assets

`kb:prune-archived-versions` deletes the artifact with the row it prunes (R4
return checked, logged, never silent). `DocumentDeleter`'s hard delete removes
the artifact and, through the same *last referencing row* gate, the
`{source_path}.ocr/` directory (ADR 0029 §6). ADR 0020 D6 crypto-shred applies
unchanged: the artifact is raw markdown outside the AI boundary, the vault is
the only link between a surrogate and a person, and shredding the vault leaves
the artifact as the user's own source file — the same posture as the original
on disk.

### 9. The MCP read surface the v8.7 feature never got

`KbDocumentVersionsTool` (read) lists a document's family with `id`, `status`,
`is_live`, `version_actor`, `version_reason`, `content_hash`,
`has_artifact`, `indexed_at`, tenant-scoped through the same service (R30,
R44). It reads; it never restores. `kb:doc-versions {document} {--tenant=}` is
the CLI over the same service — `--tenant` validated non-empty and the
document resolved with `forTenant()`, never the process-global default.
`restore` stays HTTP-only and human-only.

## Consequences

- The Time Machine answers *what did this document say on date X* with the
  document, not with its index; *Semantic Time Travel* (parked since v8.0)
  gets its data layer.
- W3 (review) and W4 (export) read `markdown_path`; neither needs to know how
  the artifact was produced. W4's `raw/` folder is a copy of the artifacts,
  rendered through the tenant PII policy.
- Disk footprint grows by one Markdown per version. The existing cap
  (`KB_KEEP_ARCHIVED_VERSIONS`, default 10) bounds it; `markdown_only` shrinks
  it below today's footprint for binary sources.
- Existing rows have no artifact and keep diffing by reconstruction until
  their next re-ingest. No migration rewrites history.
- `content_hash` and `document_hash` are equal until the first correction;
  a reader that assumes they always are will be wrong from v8.37 on.

## Surfaces (R44)

| Capability | PHP / CLI | HTTP | MCP |
|---|---|---|---|
| List a document's versions | `kb:doc-versions {document} {--tenant=}` · `DocumentVersionService::versionsFor()` | `GET /api/admin/kb/documents/{id}/versions` (existing; now returns actor/reason/hash) | `KbDocumentVersionsTool` (read) |
| Diff two versions (artifact-aware) | `kb:doc-versions {document} --diff=A:B {--tenant=}` · `DocumentVersionService::diff()` | `GET /api/admin/kb/documents/{id}/versions/diff?from&to` (existing; now reports the source of each side) | — |
| Restore a version | `DocumentVersionService::restore()` | `POST /api/admin/kb/documents/{id}/restore-version` (existing; now records actor/reason) | — (write; human-only by design) |
| Read a version's artifact | `DocumentVersionService::contentFor()` | `GET /api/admin/kb/documents/{id}/versions/{versionId}/content` | — |

All surfaces adapt one core, `DocumentVersionService`, tenant-scoped through
`KnowledgeDocument::forTenant()` (R30).
