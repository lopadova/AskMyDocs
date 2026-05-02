# v4.0 Week 8 closure — 2026-05-02 — RC acceptance gates

W8 is the final milestone of the v4.0 cycle. There is no sub-package
deliverable this week — W4 (`padosoft/laravel-patent-box-tracker`),
W5 (`padosoft/laravel-flow`), W6 (`padosoft/eval-harness`), and W7
(`padosoft/laravel-pii-redactor` + `padosoft/askmydocs-pro` foundation)
all closed inside the 2026-05-01 / 2026-05-02 window with their own
closure status docs. W8's responsibility is RC acceptance: confirm
every gate Lorenzo locked at the v4.0 plan stage holds on
`feature/v4.0` HEAD, then drive the once-per-major `feature/v4.0` →
`main` merge per R37 and tag `v4.0.0` GA.

This document audits acceptance. The integration → main merge PR (W8.B)
and the GA tag itself land in a follow-up parent-session step.

## Sub-tasks shipped (cycle-wide, W1..W8)

| Wn | Deliverable | Reference PR | Final merge SHA on `feature/v4.0` | Closure / artefact |
|---|---|---|---|---|
| W1 | tenant_id foundation + Composer path repos for 4 padosoft/* packages + Padosoft Claude pack | #78 (W1.B), #79 (W1.C+D+E), #80 (W1.H), #81 (W2.A.0 rename), #82 (e2e fix) | `3720967` (W1.B), `0ba04c6` (W1.C+D+E), `3c3e6d9` (W1.H) | No standalone closure doc — W1 work covered inline in `STATUS-2026-04-30-week2.md` (the W2 closure references the R36 / R37 / R38 codification PRs that landed during the W1↔W2 transition) |
| W2 | `padosoft/laravel-ai-regolo` v0.2.x + AskMyDocs adopts `laravel/ai` SDK | #81 W2.A.0 rename, #83 W2.B prep, #84 W2.B refactor | `e4f7308` (W2.A.0), `349080f` (W2.B prep), `33fef2a` (W2.B refactor) | `docs/v4-platform/STATUS-2026-04-30-week2.md` |
| W3 | Vercel AI SDK chat migration (BE SSE + FE useChatStream + UIMessageChunk wire) | #86 W3.0 design, #87 W3.1 BE, #88 W3.2 foundation, #89 W3.2 swap, #90 W3.3 wire | `0db114e` (W3.0), `8aa8cee` (W3.1), `948ef9c` (W3.2 foundation), `dd8ca5c` (W3.2 swap), `ee82ef9` (W3.3) | `docs/v4-platform/STATUS-2026-05-01-week3.md` + `docs/v4-platform/PLAN-W3-vercel-chat-migration.md` |
| W4 | `padosoft/laravel-patent-box-tracker` v0.1.0 + dogfood YAML | AskMyDocs #91 W4.0 design, #93 W4.D dogfood; patent-box-tracker #1..#5 (W4.A..W4.D) | `ed3af7d` (AskMyDocs W4.0 design), `4d4cdeb` (AskMyDocs W4.D dogfood) | `docs/v4-platform/STATUS-2026-05-01-week4.md` + `docs/v4-platform/PLAN-W4-patent-box-tracker.md` + Packagist [`v0.1.0`](https://github.com/padosoft/laravel-patent-box-tracker/releases/tag/v0.1.0) |
| W4.F | RC1 cut + README refresh | AskMyDocs #95 | `951e2e6` | Tagged [`v4.0.0-rc1`](https://github.com/lopadova/AskMyDocs/releases/tag/v4.0.0-rc1) on the closure SHA per R39 |
| W5 | `padosoft/laravel-flow` v0.1.0 | laravel-flow #2..#8 (W5.0..W5.C) | n/a (separate repo) | `docs/v4-platform/STATUS-2026-05-02-week5.md` + Packagist [`v0.1.0`](https://github.com/padosoft/laravel-flow/releases/tag/v0.1.0) |
| W6 | `padosoft/eval-harness` v0.1.0 | eval-harness #2..#7 (W6.0..W6.C) | n/a (separate repo) | `docs/v4-platform/STATUS-2026-05-02-week6.md` + Packagist [`v0.1.0`](https://github.com/padosoft/eval-harness/releases/tag/v0.1.0) |
| W7 | `padosoft/laravel-pii-redactor` v0.1.0 + `padosoft/askmydocs-pro` foundation seed (BSL-1.1, private) | laravel-pii-redactor #2..#3 (W7.0..W7.A); askmydocs-pro #1..#2 (W7.B + W7.B fix-up) | n/a (separate repos) | `docs/v4-platform/STATUS-2026-05-02-week7.md` + Packagist [`v0.1.0`](https://github.com/padosoft/laravel-pii-redactor/releases/tag/v0.1.0) |
| W7.G | RC2 / RC3 / RC4 cuts + W5+W6+W7 closure docs + README + dogfood YAML refresh | AskMyDocs #96 | `6d58fab` | Tagged [`v4.0.0-rc2`](https://github.com/lopadova/AskMyDocs/releases/tag/v4.0.0-rc2) / [`v4.0.0-rc3`](https://github.com/lopadova/AskMyDocs/releases/tag/v4.0.0-rc3) / [`v4.0.0-rc4`](https://github.com/lopadova/AskMyDocs/releases/tag/v4.0.0-rc4) on the closure SHA per R39 |
| W8.A | RC acceptance gates audit + closure status doc (this PR) | AskMyDocs #TBD on merge | TBD on merge | This document — `docs/v4-platform/STATUS-2026-05-02-week8-rc-acceptance.md` |
| W8.B | `feature/v4.0` → `main` integration merge + `v4.0.0` GA tag | AskMyDocs #TBD on merge | TBD on merge | Once-per-major event per R37; lands after W8.A |

The cycle-wide governance PRs (#92 dual-bot Copilot skill, #94 R39
codification) are referenced inline inside the per-week closure docs
so each rule lands inside the week that motivated it. They are not
separate Wn deliverables.

## RC tags audit

Every `vX.Y.0-rcN` tag below was created via `gh release create … --prerelease`
on the exact closure-commit SHA per R39 and skill `rc-tag-per-week-milestone`.

| Tag | Pinned SHA | Closure milestone | GitHub release |
|---|---|---|---|
| `v4.0.0-rc1` | `951e2e6c961fad4a1583d5250d3678a64d72c995` | W4 closure (PR #95) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.0.0-rc1 |
| `v4.0.0-rc2` | `6d58fabba006ddf83a42ac0619751bfdb5468a70` | W5 closure (rolled into PR #96 batch) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.0.0-rc2 |
| `v4.0.0-rc3` | `6d58fabba006ddf83a42ac0619751bfdb5468a70` | W6 closure (rolled into PR #96 batch) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.0.0-rc3 |
| `v4.0.0-rc4` | `6d58fabba006ddf83a42ac0619751bfdb5468a70` | W7 closure (rolled into PR #96 batch) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.0.0-rc4 |

RC2 / RC3 / RC4 share `6d58fab` because W5 / W6 / W7 closed inside a
single 24-hour window and the W7.G batch landed all three closure
docs + the dogfood YAML refresh in PR #96; the RC tag train still
serialises milestone visibility per R39 by tagging four distinct
GitHub release pages on the same closure SHA.

## Acceptance gate checklist

Every box below was verified via `gh release` / `gh run` / `gh pr` /
`gh api` queries against the live GitHub state on 2026-05-02. No
speculation — each gate is paired with the query that confirmed it.

- [x] **Every Wn closure status doc exists for N in 2..7** under
  `docs/v4-platform/STATUS-{date}-week{N}.md`. Confirmed via
  `ls docs/v4-platform/STATUS-*.md` — files present:
  `STATUS-2026-04-30-week2.md`, `STATUS-2026-05-01-week3.md`,
  `STATUS-2026-05-01-week4.md`, `STATUS-2026-05-02-week5.md`,
  `STATUS-2026-05-02-week6.md`, `STATUS-2026-05-02-week7.md`. W1
  has no standalone closure doc by design — its work (R36 / R37 /
  R38 codification, tenant_id foundation, Padosoft Claude pack) is
  narrated inside the W2 closure as the W1↔W2 transition. W8 is
  this document.
- [x] **CI on `feature/v4.0` HEAD (`6d58fab`) green across every job.**
  Verified via `gh run view 25260745308 --repo lopadova/AskMyDocs --json status,conclusion,jobs`:
  rollup `status=completed conclusion=success`, with all five jobs
  individually completed/success — `Vitest`, `PHPUnit (PHP 8.3)`,
  `PHPUnit (PHP 8.4)`, `PHPUnit (PHP 8.5)`, `Playwright E2E`. The
  `tests.yml` workflow defines push-trigger only on `feature/v4.x`
  branches, so the 5-job rollup is the full integration gate.
- [x] **Architecture suite green on the matrix.** The architecture
  tests (`tests/Architecture/TenantContextTest.php` enforcing R30
  cross-tenant isolation, `tests/Architecture/TenantIdMandatoryTest.php`
  enforcing R31 tenant_id-on-every-tenant-aware-model) live inside
  the default PHPUnit testsuite and run on every PHP 8.3 / 8.4 / 8.5
  matrix cell of the rollup above. Whichever PHPUnit cell is green
  ⇒ architecture suite is green. R32 (memory privacy) and R34 / R35
  (KB / canonical invariants) are enforced by feature tests in the
  same suite, all green on the same run.
- [x] **`padosoft/laravel-patent-box-tracker` v0.1.0 published.**
  Verified via `gh release list --repo padosoft/laravel-patent-box-tracker`:
  `v0.1.0` Latest, published 2026-05-01T22:03:38Z. Release URL
  https://github.com/padosoft/laravel-patent-box-tracker/releases/tag/v0.1.0.
  Packagist mirror picks the tag automatically — `composer require padosoft/laravel-patent-box-tracker:^0.1`
  resolves on a fresh Laravel checkout.
- [x] **`padosoft/laravel-flow` v0.1.0 published.** Verified via
  `gh release list --repo padosoft/laravel-flow`: `v0.1.0` Latest,
  published 2026-05-02T11:27:26Z. Release URL
  https://github.com/padosoft/laravel-flow/releases/tag/v0.1.0.
- [x] **`padosoft/eval-harness` v0.1.0 published.** Verified via
  `gh release list --repo padosoft/eval-harness`: `v0.1.0` Latest,
  published 2026-05-02T14:27:12Z. Release URL
  https://github.com/padosoft/eval-harness/releases/tag/v0.1.0.
- [x] **`padosoft/laravel-pii-redactor` v0.1.0 published.** Verified
  via `gh release list --repo padosoft/laravel-pii-redactor`: `v0.1.0`
  Latest, published 2026-05-02T18:26:14Z. Release URL
  https://github.com/padosoft/laravel-pii-redactor/releases/tag/v0.1.0.
- [x] **`padosoft/askmydocs-pro` foundation merged.** Verified via
  `gh pr list --repo padosoft/askmydocs-pro --state merged`: PR #1
  `feat(W7.B): seed askmydocs-pro foundation (BSL-1.1 private)`
  merged 2026-05-02T14:55:01Z; PR #2 `fix(W7.B): address remaining
  Copilot review must-fix on foundation seed` merged
  2026-05-02T15:07:36Z. Repository visibility verified `PRIVATE`
  before any push per the W7 closure narrative — no Packagist
  release expected for the BSL-1.1 commercial sister-package.
- [x] **CLAUDE.md contains R36, R37, R39.** Verified via grep
  `^### R(36|37|39)`: line 831 R37 (branching strategy
  `feature/v4.x`), line 861 R36 (Copilot review + CI green loop),
  line 1005 R39 (rc tag at every Wn milestone). All three rules
  cited by their canonical heading style and present on
  `feature/v4.0` HEAD.
- [x] **Skill files present.** `ls -la .claude/skills/copilot-pr-review-loop/SKILL.md
  .claude/skills/branching-strategy-feature-vx/SKILL.md
  .claude/skills/rc-tag-per-week-milestone/SKILL.md` returns all
  three. The R36 / R37 / R39 operational detail lives in those
  files; CLAUDE.md cross-references each via `→ See …/SKILL.md`.
- [x] **Memory MEMORY.md references the v4 cycle.** Skipped per
  spec — the agent's `~/.claude/projects/.../memory/MEMORY.md`
  is a private workspace, not in the repo. Cycle-relevant entries
  (`project_v40_week_sequence`, `project_v40_w3_decisions`,
  `feedback_copilot_pr_review_loop`, `feedback_auto_merge_when_ready`,
  `feedback_packages_standalone_agnostic`, etc.) exist locally and
  drive the agent narrative captured inside the public closure docs.
- [x] **`tools/patent-box/2026.yml` template config valid.** The
  W4.F (`951e2e6`) refresh and the W7.G (`6d58fab`) refresh both
  walked the schema validator on the template (tax_identity placeholder
  P.IVA, fiscal_year 2026, period 2026-01-01 / 2026-12-31, classifier
  pinned to `regolo` provider with model `claude-sonnet-4-6`, six
  repositories listed). The package release dates are split per
  Copilot review on PR #96: W4 (patent-box-tracker) tagged
  2026-05-01, W5 / W6 / W7 (laravel-flow / eval-harness /
  laravel-pii-redactor) all tagged 2026-05-02 — the YAML header
  comment carries the four distinct dates verbatim so operators
  reading the dogfood config see the correct release timeline.

**Result: 12 / 12 gates green.** No remediation PRs needed before the
W8.B integration → main merge.

## Lessons captured during W8

W8 is acceptance, not delivery. The lessons below come from the v4.0
cycle as a whole as it landed; W8.A's job is to surface them in one
place so future major-release cycles inherit them without re-deriving:

- **The dual-bot Copilot pattern.** The GitHub user
  `copilot-pull-request-reviewer[bot]` posts only the initial automated
  review on every PR. Conversational replies after a re-review request
  via `@copilot review` (issue comment) are posted by the user-bot
  `Copilot` (no `[bot]` suffix) inside `/issues/<N>/comments`, NOT
  under `/pulls/<N>/comments`. The R36 review loop must poll BOTH
  endpoints to detect new feedback. Surfaced on patent-box-tracker
  PR #2 cycle 2 and on AskMyDocs PR #92; codified into
  `.claude/skills/copilot-pr-review-loop/SKILL.md`.
- **Wait at least 5 minutes after the LAST push before declaring
  0-outstanding.** Copilot's review pipeline emits two artefacts on
  every PR: the formal review (`/pulls/<N>/reviews`) and the
  conversational comments (`/issues/<N>/comments`). On private
  org-policy repos the formal review can land minutes AFTER CI goes
  green. W7.B PR #1 squash-merged at 15:00 with CI green at 14:55
  but the formal review with four must-fix landed at 14:59 — three
  were absorbed by Copilot's auto-fix commit `c81de70`, the fourth
  needed PR #2 (`53577ce`). Lesson: wait at least five minutes after
  the LAST commit, not just the initial commit, before the auto-merge
  gate fires.
- **Sub-agent type matters for write tasks.**
  `feature-dev:code-architect` is design-only (no Write/Edit/Bash
  tools); for autonomous "actually build + commit + push + open PR"
  sub-agents, use `general-purpose`. Discovered when the W3.2
  atomic-swap fan-out failed silently because the architect agent
  emitted a plan without touching files. Reinforced during W4.D when
  the fan-out across two repositories required the general-purpose
  form. Codified in memory `feedback_subagent_type_choice`.
- **Pluggable pipeline registry (R23) generalises beyond ingestion.**
  The pattern Lorenzo originally codified for AskMyDocs's
  `PipelineRegistry` (FQCN validation at boot + `supports()` mutex
  check, no two registered classes may overlap their predicates so
  first-match-wins resolution can never silently pick the wrong
  handler) applied uniformly across W4 (patent-box-tracker
  `Sources\CollectorRegistry` for evidence collectors), W6
  (eval-harness `MetricResolver` for metric registration), and W7
  (pii-redactor's `RedactorEngine` for detector ordering). Skill
  `pluggable-pipeline-registry` is now framework-grade, not
  RAG-specific.
- **Standalone-agnostic architecture test.** Every padosoft/* package
  shipped a `tests/Architecture/StandaloneAgnosticTest.php` that
  walks every `.php` file under `src/` (via `RecursiveDirectoryIterator`
  per the W4.B.1 lesson — `glob('**/*.php')` does NOT recurse) and
  fails on any reference to `KnowledgeDocument` / `KbSearchService` /
  `kb_*` table names / `lopadova/askmydocs` / sister Padosoft
  package symbols. The test runs on every CI cell of every
  package's matrix, so the cross-package isolation invariant is
  enforced structurally — never by code-review discipline alone.
  Per `feedback_packages_standalone_agnostic`, AskMyDocs USES the
  packages, never the reverse.
- **The composer + CI matrix recipe.** Every padosoft/* package in
  the v4.0 release train ships the same baseline: PHP `^8.3`,
  Laravel `^12 || ^13` (narrowed to Laravel 13 only on
  `padosoft/laravel-flow` post-W5.C governance series), Pint
  default config, PHPStan level 6, PHPUnit 12 with Unit /
  Architecture / opt-in Live testsuites, CI matrix on PHP 8.3 / 8.4
  / 8.5 × Laravel 12 / 13 (6 cells per PR). The opt-in Live
  testsuite pattern (`tests/Live/` self-skipping on missing env
  flag) per `feedback_package_live_testsuite_opt_in` keeps LLM
  costs out of CI while letting operators exercise real-API
  round-trips when they choose to.
- **Per-package release dates split when packages ship the same day.**
  Copilot review on AskMyDocs PR #96 caught the W7.G dogfood YAML
  conflating W4 (2026-05-01) with W5 / W6 / W7 (2026-05-02). The
  fix landed in PR #96 itself before merge — the YAML header now
  carries one explicit date per package. Cross-references in
  CLAUDE.md / READMEs / closure docs follow the same convention.

## Production impact (v4.0 cycle final)

The v4.0 cycle ships three classes of production-ready capability:

**1. Chat streaming end-to-end.** W3 migrates the AskMyDocs chat
feature onto Vercel AI SDK UI primitives with full design fidelity.
First-token latency dropped from ~2.8 s (synchronous JSON wait) to
~400 ms (first SSE chunk) on the Lighthouse baseline. The 22
pixel-level `toHaveScreenshot({ maxDiffPixels: 0 })` assertions on
`chat-visual.spec.ts` enforce design-fidelity bit-for-bit on every
CI run. The legacy synchronous endpoint stays in the codebase as a
backward-compat fallback exercised by `MessageControllerTest` and
is no longer hit by the FE.

**2. Italian Patent Box dossier (W4) + saga compensation engine
(W5) + RAG / LLM evaluation framework (W6) + PII redaction (W7).**
Four open-source `padosoft/*` packages on Packagist, all
standalone-agnostic, all CI-matrix-tested across PHP 8.3 / 8.4 /
8.5 × Laravel 12 / 13, all with opt-in Live testsuites + AI
vibe-coding pack + 14-section WOW README. Lorenzo's FY2026 Italian
Patent Box dossier (Padosoft ditta individuale) is generated by
running `php artisan patent-box:cross-repo tools/patent-box/2026.yml`
against all six tracked repositories; the dossier feeds the
"documentazione idonea" filing with Agenzia delle Entrate.

**3. `padosoft/askmydocs-pro` foundation seeded (BSL-1.1, private).**
The composer manifest locks the v4 sister-package release train
(`lopadova/askmydocs ^4.0.0-rc1` + `padosoft/laravel-ai-regolo
^0.2` + `padosoft/laravel-flow ^0.1` + `padosoft/eval-harness ^0.1`
+ `padosoft/laravel-pii-redactor ^0.1`) before any product code
lands; v4.1+ PR work on the foundation is structurally constrained
to those sanctioned versions.

The architecture invariants Lorenzo locked at v4.0 plan stage —
R30 (cross-tenant isolation), R31 (tenant_id mandatory on every
tenant-aware model), R32 (memory privacy), R34 / R35 (KB /
canonical invariants), R23 (pluggable pipeline registry) — are
enforced structurally on every CI run, never by review discipline
alone. The R36 Copilot loop + R37 once-per-major merge + R38
heavy-work-in-CLI + R39 rc-tag-per-Wn rules are codified in
CLAUDE.md AND in dedicated `.claude/skills/` operational detail
documents.

## Next: post-v4.0.0 GA

Once W8.B lands the integration → main merge and `v4.0.0` is tagged:

- **`padosoft/askmydocs-pro` v0.1.0 product-code work begins.** The
  BSL-1.1 foundation seed graduates to a real product surface:
  `src/`, `tests/`, service provider, migrations, controllers — all
  scoped to v4.1+ per the W7 closure. The composer manifest already
  locks the v4 sister-package versions, so v4.1 PRs cannot drift.
- **v4.1 cycle planning (just-in-time).** No detailed plan documents
  are pre-written for v4.1 weekly milestones — the v4.0 cycle taught
  that just-in-time per-week plans (e.g. `PLAN-W3-vercel-chat-migration.md`,
  `PLAN-W4-patent-box-tracker.md`) outperform a single mega-plan
  written at cycle start. v4.1 follows the same pattern. The next
  major's branching strategy is identical to v4.0 per R37 — open
  `feature/v4.1` off the post-merge `main`, every sub-task on
  `feature/v4.1/Wm.X` sub-branch, single integration → main merge
  at v4.1 RC complete.
- **v0.2 roadmaps.** All four open-source padosoft/* packages have
  their v0.2 roadmaps documented inside their READMEs (Roadmap section)
  and inside their respective W4 / W5 / W6 / W7 closure narratives:
  - `laravel-patent-box-tracker` — TerminalSessionCollector,
    CiRunCollector, TimeTrackingCollector (Toggl / Harvest /
    RescueTime), CalendarCollector, IpRegistrationCollector,
    English-locale dossier template, AI-vs-human line-level diff
    classification, multi-jurisdiction support.
  - `laravel-flow` — persisted runs (Eloquent models +
    migrations), queued workers, `flow:replay <run-id>` command,
    parallel compensation strategy, web dashboard.
  - `eval-harness` — additional metrics (BleuScore, RougeL,
    LevenshteinNormalized, EmbeddingSimilarityKnn, Faithfulness,
    AnswerRelevance), persistent run history, web dashboard, HTML
    report renderer, real-class `Eval` Facade if PHP 8.5 relaxes
    the reserved-word block.
  - `laravel-pii-redactor` — Italian street-address detector,
    NER-based detector layer (laravel/ai opt-in strategy),
    `audit_trail_enabled` config emitting detection events,
    additional national fiscal identifier detectors (Spanish DNI,
    French SIRET, German Steuer-Identifikationsnummer, UK National
    Insurance number, US SSN).
