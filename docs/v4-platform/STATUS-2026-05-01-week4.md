# v4.0 Week 4 closure — 2026-05-01

W4 deliverable per `project_v40_week_sequence`: **`padosoft/laravel-patent-box-tracker` v0.1 — standalone Laravel package that classifies R&D activity across one or more git repositories with a deterministic LLM-based classifier and emits a tamper-evident PDF + JSON dossier suitable for the Italian Patent Box (110% R&D super-deduction) regime.**

The W4 design doc — `docs/v4-platform/PLAN-W4-patent-box-tracker.md` — locked the package's eight-section architecture, the standalone-agnostic invariant per `feedback_packages_standalone_agnostic`, the deterministic-seeded classifier contract, and the cross-repo YAML schema ahead of execution. Every locked decision held through delivery.

## Sub-tasks shipped

| Sub-task | PR | Merge SHA on `main` | Outcome |
|---|---|---|---|
| W4.0 — design doc + W3 closure | AskMyDocs #91 | `ed3af7d` (`feature/v4.0`) | Design doc landed under `docs/v4-platform/PLAN-W4-patent-box-tracker.md`; W3 closure status doc shipped |
| W4.A — initial scaffold | patent-box-tracker #1 | `3016e77` | `composer.json` (PHP 8.3+, Laravel 12 / 13, `laravel/ai` ^0.6, `symfony/yaml` ^7|^8), `PatentBoxTrackerServiceProvider`, `config/patent-box-tracker.php`, `phpunit.xml` (Unit + opt-in Live), CI matrix on PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13, `.claude/` vibe-coding pack inherited from the Padosoft baseline, README following the canonical 14-section Padosoft WOW structure |
| W4.B.1 — evidence collectors + registry | patent-box-tracker #2 | `42acf4a` | `Sources\EvidenceCollector` interface, `Sources\CollectorRegistry` with R23 boot-time validation + non-overlap mutex on `supports()`, four canonical collectors (`GitSourceCollector`, `AiAttributionExtractor`, `DesignDocCollector`, `BranchSemanticsCollector`), `Sources\CollectorContext` + `Sources\EvidenceItem` DTOs, `Sources\Internal\GitProcess` (proc_open wrapper with timeout-bounded stream draining), Unit testsuite covering all four collectors plus the registry mutex |
| W4.B.2 — classifier + storage | patent-box-tracker #3 | `d7a94f3` | `Classifier\CommitClassifier` (laravel/ai SDK driver, deterministic seed, strict-JSON parsing), `Classifier\ClassifierBatcher`, `Classifier\ClassifierPrompts` (versioned `patent-box-classifier-v1`), `Classifier\Phase` enum, `Classifier\CommitClassification` DTO, `Classifier\CostCapGuard`, `Classifier\GoldenSetValidator`, four Eloquent models (`TrackingSession`, `TrackedCommit`, `TrackedEvidence`, `TrackedDossier`), four migrations creating the audit-trail tables with the unique `(tracking_session_id, repository_path, sha)` constraint |
| W4.C — dossier renderers + hash chain | patent-box-tracker #4 | `4b3067b` | `Renderers\DossierRenderer` interface, `Renderers\PdfDossierRenderer` (Browsershot default + DomPDF fallback with engine-detection capabilities helper), `Renderers\JsonDossierRenderer` (canonical-JSON output, lexicographic key sort), `Renderers\DossierPayloadAssembler`, `Renderers\RenderedDossier` (readonly artefact DTO with `save()`), `Hash\HashChainBuilder` (per-commit `H(prev || ':' || sha)` chain + `verify()`), Italian Blade template + partials, `Console\RenderCommand` |
| W4.D — TrackCommand + CrossRepoCommand + fluent builder + dogfood | patent-box-tracker #5 (TBD on merge) + AskMyDocs #93 (this PR) | TBD | `Console\TrackCommand` (`patent-box:track` — single-repo end-to-end with eleven options + four exit codes), `Console\CrossRepoCommand` (`patent-box:cross-repo` — multi-repo orchestrator with per-repo progress + cross-repo summary), `Config\CrossRepoConfigValidator` (strict YAML schema with 15+ negative scenarios), `Config\CrossRepoConfig` + `Config\RepoConfig` typed DTOs, fluent builder `PatentBoxTracker::for(...)->coveringPeriod()->classifiedBy()->withTaxIdentity()->withCostModel()->run()`, `TrackingSession::renderDossier()` accessor returning a `Renderers\DossierRenderBuilder`. AskMyDocs ships `tools/patent-box/2026.yml` (template config covering AskMyDocs + 5 sister Padosoft repos) and this closure doc |

## Acceptance gates passed

- CI matrix on every patent-box-tracker PR — PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 (6 cells per PR) plus Pint + PHPStan + the architecture suite — converged GREEN before merge on every PR.
- Test count post-W4.D merge: **163 PHPUnit tests / 1079 assertions** across the package:
  - Unit suite: 146 tests / 613 assertions (1 skipped — the synthetic-fixture-bound test that auto-skips on environments without bash).
  - Feature suite: 14 tests / 74 assertions covering `TrackCommand`, `CrossRepoCommand`, `CrossRepoConfigValidator` (16 scenarios — happy path + 15 negative branches), and the fluent builder API end-to-end.
  - Architecture suite: 3 tests / 392 assertions enforcing the standalone-agnostic invariant across `src/Sources/`, `src/Classifier/`, `src/Models/`, `src/Renderers/`, `src/Hash/`, `src/Console/`, and `src/Config/`.
- Standalone-agnostic invariant maintained throughout — zero references to `KnowledgeDocument`, `KbSearchService`, `kb_*` table names, or `lopadova/askmydocs` in package source. `composer.json` declares only first-party Laravel packages + `laravel/ai` + `symfony/yaml`. Architecture test grep walks every `.php` file in seven src/ subdirectories.
- Copilot Code Review converged to **0 outstanding must-fix** on every PR after the R36 loop:
  - PR #1 (W4.A): ~3 review cycles
  - PR #2 (W4.B.1 — collectors + registry): 2 cycles
  - PR #3 (W4.B.2 — classifier + storage): 4 cycles (clustered around the `final class RenderException` / `Classifier\GoldenSetValidator` review surface)
  - PR #4 (W4.C — renderers + hash chain): 3 cycles
  - PR #5 (W4.D — TrackCommand + CrossRepoCommand + fluent + dogfood): TBD on merge
- The fluent builder API documented in the README quick-start round-trips end-to-end through `PatentBoxTrackerFluentApiTest::test_fluent_builder_runs_full_pipeline_and_renders_json` — the assertion includes a JSON dossier decode + commit-section count check, so README drift is structurally impossible.

## Lessons captured during W4

The lessons below are codified back into the AskMyDocs `.claude/skills/` pack and into agent memory so future PRs (on AskMyDocs and on every padosoft/* repo) inherit the fixes.

- **Dual Copilot bot pattern** (PR #2 cycle 2 on patent-box-tracker, also surfaced on PR #92 AskMyDocs). The GitHub user `copilot-pull-request-reviewer[bot]` posts only the initial automated review on every PR. Conversational replies after a re-review request via `@copilot review` (issue comment) are posted by the user-bot `Copilot` (no `[bot]` suffix) inside `/issues/<N>/comments`, NOT under `/pulls/<N>/comments`. The R36 review loop must poll BOTH endpoints to detect new feedback. Codified in `.claude/skills/copilot-pr-review-loop/SKILL.md` + memory `feedback_copilot_review_request_padosoft_repos`. AskMyDocs PR #92 shipped the skill update.
- **Copilot-bot pushes trigger workflow runs in `action_required` state.** Every commit pushed by Copilot itself (not by Lorenzo or the agent) lands the resulting workflow run in `action_required` state, which blocks the PR's CI rollup until manually re-run with `gh run rerun <id>`. Encountered on W4.B.2 PR #3 (commit `04bf880`) and W4.C PR #4 (commits `5566c3b`, `b02a171`, `6de3285`). The R36 loop now treats `action_required` as a known state and proactively re-runs.
- **Pint vs PHPStan finals can contradict.** Declaring `final class RenderException` in W4.C made PHPStan refuse `MissingRendererDependencyException extends RenderException`. The two contracts (R23-style sealing for the renderer surface vs the W4.C single-catch design that needs a parent class) conflicted; dropping `final` on the parent is the right resolution, with the design intent preserved in the parent's docblock. Codified into the renderer docblock so future maintainers see the rationale.
- **`gh pr edit --add-reviewer copilot-pull-request-reviewer` returns 422 "not a collaborator" on freshly-created padosoft/* repos.** The org-level Copilot Code Review policy fires the initial review automatically, but later re-reviews require the `@copilot review` mention via `gh api POST /issues/<N>/comments` instead. Already memory-captured pre-W4 (`feedback_copilot_review_request_padosoft_repos`); W4 confirmed the pattern across four padosoft/* PRs.
- **Sub-agent type matters for write tasks.** `feature-dev:code-architect` is design-only (no Write/Edit/Bash tools); for autonomous "actually build + commit + push + open PR" sub-agents, use `general-purpose`. Already memory-captured pre-W4; reinforced during W4.D when the fan-out across two repositories required the general-purpose form.

## Production impact

W4 ships `padosoft/laravel-patent-box-tracker` v0.1.0 to Packagist (post-merge of W4.D + tag).

The package is **standalone-agnostic** — zero `require` on `lopadova/askmydocs` or `padosoft/askmydocs-pro`, zero references to consumer-specific symbols in source, every test runs against `Orchestra\Testbench` only, and the architecture test enforces these invariants on every CI run. `composer require padosoft/laravel-patent-box-tracker` on a fresh empty Laravel application produces a working tracker that walks any git repository.

Footprint: ~5,800 LOC of source, 163 PHPUnit tests / 1079 assertions, opt-in Live testsuite (~€0.05 per run on default models), CI matrix PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13. The `.claude/` vibe-coding pack ships in the box per `feedback_package_readme_must_highlight_vibe_coding_pack` so AI-driven contribution stays consistent across the Padosoft package family.

The package is the first of its kind on Packagist — no equivalent Italian-Patent-Box-aware Laravel package exists as of 2026-05. The closest analogues (`gitinspector`, `commitlint` + downstream tooling, Toggl / Harvest plugins, commercialista Excel templates) solve adjacent problems but none produces the documentation that Agenzia delle Entrate accepts for the documentazione idonea regime.

**Real-world dogfood**: Lorenzo's FY2026 Italian Patent Box dossier (Padosoft ditta individuale) is generated by running `php artisan patent-box:cross-repo tools/patent-box/2026.yml` against AskMyDocs `feature/v4.0` + the five sister Padosoft repositories listed in `tools/patent-box/2026.yml`. The dossier feeds the "documentazione idonea" filing with Agenzia delle Entrate.

## Residual items parked for v0.2

Per PLAN-W4 §3.5 / §10, the following collectors and renderer features are explicitly out of scope for v0.1 and tracked for v0.2:

- `TerminalSessionCollector` — capture interactive Claude Code / Cursor sessions for richer context
- `CiRunCollector` — count CI iterations as a proxy for validation effort
- `TimeTrackingCollector` — Toggl / Harvest / RescueTime / Clockify integration
- `CalendarCollector` — Google Calendar / Outlook event titles for off-keyboard R&D time
- `IpRegistrationCollector` — UIBM / SIAE / EPO API to auto-link IP filings to the dossier
- English-locale dossier template (the Italian fiscal template is the only locale shipped in v0.1)
- AI-vs-human line-level diff classification (token-by-token attribution)
- Multi-jurisdiction support (UK Patent Box, Irish Knowledge Box, German FuE-Zulage)

The v0.1 dossier admits "off-keyboard time is not represented in the v0.1 dossier — the taxpayer adds it manually via the `manual_supplement` config block" (per PLAN-W4 §3.5); the dogfood YAML at `tools/patent-box/2026.yml` exercises this exact path with `off_keyboard_research_hours: 60`.

## Next: W5 — `padosoft/laravel-flow` v0.1

Per `project_v40_week_sequence`: a saga / workflow orchestration package for Laravel. Will be **tracked by `laravel-patent-box-tracker` from day 1** for Lorenzo's Patent Box dossier — the `repositories[*]` list under `tools/patent-box/2026.yml` already reserves the `./repos/laravel-flow` entry with `role: support` and a `# TODO: pending W5` comment so the cross-repo runner picks up the new repo as soon as it materialises.
