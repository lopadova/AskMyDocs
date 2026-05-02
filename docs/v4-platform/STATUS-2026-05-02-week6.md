# v4.0 Week 6 closure — 2026-05-02

W6 deliverable per `project_v40_week_sequence`: **`padosoft/eval-harness` v0.1.0 — standalone Apache-2.0 RAG / LLM evaluation framework for Laravel.** Pluggable golden-dataset YAML loader, R23-style metric registry with FQCN validation at boot, three first-party metrics (ExactMatch, CosineEmbedding, LlmAsJudge), two report renderers (Markdown for diff-friendly review + canonical JSON for machine consumption), `eval-harness:run` Artisan command, deterministic-by-default execution.

The package fills a real gap on Packagist for Laravel teams running RAG / agent stacks. Comparable tooling exists outside the PHP ecosystem (`promptfoo`, `Ragas`, OpenAI Evals, LangSmith, DeepEval) but each is either Python-first, hosted-only, or ships as a CLI binary detached from the host application. `padosoft/eval-harness` is the first in-process Laravel evaluation framework that resolves metrics through the container, integrates with `laravel/ai` for LlmAsJudge runs, and ships an additive-only canonical-JSON report contract per R27.

## Sub-tasks shipped

| Sub-task | PR | Merge SHA on `main` | Outcome |
|---|---|---|---|
| W6.0 — minimal scaffold + test-count skill | eval-harness #2 | `1b9c691` | `composer.json` baseline, PHPUnit scaffold, `test-count-readme-sync` skill imported from the Padosoft baseline |
| W6.A — full scaffold expansion + Eval engine core | eval-harness #3 | `7012aa2` | Full Padosoft `.claude/` vibe-coding pack (skills + rules + agents + COMPANY-* docs imported from `laravel-patent-box-tracker`); CI matrix on PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 with concurrency cancel + Pint + PHPStan level 6 + Unit + Architecture jobs; `composer.json` trimmed to runtime deps (`illuminate/support`, `illuminate/console`, `illuminate/http`, `laravel/ai ^0.6`, `symfony/yaml ^7|^8`) plus a `suggest` entry for `padosoft/laravel-ai-regolo`; `phpunit.xml` Unit (default) + Architecture + opt-in Live testsuites; 16-section WOW README with comparison vs OpenAI Evals / LangSmith / Ragas / Promptfoo / DeepEval; `EvalEngine` orchestrator (sequential + deterministic; per-`(sample, metric)` failures captured rather than thrown); `DatasetBuilder` fluent API (`loadFromYaml`, `loadFromYamlString`, `withSamples`, `withMetrics`, `register`); readonly DTOs (`GoldenDataset`, `DatasetSample`, `ParsedDatasetDefinition`, `MetricScore`); strict-schema `YamlDatasetLoader` with 11 distinct validation failure modes; `Metric` interface + `MetricResolver` R23 registry (alias / FQCN / instance, with concrete-class FQCN validation at registration); `ExactMatchMetric` (byte-equality), `CosineEmbeddingMetric` (`1 - cosine_distance` with dimensionality + zero-vector guards), `LlmAsJudgeMetric` (deterministic seed + temp 0 + `response_format=json_object` + strict-JSON parser with code-fence fallback); `EvalReport` aggregations (mean / p50 / p95 / macroF1); `MarkdownReportRenderer` (diff-friendly) + `JsonReportRenderer` (additive-only contract per R27); `EvalCommand` (`php artisan eval-harness:run <dataset> --registrar=FQCN --json --out=path`, exits non-zero on captured failures); `EvalFacade` registered with the user-facing alias `Eval` via `extra.laravel.aliases` (the class is named `EvalFacade` because `Eval` is a reserved PHP keyword); non-final `EvalHarnessException` parent class per the W4.C lesson + `DatasetSchemaException` + `MetricException` + `EvalRunException` |
| W6.B — governance plan + Macro Task 0 baseline lock | eval-harness #4 + #5 | `a36379d` + `fdc753c` | Adds agent / Claude / Copilot guidance, repo-local skill, rules, progress, lessons; locks roadmap assumptions (Laravel `^12|^13` at the time, PHP `^8.3`, Horizon-ready queues with `sync` / fake tests, headless APIs for a separate UI package); makes PHPStan + Pint blocking in CI; updates PR template; carries forward the existing scaffold / test-count work; CI rollup post-merge: `OK (2 tests, 3 assertions)` on the governance-only PR plus the engine PR's prior `87 tests, 180 assertions` Unit + `3 tests, 347 assertions` Architecture |
| W6.C — image optimisation | eval-harness #7 | `16f1e1e` | ImgBot housekeeping pass on README assets — non-functional, included for completeness |

## Acceptance gates passed

- CI matrix on every eval-harness PR — PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 (6 cells per PR) plus Pint + PHPStan level 6 + the architecture suite — converged GREEN before merge on every PR.
- Test count post-W6.A merge: **87 PHPUnit unit tests / 180 assertions** across 12 test classes, plus **3 architecture tests / 347 assertions** in `StandaloneAgnosticTest` enforcing zero AskMyDocs / sister Padosoft package symbol leakage in `src/`. Plus the opt-in `tests/Live/LiveLlmAsJudgeTest.php` self-skipping on missing `EVAL_HARNESS_LIVE_API_KEY` per `feedback_package_live_testsuite_opt_in`.
- Standalone-agnostic invariant maintained throughout — zero references to `KnowledgeDocument`, `KbSearchService`, `kb_*` tables, `lopadova/askmydocs`, `padosoft/laravel-patent-box-tracker`, `padosoft/laravel-flow`, or `padosoft/askmydocs-pro` in `src/`. `composer.json` declares only first-party Laravel components + `laravel/ai ^0.6` + `symfony/yaml ^7|^8`. The architecture test grep walks every `.php` file under `src/`.
- Copilot Code Review converged to **0 outstanding must-fix** on every W6 PR after the R36 loop.
- The metric registry's R23 mutex contract (no two registered metrics may both `supports()` the same `(sample, metric_alias)` tuple — first-match-wins resolution otherwise silently picks the wrong one) is enforced at boot via `MetricResolver`'s concrete-class FQCN validation, exercised by the unit suite.
- The additive-only canonical JSON report contract (R27) is exercised by the unit suite — adding a new field to a metric's score payload extends `MetricScore::extras` rather than mutating an existing key, so machine consumers built against v0.1 do not break across minor releases.

## Lessons captured during W6

The lessons below are codified back into the AskMyDocs `.claude/skills/` pack and into agent memory so future PRs (on AskMyDocs and on every padosoft/* repo) inherit the fixes.

- **Reserved-word collision on facades**. `Eval` is a reserved PHP keyword; declaring `final class Eval extends Facade {}` triggers a parse error. The package routes around it by naming the class `EvalFacade` and registering the user-facing alias `Eval` through `extra.laravel.aliases` in `composer.json` — the alias is registered at framework boot, not as a real class declaration, so the keyword collision never fires. Future Padosoft packages should `php -r 'class XYZ {}'` to validate any candidate facade name before merging the scaffold PR.
- **R23 metric resolver applies to evaluation as well as ingestion**. The pattern Lorenzo originally codified for the AskMyDocs `PipelineRegistry` (FQCN validation + `supports()` mutex enforcement at boot) generalises cleanly to the eval-harness metric registry: any first-match-wins registry that resolves user-supplied aliases must validate FQCNs at registration AND check that no two registered classes claim overlapping `supports()` predicates. Recorded in skill `pluggable-pipeline-registry` (existing) — the pattern is now framework-grade, not RAG-specific.
- **Strict-JSON parser MUST tolerate code fences**. `LlmAsJudgeMetric` calls the model with `response_format=json_object` for strict mode but still wraps the parser in a `code_fence_fallback` branch that strips ```` ```json ... ``` ```` envelopes when the model insists on emitting them. The lesson: even with strict-mode flags set, real-world model output drifts; the parser must own the tolerance, not assume the API contract holds 100% of the time.
- **Live testsuite opt-in pattern keeps cost out of CI**. `LiveLlmAsJudgeTest` self-skips on missing `EVAL_HARNESS_LIVE_API_KEY` so CI never burns LLM credits, but operators who export the env var get a real round-trip against a real provider. Replicates the pattern from `padosoft/laravel-ai-regolo` and is now the standing requirement for any Padosoft package with an external API surface (`feedback_package_live_testsuite_opt_in`).
- **Macro Task 0 governance series belongs to the same week**. PRs #4 + #5 landed governance + roadmap-locking AFTER the engine merge but BEFORE W6 closed; the closure narrative includes them as part of W6 deliverables. Same lesson the W5 closure recorded — governance PRs do NOT spawn a "W{n}.5" or "W{n+1}" sub-bucket.

## Production impact

W6 ships `padosoft/eval-harness` v0.1.0 to Packagist. The tag landed on `padosoft/eval-harness` `main` after the W6.A engine PR merged at `7012aa2`; the GitHub release is published and the package is installable via `composer require padosoft/eval-harness:^0.1`.

The package is **standalone-agnostic** — zero `require` on `lopadova/askmydocs`, `padosoft/askmydocs-pro`, `padosoft/laravel-patent-box-tracker`, `padosoft/laravel-flow`, or any other sister Padosoft package. `composer require padosoft/eval-harness` on a fresh empty Laravel application produces a working evaluation harness that loads a YAML golden dataset and runs the three first-party metrics against it.

Footprint: ~3,600 LOC across ~80 PHP files, 87 Unit tests / 180 assertions + 3 Architecture tests / 347 assertions, opt-in Live testsuite. CI matrix PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13. The `.claude/` vibe-coding pack ships in the box per `feedback_package_readme_must_highlight_vibe_coding_pack`.

**Real-world dogfood**: AskMyDocs's `tools/patent-box/2026.yml` — Lorenzo's FY2026 Italian Patent Box dossier — already lists `eval-harness` under `repositories[*]` with `role: support`. The cross-repo runner picks up the package as soon as it materialises on disk; W6 is the materialisation. Forward-looking: `padosoft/eval-harness` is the natural home for the future AskMyDocs RAG quality regression suite — moving the `tests/Feature/Kb/RetrievalQualityTest` style assertions from in-tree PHPUnit to a shared eval-harness golden dataset is a v4.1 candidate.

## Residual items parked for v0.2

Per the W6.A PR body and the README §16 Roadmap:

- Additional metrics: `BleuScore`, `RougeL`, `LevenshteinNormalized`, `EmbeddingSimilarityKnn`, `Faithfulness` (Ragas-style), `AnswerRelevance` (Ragas-style).
- Persistent run history (`eval_runs` / `eval_samples` / `eval_scores` Eloquent models + migrations + history Artisan command).
- Web dashboard (read-only run-comparison + score-distribution charts).
- HTML report renderer (current renderers: Markdown + canonical JSON only).
- Inline `Eval` Facade (currently the alias is registered through `extra.laravel.aliases`; v0.2 may ship a real-class facade if PHP 8.5 relaxes the reserved-word block).

## Next: W7 — `padosoft/laravel-pii-redactor` v0.1 + `padosoft/askmydocs-pro` foundation

Per `project_v40_week_sequence`: a regex + checksum based PII redaction layer for Italian fiscal identifiers (Codice Fiscale, Partita IVA, IBAN), plus the BSL-1.1 private foundation seed for `padosoft/askmydocs-pro` so the v4 sister-package release train has a target the CE platform can graduate users into. The detailed W7 closure narrative lives under `docs/v4-platform/STATUS-2026-05-02-week7.md`; this W6 doc is unaffected and stays scoped to the Eval engine deliverable.
