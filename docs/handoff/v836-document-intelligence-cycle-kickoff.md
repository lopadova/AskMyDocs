# Hand-off — v8.36 → v8.40 Document Intelligence cycle: session kickoff

**Status:** designed 2026-09-11, **not started**. Audit + plan + README roadmap row
are on this branch; nothing under `app/` has changed yet.
**Owner of the work:** the Claude Code session opened with `lopadova/AskMyDocs` as
its *initial* source (§5 explains why it has to be a new session), driven by Lorenzo.
**Goal:** execute `docs/v4-platform/PLAN-v8.36-document-intelligence-and-llm-wiki-export.md`
end to end — W1..W5, W6 optional — under this repo's R-rules, every PR merged once CI
is green and the Copilot loop is at zero must-fix, RC-tagged per Wn and GA-tagged per
cycle, until every workstream is shipped.

---

## 1. Why this file exists

The design was produced in a session whose GitHub scope was `padosoft/*`. That session
could not write to `lopadova/AskMyDocs`: the direct push answered 403 and `add_repo`
refused the cross-owner add (*"cross-tier adds are not supported in v1 … session
already has repos from owner(s) [padosoft]. Start a new session with the requested
repo as the initial source"*). The commits were therefore delivered as a `git am`
patch series and applied by hand.

Chat memory does not cross sessions. This file does. Everything the new session
needs is **in the repository**, in this order of reading:

1. this hand-off (all sections, then the checkpoint in §7);
2. the plan — `docs/v4-platform/PLAN-v8.36-document-intelligence-and-llm-wiki-export.md`;
3. the audit it rests on — `docs/v4-platform/AUDIT-2026-09-11-annota-ai-gap.md`;
4. `CLAUDE.md` (every R-rule; the ones this cycle leans on are listed in §4) and
   `.claude/skills/` (`branching-strategy-feature-vx`, `rc-tag-per-week-milestone`,
   `copilot-pr-review-loop`, `pluggable-pipeline-registry`, `cross-tenant-isolation`,
   `mintlify-doc-authoring`, `docs-match-code`, `secure-ai-surface`);
5. the README roadmap row **v8.36 → v8.40** (the public promise the cycle must keep).

---

## 2. What is already done

| Item | Where | State |
|---|---|---|
| Competitor audit (Annota AI vs AskMyDocs v8.35.0, every cell grounded with `git grep` on `origin/main` = `51dd1b3`) | `docs/v4-platform/AUDIT-2026-09-11-annota-ai-gap.md` | committed |
| Cycle plan, five workstreams + one optional, ADRs 0029–0033 reserved | `docs/v4-platform/PLAN-v8.36-document-intelligence-and-llm-wiki-export.md` | committed |
| README roadmap row `v8.36 → v8.40` inserted before `Future`; `Future` trimmed of the two items the cycle absorbs | `README.md` §Roadmap | committed |
| This hand-off | `docs/handoff/v836-document-intelligence-cycle-kickoff.md` | committed |

**One correction was made before hand-off and must not be undone.** The first draft
of the audit marked *document versions* as absent. They are not: **Cloud Time
Machine** shipped in v8.7/W5 (`app/Services/Kb/Versioning/DocumentVersionService.php`,
`App\Support\MarkdownDiff`, `KbDocumentVersionController`, Admin → Time Machine,
`kb:prune-archived-versions`) — every re-ingest keeps the prior `knowledge_documents`
row as `archived` with its chunks, and that family is the version model. The real gap
is narrower: the body is `reconstructContent()` from chunks (no frontmatter, no images,
chunker-transformed), a version is born only on re-ingest, and a version carries no
`actor`/`reason`. **W2 was re-sized to exactly that** (“Conversion artifacts on the
existing Time Machine”, no new versions table). If a grep during implementation
contradicts the audit again, fix the audit in the same PR — the audit is a record,
not scripture.

Session artefacts that are **not** in the repo and are not needed: a readable HTML
rendering of audit + plan, and the two-patch `git am` series used to move the commits.

---

## 3. Decisions already taken — do not re-open

1. **Scope and order.** W1 + W2 together (v8.36) → W3 (v8.37) → W4 (v8.38) → W5
   (v8.39) → W6 optional (v8.40). W3 needs W1 and W2; W4 needs W2; W6 needs W1.
   Every executable workstream (W1–W5) ships behind a **default-OFF** flag tested in
   both states (R43): W1 `KB_OCR_ENABLED`, W2 `KB_CONVERSION_ARTIFACTS_ENABLED`, W3
   `KB_DIGITIZATION_REVIEW_ENABLED`, W4 `KB_WIKI_EXPORT_ENABLED`, W5
   `KB_WIKI_ROUTINE_ENABLED`. W6 is deferred (plan §W6): its flag
   `KB_TABULAR_VISION_ENABLED` exists only if a plan addendum promotes it.
2. **W1 `OcrConverter`** implements the existing `ConverterInterface`, registered in
   `config/kb-pipeline.php` (the `pluggable-pipeline-registry` skill); drivers
   `docling` / `mistral-ocr` / `vision-llm` / `tesseract` behind `KB_OCR_DRIVER`;
   `KB_OCR_ENABLED=false` by default; `SourceType` **always** defines the `image`
   case and its four MIME mappings (`image/png`, `image/jpeg`, `image/tiff`,
   `image/webp`) — the flag gates **acceptance at the entry points**
   (`supportedMimes(bool)` / `knownExtensions(bool)` receive it from the
   controller, the staging request, the folder walker and the connector bridge),
   never the enum; figures to `{source_path}.ocr/{run}/images/fig-{page}-{n}.png`
   with a content-addressed `{run}` (exact W1 wording in the plan); per-page
   `ocr_confidence`; extraction origin `ocr` on the document and chunk metadata,
   **orthogonal** to the ADR 0028 `provenance_tier` (which stays the connector's
   authorship declaration — the tool firewall keeps filtering on that); PII
   redaction **before** embedding through the ADR 0020 seam; FinOps `ocr` category
   with a cost estimate on the upload modal. Scanned PDFs are routed by a
   text-layer probe inside `PdfConverter` (the registry resolves by MIME alone),
   images by `OcrConverter`. **ADR 0029.**
3. **W2** — see §2: artifacts on the Time Machine, three columns on
   `knowledge_documents` (`version_actor`, `version_reason`, `content_hash`), `diff`
   artifact-aware with reconstruction fallback, restore re-activates the artifact,
   MCP `KbDocumentVersionsTool` (read). **ADR 0030.**
4. **W3 Digitization Review.** A converted document is born in the **`auto` tier**
   (ADR 0014) — set explicitly by W1 in `DocumentIngestor::persistDocumentAndChunks()`
   (both paths) for a non-canonical document whose extraction origin is `ocr`, since
   the column otherwise defaults to `human` — and is promoted to `human` on approval. **The agent proposes, never
   commits**: MCP `KbProposeTextCorrectionTool` writes a correction *candidate* (the
   ADR 0003 `/suggest → /candidates → /promote` pattern); **no MCP tool sets a
   review status** — status changes are HTTP + CLI only (a documented R44
   exception) and MCP gets a read-only `KbReviewStatusTool`. This is the explicit
   inverse of the competitor's `update_document_page`, and an ecosystem invariant. CER/WER as **host-side**
   metrics in `app/Eval/Metrics/` (R23 pattern), not a change to `padosoft/eval-harness`.
   **ADR 0031.**
5. **W4 `kb:export-wiki` / `kb:import-wiki`.** Karpathy layout (`raw/`, `wiki/`,
   `index.md`, `log.md`, `AGENTS.md`, `CLAUDE.md`, `README.md`, `llms.txt`,
   `.mcp.json` — secret-free —, `MANIFEST.json`) but **already compiled and
   governed**: frontmatter `tier` / `evidence` / `provenance_tier` (authorship, ADR
   0028) / `extraction` (`text-layer` | `ocr`, W1) — two keys, never merged —,
   rejected approaches included, **ACL-filtered in
   SQL (R33)** for the exporting principal, hash-manifested. Import returns edits as
   promotion **candidates**, never direct writes. `KB_WIKI_EXPORT_ENABLED=false` by
   default. **ADR 0032.**
6. **W5** makes Auto-Wiki maintenance a `padosoft/laravel-routines` routine with a
   mandate and a pause-and-ask. `RoutineTarget` is defined by
   `padosoft/laravel-routines-contracts` and `laravel-flow` v2.5 ships no adapter
   for it, so the host implements a thin `App\Routines\WikiMaintenanceRoutineTarget`
   over the existing maintenance core the cron command already injects,
   `App\Services\Kb\AutoWiki\WikiMaintainer` (no rename, no second
   implementation). This is a **deliberate
   new dependency** — `padosoft/laravel-routines` v1.2.0,
   `padosoft/laravel-routines-contracts` v1.2.0, admin panel v1.1.0 are published.
   The scheduler entry for `kb:wiki-maintain` is gated off only when the routine
   can own the run — flag ON **and** package/adapter present **and** routine
   registered (no double run, no orphaned run); otherwise the cron path is
   byte-identical, with a log line saying why. **ADR 0033.**
7. **The `human > auto > raw` reranker invariant is preserved — its implementation is
   extended, not bypassed.** W3 requires `Reranker::canonicalAdjustment()` to apply the
   `generation_source` adjustment to non-canonical OCR rows too (today it only reads the
   column on canonical rows); that is a required change and it must keep the ordering
   `human > auto` intact for every row it now covers (`GenerationSource` has exactly
   `human` and `auto`; "raw" in the firewall test is a non-canonical row, which keeps
   its default `human` and is unaffected — only unreviewed OCR text is re-ordered), with the existing
   reranker firewall tests extended to the non-canonical case. Nothing in this cycle
   lets machine output outrank human-vouched knowledge.
8. **Documentation language is English**, community-facing (README, doc-site, ADRs,
   this file). `AGENTS.md`/`CLAUDE.md` generated by the export are English too.
9. **ADR numbers 0029–0033 are reserved** by the plan. Last ADR on `main` at hand-off:
   `0028`. Re-check before creating; if the numbers were taken, renumber in the plan
   in the same PR.

---

## 4. Execution order and conventions

**Branching (R37).** `main` is production. One integration branch per release:
`feature/v8.36`, `feature/v8.37`, `feature/v8.38`, `feature/v8.39`, `feature/v8.40`.
Sub-branches target the integration branch and are named with a **dash**,
`feature/v8.36-W1`, `feature/v8.36-W2` (not `feature/v8.36/W1`: git refuses a ref
that is both a file and a directory once `feature/v8.36` exists — the repository's
own precedent is `feature/v8.30-W1-fullscreenwidget`). This is the `feature/vX.Y`
convention of the `branching-strategy-feature-vx` skill applied to the v8 line —
the naming **chosen for this cycle** (the repository has used both
`feature/v8.30-W1-…` and `feature/v8.30/W2-…` in the past; the slash form is
impossible here once `feature/v8.36` exists). Merge to `main` once per release
with the GA tag.
The planning branch this file is on is a **docs-only PR to `main`** (the convention
of #470 and the earlier audits).

**Tags (R39).** `vX.Y.0-rcN` at every Wn closure, at the closure SHA, after the closure
STATUS doc (`docs/v4-platform/STATUS-{date}-v8{XY}-w{n}.md`) and the README
`### Key Features` + `## Changelog` refresh; `CHANGELOG.md` also gets its entry. GA tag
when the last Wn of the release closes.

**Loop (R36 / R40).** Local critic, then PR with reviewer
`copilot-pull-request-reviewer`, wait for CI **and** for the Copilot review, fix, re-push,
re-request, repeat to zero must-fix, merge. In a web session there is no `gh` CLI:
use the GitHub MCP tools, and end every GitHub comment with the Claude Code attribution
footer.

**Every feature (R30/R31/R33/R43/R44/R45).** Tenant scope through relationships and in
SQL; both flag states tested; **tri-surface** PHP Artisan + HTTP API + MCP over one
core; a Mintlify page under `docs-site/` per feature
(`documents-and-ocr`, `digitization-review`, `wiki-export`) shipped **with** the code,
navigation in `docs-site/docs.json`; Playwright real-data E2E for every screen
(R12/R13) with the a11y checklist (R15).

**Step list.**

- [ ] 0. PR of this branch → `main` (docs only) · loop · merge.
- [ ] 1. `feature/v8.36` from `main`. ADR 0029 + ADR 0030 accepted (docs PR first).
- [ ] 2. `feature/v8.36-W1` — `OcrConverter` + drivers + `image/*` + figures + confidence
      + extraction origin + PII seam + FinOps `ocr` + upload estimate + tri-surface + doc page.
- [ ] 3. `feature/v8.36-W2` — `source_retention`/`markdown_path` wired in the one
      persistence core both ingest paths share (`ParseMarkdownStep` → `PersistChunksStep`
      → `DocumentIngestor::persistDrafts`, and `ingest()` → `persistFromDrafts`; there is
      no `ConvertDocumentStep`), three columns (+ mirrored test migration),
      artifact-aware `diff`/`restore`, `kb:doc-versions`, `KbDocumentVersionsTool`,
      doc page. (W1 and W2 in parallel is fine.)
- [ ] 4. Closure STATUS doc + README refresh + `v8.36.0-rc1` … then GA `v8.36.0`
      (`feature/v8.36 → main`).
- [ ] 5. `feature/v8.37` — W3 on `feature/v8.37-W3` (ADR 0031 first). GA `v8.37.0`.
- [ ] 6. `feature/v8.38` — W4 on `feature/v8.38-W4` (ADR 0032 first). GA `v8.38.0`.
- [ ] 7. `feature/v8.39` — W5 on `feature/v8.39-W5` (ADR 0033 first; `composer require
      padosoft/laravel-routines` in its own commit). GA `v8.39.0`.
- [ ] 8. `feature/v8.40` — W6 if still wanted; otherwise close it in the plan as
      “deferred” with one sentence of why.
- [ ] 9. Final: README roadmap row flips to ✅ shipped with PR numbers; audit §2.1
      table re-graded against what shipped; `docs/ENTERPRISE-COMPLETENESS-ROADMAP.md` R5
      (Slides OCR) re-scoped onto `OcrConverter`.

---

## 5. Boundaries the new session must know

- **`padosoft/*` repositories are out of write scope** for a session whose initial
  source is `lopadova/AskMyDocs` — the same cross-owner rule that forced this hand-off,
  in the other direction. Consume **published** versions (`composer.json` pins). Where
  the plan would benefit from a change in a padosoft package (a metric upstreamed to
  `eval-harness`, an extra `RoutineTarget` in `laravel-routines`), implement the
  host-side version first, and record the upstream ask as a short file in
  `docs/handoff/` for a padosoft-scoped session to pick up.
- **Network.** `annota.ai` was allow-listed in the environment for re-verification of
  the audit; it is not needed for implementation. OCR drivers that call an API
  (`mistral-ocr`, `vision-llm`) are exercised through recorded fixtures in tests
  (`RUNBOOK-live-fixture-recording.md`), never live in CI.
- **GitHub from a web session.** No `gh`/`hub`; the GitHub MCP tools do everything
  (PRs, reviews, merges, Actions). Proxy 403s are reported, never routed around.
- **Attribution.** Commit trailers and PR footers are whatever the session's own
  system instructions prescribe; never hard-code a model name in files pushed to the
  repo.

---

## 6. Acceptance — what “done” proves (from the plan §4)

- A scanned PDF and a PNG ingest end to end with `KB_OCR_ENABLED=true` and are refused
  exactly as today with the flag off (R43).
- Every **non-canonical** OCR'd document has an `auto` tier (a canonical one keeps
  what its frontmatter says), extraction origin `ocr` on the document
  and its chunks, a per-page confidence, a FinOps line in the `ocr` category, and
  PII redacted before the first embedding; **with `KB_CONVERSION_ARTIFACTS_ENABLED=true`
  (W2) it also has a stored artifact** — with the W2 flag off, by design, it has none.
- Time Machine `diff` on two versions with artifacts diffs the artifacts; on two
  without, it falls back to reconstruction; a correction saved in the review UI is a
  new version with `version_actor = user:{id}`.
- An agent can **propose** a correction over MCP and cannot set a review status; the
  proposal is visible as a candidate; approval promotes the document to `human`.
- `kb:export-wiki` for a principal produces a folder that a coding agent opens and
  queries without any ingestion step, with **no** page the principal could not read
  in the app (R33 test through the relationship); `kb:import-wiki` of an edited folder
  yields candidates, not writes.
- The Auto-Wiki maintenance routine runs under a mandate, pauses on the pause-and-ask
  condition, and the cron path is unchanged when the routine is disabled.
- CER/WER lane in `eval:nightly` runs on the fixture set and gates a driver upgrade.

---

## 7. AUTO-MODE CHECKPOINT

*Update after every merged PR: branch, PR number, HEAD sha, rc/GA tag, absolute date.*

| Wn | Integration branch | Sub-branch | PR | HEAD | Tag | State |
|---|---|---|---|---|---|---|
| plan | `main` | `claude/plan-annota-gap-document-intelligence` | [#476](https://github.com/lopadova/AskMyDocs/pull/476) | `b20420a3` | — | merged 2026-09-12 (squash; 12 Copilot rounds) |
| ADR 0029+0030 | `feature/v8.36` (from `main` @ `b20420a3`) | `feature/v8.36-adr-0029-0030` | — | — | — | PR open 2026-09-12 |
| W1 | `feature/v8.36` | `feature/v8.36-W1` | — | — | — | in progress 2026-09-12 (local critic rounds 1–8 done, full PHPUnit 4157 green, local E2E 7/7) |
| W2 | `feature/v8.36` | `feature/v8.36-W2` | — | — | — | not started |
| v8.36 GA | `main` | — | — | — | — | — |
| W3 | `feature/v8.37` | `feature/v8.37-W3` | — | — | — | not started |
| W4 | `feature/v8.38` | `feature/v8.38-W4` | — | — | — | not started |
| W5 | `feature/v8.39` | `feature/v8.39-W5` | — | — | — | not started |
| W6 | `feature/v8.40` | `feature/v8.40-W6` | — | — | — | optional |

Last updated: 2026-09-12 (plan merged; ADR PR opened).

---

## 8. Kickoff prompt

Paste this as the first message of the new session (initial source: `lopadova/AskMyDocs`).

```text
AUTO-MODE EXECUTION PROMPT — AskMyDocs v8.36 → v8.40 (Document Intelligence + LLM Wiki export)

Goal: ship docs/v4-platform/PLAN-v8.36-document-intelligence-and-llm-wiki-export.md end to
end — W1..W5, W6 optional — RC-tagged per Wn (R39), GA-tagged per release (R37), every PR
merged by you once CI is green and the Copilot loop (R36/R40) is at zero must-fix.

Start here, in this order:
1. git fetch origin && git checkout claude/plan-annota-gap-document-intelligence
   Read fully, in this order: docs/handoff/v836-document-intelligence-cycle-kickoff.md
   (including its §7 checkpoint), then the PLAN and the AUDIT it points to, then CLAUDE.md
   (all R-rules) and .claude/skills/.
2. If that branch has no PR to main yet, open one (docs-only planning PR, reviewer
   copilot-pull-request-reviewer), run the loop, merge. If it is already merged, skip.
3. Create feature/v8.36 from main. Write and merge ADR 0029 and ADR 0030 first (verify
   0028 is still the last ADR), then start W1 and W2 in parallel on feature/v8.36-W1 and
   feature/v8.36-W2 (dash: git refuses a ref under an existing branch name).
4. Follow the step list in §4 and the checkpoint table in §7 of the hand-off; update the
   table after every merged PR with branch, PR number, HEAD sha, tag, absolute date.

Hard rules:
- Do not ask for confirmations; continue to the next actionable item until the goal is done.
- Do not re-open the decisions in §3 of the hand-off; if code contradicts the audit, fix the
  audit in the same PR and continue.
- Every feature: default-OFF flag tested in both states (R43), tenant scope in SQL
  (R30/R31/R33), tri-surface PHP + HTTP + MCP over one core (R44), Mintlify doc-site page
  shipped with the code (R45), Playwright real-data E2E + a11y for every screen.
- The agent proposes, a person confirms: no MCP tool in this cycle writes content or sets
  a review status.
- padosoft/* repositories are out of this session's write scope: consume published
  versions, implement host-side first, record upstream asks in docs/handoff/.
- If blocked by CI or review, run the wait/recheck loop and resume; retry transient failures.
- No gh CLI here: use the GitHub MCP tools; end every GitHub comment with the attribution
  footer your instructions prescribe.

Output style: concise operational updates only; exact PR numbers, HEAD shas, absolute dates.
```
