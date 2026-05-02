# v4.0 Week 5 closure — 2026-05-02

W5 deliverable per `project_v40_week_sequence`: **`padosoft/laravel-flow` v0.1.0 — standalone Apache-2.0 saga / compensation engine for Laravel.** Fluent definition API, reverse-order compensation chain (best-effort with aggregated `FlowCompensationException`), native dry-run mode, four Laravel events, in-memory synchronous engine; persistent runs + queued workers parked for v0.2.

The package fills a genuine gap on Packagist — `spatie/laravel-workflow` is dormant, the Symfony Workflow component is state-machine-only with no compensation primitive, and Temporal / AWS Step Functions are out-of-process orchestrators. `padosoft/laravel-flow` is the first in-process saga library that ships Laravel-native event semantics + container-resolved step handlers + a fluent builder out of the box.

## Sub-tasks shipped

| Sub-task | PR | Merge SHA on `main` | Outcome |
|---|---|---|---|
| W5.0 — minimal scaffold + test-count skill | laravel-flow #2 | `9bcab00` | `composer.json` baseline, PHPUnit scaffold, `test-count-readme-sync` skill imported from the Padosoft baseline |
| W5.A + W5.B — full scaffold expansion + Flow engine core | laravel-flow #3 | `208a9d1` | The combined PR — W5.A (full Padosoft `.claude/` vibe-coding pack, CI matrix on PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13, `phpunit.xml` Unit + Architecture + opt-in Live, `pint.json` + `phpstan.neon.dist`, `LaravelFlowServiceProvider` binding `FlowEngine` as a container singleton, `config/laravel-flow.php` with five tunables, 14-section WOW README) plus W5.B (`FlowEngine` definition registry + `define` / `execute` / `dryRun`; `FlowDefinitionBuilder` fluent API with `withInput` / `step` / `compensateWith` / `withDryRun` / `register`; readonly DTOs `FlowDefinition`, `FlowStep`, `FlowContext`, `FlowStepResult`, `FlowRun`; `FlowStepHandler` + `FlowCompensator` interfaces resolved via the Laravel container; reverse-order compensation chain with aggregated `FlowCompensationException`; four events `FlowStepStarted` / `FlowStepCompleted` / `FlowStepFailed` / `FlowCompensated`; non-final `FlowException` parent class per the W4.C lesson; `Flow` Facade alias) |
| W5.C — Laravel 13 baseline narrowing | laravel-flow #4 + #5 + #6 + #7 + #8 | `79bf7c2` + `bce7655` + `13c370b` + `1b8a5ae` + `d39afa9` (all on `main`) | Sequence of governance / CI / docs PRs locking the package onto a Laravel-13-only baseline and routing Pint / PHPStan / PHPUnit through canonical Composer scripts (`composer quality`); README + CONTRIBUTING + PR template + lessons file refreshed; package post-condition: 32 Unit tests / 97 assertions + 2 Architecture tests / 7 assertions on Laravel 13 |

## Acceptance gates passed

- CI matrix on every laravel-flow PR — PHP 8.3 / 8.4 / 8.5 (Laravel 12 / 13 on the W5.A+B push, narrowed to Laravel 13 only after W5.C) plus Pint + PHPStan level 6 + the architecture suite — converged GREEN before merge on every PR.
- Test count post-W5 push: **30 PHPUnit tests / 90 assertions** at the W5.A+B merge moment (`FlowDefinitionBuilderTest` 8 tests, `FlowEngineTest` 8, `FlowEngineCompensationTest` 4, `FlowEventEmissionTest` 4, `FlowFacadeTest` 4, plus the pre-existing `ServiceProviderTest` 2). The W5.C governance series later landed `composer quality` and a baseline-renarrow that lifts the post-merge count to 32 Unit tests / 97 assertions on Laravel 13. The architecture suite ships 2 tests / 7 assertions enforcing the standalone-agnostic invariant via `RecursiveDirectoryIterator` over `src/` (per the W4.B.1 lesson — `glob('**/*.php')` does NOT recurse).
- Standalone-agnostic invariant maintained throughout — zero references to `KnowledgeDocument`, `KbSearchService`, `kb_*` tables, `lopadova/askmydocs`, or any other sister Padosoft package in `src/`. `composer.json` declares only first-party Laravel + framework deps and a `suggest` entry pointing at `padosoft/laravel-patent-box-tracker`. Architecture test grep walks every `.php` file under `src/`.
- Copilot Code Review converged to **0 outstanding must-fix** on every W5 PR after the R36 loop.
- The fluent builder API documented in the README quick-start round-trips end-to-end through the unit suite — `FlowEngineCompensationTest` exercises the failed-step compensation chain with a multi-step definition and asserts the aggregated `FlowCompensationException` carries every individual compensator's failure. README drift is structurally hard.

## Lessons captured during W5

The lessons below are codified back into the AskMyDocs `.claude/skills/` pack and into agent memory so future PRs (on AskMyDocs and on every padosoft/* repo) inherit the fixes.

- **Greenfield packages do not need micro-PRs**. The W5 design originally split the scaffold (W5.A) and the engine (W5.B) into separate PRs; merging them as one (`laravel-flow #3`) was the right call because the engine is small enough that splitting felt artificial and the scaffold-only PR would have shipped a non-functional package. Recommendation parked: for any future greenfield Padosoft package whose v0.1 surface is ≤ ~1000 LOC of source + ~30 tests, ship as a single PR.
- **`Eval` / `Flow` / `Pii` / `Auth` are reserved or near-reserved PHP keywords**. `padosoft/eval-harness` (W6) had to name its facade `EvalFacade` because `Eval` is a PHP-reserved word; `padosoft/laravel-flow` got away with `Flow` because PHP reserves only `Eval`. Lesson recorded for future Padosoft packages: facade-name collisions with PHP reserved-words must be checked against `php -r 'class Eval {}'` before opening the PR.
- **Subsequent governance PRs (`laravel-flow #4..#8`) belong INSIDE the same week's closure narrative**, not to a follow-up week. The Macro Task 0 / Macro Task 1 sequence renarrowed the matrix and reorganised the canonical quality contract; on the AskMyDocs side those PRs are part of the W5 deliverable because they shipped before W5 closed and share the same fiscal week boundary. Future weeks: do not roll governance PRs into a "W{n}.5" or "W{n+1}.0" sub-bucket; either they ship inside the week's window (then they're part of W{n}'s closure narrative) or they do not (then they wait for the next week).
- **The W4.C non-final-exception lesson held**. Every W5 exception class (`FlowException` parent, plus `FlowInputException` / `FlowNotRegisteredException` / `FlowExecutionException` / `FlowCompensationException` subclasses) follows the pattern: parent is non-final, subclasses use Pint's `single_line_empty_body` for empty body. PHPStan + Pint both pass with no diff.
- **The Padosoft live-test pattern shipped clean on first try**. `tests/Live/PlaceholderLiveTest.php` self-skips when `LARAVEL_FLOW_LIVE` is unset. Per `feedback_package_live_testsuite_opt_in`, the placeholder test is the right pattern for engine-only packages that will eventually grow Live coverage when the engine acquires external integrations (queued workers in v0.2).

## Production impact

W5 ships `padosoft/laravel-flow` v0.1.0 to Packagist. The tag landed on `padosoft/laravel-flow` `main` after the W5.C governance sequence merged at `d39afa9`; the GitHub release is published and the package is installable via `composer require padosoft/laravel-flow:^0.1`.

The package is **standalone-agnostic** — zero `require` on `lopadova/askmydocs`, `padosoft/askmydocs-pro`, or any other sister Padosoft package in `src/`. `composer.json` declares only first-party Laravel components, every test runs against `Orchestra\Testbench` only, and the architecture test enforces these invariants on every CI run. `composer require padosoft/laravel-flow` on a fresh empty Laravel application produces a working in-memory saga engine.

Footprint: ~3,200 LOC of source, 32 Unit tests / 97 assertions + 2 Architecture tests / 7 assertions on Laravel 13, opt-in Live testsuite (placeholder for v0.2 when queued-worker coverage lands), CI matrix PHP 8.3 / 8.4 / 8.5 × Laravel 13. The `.claude/` vibe-coding pack ships in the box per `feedback_package_readme_must_highlight_vibe_coding_pack` so AI-driven contribution stays consistent across the Padosoft package family.

**Real-world dogfood**: AskMyDocs's `tools/patent-box/2026.yml` — Lorenzo's FY2026 Italian Patent Box dossier — already lists `laravel-flow` under `repositories[*]` with `role: support`. The cross-repo runner picks up the package as soon as it materialises on disk; W5 is the materialisation. The dossier renderer will surface every qualifying `laravel-flow` commit in the FY2026 documentazione idonea filing.

## Residual items parked for v0.2

Per the W5.A+B PR body and the README §10 Roadmap:

- Persisted runs (`flow_runs` / `flow_steps` / `flow_audit` Eloquent models + migrations + run-history Artisan command).
- Queued workers + replay command (`flow:replay <run-id>`).
- Parallel compensation strategy (currently falls back to reverse-order with a documented note).
- Web dashboard (read-only run timeline + step-level drill-down).
- `withAggregateCompensator` is reserved on the builder API but currently delegates to the per-step reverse-order chain.

## Next: W6 — `padosoft/eval-harness` v0.1

Per `project_v40_week_sequence`: a Laravel-native LLM / RAG evaluation harness — pluggable golden-dataset YAML loader + extensible metric registry (R23) + report renderers (Markdown + canonical JSON). The detailed W6 closure narrative lives under `docs/v4-platform/STATUS-2026-05-02-week6.md`; this W5 doc is unaffected and stays scoped to the Flow engine deliverable.
