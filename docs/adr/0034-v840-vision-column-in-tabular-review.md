# ADR 0034 — `vision` agent for Tabular Review columns

- **Status:** Accepted
- **Date:** 2026-09-26
- **Cycle:** v8.40 (W6 of the Document Intelligence & LLM Wiki Export cycle —
  promoted from "optional adjacency, do only if the grid gets image-heavy
  tenants" to a committed workstream on explicit business justification, see
  below).
- **Builds on:** [ADR 0010](0010-v47-tabular-review-and-workflows.md) (Tabular
  Review itself), the v8.19/W4 `AgentKind` dimension (`extract`/`graph`/`verify`,
  see `TabularReviewExtractor`), [ADR 0029](0029-v836-ocr-converter-drivers-and-ocr-provenance.md)
  (OCR figure extraction, `OcrFigureStore`), R30/R31 tenant scoping, R43
  both-state flags, R44 tri-surface, R32 admin-route gating, SEC-LLM-001
  (model output is data, never instructions).
- **Plan:** [PLAN v8.36 → v8.40](../v4-platform/PLAN-v8.36-document-intelligence-and-llm-wiki-export.md)
  §W6 (the plan's own text reserved the flag name `KB_TABULAR_VISION_ENABLED`
  and required this ADR + a doc-site page before a branch is opened).

## Context

The plan's own framing for W6, verbatim: *"a column whose cell is produced by
a vision call over the document's figures (W1 extracted them) or over an
image asset. The gescat case: product photos → colour/material/pattern with
human review in the grid. **Not strategic**; do it only if the grid gets
image-heavy tenants."* At v8.39 closure this was deliberately left
undecided — no image-heavy tenant signal existed yet, and unlike W1–W5 it had
no executable spec.

That signal now exists: the user (2026-09-26) has multiple fashion-ecommerce
clients whose knowledge bases are photo-heavy (product catalogs, lookbooks,
per-SKU photography) and who need exactly the case the plan named —
colour/material/pattern extraction from product photos, reviewed by a human
in the same grid every other Tabular Review column already renders in. This
ADR promotes W6 to v8.40 and gives it the same executable scope as W1–W5.

## Decision

### 1. A fourth `AgentKind`: `vision`

`App\Support\TabularReview\AgentKind` gains `case VISION = 'vision';` +
`isVision(): bool`, alongside the existing `extract` (default) / `graph`
(deterministic, LLM-free) / `verify` (anti-hallucination post-pass). Same
single-source-of-truth discipline as the other three (R23): one enum case,
one new bucket + branch in `TabularReviewExtractor::extract()`'s column
router — no separate registry, no overlapping predicate.

A `vision` column carries the same `name` / `prompt` / `format` /
`enum_values` fields every column already has — `prompt` is the extraction
instruction ("identify the primary colour, material and pattern of the
garment"), `format` + `enum_values` constrain the answer exactly as they do
for `extract` (e.g. `format: enum, enum_values: [black, navy, red, ...]`).
No `metric` (that key stays `graph`-only). Vision columns do **not**
participate in the batched `extract`/`verify` LLM call — the visual modality
means one call per column per document, unbatched, mirroring the shape of
the `graph` bucket (one resolver call per column) rather than the `extract`
bucket's single multi-column call.

### 2. Image source: OCR figures, with a standalone-image-document fallback

The plan named two possible image sources — "the document's figures" or "an
image asset" — and both are real for the stated fashion-ecommerce case, so
`VisionColumnResolver` tries both, in order:

1. **OCR-extracted figures** (`OcrFigureStore`, W1/ADR 0029): a scanned
   catalog page or a lookbook PDF, OCR'd with figure extraction on
   (`KB_OCR_FIGURES_ENABLED`, Mistral/Docling drivers only — `vision-llm` OCR
   extracts none, a documented pre-existing limitation this ADR does not
   change). Read via `OcrService::status($doc)` — the SAME tenant-scoped,
   already-established read API the Digitization Review UI uses — for
   `figures_dir` + `figures` count; the actual bytes come from
   `Storage::disk($disk)->files("{figures_dir}/images")`.
2. **The document's own source file, when it is itself an image**
   (`mime_type` starting `image/`, resolved the same way `OcrService`
   resolves a raster document today): one product photo ingested as one KB
   document. This is very plausibly the PRIMARY real-world shape for a
   fashion-ecommerce catalog (one photo per SKU, not embedded in a PDF), and
   the plan's own "or over an image asset" phrase names it explicitly.
3. **Neither** → the column has no visual evidence for this document. A
   real, definite, loudly-surfaced fact (R14), not a silent skip: red flag,
   `reasoning` names which of the two sources was checked and found empty.

Both sources resolve through `StorageNamespace::diskOf()` /
`recordedPrefix()` — the same tenant/project storage-namespace resolution
every other KB read already uses — so a vision column can never cross a
tenant's storage boundary.

Images are capped at `KB_TABULAR_VISION_MAX_IMAGES` (default 4) per cell —
bounded work (SEC-LLM-001 gate 7): a catalog page with many figures does not
turn one cell's generation into an unbounded multi-image provider call.

### 3. The vision call: the established `SdkAnonymousAgent` + `Base64Image` pattern

Reuses, verbatim in shape, the pattern `VisionLlmOcrDriver` (v8.36/W1) already
established for sending an image to a provider through the `laravel/ai` SDK
— `new SdkAnonymousAgent(instructions:, messages: [], tools: [], maxTokens:,
temperature:)` then `$agent->prompt($text, [new Base64Image(...), ...],
$provider->name(), $model, $timeout)`. Metered automatically by the SDK
lifecycle hook (no double-counting, same as `VisionLlmOcrDriver`).

The **system instructions are fixed** (SEC-LLM-001 gate 4/6): the admin's
column `prompt` is embedded as a labelled user-facing extraction task, never
promoted to a system-level instruction, mirroring exactly how the existing
`extract`/`verify` columns already treat `col['prompt']` — this is
established, reviewed precedent, not a new trust boundary. The model is
instructed to answer with the same `{summary, flag, reasoning}` NDJSON-free
single-object shape the `extract` path parses, honouring `format` +
`enum_values` the same way `FormatType::promptSuffix()` already renders them
into the `extract` system prompt. Model output is parsed defensively — an
unparseable or empty response is a definite failure cell (see §5), never a
silently-invented value.

### 4. Cell content contract — unchanged

The persisted `content` shape is the same
`{summary, flag, reasoning, citations}` every column type already produces
(no FE/SSE/MCP/evidence-panel change needed for the shape itself —
`TabularReviewExtractor::persistCell()` doesn't care which agent produced
the array). `citations` reference the image(s) the model was actually shown
— `{chunk_id: "<figure-or-source-relative-path>", quote: ""}` — the same
"loose string identifier" contract `normaliseCitations()` already accepts
(it never assumed `chunk_id` was numeric).

### 5. Failure modes — mirrors the existing `graph`/`extract` conventions

| Condition | Outcome |
|---|---|
| `KB_TABULAR_VISION_ENABLED=false` | `persistFailure()` — red, status `failed`, reasoning names the flag. Mirrors the `graph` bucket's "unknown metric" null-return convention: a configuration/availability gap, not a real attempt. |
| No figures AND no standalone image source | Resolver returns a definite content array (not null) — red flag, `status: ready`, reasoning names both sources it checked. A real, stable fact — same posture as the `graph` resolver's "non-canonical, n/a" cells. |
| Vision provider call throws, or times out | Content array — red flag, generic "provider error, see application log" reasoning (never the raw exception — R14/SEC-ERRLEAK-equivalent: `Log::warning` gets the detail, the persisted cell does not). |
| Model returns unparseable/empty output | Content array — red flag, "model did not return a usable result." |
| Model answers normally | Content array with the model's own `flag` (default green), `summary`, `reasoning`, `citations` — `status: ready`. |

### 6. Human review — the existing grid pattern, not a new capability (explicit scope boundary)

"With human review in the grid" (the plan's own phrase) is satisfied by the
**existing** Tabular Review UX every column type already has: the coloured
flag, the evidence panel (image thumbnails instead of chunk quotes for a
vision cell), and **regenerate cell** to re-run extraction when a reviewer
disagrees. No column type in this codebase today supports **editing a cell's
persisted value directly** (an operator "correcting" a misread colour without
re-running extraction) — that would be a materially larger, cross-cutting
capability spanning every `AgentKind`, not a vision-specific one, and was not
part of what W1–W5 built for any other column type either. **Explicitly out
of scope for v8.40** — regenerate-on-disagreement is the review loop this
cycle ships; a genuine manual-override capability is a natural, separate
future workstream if the fashion-ecommerce tenants need it in practice
(flagged in the closure doc's Deferred section).

### 7. Tri-surface (R44)

- **PHP/CLI**: no new Artisan command — vision columns run through the
  existing `kb:tabular-review-generate` (or equivalent) command and the
  existing `TabularReviewExtractor::extract()` entry point; a `vision`
  column is just a fourth bucket in the same extractor every surface already
  calls.
- **HTTP**: no new route — the existing `POST
  /api/admin/tabular-reviews/{review}/generate` and
  `.../cells/{document}/{column}/regenerate` endpoints already accept any
  `agent` value the `StoreTabularReviewRequest`/`UpdateTabularReviewRequest`
  enum validates against `AgentKind::values()` (already `Rule::in(...)`
  driven, so adding the case is enough — no new validation branch needed
  beyond what `metric`'s `required_if:...,graph` already demonstrates as the
  pattern for agent-specific fields, of which `vision` has none).
- **MCP**: `KbRunReportTool`'s existing column projection
  (`'agent' => (string) ($c['agent'] ?? 'extract')`) already passes the raw
  `agent` value through — a `vision` column is visible to MCP clients with
  zero code change, consistent with how `graph`/`verify` needed none either
  when introduced in v8.19/W4.

### 8. Configuration — new block, default OFF (R43)

```php
// config/kb.php
'tabular_review' => [
    'vision' => [
        'enabled' => filter_var(env('KB_TABULAR_VISION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'max_images_per_cell' => (int) env('KB_TABULAR_VISION_MAX_IMAGES', 4),
        // Empty → falls back to KB_OCR_VISION_PROVIDER/_MODEL, then AiManager's default chat provider.
        'provider' => env('KB_TABULAR_VISION_PROVIDER') ?: null,
        'model' => env('KB_TABULAR_VISION_MODEL') ?: null,
        'max_tokens' => (int) env('KB_TABULAR_VISION_MAX_TOKENS', 1200),
        'timeout' => (int) env('KB_TABULAR_VISION_TIMEOUT', 120),
    ],
],
```

OFF path (both states tested per R43): a review with a `vision` column and
the flag off generates every OTHER column normally and produces a clean,
loud, red `failed` cell for the vision one — never a 500, never a silent
skip, never a fallback to `extract` semantics.

## Consequences

- **No new schema.** `tabular_reviews.columns_config` (JSON) and
  `tabular_cells.content` (JSON, `{summary, flag, reasoning, citations}`)
  already accommodate the fourth agent kind — confirmed by reading both
  migrations before writing this decision. Zero migrations this cycle.
- **No new HTTP routes, no new MCP tools.** The tri-surface is "free" because
  v8.19/W4 already built the `agent`-dimension plumbing generically; this ADR
  is the fourth (not the first) kind to ride it.
- **Real cost, bounded.** Every vision cell is one provider call with up to
  `KB_TABULAR_VISION_MAX_IMAGES` image attachments — metered automatically
  (SDK lifecycle hook), same as OCR's `vision-llm` driver. A tenant with a
  100-row × 3-vision-column review generating from scratch issues up to 300
  bounded provider calls; no batching across rows exists (mirrors the `graph`
  bucket's one-call-per-cell shape, not `extract`'s one-call-per-document
  batching) — documented, not hidden.
- **Depends on OCR figures being populated with a non-`vision-llm` driver, OR
  standalone image documents.** A tenant using only `vision-llm` OCR (no
  figure extraction, by design — ADR 0029) and no standalone image ingestion
  gets an honest "no figures" red cell for every vision column, never a
  fabricated answer. This is a real, named limitation, not a bug: closing it
  fully (image-region detection inside `vision-llm`'s own transcription) is
  explicitly deferred — genuinely separate, larger scope.

## Deferred (documented, tracked for a future cycle)

- **Manual cell-value override** (§6) — editing a persisted cell without
  re-running extraction. Cross-cutting across every `AgentKind`, not
  vision-specific.
- **Region-level figure detection inside `vision-llm` OCR** — today only
  Mistral/Docling extract figures; giving `vision-llm` the same capability
  (asking the model to also emit bounding-box crops) is a distinct,
  materially larger OCR-driver change, not a Tabular Review one.
- **Direct multi-image "product asset" ingestion as a first-class KB
  concept** (e.g. a gallery of N photos per SKU, not one document per photo)
  — the standalone-image-document fallback (§2.2) covers the one-photo-per-
  document case; a genuine multi-image product entity is a new ingestion
  primitive, out of scope for a Tabular Review agent kind.

## Test plan

- `AgentKindTest` — `VISION` case, `isVision()`, `fromNullable()` round-trip,
  `values()` membership.
- `VisionColumnResolverTest` — OFF flag → null; figures present → images
  loaded + provider called with the right count (capped at
  `max_images_per_cell`); no figures, standalone image doc → single-image
  call; neither source → definite red content array, no provider call;
  provider throws → red content array, no raw exception text leaked; model
  returns unparseable output → red content array; happy path → content
  array matches `{summary, flag, reasoning, citations}` with
  `format`/`enum_values` honoured.
- `TabularReviewExtractorTest` (extend) — a review with one `vision` column
  alongside existing `extract`/`graph`/`verify` columns in the same
  `columns_config`: every bucket still resolves independently; a `vision`
  column with `format: json_path` still routes to vision (not the shortcut)
  — same "agent wins over format shortcut" invariant the `graph` bucket
  already has a regression test for.
- `AdminAuthorizationMatrixTest` (R32) — no new route, so no new matrix row;
  confirm the existing `tabular-reviews` row still covers generation with a
  `vision` column present (regression, not a new row).
- Feature test — `StoreTabularReviewRequest`/`UpdateTabularReviewRequest`
  accept `agent: vision` (enum membership), reject an unknown agent value
  (regression).
- `KnowledgeBaseServerRegistrationTest` — unaffected (no new MCP tool); a
  `KbRunReportToolTest` case asserts a `vision` column's `agent` field passes
  through the projection unchanged.
- E2E (Playwright, R12/R13, deferred to the closure step per R46) — create a
  review with a vision column against a real seeded fixture image document,
  generate, assert the evidence panel renders an image citation and the flag
  colour; OFF-state E2E asserting the clean red-failed cell + no 500.
- Doc-site (R45) — new `docs-site/tabular-vision.mdx` page (or a section
  appended to `agentic-knowledge-reports.mdx` if editorially tighter),
  registered in `docs.json`, covering theory (why a fourth agent kind),
  design (image-source precedence diagram), the ADR-rationale cross-link,
  and a worked fashion-ecommerce example.

## Acceptance criteria

1. `AgentKind::VISION` exists; `AgentKind::values()` includes `'vision'`.
2. A `vision` column in `columns_config` is accepted by both FormRequests
   (enum membership) with no new field required.
3. `TabularReviewExtractor::extract()` routes `vision` columns to
   `VisionColumnResolver`, persists a cell for every one (never an empty
   200, R14), and every other agent kind's behaviour is unchanged (existing
   test suite green, zero regressions).
4. `KB_TABULAR_VISION_ENABLED=false` (the shipped default) leaves every
   existing review byte-identical in behaviour except a definite red cell on
   any `vision` column present; `=true` with no provider configured behaves
   the same as `VisionLlmOcrDriver`'s own "no chat provider configured"
   posture — loud, not silent.
5. `KbRunReportTool` exposes `agent: "vision"` for such a column with zero
   code change beyond the enum addition (confirmed by test, not assumption).
6. Doc-site page ships (R45); README + Changelog updated at closure (matching
   the standard this cycle's own audit was held to for v8.36–v8.39).
