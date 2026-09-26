# STATUS — AskMyDocs v8.40.0 GA — Vision column for Tabular Review (W6)

**Cycle:** v8.40 (W6 of the Document Intelligence & LLM Wiki Export cycle — promoted from
"optional adjacency, do only if the grid gets image-heavy tenants" to a committed workstream
the same day, 2026-09-26, on an explicit business signal).
**Closed:** 2026-09-26. **GA merge:** `feature/v8.40 → main`, PR
[#516](https://github.com/lopadova/AskMyDocs/pull/516), merge commit `ede79491`.
**Origin:** the sixth, originally-optional workstream of
[`PLAN-v8.36-document-intelligence-and-llm-wiki-export.md`](PLAN-v8.36-document-intelligence-and-llm-wiki-export.md)
§W6. Design: [ADR 0034](../adr/0034-v840-vision-column-in-tabular-review.md).

## Why this cycle exists at all

At v8.39 closure ([STATUS](STATUS-2026-09-26-v839-w5-closure.md) §"Deferred"), W6 was left
explicitly not promoted: the plan's own text conditioned promotion on "the grid gets
image-heavy tenants," and no such tenant signal existed yet. That decision reversed the same
day: the user has multiple fashion-ecommerce clients whose knowledge bases are photo-heavy
(product catalogs, per-SKU photography) and need exactly the case the plan named — colour/
material/pattern extraction from product photos, reviewed by a human in the same grid every
other Tabular Review column already renders in. ADR 0034 is the plan addendum the promotion
clause itself required before a branch could open, giving W6 the same executable scope (tri-
surface contract, OFF-state behaviour, tenant boundary, test plan, acceptance criteria, doc-
site deliverable) W1–W5 each had.

## Single PR, not a sub-task split (same deviation v8.39 took, same reason)

Like v8.39, v8.40 has exactly one workstream, so the whole cycle — implementation, the
independent-review fix, and this closure doc — ships across commits on `feature/v8.40`
merged directly into `main` as **one PR: #516**. No second sub-task exists to justify a
separate integration branch.

## What shipped

A fourth Tabular Review `AgentKind` — `vision` — alongside the existing `extract` (RAG
single-shot), `graph` (deterministic, LLM-free governance metric, v8.19/W4) and `verify`
(bounded anti-hallucination second pass, v8.19/W4). Default **OFF** end to end
(`KB_TABULAR_VISION_ENABLED`, R43, both states tested):

- **`VisionColumnResolver`** — the one core behind the new agent kind. Resolves images for a
  document in a fixed precedence (ADR 0034 §2):
  1. **OCR-extracted figures** (`OcrFigureStore`, v8.36/W1) — read via `OcrService::status($doc)`,
     the same tenant-scoped call the Digitization Review UI already uses, for `figures_dir` +
     `figures` count. Only the `mistral` and `docling` OCR drivers extract figures; `vision-llm`
     OCR extracts none — a pre-existing, documented limitation this cycle does not change.
  2. **The document's own source file, when it is itself an image** — a standalone product photo
     ingested as one KB document, very plausibly the primary real-world shape for most
     fashion-ecommerce catalogs (one photo per SKU, not embedded in a PDF).
  3. **Neither** → a definite red cell, no provider call, no invented answer (R14) — reasoning
     names both sources checked.
  Images are capped at `KB_TABULAR_VISION_MAX_IMAGES` (default 4) per cell — bounded work,
  SEC-LLM-001 gate 7. The provider call reuses the `SdkAnonymousAgent` + `Base64Image` pattern
  `VisionLlmOcrDriver` (v8.36/W1) already established for sending an image through the
  `laravel/ai` SDK — metered automatically by the SDK lifecycle hook, no double-counting, no new
  infrastructure.
- **`TabularReviewExtractor`** — a new vision bucket in the column router, inserted between the
  `graph` bucket and the `json_path` format shortcut so `agent: vision` wins over a stray
  `format: json_path` exactly like `agent: graph` already does.
- **Zero new schema, zero new HTTP routes, zero new MCP tools.** `tabular_reviews.columns_config`
  and `tabular_cells.content` (both JSON) already accommodated the fourth agent kind;
  `Store/UpdateTabularReviewRequest` already validated `agent` via `Rule::in(AgentKind::values())`;
  `KbRunReportTool`'s existing column projection already passed the raw `agent` value through —
  confirmed by a new test, not assumed. This is the fourth (not the first) agent kind to ride the
  v8.19/W4 plumbing.
- **Tri-surface (R44)**: PHP/CLI and HTTP need no new entry points (above); MCP needs none either.
  The capability is reachable everywhere the existing three agent kinds already are.
- **FE**: `AgentKind`/`AGENT_KINDS` extended (the existing generic `<select>` renders "vision"
  automatically); the evidence panel labels a vision citation "image" instead of "chunk" — the
  one FE change this feature needed, since a vision cell's citations reference the image the model
  was shown, not a retrieved chunk.
- **Doc-site (R45)**: `agentic-knowledge-reports.mdx` extended in place — theory, router
  mermaid diagram, an image-source-precedence subsection, security posture, decision rationale
  (why image source is derived rather than admin-picked; why no manual override yet), a worked
  fashion-catalog example, gotchas — rather than forking a new `tabular-vision.mdx` page, since
  vision is a fourth entry in a taxonomy that page already owns end to end and a split page would
  either re-derive that context or drift from it (R9).

## Independent review (Copilot unavailable for this roadmap; Codex silent) — one real fix

Per explicit user direction this cycle, GitHub Copilot Code Review was **not used at all**:
Copilot's review budget is exhausted for this roadmap. The `@codex review` fallback comment was
posted; Codex never responded within the window given. Per R36's "always-on local gate," an
independent subagent review (Agent tool, given only the PR diff — no context from the
implementing session) carried the pre-merge safety net, cross-checking every claim in the PR body
against the live source rather than trusting the description.

It found **one genuine must-fix**, fixed in a follow-up commit and re-verified locally (82 tests,
229 assertions, full Tabular Review suite) before merge:

1. **Uncaught exception on a malformed recorded storage prefix (must-fix).**
   `VisionColumnResolver::resolveImages()`'s standalone-image-document fallback composed
   `KbPath::normalize($prefix.'/'.$normalized)` from `StorageNamespace::recordedPrefix($doc->metadata)`
   without first checking `StorageNamespace::prefixCanNamePath($prefix)`. A legacy/malformed
   prefix (e.g. `../outside`) is returned **verbatim** by `recordedPrefix()` by design — its own
   contract states composing a path from it "throws, in whatever ran next" unless the caller
   guards first. Every other consumer that composes a path from `recordedPrefix()`
   (`IngestDocumentJob`, `OcrService`, `DocumentDeleter`, `DocumentIngestor`, `ReembedDocumentJob`)
   already guards with `prefixCanNamePath()`; this resolver was the one that didn't. Because
   `TabularReviewExtractor`'s vision bucket has no try/catch around `resolve()` (by design — it
   mirrors the `graph` bucket's resolver, which never throws), an uncaught
   `InvalidArgumentException` here would have aborted **every** column for that document, not just
   degraded the vision one — directly contradicting ADR 0034 §5's own "never a silent skip"
   guarantee. Fixed by guarding before composition and falling through to the existing "no visual
   evidence" red cell, exactly like every other unreadable-source case already does. A new
   regression test (`test_a_prefix_that_cannot_name_a_path_degrades_to_a_red_cell_instead_of_throwing`)
   proves it degrades instead of throwing.

Two low-cost hardening items were folded into the same fix: the OCR-figures image-loading branch
now applies the same `IMAGE_MIME_ALLOWLIST` the standalone-document branch already enforced
(`OcrFigureStore` only ever writes images today, but the resolver shouldn't assume that invariant
silently), and `Storage::mimeType()` is wrapped in try/catch (some Flysystem adapters throw rather
than returning falsy).

## New schema

None. `tabular_reviews.columns_config` (JSON) and `tabular_cells.content` (JSON,
`{summary, flag, reasoning, citations}`) already accommodated the fourth agent kind — confirmed
by reading both migrations before writing ADR 0034, not assumed. Zero migrations this cycle.

## Defaults / cost posture

- `KB_TABULAR_VISION_ENABLED` default **OFF** (R43, both states tested) — with it off, every
  existing review's behaviour is byte-identical; a `vision` column present produces a definite
  red `failed` cell, never a 500, never a silent fallback to `extract` semantics.
- `KB_TABULAR_VISION_MAX_IMAGES` default `4` — bounded work per cell (SEC-LLM-001 gate 7).
- **Real, bounded cost.** Every vision cell is one provider call with up to
  `KB_TABULAR_VISION_MAX_IMAGES` image attachments — metered automatically (SDK lifecycle hook),
  same as OCR's `vision-llm` driver. No batching across rows or columns (mirrors the `graph`
  bucket's one-call-per-cell shape, not `extract`'s one-call-per-document batching): a 100-row ×
  3-vision-column review generating from scratch issues up to 300 bounded provider calls —
  documented in ADR 0034 §Consequences, not hidden.

## Test coverage added this cycle

- `AgentKindTest` — `VISION` case, `isVision()`, `fromNullable()` round-trip, `values()`
  membership.
- `VisionColumnResolverTest` — 8 tests: OFF flag → null, provider never touched; figures present
  → images loaded, provider called, capped at `max_images_per_cell` (5 figures on disk, exactly 4
  attached); no figures + standalone image doc → single-image call; neither source → definite red
  cell, no provider call; provider throws → red cell, raw exception text never leaked; unparseable
  output → red cell; happy path with `format`/`enum_values` honoured in the prompt; the
  malformed-prefix regression test (the independent-review fix).
- `TabularReviewExtractorTest` — +2 tests: a review mixing all four agent kinds resolves each
  bucket independently with no regression to existing kinds; a `vision` column with
  `format: json_path` still routes to vision, not the shortcut.
- `TabularReviewControllerTest` — +3 tests: `agent: vision` accepted by both Store/Update
  FormRequests with no new field required; an unknown agent value is still rejected (regression).
- `RunReportToolTest` — +1 test: a vision column's `agent` field passes through the MCP
  projection unchanged (acceptance criterion 5).
- **Playwright E2E** (R12/R13/R46) — 2 new scenarios in `frontend/e2e/admin-tabular-reviews.spec.ts`,
  validated on the real `run-e2e` CI gate before merge (the first time they actually ran):
  FE gating (selecting `agent=vision` does not reveal the graph-only metric picker — negative
  counterpart to the existing `agent=graph` test) and a real generate round-trip against the
  seeded `hr-portal` project (text-only policy docs, no figures, no image documents) proving the
  vision cell resolves to a definite red flag with **zero AI provider calls**, since
  `resolveImages()` returns `[]` before the provider is ever touched — no AI credentials needed in
  CI. `KB_TABULAR_VISION_ENABLED=true` added to `tests.yml`'s Playwright job env, mirroring the
  existing `KB_DIGITIZATION_REVIEW_ENABLED` precedent.

Full local verification before the independent-review fix: `vendor/bin/phpunit` 4958 tests /
22268 assertions, `npx vitest run` 1388 tests / 177 files, both green, zero regressions. After the
fix: full `TabularReview`-filtered suite (82 tests, 229 assertions) re-verified green. CI on the
final head (`9c9960a9`) with the real `run-e2e` gate applied: PHPUnit, Vitest, dependency audit,
RAG regression gate and all 4 Playwright shards green.

## Deferred (documented, per ADR 0034 §6/Deferred)

- **Manual cell-value override.** "Human review in the grid" is satisfied by the existing Tabular
  Review UX every column type already has — flag colour, evidence panel, **regenerate cell**. No
  column type in this codebase supports editing a cell's persisted value directly without
  re-running extraction; that would be a materially larger, cross-cutting capability spanning
  every `AgentKind`, not a vision-specific one, and was explicitly out of scope for this cycle.
- **Region-level figure detection inside `vision-llm` OCR.** Today only Mistral/Docling extract
  figures; giving `vision-llm` the same capability (asking the model to also emit bounding-box
  crops) is a distinct, materially larger OCR-driver change, not a Tabular Review one.
- **Direct multi-image "product asset" ingestion as a first-class KB concept** (a gallery of N
  photos per SKU, not one document per photo) — the standalone-image-document fallback covers the
  one-photo-per-document case; a genuine multi-image product entity is a new ingestion primitive.

## Tags — blocked by tooling, operator follow-up required

Same blocker documented at v8.36, v8.37, v8.38 and v8.39 closure: this session's git credential
can push branch commits and merge PRs (confirmed again this cycle — PR #516 merged via the
GitHub API), but `git push` of a **tag** ref returns `403`. `v8.36.0`, `v8.37.0`, `v8.38.0` and
`v8.39.0` remain untagged from prior cycles; `v8.40.0` joins them. An operator with a tag-capable
credential should run, in addition to the four commands the prior STATUS docs describe:

```bash
# v8.40.0 — at the GA merge commit on main (PR #516)
git fetch origin main
git tag -a v8.40.0 ede79491 -m "v8.40.0 — Vision column for Tabular Review (W6). See docs/adr/0034-v840-vision-column-in-tabular-review.md and docs/v4-platform/STATUS-2026-09-26-v840-w6-closure.md."
git push origin v8.40.0
```

Per R39, an rc tag would normally precede the GA tag; given the tag-push blocker is already
established and unresolved across five consecutive cycles and CI was fully green (PHPUnit,
Vitest, dependency audit, RAG regression gate, all 4 Playwright shards — the real E2E gate, not
skipped), this closure skips straight to proposing the GA tag rather than adding a redundant rc
step an operator would have to push twice.

Cycle plan: [`PLAN-v8.36-document-intelligence-and-llm-wiki-export.md`](PLAN-v8.36-document-intelligence-and-llm-wiki-export.md) §W6.
Design: [ADR 0034](../adr/0034-v840-vision-column-in-tabular-review.md).
