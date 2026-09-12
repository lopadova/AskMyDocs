# PLAN v8.36 → v8.40 — Document intelligence & LLM Wiki export

**Status:** DRAFT — designed 2026-09-11 from
[`AUDIT-2026-09-11-annota-ai-gap.md`](AUDIT-2026-09-11-annota-ai-gap.md).
Version slot assumed **v8.36 → v8.40** (first free slot after v8.35.0); if
another cycle claims it, renumber — nothing below depends on the number.

**One sentence:** close the only seam where a data-preparation competitor is
ahead of us — *file → reviewed Markdown* — and then export what only we have,
a **governed** wiki, as the portable workspace they made popular.

**Invariants this plan does not bend:** the agent proposes, a person commits
(ADR 0003); `human > auto > raw` (ADR 0014); tenant scope on every row (R30/R31);
externally authored text may be quoted but never drives a tool call (ADR 0028);
every capability is tri-surface over one core (R44); every flag is tested in
both states (R43); every user-visible change ships E2E (R12) and its doc-site
page (R45).

---

## 0. Why now, in three facts

1. **We refuse a whole class of enterprise input.** `SourceType::supportedMimes()`
   has no `image/*`; `PdfConverter` reads the text layer only. A scanned
   contract arriving through IMAP or OneDrive today is a 422 or an empty
   document — and the connectors that carry it are already shipped.
2. **The converted Markdown does not exist as an artifact.** `source_retention`
   + `markdown_path` are schema foundations (ADR 0014); the document body is
   re-derived from chunks. Nothing can be reviewed or exported that is not
   stored first — and the Time Machine we already have (v8.7) diffs chunk
   reconstructions, not documents.
3. **A competitor three weeks out of stealth has the review UI and the export
   we lack, and has announced the hosted KB we have.** Symmetric race; the
   half we lack is a commodity, the half they lack is not.

---

## 1. Workstreams

Effort key as in `ENTERPRISE-COMPLETENESS-ROADMAP.md`: **S** ≤ 1 day · **M** 2–4 days · **L** ≥ 1 week.

### W1 — `OcrConverter`: ingest with eyes (M) — v8.36

**Goal.** Scanned PDFs and images become Markdown of the same quality bar Annota
sets: layout-aware text, tables, figures extracted to an assets store with
references in the Markdown, formulas as LaTeX, a confidence per page.

**Seams (existing).**
- `app/Services/Kb/Contracts/ConverterInterface.php` + the registry in
  `config/kb-pipeline.php` (`converters` list, first-match-wins, R23 FQCN
  validated at boot). `OcrConverter` registers **before** `PdfConverter` and
  claims the four image MIMEs below — **only when `KB_OCR_ENABLED=true`**
  (OFF → `supports()` is false for every MIME and the registry falls through
  as in v8.35). The registry resolves by MIME alone (`supports(string $mime)`),
  so **scanned PDFs are not claimed by `OcrConverter`**: `PdfConverter` stays
  the sole `application/pdf` match and, when OCR is on and a cheap text-layer
  probe (`PdfTextLayerProbe`, smalot over the first `KB_OCR_PROBE_PAGES`
  pages, verdict recorded in `extractionMeta.text_layer_probe`) finds no text
  — or the ingest metadata carries `ocr.force` (set by `kb:ocr`) — it
  delegates to the same `OcrService`. One core, two entry MIMEs, no
  overlapping predicates (the converter mutex test gains the image rows).
- `App\Support\Kb\SourceType` gains `IMAGE` for exactly `image/png`,
  `image/jpeg`, `image/tiff`, `image/webp` (an exact list, not a wildcard) —
  `fromMime()` / `fromExtension()` / `toMime()` / `isBinary()` (true: base64
  on the HTTP endpoint, raw bytes on disk) updated together with
  `config/kb-pipeline.php::mime_to_source_type`. The enum stays pure;
  **acceptance is gated at the entry points**: `supportedMimes(bool
  $includeImages)` / `knownExtensions(bool $includeImages)` receive
  `config('kb.ocr.enabled')` from `KbIngestController`, the upload
  `StageKbUploadRequest` + `KbUploadStagingService`, and the folder walker
  (`KbIngestFolderCommand`, `ListFolderFilesStep`), so with the flag off an
  image is refused with the same 422 / "Unsupported file type" as today and
  the "Supported:" list does not mention it (R43). `FileTypeSniffer` verifies
  the real magic bytes for `IMAGE` (SEC-UPLOAD-001). The **connector** entry
  point is gated too: `HostIngestionBridge::dispatchIngestion()` (the path
  IMAP / OneDrive attachments take, which reaches `IngestDocumentJob` without
  a controller) refuses an image MIME with the flag off — recorded as a
  failed ingestion with a reason, never a job that dies in converter
  resolution — with an OFF-state regression test on that bridge.
- `PdfPageChunker` already slices on `## Page N`; the converter keeps that
  shape so chunking is untouched.

**Drivers** (`KB_OCR_DRIVER`, pluggable behind one `OcrDriver` contract, R23):

| Driver | Why it is in the list |
|---|---|
| `docling` (IBM, Apache-2.0, local process) | Layout, tables, figures, formulas → LaTeX. The default for sovereign installs. |
| `mistral-ocr` (API) | The engine `lucasastorian/llmwiki` uses for "higher-quality OCR on tables and complex layouts". EU-hosted provider. |
| `vision-llm` (`laravel/ai` — Claude / Gemini / Regolo EU) | Zero new infra; metered by FinOps like any call. |
| `tesseract` (local) | Free fallback; no layout. |

**Design decisions.**
- Figures land on the `kb` disk under
  `{source_path}.ocr/{run}/images/fig-{page}-{n}.png`, where `{run}` is
  **content-addressed** (the first 16 hex chars of the SHA-256 of the bytes
  that were OCR'd): two versions of the same source path never overwrite each
  other's pixels, a re-run on identical bytes lands on the same directory
  (the ingest's own idempotency), and a W2 artifact can point at the run that
  produced it. Tenant separation is the **source file's own** — the assets sit
  beside the file whose namespace (disk + prefix + path) they inherit, so a
  deployment that isolates tenants by disk/prefix isolates the figures with
  them, and one that does not already shares the source object itself. A
  collision test (two byte-versions at one path → two run directories, the
  first still intact) and a hard-delete test (last referencing row removes the
  whole `.ocr/`) are part of W1. The Markdown references
  `![…](images/fig-3-1.png)` relative to the run directory — the same shape
  Annota exports, so W4's export is a copy, not a transform. They are
  written **by the converter at conversion time** through `OcrFigureStore`
  (every `put()` checked, R4): the Flow persists step outputs to the database,
  so binary blobs cannot travel in `ConvertedDocument::mediaItems` — the
  seam Copilot flagged as missing is the store, and `mediaItems` lists the
  written paths. Hard delete and `kb:prune-archived-versions` remove the
  `.ocr/` directory together with the row (`OcrFigureStore::purge()`).
- Every page carries `ocr_confidence` in chunk metadata; `Reranker` Layer-4
  may read it as a soft signal later — **not** in this workstream.
- **Extraction origin, not authorship.** ADR 0028's `provenance_tier`
  (`trusted-internal` / `untrusted-external` / `machine-generated`) records
  *who authored* the source and stays whatever the connector declared — OCR
  never writes it. OCR adds an **orthogonal** fact, *how the text was
  obtained*: `metadata.converter.provenance = 'ocr'` on the document and
  `provenance: ocr` + `ocr_confidence` on every chunk's metadata. The
  `ProvenanceToolFirewall` keeps filtering on `provenance_tier`, so an OCR'd
  IMAP attachment is withheld from tool calls **because IMAP declares it
  `untrusted-external`**, not because it was OCR'd; the test asserts that
  boundary through the chunk → document relationship (R33 lesson).
- **PII**: `ChunkRedactor` (ADR 0020) runs unchanged on the OCR output before
  embedding. Scans are where the codici fiscali live. That boundary protects
  the **index**; it cannot protect bytes that leave the tenant to be
  recognised. So remote drivers (`mistral-ocr`, `vision-llm`) are a
  **final-egress policy** of their own: each driver declares
  `isRemote()`, the registry refuses a remote driver unless
  `KB_OCR_ALLOW_REMOTE=true` (fail closed, default off — a sovereign install
  never sends a scan out by accident), the choice is audited on the
  document (`metadata.converter.ocr.driver` + `remote: true`), and a
  negative test proves a remote driver cannot run with the knob off.
  Figures are pixels: regex redaction cannot inspect them, so the figure
  directory is treated as **unredacted** — see W4 for what that means for
  export.
- **FinOps**: OCR calls are metered per page under a new `ocr` category; the
  upload modal shows an **estimate before commit** (pages × configured
  rate) — parity with Annota's upload screen, and honest about cost.
- **Flags**: `KB_OCR_ENABLED` default-**OFF** for v8.36 (R43), ON by default
  once W3's eval metric has a baseline.

**Tests.** A three-page scanned fixture (text + table + figure), one per
driver behind an env guard (live recording pattern of `tests/Live/`), a fake
driver for CI; both flag states; the `image/*` 422 path when OFF; a scanned
PDF with the flag OFF keeps **today's** behaviour — an empty document from
`PdfConverter`, or the same `RuntimeException` when neither smalot nor
`pdftotext` can read the file; the extraction label asserted through the
relationship, not the controller (R33 lesson).

**Tri-surface.** Artisan `kb:ocr {document} {--status} {--tenant=}` (re-run /
status; `--tenant` follows the `kb:reembed-project` contract — validated
non-empty before any lookup, the document resolved with `forTenant()`, so a
bare id can never land in another tenant) · HTTP
`POST /api/admin/kb/documents/{id}/ocr` (re-run) + `GET …/ocr` (status) ·
MCP `KbOcrStatusTool` (read). **Documented R44 exception:** there is no MCP
write surface for OCR — re-running a conversion spends money and rewrites
grounding, and by the cycle's invariant that is a human decision. The
estimate is `GET /api/admin/kb/uploads/{batch}/estimate`.

**ADR.** **0029 — OCR converter, drivers, and OCR provenance.**

---

### W2 — Conversion artifacts on the existing Time Machine (M) — v8.36

**Goal.** Finish what ADR 0014 started: the converted Markdown is a **stored**
artifact, and the version model AskMyDocs already has carries it. Every
conversion, correction and re-ingest is a version with a faithful diff and a
restore that brings back the exact text, not a reconstruction.

**What exists — do not rebuild it.** *Cloud Time Machine* (v8.7/W5):
`DocumentIngestor::archivePreviousVersions` keeps the prior `knowledge_documents`
row as `archived` with its chunks; `DocumentVersionService` (`versionsFor` over
the `(tenant, project_key, source_path)` family, `reconstructContent`, `diff`
via `App\Support\MarkdownDiff`, `restore` with canonical-identity transfer and a
`kb_canonical_audit` row); `KbDocumentVersionController`
(`GET /api/admin/kb/documents/{id}/versions`, `/versions/diff?from&to`,
`POST …/restore-version`); **Admin → Time Machine**; `kb:prune-archived-versions`
(`KB_KEEP_ARCHIVED_VERSIONS`, default 10). The first draft of the audit missed
it; the gap is narrower than "no versions", and this workstream is sized to it.

**The gap, precisely.** (a) `reconstructContent()` rebuilds the body from
chunks — no frontmatter, no images, chunker-transformed text — so the Time
Machine diffs two *reconstructions*. (b) A version is born only on re-ingest;
nothing else can create one because nothing else can write. (c) No
`actor`/`reason` on a version: the Time Machine cannot say *who* changed *why*.

**Seams.** `config/kb.php` `source_retention` (`full_copy` / `markdown_only` /
`reference_only`) and `knowledge_documents.markdown_path` — wired in the one
persistence core both ingest paths already share: the Flow saga
(`ParseMarkdownStep` converts → `PersistChunksStep` → `DocumentIngestor::persistDrafts`)
and the direct path (`DocumentIngestor::ingest` → `persistFromDrafts`) both
reach `persistDocumentAndChunks()`, where the artifact is written — **one
core, both paths**, exactly as `ChunkRedactor` was wired (ADR 0020 D3). A
database transaction cannot roll back a filesystem write, so the write is
**compensated, not "inside" the transaction**: the artifact is written under a
path that is **unique per version row** — `{source_path}.versions/{version_hash}.md`,
where `version_hash` is already unique per `(project_key, source_path)` and the
row that is about to be inserted is the only one that can ever reference it —
before the row is committed, the row records the path, and if the commit fails
the file is deleted in the failure branch. Because no two rows (across versions
or tenants on a shared disk, whose source paths differ by prefix) share an
artifact path, the compensating delete cannot remove a file another committed
transaction references — there is nothing to reference-count.
`kb:prune-archived-versions` additionally sweeps artifacts whose
`version_hash` no row (including trashed rows, R2) references — orphans left by
a crash between the two steps — and only after that authoritative check.
Failure, idempotency and concurrent-version tests cover all three. There is no `ConvertDocumentStep`; the conversion step is
`ParseMarkdownStep`. OCR figures (W1) live beside the artifact under
`{source_path}.ocr/{run}/images/`.

**Flag.** `KB_CONVERSION_ARTIFACTS_ENABLED` default-**OFF** in v8.36 (R43):
OFF = no artifact is written and `diff` reconstructs from chunks exactly as
today; ON = the artifact is written under the `source_retention` mode.

**Schema.** No new versions table — the family *is* the version model. Three
columns on `knowledge_documents`: `version_actor` (`system:ingest` /
`system:ocr` / `user:{id}`; `agent:{id}` reserved for the IAM-agents
integration), `version_reason`, `content_hash` of the artifact. The columns
are **surfaced, not storage-only**: `DocumentVersionService::versionsFor()`
selects them, `KbDocumentVersionController::index()` returns them
(additive, R27), `timemachine.api.ts` `DocVersion` carries them and the
Time Machine timeline shows actor + reason per version, with tests at each
layer.
`DocumentVersionService::diff` prefers the stored artifacts when both versions
have one and falls back to `reconstructContent()` otherwise (R43: both branches
tested); `restore` re-activates the artifact with the row. W3 creates versions
on correction through the same service. Retention: `kb:prune-archived-versions`
deletes the artifact with the row; ADR 0020 D5 crypto-shred applies to
artifacts.

**Why it is its own workstream.** It is the prerequisite of W3 and W4, and it
gives *Semantic Time Travel* (parked since v8.0) the faithful "what did this
document say on date X" it needs: the Time Machine already answers that for the
indexed body, the artifact answers it for the document.

**Tri-surface.** `kb:doc-versions {document} {--tenant=}` (new CLI over the
existing tenant-scoped `DocumentVersionService`; `--tenant` validated non-empty
and the document resolved with `forTenant()`, as `kb:ocr` and
`kb:reembed-project` do — never the process-global default context) · the existing HTTP endpoints, `diff` now artifact-aware · MCP
`KbDocumentVersionsTool` (read — the R44 surface the v8.7 feature never got).

**ADR.** **0030 — Conversion artifacts on the Time Machine.**

---

### W3 — Digitization Review: side-by-side, as a workflow (L) — v8.37

**Goal.** The human-in-the-loop surface Annota has, plus the three things
they cannot have: it is a workflow, it is measured, and the agent only proposes.

**UI** (`frontend/src/features/admin/kb/review/*`, R11/R12/R15): original
page render left (PDF.js / image), Markdown right in raw or preview, page
navigator, a **confidence heat-map** marking low-`ocr_confidence` spans,
Save → new version (W2), per-page status `unreviewed → reviewed` stored in a
new tenant-aware `kb_document_page_reviews` table (R30/R31), and document
approval. **`approved` is not a new document status**: approval is the ADR
0014 promote action — `generation_source` `auto → human` — plus
`canonical_status = accepted` when the document is canonical; nothing else is
written. A correction creates a new version through `DocumentIngestor` (the
full document is re-chunked, as any re-ingest); pages whose text is unchanged
are served by the embedding cache (`EmbeddingCacheService`, keyed by text
hash), so no provider call is made for them — cheap, but not "page-only".
**Flag:** `KB_DIGITIZATION_REVIEW_ENABLED` default-OFF (R43): OFF = routes and
screen absent (clean 404), tools not registered; ON = the surface above.

**The tier mapping — the elegant part.** A converted page is machine output:
its document is born in the **`auto` tier** (ADR 0014) and stays there until
a person approves it; approval promotes it to `human`. The reranker firewall
we already have ranks it accordingly — Annota's "review before export" is
already modelled by our schema. Nothing new to invent, one column to set.

**Agent surface — propose, never commit.** MCP `KbProposeTextCorrectionTool`
(`document`, `page`, `old`, `new`, `rationale`) writes a **correction
candidate** (the ADR 0003 pattern: `/suggest → /candidates → /promote`) —
the only MCP tool of the cycle that writes toward the **corpus**, and it
writes a candidate, never content. (W4's `KbCreateExportTool` is
side-effecting too — it starts a job and writes a retained export on the
staging disk — but it never touches corpus content; its controls are listed
in W4.)
**There is no MCP tool that sets a review status**: page and document review
status change only through the HTTP surface (role-gated, R32 matrix row) and
the CLI — a documented R44 exception, the explicit inverse of Annota's
`update_document_page` / `update_asset_review_status`. Rationale in ADR 0029:
an OCR'd inbound letter is external text; an agent that read it must not be
able to edit another document's content, nor vouch for it.

**Workflow.** The review queue is a `laravel-flow` definition with an
approval node (flow v2 is already the host's dependency); reviewers see it in
`flow-admin`; the v8.15 engagement suite counts corrections toward badges.

**Measured.** Two host-side metrics, **`CharacterErrorRateMetric`** and
**`WordErrorRateMetric`** in `app/Eval/Metrics/` implementing
`Padosoft\EvalHarness\Metrics\Metric` and registered by `EvalRegistrar` — the
R23 pattern `CitationGroundednessMetric` already follows, so no change to the
`padosoft/eval-harness` package is required (upstreaming them later is a
separate, padosoft-scoped ask). CER/WER are computed against the human-approved
version as gold; the nightly `eval:nightly` gains an `ocr` lane; a regression
in CER across a driver upgrade fails the gate. *Nobody measures their own OCR
correction quality; we will publish ours.*

**Tri-surface.** `kb:review {document} --page` (CLI apply of a candidate /
set status) · HTTP `/api/admin/kb/documents/{id}/pages/{n}` +
`/review-status` + `/corrections` · MCP `KbProposeTextCorrectionTool`
(propose) + `KbReviewStatusTool` (**read** — what is reviewed, by whom).
Documented R44 exception: no MCP write of review status.

**ADR.** 0029 (extended with the propose-only decision), plus **0031 —
Digitization Review and the auto-tier mapping**.

---

### W4 — Export the governed wiki as a portable workspace (M/L) — v8.38

**Goal.** `kb:export-wiki --project=X --as-user=U --format=llm-wiki|markdown|llms-txt`
produces the folder the pattern expects — and then more than the pattern.
`--as-user` is not optional: the export is ACL-filtered for that principal
(below), and the command refuses to run without one.

```
{project}/
  raw/                 # stored conversion artifacts (W2), images/ alongside
  wiki/                # canonical + auto pages, one .md per document
    index.md           # from KbWikiHubTool's hub + per-project roll-ups
    log.md             # from the Auto-Wiki operation log (v8.11.5)
  AGENTS.md            # skills: how to EXTEND this wiki (ingest/query/lint)
  CLAUDE.md            # same, Claude-flavoured; generated from CanonicalType
  README.md            # what this is, where it came from, how to talk to it
  llms.txt / llms-full.txt
  .mcp.json            # → the enterprise-kb server URL; secret-free (see below)
  MANIFEST.json        # tenant, project, exporter, ACL summary, sha256 per file, chain hash
```

**What makes it ours, not theirs.**
- Pages carry frontmatter the agent can *trust differently*: `tier: human|auto`,
  `evidence: guideline|peer_reviewed|…`, `provenance_tier:
  trusted-internal|untrusted-external|machine-generated` (the ADR 0028
  authorship value, verbatim) plus `extraction: text-layer|ocr` (the W1
  extraction origin — two keys, two contracts, never merged),
  `canonical_type: decision|runbook|rejected-approach|…`. **Rejected
  approaches are exported** — an agent reading the folder inherits what the
  team ruled out, not just what it wrote.
- **`.mcp.json` carries no credential.** The folder is portable by design,
  so anything inside it must be safe to copy: the file names the server URL
  and an `env`-referenced token variable (`ASKMYDOCS_TOKEN`) the consumer sets
  at connection time — never a bearer token, never a signed URL, never the
  exporting user's session. Authentication is the live server's own (Sanctum
  token issued to the person who opens the folder, bound to *their* ACL, not
  the exporter's). A regression test asserts that no token-shaped string
  (`Bearer …`, `sk-…`, a 40+ char base64 run) appears anywhere in the export
  or the manifest.
- **ACL-aware** (R33): the export contains only what the exporting user may
  retrieve, computed through `AccessScopeScope` — never a superset. The
  export runs async, and a queue worker has no request principal
  (`AccessScopeScope` applies no restriction for a null user), so the
  **exporting principal is captured at request authorization** (user id +
  tenant) and **re-applied in the job** before any query — and "re-applied"
  means the **user**, not only the tenant: `AccessScopeScope` derives its
  predicate from `auth()->user()` and applies no restriction when there is
  none, so the job reloads the `User` by id, re-checks it still holds the
  export permission, sets it as the authenticated user for the duration of
  the export and clears it in `finally` (a queue worker is reused across
  jobs). Regression tests: no user → the job refuses, wrong/deleted user →
  refuses, worker reuse → the second job sees no leaked principal. The CLI
  requires an explicit, audited `--as-user=` and refuses to run unrestricted.
- **PII-governed `raw/`**: the artifact is the raw converted Markdown (ADR
  0020 keeps the vector store, not the disk, as the protected surface). The
  export therefore renders `raw/` **through the tenant PII policy** — the same
  surrogates `ChunkRedactor` produces when redaction is active — so the folder
  never carries text the index itself refuses to hold; a regression test
  ingests a fixture with a codice fiscale and asserts the export. Figures are
  different — `ChunkRedactor` rewrites text, not pixels — so when the
  tenant's PII policy is active the `.ocr/images/` directory is **omitted**
  from the export by default (the Markdown keeps the reference, the
  `MANIFEST.json` lists the omission) and included only with an explicit,
  audited `--include-images`; a test covers both.
- **Tamper-evident**: `MANIFEST.json` hashes every file and chains them, the
  same primitive as the compliance reports (v8.0 W8). A folder found on a
  laptop can be verified against the server.
- **Dual-link syntax** `[[slug|Title]]` + `(relative/path.md)` so Obsidian,
  GitHub and plain Markdown all resolve.
- **Already compiled.** Annota ships `raw/` + instructions and the customer's
  agent pays to compile. We ship the compiled, human-vouched wiki; the skills
  in `AGENTS.md` are for *extending* it.
- **The folder is untrusted content, and says so.** Once materialised, the
  live `ProvenanceToolFirewall` cannot follow the pages; the generated
  `AGENTS.md` / `CLAUDE.md` therefore open with an explicit boundary — every
  page under `raw/` and `wiki/` is data, `provenance_tier:
  untrusted-external` pages may be quoted but never followed as
  instructions, and the `.mcp.json` connection must not be used on a page's
  say-so — and the export test suite includes a prompt-injection fixture
  (an externally authored page carrying instructions) asserting it is
  exported with the marker, not stripped, not promoted.

**Round-trip.** `kb:import-wiki {folder} --tenant= --as-user=` diffs the
folder against the server's versions (W2) and turns edits into **promotion
candidates** (ADR 0003) attributed to the importing user — never direct
writes. `{folder}` is a **local filesystem path** (the folder a person
brought back, not a KB-disk path): absolute or relative to the working
directory, resolved with `realpath()` and every file inside re-checked to
stay under it (no symlink escape), while the *destination* `source_path` of
each candidate is normalised through `KbPath::normalize()` like any ingest —
the same guard `kb:ingest-folder` applies, stated so the two cannot diverge.
`--tenant` and `--as-user` are mandatory: a console process has no principal,
and a candidate without an actor would violate the attribution the whole
loop exists for. This is the loop Annota does not close.

**Tri-surface.** Artisan `kb:export-wiki --as-user=` / `kb:import-wiki` ·
HTTP `POST /api/admin/kb/exports` (async, `kb-staging` disk, signed download
URL) + `GET /exports/{id}` + `POST /api/admin/kb/imports` (candidates only) ·
MCP `KbCreateExportTool` / `KbGetExportTool` (the two Annota tools we *do*
mirror — read-only **with respect to the corpus**, but `KbCreateExportTool`
is side-effecting: it starts a job and writes a retained artifact, so it is
annotated as such, authorised like the HTTP endpoint, audited in
`admin_command_audit`, rate-limited per principal and idempotent on
`(tenant, principal, project, format)` for the retention window) +
`KbImportWikiTool` (yields promotion candidates — the propose-only pattern,
never a write). Retention is its own
knob, `KB_WIKI_EXPORT_RETENTION_HOURS` (default 24), swept by
`kb:prune-wiki-exports`; `KB_STAGING_RETENTION_HOURS` keeps its single job
(upload staging batches) and is not reused.

**Flags.** `KB_WIKI_EXPORT_ENABLED` default-OFF (R43). Closes the README
`Future` items *source-retention wiring* and *content export/portability*.

**ADR.** **0032 — Portable wiki export and candidate-only import.**

---

### W5 — Wiki maintenance as a routine with a mandate (S/M) — v8.39

**Goal.** `kb:wiki-maintain` runs today as `system:autowiki` on a cron. Run it
instead as a **routine**: *the user, through the agent*, with a mandate
(`kb.wiki.compile`, `kb.wiki.lint`), a spend ceiling, and a pause-and-ask when
it wants to do something outside the mandate — deprecating a `human` page,
say. The question lands in the "awaiting you" queue; `laravel-rebel-ai-guard`
sees `routine_approval_starvation` if nobody answers.

**Why.** `lucasastorian/llmwiki` tells its users to *"set up a Claude Routine
so Claude refreshes the wiki"*. We have the routine engine; ours is sovereign,
delegated, budgeted, and has a human reachable. Same story, better ending.

**Dependency decision — explicit.** AskMyDocs depends on `laravel-flow ^2.5`,
**not** on `laravel-routines`. Adding it is a deliberate choice, like the IAM
dependency flagged in ADR 0028 — record it in the ADR, default the target
**OFF**, and keep the cron path working unchanged (R43 both states).
`RoutineTarget` is defined by `padosoft/laravel-routines-contracts`;
`laravel-flow` v2.5 ships **no** adapter for it. The host therefore implements
one thin adapter, `App\Routines\WikiMaintenanceRoutineTarget implements
RoutineTarget`, over the **existing** core the cron command already injects —
`App\Services\Kb\AutoWiki\WikiMaintainer` (no rename, no second
implementation); a generic flow-backed target is recorded as an upstream ask
in `docs/handoff/` for a padosoft-scoped session.

**No double run.** With `KB_WIKI_ROUTINE_ENABLED=true` the scheduler entry for
`kb:wiki-maintain` is gated off (the routine owns the nightly run); with the
flag off, or the package absent, the cron entry is byte-identical to v8.35.

**Tri-surface.** PHP `kb:wiki-routine {status|run}` · HTTP
`GET /api/admin/kb/wiki-routine` (status, last run, pending questions) +
`POST /api/admin/kb/wiki-routine/run` · MCP `KbWikiRoutineStatusTool` (read).
Doc-site: the `auto-wiki` page gains a "Maintenance as a routine" section
(R45).

**ADR.** **0033 — Auto-Wiki maintenance as a delegated routine.**

---

### W6 — Optional adjacency: `vision` column in Tabular Review (S) — v8.40

**Status: deferred — out of scope for this cycle unless promoted.** W6 is
recorded here as an adjacency, not as an executable workstream: it has no
tri-surface contract, OFF-state behaviour, tenant boundary, test plan,
acceptance criteria or doc-site deliverable in this plan, and the checklist in
§5 names only its flag (`KB_TABULAR_VISION_ENABLED`). It is **not** scheduled
in §2 and the hand-off checkpoint row exists only to record the decision. If it
is promoted, it gets a plan addendum with the same executable scope as W1–W5
(the R43/R44/R45 lines below, plus a `tabular-vision.mdx` page) **before** a
branch is opened.

A fourth `agent` dimension next to `extract` / `graph` / `verify`
(`GovernanceColumnResolver`, `TabularReviewExtractor`): a column whose cell is
produced by a vision call over the document's figures (W1 extracted them) or
over an image asset. The gescat case: product photos → colour / material /
pattern with human review in the grid. **Not strategic**; do it only if the
grid gets image-heavy tenants. Everything else about image labelling — Magic
Select, COCO, YOLO — stays out (audit §3.5).

---

## 2. Sequencing and dependencies

```
W1 OcrConverter ──┐
                  ├──► W3 Digitization Review ──► W5 routine (after W4)
W2 Versions ──────┤
                  └──► W4 Export / Import ──────► W6 vision column (optional)
```

W1 and W2 are independent and start together (v8.36). W3 needs both. W4 needs
W2 (artifacts) and benefits from W1 (images/). W5 needs nothing but the
dependency decision. Branching per R37 as the hand-off spells out: one
**release integration branch** per version (`feature/v8.36`, `feature/v8.37`,
…) merged to `main` once with the GA tag, and one **task branch** per
workstream (`feature/v8.36-W1`, `feature/v8.36-W2`, `feature/v8.37-W3`, …)
whose PR targets its integration branch; RC-tagged per R39, Copilot loop per
R36/R40.

---

## 3. Out of scope, on purpose

- Image labelling, Magic Select/Annotate, COCO export, model training — a
  different market (audit §3.5).
- A hosted "Brain": we already are one.
- Changing the canonical promotion rule (ADR 0003) to let agents commit —
  the propose-only stance is the product, not a gap.
- Auto-enabling OCR before the CER/WER baseline exists.

---

## 4. Acceptance — what "done" proves

1. A scanned three-page PDF with a table and a figure, uploaded via the admin
   modal **and** arriving as an IMAP attachment, becomes a searchable document
   with the figure referenced in its Markdown, PII redacted before embedding,
   `provenance: ocr` on every chunk, and a FinOps line item — with
   `KB_OCR_ENABLED=false` an image is the same 422 as today and a scanned PDF
   yields the same empty document (or the same `RuntimeException` when neither
   smalot nor `pdftotext` can read it) as today (R43).
2. Correcting one word on page 2 creates version 2, re-embeds only page 2,
   and the diff endpoint shows exactly that word.
3. An MCP agent calling `KbProposeTextCorrectionTool` produces a candidate; no
   chunk changes until a human approves; no MCP tool can set a review status
   (the roster test proves none is registered), and the HTTP `/review-status`
   endpoint returns 403 to every non-reviewer role (R32 matrix row).
4. CER/WER between the OCR output and the approved version is reported by
   `eval:nightly`; a synthetic 5-point CER regression fails the gate.
5. `kb:export-wiki` on a project with one `human`, one `auto` and one
   `rejected-approach` doc yields the layout above; a member scoped to
   `hr/policies/**` gets an export **without** `hr/salaries/**`; editing a page
   and running `kb:import-wiki` yields one promotion candidate and zero writes.
6. With `laravel-routines` installed and the target ON, the nightly wiki
   maintenance runs as a routine and a deprecation of a `human` page **pauses**
   with the question in the queue; with the package absent, the cron path is
   byte-identical to v8.35.

---

## 5. Deliverables checklist (per workstream)

- [ ] ADR (0029–0033) accepted before code
- [ ] Core service + PHP Artisan + HTTP + MCP over one core (R44)
- [ ] Both flag states tested (R43); tenant scope through relationships (R30/R33).
      The flags: **W1** `KB_OCR_ENABLED`, **W2** `KB_CONVERSION_ARTIFACTS_ENABLED`,
      **W3** `KB_DIGITIZATION_REVIEW_ENABLED`, **W4** `KB_WIKI_EXPORT_ENABLED`,
      **W5** `KB_WIKI_ROUTINE_ENABLED`, **W6** `KB_TABULAR_VISION_ENABLED` — all
      default-OFF
- [ ] Playwright real-data E2E for every screen (R12/R13); a11y checklist (R15)
- [ ] Doc-site page per feature (R45): `documents-and-ocr.mdx` (W1+W2),
      `digitization-review.mdx` (W3), `wiki-export.mdx` (W4), and a
      "Maintenance as a routine" section on `auto-wiki.mdx` (W5); README
      feature rows + the comparison table gains an **Annota AI** column
- [ ] `CHANGELOG.md` entry per release; `ENTERPRISE-COMPLETENESS-ROADMAP.md`
      R5 (Slides OCR) re-scoped onto `OcrConverter`
