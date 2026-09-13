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
(`config('kb.conversion_artifacts.enabled')`). With the flag off **no new
artifact is written**: `markdown_path` stays `null` on every row ingested
while it is off, and `diff` / `restore` / the versions endpoint keep working
by reconstruction, as in v8.35. The read surface is not byte-for-byte v8.35,
by design: the responses gain the additive fields of §4 (`null` / *unknown*
for rows that never had them, R27), and a row that received an artifact while
the flag was on keeps it — `contentFor()` (§5) still reads it and still says
which source it used. Turning the flag off is therefore a stop, not a
rollback; nothing already stored is discarded or hidden. With the flag on, `source_retention`
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
`^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$` (no `/`, no `..`) **and does not start
with `h-`** — that prefix is reserved for the encoding — and is otherwise
replaced by `h-` + the **full** 64-hex SHA-256 of the value. The mapping is
injective: a verbatim segment can never spell an encoded one (`h-…` is never
admitted verbatim, so a tenant literally named `h-<hex>` is itself hashed),
and two distinct values share an encoded segment only on a SHA-256 collision.
A collision test pins both properties (a literal `h-<64 hex>` name and the
unsafe value whose digest it spells resolve to different paths). The composed
path is normalised with `KbPath::normalize()` and must resolve **inside** the
artifact root. Two checks, by disk kind: the lexical one on every disk — a string
check on the normalised path (`str_starts_with($path, $root.'/')`), after
`KbPath::normalize()` has rejected `.` and `..` segments, so no traversal
survives it — and, on a **local** disk, a `realpath` check on every read,
delete and publish (the file's real path when it exists, its parent's
otherwise, and the published file's after the move) that refuses a path a
symlink planted under `.artifacts/` makes resolve outside the real root.
Object stores have no symlinks: there the lexical check is the whole check. The `.artifacts/` root
is a generated-asset subtree (`KbPath::isGeneratedAsset()`, ADR 0029): the
folder walker and the orphan sweeps never read it back as a source.

A database transaction cannot roll back a filesystem write, so the publish is
**compensated, not "inside" the transaction**, and race-safe against two
concurrent identical ingests: each writer writes to its own temporary name
(`{final}.{uuid}.tmp`), commits the row with the **final** path recorded, and
only after commit moves its temp file into place. A final file that already
exists is **not** taken on faith: the path is the content hash, so the store
re-hashes what is there — identical bytes → the temp is dropped; anything else
(a truncated or replaced file) → the verified temp is moved over it, an atomic
rename on a local disk, so a corrupt pre-existing artifact is repaired
instead of being kept and later reported as `integrity: mismatch` (§5) while
the temp that was correct is thrown away. The identical-ingest path does not
skip this: the ingestor's same-hash short-circuit returns the existing version
only **after verifying its artifact** — present on disk and hashing to
`content_hash` — and when the artifact is missing or corrupt it republishes it
from the freshly converted bytes through the same temp-then-publish protocol
(no new version, no chunk rewrite, the pointer and `content_hash` unchanged
because the bytes are the same); `kb:artifacts-backfill` covers the rows
nobody re-ingests.
The loser of the
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
**final move has succeeded** — `publish()` throws on a failed move, reuses a
pre-existing final only after re-hashing it (a corrupt one is replaced, never
kept), and the drop runs after it, never on the database commit alone — and
only when every
other row referencing the same storage key (any tenant, trashed included) has
its artifact **present on disk**: a `markdown_path` whose file never landed (a
publish that failed after commit, repaired later by the backfill) does not
stand in for the original. The gate also honours each row's own **retention
contract**: every row records the mode it was ingested under
(`metadata.source_retention`), and a shared original is dropped only when
every referencing row was ingested under a mode that does not require it — a
`full_copy` row (or a pre-v8.36 row without the stamp, which counts as
`full_copy`) blocks the drop even with its artifact present; a
`reference_only` row never needed the local source; a `markdown_only` row
needs its artifact on disk. A kept original is logged with the blocking row
(R4 return checked on the delete).

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
raw markdown is already the `document_hash` idempotency anchor. For a
Markdown source that is byte for byte the user's own file; for a PDF, DOCX or
OCR'd source it is **generated** text that did not exist on disk before —
un-redacted, and therefore a data surface with its own controls, stated
here rather than assumed:

- **Access.** The artifact is readable only through the admin Time Machine
  surfaces (`role:admin|super-admin`, R32 matrix row), scoped by tenant
  (R30) and by the document's own family; it is never served to the chat,
  the widget, retrieval or an MCP tool (§9, surfaces table). The vector
  store stays the only surface the model reads, and it holds the redacted
  chunks (ADR 0020).
- **PII.** Redaction runs on chunks, not on the artifact, on purpose: the
  artifact must hash to `document_hash` and be restorable as-is. So it is
  treated like the original binary on the same disk — the same ACL, the same
  disk, the same operators — and the only agent-facing rendering of it is
  the W4 export, which passes every artifact through the tenant PII policy
  before it leaves (ADR 0032).
- **Erasure.** The artifact goes with its row: `DocumentDeleter`'s hard
  delete removes it, and `kb:prune-archived-versions` removes it with each
  pruned version and sweeps orphans (§8). Every flow that hard-deletes a
  document goes through `DocumentDeleter`, so every one of them removes the
  artifact. **This is document deletion, not subject erasure.** The Art.17
  path that exists today (`SubjectErasureService`, ADR 0020 Decision 6, also
  the DSAR `delete` hook of `AskMyDocsUserDataDeleter`) crypto-shreds the
  `pii_token_maps` vault — the AI boundary: surrogates in chunks, embeddings
  and chat become unresolvable — and **touches no raw asset**: not the
  original binary the customer uploaded, not this artifact, not the
  `{source_path}.ocr/` run (ADR 0029). The artifact is raw Markdown *before*
  the PII seam and therefore still contains the subject's original values
  after a shred, exactly as the original PDF does. So the artifact adds no
  new erasure obligation, but the ADR must not overstate the guarantee: a
  data-subject request that reaches raw assets is completed by **hard-deleting
  the documents that contain the subject** (`kb:delete --force`,
  `DELETE /api/kb/documents`, the retention prunes), which is a customer /
  operator step the DSAR runbook has to include — a vault shred is not an
  erasure of stored Markdown, of OCR figures, or of the source. Locating "every
  document that mentions this subject" is not a W2 capability: it is recorded
  as a candidate for the v8.39 routine (W5) — a subject → documents locator
  over the vault's token map, driving `DocumentDeleter` — and, until it
  exists, the compliance claim is "shred covers the index; deletion covers
  the raw assets; the operator joins the two".
- **Retention.** No artifact is written in `reference_only`; `markdown_only`
  replaces the original binary with the artifact rather than adding to it.

OCR figures (ADR 0029) are **not** copied under the artifact: they stay at
`{source_path}.ocr/{run}/images/`, and the artifact's `metadata.converter.ocr.run`
names the run whose images it references.

### 4. Three columns on `knowledge_documents` — and the seams that surface them

| Column | Type | Meaning |
|---|---|---|
| `version_actor` | `string(191) null` | Who created this version: `system:ingest`, `system:ocr`, `system:autowiki`, `user:{id}`, `agent:{id}` (when an agent acts for a user through `DelegationContext`; today the authenticated principal yields `user:{id}`). **Derived server-side, never taken from the client**: `version_actor` is a host-only metadata key, stripped at the HTTP ingest and connector boundaries by the same `OcrService::stripTrustedOnlyKeys()` gate that already strips `dry_run` / `ocr.*` (ADR 0029), and then set by the trusted caller — `user:{id}` from the authenticated principal in `KbIngestController` and in `restore`, `system:ingest` for the CLI walker and the connector bridge, `system:ocr` for a `kb:ocr` re-run; the ingestor falls back to `system:ingest` when no trusted caller set it. A caller who sends `version_actor` in `documents.*.metadata` cannot forge a `user:{id}` or `system:*` identity; a negative test sends one and asserts the stored actor is the principal's. |
| `version_reason` | `string(1024) null` | Free text supplied by the caller: `re-ingest`, `ocr`, `correction: page 2`, `restore of #123`. |
| `content_hash` | `string(64) null` | SHA-256 of the stored artifact bytes, recorded at the moment they are written. For every ingested version it equals `document_hash` **by construction** (both hash the same Markdown; a correction is an ordinary new version, §7), and it is null on rows without an artifact. It exists as its own column so a reader can verify the stored bytes against the row without re-hashing the source — the integrity check the backfill (§3), the prune (§8) and the export manifest (W4) rely on — not to diverge from `document_hash`. |

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

`DocumentVersionService::contentFor(KnowledgeDocument $v)` returns the
artifact when `markdown_path` is set, the file exists **and its bytes hash to
`content_hash`** — `content_hash` is the integrity check, so it is checked on
every read: a truncated or replaced file is logged, degrades to
`reconstructContent()` and says so (`integrity: mismatch`; `verified` when the
hash matched, `null` when there was nothing to check against — a row without
`content_hash`). The content endpoint carries `integrity`, the diff
`from_integrity` / `to_integrity` (additive, R27), so a tampered artifact is
never presented as a faithful side. Otherwise `reconstructContent()`. `diff()` uses `contentFor()` on both sides and reports
which source each side came from (`from_source` / `to_source` ∈
`artifact | reconstruction`) so the UI can say when a diff is faithful and when
it is an index diff. Both branches are tested (R43): two versions with
artifacts diff the artifacts; two without fall back; a mixed pair falls back
on the side that lacks one and says so. A missing file behind a non-null
`markdown_path` is logged and falls back — it is not a 500 (R14 applies to
silent success, not to a documented degrade).

### 6. `restore` re-activates the artifact with the row

Restoring an archived version already flips status and transfers canonical
identity inside one transaction. `version_actor` / `version_reason` are the
**creation** provenance of the version and are never rewritten by a restore:
the restore is recorded apart, appended to the row's `metadata.restores` as
`{actor: user:{id}, at, previous_live_id}`, and the versions surfaces (HTTP,
CLI, MCP) expose the last entry as the additive `restored_by` / `restored_at`
(R27) — so the timeline says both who created a version and who brought it
back, and a second restore does not erase the first. `markdown_path` is left
untouched — the artifact was never deleted with the archive, only with the
prune. Nothing is re-embedded; the retained chunks are reused as before.

### 7. Versions born from a correction go through the same service

W3 will create a version from a saved correction. It does so by calling
`DocumentIngestor` with the corrected markdown, `version_actor = user:{id}`,
`version_reason = "correction: page N"`: an **ordinary new version**, with
`document_hash = version_hash = content_hash = sha256(corrected markdown)`,
its own artifact and its own row — the original conversion stays in the
family as the archived version it always was, so the Time Machine diffs the
correction against it faithfully. Nothing preserves the original document
hash across a correction, and nothing needs to: the hashes name bytes, and
the bytes changed. The document is re-chunked and re-embedded as a whole,
and the cost of the unchanged pages is bounded by the **embedding cache**
(`embedding_cache`, keyed on text hash), not by a partial update — the
`## Page N` boundaries make the untouched chunks byte-identical, so their
embeddings are cache hits. This ADR fixes the contract; W3 implements the
caller.

### 8. Retention and erasure cover the artifact and the OCR assets

`kb:prune-archived-versions` deletes the artifact with the row it prunes (R4
return checked, logged, never silent). The prune hard-deletes archived rows
**by query** (one family at a time, R3) rather than through
`DocumentDeleter::delete()` row by row — but it does not re-implement the
reference rules: the decision whether a pruned row's recorded run
(`metadata.converter.ocr.run`, the `{source_path}.ocr/{run}/` directory) may
go is delegated to the deleter's gate, `DocumentDeleter::documentReferencingOcrRun()`,
the same helper family as `documentReferencingStorageKey()` — a run is purged
only when no remaining row, live, archived or soft-deleted, of any tenant
sharing that source key still references it, and never while it is inside the
in-flight grace (ADR 0029 §6); the `.ocr/` tree as a whole still goes with the
*last referencing row* of the source, through `DocumentDeleter`'s hard delete.
One gate, two callers: the hard delete and the prune cannot diverge on what
"referenced" means. `DocumentDeleter`'s hard
delete removes the artifact of the row it deletes unconditionally: each row
owns its own artifact, unlike the shared source file. ADR 0020 Decision 6
crypto-shred applies unchanged and **stops at the AI boundary**: it shreds the
vault, which is the only link between a surrogate and a person, and it does not
touch the artifact — raw Markdown before the PII seam — nor the OCR run, nor
the source. Those are erased by deletion only: the documents that contain the
subject are hard-deleted (row by row or by the prune) and the artifact, the
run and the source go with them (§3, *Erasure*). A shred alone is not an
erasure of any raw asset.

### 9. The MCP read surface the v8.7 feature never got

`KbDocumentVersionsTool` (read) lists a document's family with `id`, `status`,
`is_live`, `version_actor`, `version_reason`, `content_hash`,
`has_artifact`, `restored_by`, `restored_at` (§6), `indexed_at`, tenant-scoped through the same service (R30,
R44). It reads; it never restores. `kb:doc-versions {document} {--tenant=}` is
the CLI over the same service — `--tenant` validated non-empty and the
document resolved with `forTenant()`, never the process-global default.
`restore` stays HTTP-only and human-only, and `kb:artifacts-backfill` (§3)
stays console-only: both are listed with their exception in the surfaces
table below.

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
- `content_hash` equals `document_hash` **on every version that has a stored
  artifact**, by construction (§4, §7); on a row without one — flag OFF,
  `reference_only`, a legacy row ingested before W2 — it is null, and null
  means "no artifact", never "a correction" or "a mismatch". Its value
  is the integrity check on the stored bytes, not a second identity —
  a reader that treats it as a version id will be wrong; a reader that
  compares it to the bytes on disk is doing exactly what it is for.

## Surfaces (R44)

| Capability | PHP / CLI | HTTP | MCP |
|---|---|---|---|
| List a document's versions | `kb:doc-versions {document} {--tenant=}` · `DocumentVersionService::versionsFor()` | `GET /api/admin/kb/documents/{id}/versions` (existing; now returns actor/reason/hash) | `KbDocumentVersionsTool` (read) |
| Diff two versions (artifact-aware) | `kb:doc-versions {document} --diff=A:B {--tenant=}` · `DocumentVersionService::diff()` | `GET /api/admin/kb/documents/{id}/versions/diff?from&to` (existing; now reports the source of each side) | — (documented R44 exception, see below) |
| Restore a version | `DocumentVersionService::restore()` | `POST /api/admin/kb/documents/{id}/restore-version` (existing; now records actor/reason) | — (write; human-only by design) |
| Read a version's artifact | `DocumentVersionService::contentFor()` | `GET /api/admin/kb/documents/{id}/versions/{versionId}/content` | — (documented R44 exception, see below) |
| Backfill artifacts for existing rows | `kb:artifacts-backfill {--project=} {--tenant=} {--dry-run}` (§3) | — | — (operator-only maintenance that re-converts, can spend and rewrites storage: console is its authorization boundary; documented R44 exception) |

**Why diff and content have no MCP surface in v8.36.** The artifact is the
converter's output **before** the PII seam: redaction runs on the chunks
(ADR 0020), not on the stored Markdown, and the two admin endpoints are
role-gated precisely because they return un-redacted bytes to a human who is
allowed to see them. An MCP tool returning the same bytes — or a diff of
two of them — would hand the model text that never passed the tenant PII
policy, which is the one read path agents are not given anywhere else in the
platform (SEC-LLM-001 gate 3). The list tool exposes only the metadata of
§4, none of the content. A **redacted** agent read of an artifact is the W4
concern: the export renders every artifact through the tenant PII policy
(ADR 0032), and that rendered form is what an agent may consume. Until then
the exception is explicit, not an omission.

All surfaces adapt one core, `DocumentVersionService`, tenant-scoped through
`KnowledgeDocument::forTenant()` (R30).
