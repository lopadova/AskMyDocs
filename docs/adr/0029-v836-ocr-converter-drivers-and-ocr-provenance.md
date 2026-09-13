# ADR 0029 — OCR converter, drivers, and OCR provenance

- **Status:** Accepted
- **Date:** 2026-09-12
- **Cycle:** v8.36 (W1 of the Document Intelligence cycle); extended by W3 (v8.37)
- **Builds on:** [ADR 0014](0014-v811-auto-wiki-tier.md) (`auto` tier),
  [ADR 0020](0020-v823-pii-safe-ingestion-reversible-vault.md) (redact-before-embed),
  [ADR 0028](0028-source-acl-mirroring-and-ingest-provenance.md) (ingest-time
  provenance), the v3 ingestion-pipeline registry (`config/kb-pipeline.php`,
  R23 FQCN validation at boot + `supports()` mutex), R30/R31 tenant scoping,
  R43 both-state flags, R44 tri-surface, SEC-LLM-001 (provider egress policy).
- **Plan:** [PLAN v8.36 → v8.40](../v4-platform/PLAN-v8.36-document-intelligence-and-llm-wiki-export.md) §W1;
  audit [AUDIT-2026-09-11-annota-ai-gap](../v4-platform/AUDIT-2026-09-11-annota-ai-gap.md) §2.1.

## Context

AskMyDocs refuses a whole class of enterprise input. `SourceType::supportedMimes()`
has no `image/*`, so a PNG or JPEG upload is a 422; `PdfConverter` reads the
text layer only (`smalot/pdfparser`, then a `pdftotext` fallback), so a scanned
PDF yields an empty document or a `RuntimeException`. The connectors that carry
scans — IMAP attachments, OneDrive, Drive — are already shipped, which means the
gap is visible to every tenant that receives a contract on paper.

Three properties of the existing pipeline shape the decision:

1. **Conversion is a registry, not a switch.** `PipelineRegistry` boots every
   converter listed in `config/kb-pipeline.php`, validates the FQCN implements
   `ConverterInterface`, and resolves by *first match on `supports($mime)`*.
   `supports()` sees a MIME string, never the bytes; ambiguity is a
   configuration bug caught by the mutex tests. A new converter is therefore a
   list entry, not a change to the pipeline — and a *content-dependent* choice
   cannot be a converter at all.
2. **Chunking is shape-driven.** `PdfPageChunker` slices on `## Page N`
   headings; any converter that emits that shape gets per-page chunks with no
   chunker change.
3. **Provenance and PII already have one seam each.** `provenance_tier` is set
   from ingestion metadata by `ProvenanceResolver` (ADR 0028) and
   `ChunkRedactor` runs on the chunk drafts in both ingest paths (ADR 0020 D3).
   OCR output must flow through both, unchanged.

Scans are also where the personal data lives (codici fiscali, IBANs, signatures
on inbound letters), and OCR is the first per-page priced operation in the
platform: neither the PII policy nor FinOps can be an afterthought. Two of the
engines worth having are hosted APIs — the bytes leave the tenant *before* any
text exists for the PII seam to see — so egress is a decision of its own.

## Decision

### 1. `OcrConverter` for images; the scanned-PDF fallback lives inside `PdfConverter`

`App\Services\Kb\Converters\OcrConverter` implements `ConverterInterface`, is
listed in `config/kb-pipeline.php` before `PdfConverter`, and `supports()`
**exactly** the four image MIMEs in `SourceType::imageMimes()` (`image/png`,
`image/jpeg`, `image/tiff`, `image/webp`) — and only when `KB_OCR_ENABLED=true`.
With the flag off it claims nothing, so the registry behaves as v8.35 (image →
"No converter registered", PDF → `PdfConverter`).

`PdfConverter` stays the **sole** `application/pdf` match in both flag states.
When OCR is on it runs `PdfTextLayerProbe`, which decides **per page** over
the whole probe window — every page up to `KB_OCR_MAX_PAGES`
(`KB_OCR_PROBE_PAGES=0`, the default; a positive value bounds the window and
is a documented trade-off: a scanned page beyond it is not seen, and a page
the OCR cap would refuse cannot change the verdict). A page with fewer than
`KB_OCR_PROBE_MIN_CHARS` extractable characters that carries an image XObject
is a *scanned* page; one with neither is *blank* (a separator — never a reason
to OCR by itself). No text page at all is `empty`; text pages **and** scanned
pages is `mixed` — a typed cover over scanned body pages, scans stapled to a
memo — and the **whole document** is routed to OCR so no page is silently
lost (the text pages are OCR'd too; a per-page hybrid that keeps the parsed
text of text pages is a later refinement, not this cycle's); otherwise
`present`. `empty`, `mixed`, an unreadable file that `pdftotext` cannot read
either, or an ingest carrying `metadata.ocr.force = true` (what `kb:ocr`
sets) routes the bytes to the same `OcrService` the image converter uses,
with `reason` `scanned_pdf` / `mixed_pdf` / `forced`. The verdict (`present`
· `mixed` · `empty` · `unreadable` · `skipped`) is recorded in
`extractionMeta['text_layer_probe']`, the scanned page numbers with it; a PDF
whose every content page has a text layer keeps its current path, byte for
byte. Regression cases: a cover over scanned pages is `mixed` and OCR'd, a
blank separator inside a text PDF is `present`, a bounded window is blind
beyond it. No two converters ever claim one MIME: a
converter-mutex test (the twin of the chunker one) proves it in both states.

### 2. `SourceType` always knows `IMAGE`; the flag gates acceptance at the entry points

`SourceType::IMAGE = 'image'` exists unconditionally, with `fromMime()` /
`fromExtension()` (`png`, `jpg`, `jpeg`, `tif`, `tiff`, `webp`) / `toMime()`
(`image/png`, the **family** label — one source type is one value per family)
/ `isBinary()` (`true`) and `config/kb-pipeline.php::mime_to_source_type`
updated in lockstep. The family label is never what an image is dispatched
as: every entry point that knows the extension sends the **exact** raster MIME
— `SourceType::imageMimeFromExtension()` (`image/jpeg`, `image/tiff`,
`image/webp`, `image/png`) in the folder walker (`DispatchIngestFanOutStep`,
sync and queued) and in the upload staging (`KbIngestBatchItem.mime_type`,
which the commit dispatches), connectors the MIME they carry — so the
converter registry resolves the four exact MIMEs `OcrConverter` claims and
`knowledge_documents.mime_type` records what the bytes are. The drivers still
read the magic bytes (`OcrRequest::effectiveMimeType()`) before building a
data URL or picking an input format: an extension is a label, the bytes are
the fact. What the flag gates is **acceptance**:
`supportedMimes(bool $includeImages)` and `knownExtensions(bool $includeImages)`
receive `config('kb.ocr.enabled')` from every entry point — `KbIngestController`,
`StageKbUploadRequest` + `KbUploadStagingService`, `KbIngestFolderCommand` /
`ListFolderFilesStep` / `DispatchIngestFanOutStep`, and the connector bridge
(`HostIngestionBridge::dispatchIngestion()`, which refuses an image with the
flag off as a recorded `connector_ingest_refused` audit event with
`reason: ocr_disabled`, confirming the IMAP UID so the mailbox is not
re-presented every sync). With the flag off an image is the same 422 /
"Unsupported file type" as today and the "Supported:" list does not mention it
(R43 — the OFF path is the shipped default). `FileTypeSniffer` verifies the
magic bytes of every image so a renamed executable never reaches a driver.

`PdfPageChunker` claims the `image` source type alongside `pdf`: the converter
emits the same `# {filename}` + `## Page N` shape for images, so chunking is
untouched.

### 3. Drivers behind one contract, selected by `KB_OCR_DRIVER`

`App\Services\Kb\Ocr\OcrDriver` is the contract:

```php
interface OcrDriver
{
    public function name(): string;
    public function fingerprint(): string;               // engine variant: model / binary version / language pack
    public function isAvailable(): bool;                 // binary / key / package present
    public function isRemote(): bool;                    // sends the bytes out of the tenant
    public function meteringMode(): OcrMeteringMode;     // PerPage | Sdk
    public function maxDurationSeconds(int $pages): int;  // declared worst case, capped by the run budget KB_OCR_JOB_TIMEOUT every driver enforces: sizes the run-directory lease (§6)
    public function boundsWorkWithoutPageCount(): bool;  // may run on an uncountable PDF: work bounded by construction (§4)
    public function recognise(OcrRequest $request): OcrResult;
}
```

`fingerprint()` is part of the contract, not a convention: it is one of the
inputs of the content-addressed run key (§5), so a driver that changes engine
without changing its fingerprint would silently reuse another engine's
recorded run. `OcrResult` carries `pages: list<OcrPage>` — `number`, `markdown`, `confidence`
(0..1, null when the driver cannot say), `figures: list<OcrFigure>` — plus the
engine's own `meta`. Drivers are listed in `config/kb.php` under `ocr.drivers`
(key → FQCN) and resolved through `OcrDriverRegistry`, which validates every
FQCN at boot (R23):

| Driver | Key | Remote | Why it is in the list |
|---|---|---|---|
| Tesseract (local CLI) | `tesseract` | no | Free fallback; no layout. **The shipped default** (`KB_OCR_DRIVER` unset or blank → `tesseract`, `config/kb.php`): it is the only engine with no Python runtime, so a fresh install that flips `KB_OCR_ENABLED` on can run OCR at all. |
| Docling (IBM, Apache-2.0, local CLI) | `docling` | no | Layout, tables, figures, formulas → LaTeX. **The recommended driver for a sovereign install** — recommended, not default: an operator opts in with `KB_OCR_DRIVER=docling` once the binary is on the worker; the plan's "default for sovereign installs" means this recommendation, never a different shipped default. |
| Mistral OCR (API) | `mistral-ocr` | **yes** | Strongest on tables and complex layouts; EU-hosted provider. |
| Vision LLM (`laravel/ai` — the configured chat provider) | `vision-llm` | **yes** | Zero new infrastructure; metered by the SDK hook like any call. |
| Fake | `fake` | no | Deterministic fixture driver for tests and the E2E harness; resolvable only in `local` / `testing` / `development` — an allow-list, so `prod` or a misspelt name is production (SEC-ENV-001). |

The driver is a deployment choice, not a per-document one: `KB_OCR_DRIVER`
names exactly one. An unavailable driver **fails loudly** at conversion time
(`OcrDriverUnavailableException` → the ingest job fails, the upload item
transitions to `failed` with the reason) — never a silent empty document (R14).
Local drivers invoke their binaries through `Symfony\Process` argument arrays
with timeouts; no shell string is ever built from a filename, a process
failure is rethrown with a bounded diagnostic (exit code + 200 chars of
stderr — never the recognised text, SEC-LOG-001), and the figure links in
Docling's Markdown — OCR output — are accepted only as a bare
`input_artifacts/<name>.<png|jpg|webp|tif>` resolved inside the working
directory (SEC-PATH-001).

### 4. Remote drivers run only with `KB_OCR_ALLOW_REMOTE=true` — fail closed; work is bounded before egress

`mistral-ocr` and `vision-llm` post the document bytes to a third party before
the PII seam can see any text, which makes the driver choice a data-subprocessor
decision (SEC-LLM-001 gate 3). Selecting them is therefore not enough:
`OcrDriverRegistry::resolve()` refuses any driver whose `isRemote()` is true
unless `config('kb.ocr.allow_remote') === true`. The env value is cast with
`FILTER_VALIDATE_BOOLEAN` and compared strictly with `=== true`, so only a
value the filter recognises as true (`true`, `1`, `yes`, `on`) opens the
gate; `false`, `0`, `no`, `off`, an empty value, an absent variable or any
typo casts to `false` and keeps the gate **closed**. Every status surface reports `remote: true|false` on an
OCR'd document, so an auditor can answer *did this scan leave our
infrastructure?* per document. A negative test proves a remote driver cannot
run with the knob off.

The switch is necessary, not sufficient (SEC-LLM-001 gates 2 and 7). Where
the bytes go is bounded: `vision-llm` resolves provider and model through
`AiManager`, the choke point every chat call passes, so the platform's
provider policy applies unchanged; `mistral-ocr` posts only to an `https`
host in the exact allow-list `kb.ocr.mistral.allowed_hosts` and validates
the response's content type, size and per-figure size. How much leaves is
bounded: `OcrService::convert()` refuses a document over `KB_OCR_MAX_PAGES`
(counted format-independently before conversion — the probe's parser for a
PDF, the IFD chain for a multi-page TIFF, one for any other image) or
`KB_OCR_MAX_BYTES` **before** any driver
runs, with a machine-readable reason (`too_many_pages` / `too_many_bytes`;
and `multi_frame_image` for a multi-page TIFF handed to a driver that
transcribes one frame per image — `OcrDriver::acceptsMultiFrameImages()`,
false for `tesseract`, `vision-llm` and `mistral-ocr`, true for `docling`
— which would otherwise be billed for every frame and read the first)
that `OcrCostEstimator` reports in advance together with
`driver_available`, so the modal never promises a run the registry will
refuse (R14); the estimate and the service read the page count from the
same probe (`pages_total`) so they cannot disagree, and a refusal fails the
ingest job immediately rather than being retried three times. The count is
trusted only when the parser actually read the file (`pages_exact = true`).
When it could not, the probe still reports a `/Type /Page` object count, but
that number is a **floor**, and a lower bound cannot enforce a maximum: a
malformed or hostile PDF with more real pages than visible page objects
would pass the cap and be posted to a third party. So an uncountable
document is treated as uncountable: for a **remote** driver the service
refuses it **before egress** with a third machine-readable reason,
`pages_uncountable`, and the estimate reports the same refusal
(`would_ocr = false`, `pages_exact = false`); a **local** driver runs on it
only where the work is **bounded by construction** — a byte cap is not such
a bound, since a small compressed file can still hold an unbounded page
count or expensive image streams. The local driver that rasterises page by
page (`tesseract`) qualifies: `pdftoppm -f 1 -l KB_OCR_MAX_PAGES` renders at
most the cap whatever the object table claims, and each page then runs under
the driver's own per-page timeout, so CPU, memory and time are capped by the
same numbers the verified path uses. `vision-llm` rasterises the same way but
is a **remote** driver (each page is posted to the configured provider), so
the egress rule above applies first and it is refused like `mistral-ocr`.

Two byte limits, not one. `KB_OCR_MAX_BYTES` bounds the **source file**; it
says nothing about what a page renders to — a small compressed PDF can declare
a 200-inch MediaBox and rasterise to gigapixels, or carry image streams that
expand far beyond the file. The page-by-page drivers therefore bound the
**rendered page** separately, and **before the render**: the rasteriser reads
the page geometry `pdfinfo` reports (points, 72 per inch) for the pages that
will be rendered and lowers the render DPI so that no page's long side exceeds
`KB_OCR_RASTER_MAX_PAGE_PX` (default 6000) — `pdftoppm -W/-H` are crop sizes,
not a scale bound, and are not used: a crop would silently discard the text
outside the box, never shrink the render. A page that would need less than the
50-DPI floor to fit (a 200-inch MediaBox) is refused before anything is
rendered; a PDF for which `pdfinfo` reports no page size is refused the same
way (an unbounded render is never attempted); a missing `pdfinfo`
(`KB_OCR_PDFINFO_BIN`, poppler-utils beside `pdftoppm`) is an unavailable
driver. The PNG that was actually produced is re-measured against the same
box (defence in depth), and a rendered page over
`KB_OCR_RASTER_MAX_PAGE_BYTES` (default 10 MiB) or over the box is a
deterministic refusal (`rendered_page_too_large`) raised **before** the page
is decoded locally or posted to a vision provider, with the working directory
removed. A **source image** is a page too: the drivers that decode or post
it as is (`docling`, `mistral-ocr`) apply the same pixel box and page byte
cap to the source bytes before the engine starts or the request is built —
the source cap admitted the file, the page cap decides whether it may be a
page — and the upload estimate takes that decision on the staged bytes
(`ImageBounds::refusalReason()`, after the page-count and frame gates) so
the modal states `rendered_page_too_large` before commit. For
`vision-llm` this is the egress invariant made concrete: what leaves is a
rendered page, and no rendered page leaves unbounded. A
whole-file engine (`docling`) cannot be told the size of what it is handed and
is refused
like a remote one. The contract carries the fact
(`OcrDriver::boundsWorkWithoutPageCount()`), the service and the estimate
consult it, and where the run is allowed the estimate shows the floor with
`pages_exact = false` so the modal never presents it as an exact price. Deny-by-default tests cover
the knob off, a host outside the list, a non-JSON response, both overflows
and the uncountable-to-remote refusal.

### 5. Same bytes, same driver: the recorded run is reused, never re-billed

Conversion runs before the version-hash check, so idempotency (CLAUDE.md §5)
would otherwise stop at the database while the OCR bill did not: a
re-ingest of identical bytes, an IMAP backfill or a GitHub-Action full sync
would pay for every document again. `OcrService` therefore records each run
at `{source}.ocr/{run}/result.json` (pages, confidence, figure descriptors,
driver, engine meta) next to the figures, and reuses it when the same bytes
arrive through the same engine (the run key embeds the driver fingerprint) —
no driver call, no FinOps row, `metadata.converter.ocr.reused = true`. The
lookup needs only the driver's identity: a run recorded by a driver that
cannot run here today (remote egress off, a binary gone) is still reused —
the egress gate and the availability check apply before a driver call, never
before the lookup.
`kb:ocr` (`metadata.ocr.force`) bypasses the reuse on purpose; a run whose
figures went missing is redone. The recorded text is **raw** OCR output on
the KB disk, the same posture as the source file itself (ADR 0020 keeps the
vector store, not the disk, as the protected surface): under the source's
ACL, purged with it by the deleter's reference gate, never the redacted
text (that lives only in the chunks). A deployment that must not hold raw
OCR text beside its scans sets `KB_OCR_REUSE_ENABLED=false` — every ingest
then runs the driver, records **no `result.json`** and is a **new run with
its own attempt identity** (its own `{run}` directory, exactly like a forced
re-run), so a re-ingest of the same bytes never rewrites the figures a
previous document version still references; both states are tested (R43).
That knob governs the recorded run — the raw OCR text and its reuse — and
nothing else: the figures under `{run}/images/` follow
`KB_OCR_FIGURES_ENABLED` (default on) and the retention mode of §5
(`reference_only` stores neither run nor figures; `full_copy` and
`markdown_only` store figures when the switch is on, whatever the reuse
knob says), and the W2 artifact follows ADR 0030. So the two flags compose
as a matrix, not a hierarchy: reuse off + figures on = figures on disk, no
raw text; a deployment that must hold **no** OCR asset beside its scans sets
both off, or runs `reference_only`. Whether the
converted Markdown of an OCR'd document is kept as a *version artifact* is
ADR 0030's decision (`KB_CONVERSION_ARTIFACTS_ENABLED` under the effective
`source_retention` mode), and the two compose without surprises: a
deployment that must retain **no** raw OCR text on disk sets reuse off
**and** either leaves artifacts off or runs `reference_only`; one that keeps
artifacts on has, by that choice, accepted raw converted text under the
document's own controls (ADR 0030 §3), whatever the reuse knob says. The
chunks stay the only redacted form in every combination.

The `.ocr/` directory is **retention-aware**, like every other local copy
(ADR 0014, ADR 0030 §3): it is written only when the effective
`source_retention` mode keeps a local copy at all (`full_copy`,
`markdown_only`). In `reference_only` — the mode that promises *metadata,
external identifiers, chunks and embeddings, nothing else on our disk* —
`OcrService` records no run and stores no figure: the driver runs, the text
is chunked and embedded, the figures are counted in the metadata but not
persisted (the Markdown carries no `images/` reference, the same shape as
`KB_OCR_FIGURES_ENABLED=false`), and reuse is not available because there is
nothing to reuse from. The resolver that decides this
(`SourceRetentionResolver`, ADR 0030) lands with W2; until it does the flag
`KB_OCR_ENABLED` is off, so no deployment holds an `.ocr/` directory it
did not ask for. Both modes are tested (R43). On
a shared disk two tenants with one source key and identical bytes share the
run (the second is `reused: true` with no FinOps row; no data crosses, the
first tenant carries the cost) — a deployment that isolates tenants by disk
or prefix isolates the runs with them.

### 6. Figures beside the document, in a content-addressed run directory

Figures are written **at conversion time** by `OcrFigureStore` — the Flow
persists step outputs to the database, so binary blobs cannot travel in
`ConvertedDocument::mediaItems`; the store writes them and `mediaItems` lists
the paths — at

```
{prefix}/{dir of source_path}/{basename}.ocr/{run}/images/fig-{page}-{n}.png
```

where `{run}` is the **full 64-hex** `sha256(bytes · driver name · variant)`
— never a truncated prefix: a 16-hex prefix is a 64-bit identifier two
different tuples could share, and one immutable run directory would then
serve the wrong text or figures to a document and defeat the reuse lookup;
the full digest makes the identity collision-free for every practical
purpose. The **variant** is what `OcrService` composes from everything that
shapes the output: the engine variant each driver declares
(`OcrDriver::fingerprint()`: effective provider + model + rasteriser for
`vision-llm`, model + endpoint for `mistral-ocr`, language + DPI + the
`tesseract` / `pdftoppm` / `pdfinfo` executables for `tesseract`, the full
binary path for `docling` — the executables ARE the engine for a local
driver, another build is another transcript), the caps that shape the
output (the page cap `;pages=N`, the raster bounds `;raster=<px>:<bytes>`,
the figure switch and budget `;figures=0` / `;figures=1:<bytes>:<count>:<total>`
— a lowered cap must never reuse a run recorded under a wider one), and —
for a forced re-run or a run with reuse off — a fresh per-attempt salt
(`;attempt=<16 hex>`), so `ocr.force` always lands on a **new** immutable
run and never reuses or rewrites the recorded one (§6); the `ocr.force` /
`ocr.rerun_lock` / `dry_run` keys are inputs of that one job and are stripped
before the row is persisted (`OcrService::stripTrustedOnlyKeys()`), so a later
ingest built from the row's metadata is never forced again. Two versions of one
source path never overwrite each other's pixels; the same bytes through
another engine land in another run; an ordinary re-ingest with identical
input and engine lands on the same, immutable run (the ingest's own
idempotency); a W2 artifact points at the exact run that produced it. A run is referenced by every row that carries it, so the deleter
removes `.ocr/` only when the last row referencing the source key goes. Tenant
separation is the source file's own — the assets inherit the namespace (disk +
prefix + path) of the file they sit beside. That namespace is not
tenant-derived (`KB_PATH_PREFIX` is global, an upload lands at
`sub_path/basename`), so on a shared disk two tenants using one `source_path`
already share the source object — a pre-existing property of the KB layout
this ADR neither creates nor fixes (ADR 0030's `.artifacts/` tree, free of the
"beside the source" constraint, is namespaced by tenant and project). The
shared tree is safe because of content addressing plus a cross-tenant
reference gate: a run is named by the hash of the bytes, so a tenant can only
reuse or read a run for bytes it already possesses; and the deleter's
storage-key gate counts referencing rows across tenants (`withoutGlobalScopes`,
a documented R30 exception for a shared physical object, the same posture as
the IMAP mailbox lock), so no tenant's hard delete removes assets another
tenant's row still references. The Markdown references
`![Figure {page}.{n}](images/fig-{page}-{n}.png)` relative to the run
directory, the same shape the W4 export copies verbatim. Every `put()` return
is checked (R4). The assets share the source's lifecycle: a soft delete keeps
them; `DocumentDeleter`'s hard delete removes the whole `.ocr/` directory
through the same *last referencing row* gate it applies to the source file.
Formulas are inlined as LaTeX by the drivers that recognise them; the
converter does not post-process them.

A run directory's **first write is reserved atomically**: `OcrService` holds a
cache lock on the directory (`kb:ocr:run:{disk}:{sha1(dir)}`, Redis in
production) from the recorded-run check through `result.json`, so a concurrent
ingest of the same bytes through the same engine waits (`KB_OCR_RUN_LOCK_WAIT`,
default 300 s, then the job retries), looks again and reuses the run the first
worker recorded — one bill, one directory, never two nondeterministic remote
results interleaved in it.

The reservation ends with `result.json`, but the row that will reference the
run commits later — chunking, redaction and embedding sit in between — so a
reference gate that counts committed rows only has a window in which a
concurrent hard delete or orphan sweep sees no reference and would remove the
figures a row is about to point at. The run directory itself is therefore the
**durable reservation**: a run whose newest file is younger than
`KB_OCR_PURGE_GRACE_SECONDS` (default 1800, well above the lease plus the
longest ingest tail) is *in flight* and is never purged — neither by
`DocumentDeleter`'s hard delete, which then keeps the tree and reports it,
nor by the orphan sweep. A **reuse** performs no write of its own, so it
refreshes the reservation explicitly: `result.json` is re-recorded with the
same bytes and the reused run is in flight again until the new row commits —
an old run can never be reused and purged in the same window. A run that never gains a row (its ingest failed after
recording, or the last row went while it was young) is a *dangling* tree —
`{source}.ocr/` with the source gone from the disk and from every row of any
tenant, trashed included — and `kb:prune-orphan-files` removes it once it has
aged past the grace. No lock is held across the Flow steps and no reference
count is kept on disk: recency is the reservation, the sweep is the reaper.

A forced re-run (`ocr.force`, set only by `kb:ocr` / the HTTP re-run on the
job they build) is a **new attempt with its own run identity** — the run key
carries a per-attempt salt — so the recorded run is never rewritten in place
and an artifact that names it stays valid. A fresh Flow `runKey` only keeps
the Flow's `tenant:project:path` idempotency from collapsing the job; it does
**not** bypass the ingestor's version idempotency, which is content-addressed
(`version_hash` of the converted Markdown). So the force flag travels to
persistence as well: `PersistChunksStep` passes `replaceExisting = true`
(from `OcrService::isForced()`, or `OcrService::isFreshOcrRun()` — any run
that actually called the driver, `converter.ocr.reused === false`, such as
every ingest with reuse off or a recorded run redone because a figure went
missing; `DocumentIngestor::ingest()` applies the same rule on the direct
path) into `DocumentIngestor::persistDrafts()`,
which then skips the same-hash short-circuit and **replaces in place** the
live version's chunk set and its `metadata.converter.ocr` block (run key,
attempt, confidence) — the row points at the new run, no second version is
created, and the superseded run becomes unreferenced and is purged by the
reference gate once it is past the in-flight grace (§6). Without that
contract a byte-identical forced run would be billed and recorded while the
row kept naming the old run; the regression test drives the real job and
asserts the new run key on the row, a replaced (not duplicated) chunk set and
exactly two metered runs. `ocr.force`, `ocr.rerun_lock` and `dry_run` are host-only controls:
the HTTP ingest entry point and the connector bridge strip them from any
metadata a client hands in (`OcrService::stripTrustedOnlyKeys()`), so nobody
can start a billed engine run through `documents.*.metadata`. Discovery never
reads the converter's output back: `KbPath::isGeneratedAsset()` marks every
path under a `{name}.ocr/` segment (and the ADR 0030 `.artifacts/` root) and
the folder walker and the orphan-file sweep exclude it; the sweep also
purges the `.ocr/` tree beside every orphan source it removes, which is the
one sweep a run written by a failed first ingest ever gets.

The write is a **documented exception** to the `ConverterInterface`
"stateless and side-effect-free" contract, recorded in the interface's own
docblock: content-addressed and idempotent, immutable, bound to the source's
lifecycle — and **never under a dry run**. `ParseMarkdownStep` still calls
`convert()` during `Flow::dryRun()`, so the step marks the `SourceDocument`
(`metadata.dry_run`) and the OCR core then neither calls a driver (paid, and
remote for some), nor writes `.ocr/`, nor meters: it returns a page-shaped
preview (`## Page n` sections, `ocr.dry_run: true`) and shows a run already
recorded for those bytes read-only.

### 7. Confidence is per page, recorded, and not yet ranked on

Every emitted page carries `ocr_confidence` in its chunk metadata (projected by
`PdfPageChunker` from `extractionMeta['ocr']['pages']`), and the document
metadata carries `ocr: {driver, remote, reason, run, pages, mean_confidence,
min_confidence, figures, figures_dir, engine, ran_at}`. `Reranker` does **not**
read it in this cycle: W3 needs the CER/WER baseline before a soft signal is
justified. The W3 review heat-map is the first consumer.

### 8. Extraction origin `ocr` is orthogonal to `provenance_tier`

ADR 0028 records *who authored* a document in `provenance_tier`. OCR does not
change authorship — an inbound scanned letter is still `untrusted-external` —
so the tier stays whatever the connector declared and OCR never writes it.
OCR adds a second, orthogonal fact: *how the text was obtained*. It is
recorded as `metadata.converter.provenance = 'ocr'` on the document and
`provenance: ocr` on every chunk's metadata, and the `ProvenanceToolFirewall`
keeps filtering on the tier: an OCR chunk with an external tier may be quoted
and never drives a tool call, exactly as any other external text. The W4
export carries the two as separate frontmatter keys (`provenance_tier`,
`extraction`).

The W3 extension of this ADR draws the consequence for agents: **an agent that
read an OCR'd document can propose a correction to it, never commit one**
(`KbProposeTextCorrectionTool` writes a candidate; review status changes only
through the role-gated HTTP surface and the CLI — there is no MCP tool that
sets it, and `KbReviewStatusTool` only reads). That is the explicit inverse of
the competitor's `update_document_page`, and the invariant the whole cycle keeps.

### 9. PII is redacted before the first embedding — through the existing seam

`ChunkRedactor` (ADR 0020 D3) runs on the OCR chunk drafts in both ingest paths
with no OCR-specific code. The only addition is a test that proves it: a
fixture page carrying a codice fiscale is ingested with the fake driver and the
persisted `chunk_text` holds the surrogate, asserted **through the chunk
relationship**, not the controller (the R33 lesson). What the seam cannot
cover is named, not hidden: the pixels of a figure (they stay on the KB disk
behind the source's ACL; the W4 export omits them under the PII policy) and
the bytes a remote driver received (§4).

### 10. FinOps: metered under the `ocr` purpose, estimated before commit

Every OCR run is a `padosoft/laravel-ai-finops` ledger row with
`purpose_tag = ocr`: `App\FinOps\OcrCallMeter` records `pages × KB_OCR_RATE_PER_PAGE`
in the FinOps base currency for drivers whose `meteringMode()` is `PerPage`
(`tesseract`, `docling`, `mistral-ocr`); `vision-llm` declares `Sdk` and is
metered per token by the `laravel/ai` lifecycle hook like any chat call — the
page meter deliberately skips it so nothing is double-counted. The upload
modal asks `GET /api/admin/kb/uploads/{batch}/estimate` in its review step and
shows, per staged item, whether OCR would run (`image` · `scanned_pdf` ·
`text_layer_present` · `not_ocr_able` · `ocr_disabled`), the page count and
`pages × rate` — computed by `OcrCostEstimator` from the staged bytes and the
probe, never by running a driver. `pages × rate` is the **PerPage** price
only: for an **Sdk**-metered driver (`vision-llm`) there is no page rate to
multiply, so the estimate says `metering: sdk`, carries no price
(`rate_per_page` and every `cost` are `0`, the additive R27 shape) and the
modal says the provider meters tokens and FinOps records the real spend —
never a `pages × rate` figure that the SDK hook would not honour.

### 11. Default-OFF, both states tested

`KB_OCR_ENABLED=false` in v8.36. With the flag off the deployment is byte for
byte the v8.35 one: image MIMEs are refused with the current 422, a scanned PDF
takes one of the two no-OCR outcomes `PdfConverter` already has — an empty
document when the parser reads the file and finds no text, a
`RuntimeException` (and the failed ingest job it implies) when neither the
parser nor `pdftotext` can read it — and `supportedMimes(false)` is
unchanged. Both branches are pinned by regression tests so the OFF state is
the current behaviour, not a new "empty document" one.
Both states are tested at the converter registry (mutex), the ingest endpoint,
the upload request and modal (Vitest + Playwright), the folder walker, the
connector bridge, the estimate, the CLI and the MCP tool. The flag flips to
default-ON only after W3 publishes a CER/WER baseline for the shipped drivers.

## Consequences

- A scanned three-page PDF and a PNG ingest end to end with the flag on — from
  the modal, the API, the folder walker and a connector — and are refused
  exactly as today with the flag off (plan §4.1).
- The document intelligence cycle gets its first stored *fact about
  conversion*: driver, remote-or-not, confidence, figures, run. W2 stores the
  *artifact* itself; W3 reviews it; W4 exports it. None of the three needs to
  know which driver ran.
- `provenance` on chunk metadata is a second vocabulary next to
  `provenance_tier`; the two are deliberately not merged (one is authorship,
  the other is extraction method), mirroring the ADR 0028 decision not to
  collapse provenance into the curation tier.
- A re-run through `kb:ocr` is an ordinary `IngestDocumentJob` with
  `metadata.ocr.force = true` and a fresh `runKey`, so the Flow's
  `tenant:project:path` idempotency does not collapse it into the existing run
  — and, because the Flow key is not the version key, the same flag reaches
  `DocumentIngestor::persistDrafts(replaceExisting: true)` so a byte-identical
  result replaces the live version's chunks and OCR block in place instead of
  leaving the new run unreferenced (§6).
- The `OcrDriver` contract is host-side. An upstream ask — an `OcrDriver`
  adapter package or a `laravel/ai` vision helper — is recorded in
  `docs/handoff/` for a padosoft-scoped session; nothing here waits on it.
- Live drivers (`mistral-ocr`, `vision-llm`, `docling`, `tesseract`) are not
  exercised in CI; the suite and the E2E harness run on `fake`.

## Surfaces (R44)

| Capability | PHP / CLI | HTTP | MCP |
|---|---|---|---|
| Re-run OCR on a document | `kb:ocr {document} {--tenant=}` · `OcrService::rerun()` | `POST /api/admin/kb/documents/{id}/ocr` (202; 409 while a re-run is already queued — the lock is carried by the job and released when it finishes, on any outcome; needs an atomic shared lock store across pods) | — (spends and rewrites grounding: human-only by design, documented R44 exception) |
| OCR status, driver, remote, confidence per page | `kb:ocr {document} --status {--tenant=}` · `OcrService::status()` | `GET /api/admin/kb/documents/{id}/ocr` | `KbOcrStatusTool` (read) |
| Cost estimate before commit | `OcrCostEstimator::forBatch()` | `GET /api/admin/kb/uploads/{batch}/estimate` | — (documented R44 exception: the estimate is computed over an **upload staging batch**, an object that exists only between the modal's *stage* and *commit* steps for the human uploading; no agent surface stages files, so there is no batch an agent could ask about. The after-the-fact facts an agent needs — page count, driver, remote, cost of the recorded run — are read through `KbOcrStatusTool`) |

All three adapt one core, `App\Services\Kb\Ocr\OcrService`, tenant-scoped
through `KnowledgeDocument::forTenant()` (R30); the CLI validates `--tenant`
non-empty before any lookup (the `kb:reembed-project` contract). The HTTP
routes have their `AdminAuthorizationMatrixTest` row (R32).
