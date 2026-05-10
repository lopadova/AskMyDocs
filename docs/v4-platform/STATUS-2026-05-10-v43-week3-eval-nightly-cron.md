# v4.3 Week 3 closure — 2026-05-10 — eval-harness LLM-as-judge nightly cron + ops polish

W3 of the v4.3 cycle ships the **host-level nightly eval-harness regression
sentinel**: a `eval:nightly` Artisan command + Laravel scheduler entry that
runs the full RAG pipeline against the seeded golden baseline once per day
at 05:30 UTC, optionally with `EVAL_LIVE_AI=1` to catch real-provider drift
(model behaviour, refusal-policy shifts, embedding-space changes) that the
PR-time CI gate cannot see because it pins `Http::fake()` for cost.

Defense-in-depth on cost: the master kill switch (`EVAL_NIGHTLY_ENABLED`)
gates the scheduler entry at all; a second independent fence
(`EVAL_NIGHTLY_LIVE`) gates the live-AI override even if the scheduler
fires. Both default OFF. A third fence inside the command refuses to run
in live mode if no provider key is present — so a misconfigured prod host
that flips the env knobs without provisioning the secret refuses cleanly
instead of silently billing tokens.

Persisted artefacts land in `storage/app/eval-harness/nightly/<YYYY-MM-DD>.json`
+ `<YYYY-MM-DD>.md` and are auto-pruned after `EVAL_NIGHTLY_RETENTION_DAYS`
(default 90). Regression detection compares the current run's `macro_f1`
against the most recent prior nightly (or, on first run, the most recent
PR-time baseline report) and emits `Log::alert()` + a forensic sidecar
`<date>.alert.json` when the delta exceeds `EVAL_NIGHTLY_REGRESSION_THRESHOLD`
(default 0.05 = 5%). Operators wire alert-channel notifications off the
log; no Notification class is shipped (rationale in ADR 0006).

This document is the W3 closure artefact per R39. Closure SHA pinned in
§RC tag below.

## Sub-PR shipped (v4.3 W3)

| Sub-PR | Reference PR | Closure SHA on `feature/v4.3` | Scope |
|---|---|---|---|
| **W3** — eval-harness nightly cron + ops polish + ADR 0006 | [#131](https://github.com/lopadova/AskMyDocs/pull/131) | `90c5784` | NEW `app/Console/Commands/EvalNightlyCommand.php` (4 ops flags: `--dry-run`, `--status`, `--prune-only`, plain run; two-fence cost guard `EVAL_NIGHTLY_ENABLED` scheduler gate + `EVAL_NIGHTLY_LIVE` provider-key check; writes dated JSON+MD report; computes delta vs prior baseline; fires `Log::alert()` + sidecar `<date>.alert.json` on regression; auto-prunes old reports beyond retention). NEW `app/Eval/Support/NightlyDeltaCalculator.php` (pure-PHP delta computation, returns `{macro_f1_prior, macro_f1_current, macro_f1_delta, regressed_metrics, improved_metrics}` or null for first run). NEW `app/Eval/Support/EvalHarnessRunner.php` (thin console-kernel wrapper to enable test-time substitution — Testbench's final `Console\Kernel` cannot be Mockery-mocked). 4 NEW env knobs all default OFF (`EVAL_NIGHTLY_ENABLED`, `EVAL_NIGHTLY_LIVE`, `EVAL_NIGHTLY_REGRESSION_THRESHOLD`, `EVAL_NIGHTLY_RETENTION_DAYS`). NEW scheduler entry in `bootstrap/app.php` at 05:30 UTC, gated by `EVAL_NIGHTLY_ENABLED`. NEW `docs/adr/0006-v43-nightly-eval-cron.md` documenting cost guard, alerting choice (`Log::alert` over Notification), retention, and host/package boundary rationale. |

**Cycle test count delta on `feature/v4.3` HEAD:** 1397 (start of W3 from W2 closure SHA `d83b95e`) → **1408** (end of W3) — **+11 new tests** (4 unit tests for NightlyDeltaCalculator + 6 feature tests for EvalNightlyCommand + 1 defense-in-depth test for the two-fence cost guard added in iter 2). All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest + Playwright E2E + the RAG regression workflow.

## R30 / R7 / R14 / R26 disciplines applied

- **R7**: every alerting path uses `Log::alert()` with structured context; no `@`-silenced errors; the cron is a safety net (R14 inversion), never load-bearing.
- **R14**: regression detection surfaces FAILURE LOUDLY through the alert log channel + the sidecar JSON; the run itself completes cleanly so the scheduler isn't poisoned by a single noisy night.
- **R16**: every test name matches what the body asserts. Iter 1 had one R16 violation (`test_command_refuses_when_live_disabled_without_provider_key` actually tested live-requested-without-key); iter 2 renamed to `test_command_refuses_when_live_requested_without_provider_key` so the contract reads correctly.
- **R26**: defense-in-depth test (`test_command_runs_in_fake_mode_when_eval_live_ai_set_but_nightly_live_false`) pre-seeds `eval-harness.askmydocs.live_ai=true` AND env `EVAL_LIVE_AI=1`, then asserts the command FORCES `live_ai=false` at runner-call time AND `Http::assertNothingSent()` proves no real provider HTTP traffic. Catches the iter-1 hole where the second fence was only WRITTEN when `$live=true`, leaving stale truthy config in flow.
- **R30/R31**: command goes through the existing `EvalRegistrar` which already pins `TenantContext` to 'default'. No new tenant scoping required.

## Default-off invariant preserved

All 4 new env knobs default `false`/sensible-default:

| Knob | Default | Purpose |
|---|---|---|
| `EVAL_NIGHTLY_ENABLED` | `false` | Master kill switch — when false, scheduler skips the entry entirely |
| `EVAL_NIGHTLY_LIVE` | `false` | Live-AI override — when false, the run uses `Http::fake()` exactly as the PR-time gate does |
| `EVAL_NIGHTLY_REGRESSION_THRESHOLD` | `0.05` | macro_f1 delta threshold for triggering an alert (5%) |
| `EVAL_NIGHTLY_RETENTION_DAYS` | `90` | Nightly report retention before auto-prune |

A v4.2 host upgrading to v4.3.0-rc3 sees byte-identical scheduler behaviour until they explicitly opt in via `EVAL_NIGHTLY_ENABLED=true`. Operators who want regression detection without live-AI cost can opt in to `EVAL_NIGHTLY_ENABLED=true` AND leave `EVAL_NIGHTLY_LIVE=false` — the cron then runs nightly against the fake providers and detects regressions in the LOCAL deterministic stub layer (caught the seeded-corpus drift PR #127's chat_logs observer would have surfaced earlier had nightly been on).

## R36 review-loop summary

PR #131 took **2 effective iterations** under the 5-iteration cap. Iter 1 surfaced 5 findings (2 HIGH + 1 MEDIUM + 2 LOW):

- HIGH — `--out` without `--raw-path` would resolve to `<prefix>/eval-harness/nightly/...` (default `eval-harness/reports/eval-harness/nightly/...`) while the lookups used the un-prefixed path. Fixed by adding `--raw-path => true` to both `eval-harness:run` invocations + new `absolutePathFor()` helper resolving via `Storage::disk()->path()`. Test gate added: stub-runner asserts `--raw-path => true` is in the options array.
- HIGH — Two-fence cost guard incomplete: `Config::set('eval-harness.askmydocs.live_ai', true)` only fired when `$live` was true, leaving a stale truthy config value if the env had `EVAL_LIVE_AI=1`. Fixed by always writing the value (both branches) + the R26 defense-in-depth test described above.
- MEDIUM — Reports path was hard-coded as `eval-harness/reports`. Fixed by reading `eval-harness.reports.path_prefix` from config; only `nightly/` stays a fixed convention.
- LOW (R9) — Scheduler comment in `bootstrap/app.php` claimed "nightly batch profile + adversarial subset" but the command runs only the baseline. Comment rewritten to match actual behaviour.
- LOW (R16) — Test name `test_command_refuses_when_live_disabled_without_provider_key` actually tested live-REQUESTED-without-key. Renamed.

Iter 2 (`34ef4e8`) addressed all 5 + added the new R26 test (1407 → 1408 tests). Copilot iter 2 confirmed addresses ("Copilot findings are already addressed in commit 34ef4e8"). Merged at iteration 3 closure with all CI green and 0 outstanding must-fix.

## R39 RC tag

```bash
CLOSURE_SHA=$(git rev-parse origin/feature/v4.3)
gh release create v4.3.0-rc3 \
  --repo lopadova/AskMyDocs \
  --target "$CLOSURE_SHA" \
  --title "v4.3.0-rc3 — W3 milestone (eval-harness nightly cron + ops polish)" \
  --prerelease \
  --notes "Host-level nightly eval-harness regression sentinel. NEW eval:nightly Artisan command + Laravel scheduler entry at 05:30 UTC, default-OFF via EVAL_NIGHTLY_ENABLED. Two-fence cost guard (EVAL_NIGHTLY_ENABLED scheduler gate + EVAL_NIGHTLY_LIVE provider-key check) + third fence inside the command (refuses live mode without provider key). Writes dated JSON+MD report to storage/app/eval-harness/nightly/, computes delta vs prior baseline, fires Log::alert() + sidecar <date>.alert.json on regression > EVAL_NIGHTLY_REGRESSION_THRESHOLD (default 0.05). Auto-prunes old reports beyond EVAL_NIGHTLY_RETENTION_DAYS (default 90). 3 ops flags: --dry-run, --status, --prune-only. ADR 0006 documents cost guard, alerting choice (Log::alert over Notification), retention, host/package boundary rationale. 1 sub-PR (#131). +11 PHPUnit tests (1397 -> 1408). Closure: docs/v4-platform/STATUS-2026-05-10-v43-week3-eval-nightly-cron.md"
```

## What's next — W4

`v4.3.0` GA will close W4 and merge `feature/v4.3` → `main` (per R37 once-per-major event): final ADR + INTEGRATION-ROADMAP refresh + status doc + GA tag at the merge SHA. v4.3 cycle deliverables summary:

- **W1** — PII redactor comprehensive boundary coverage (7 persistence-boundary touch-points + 6 admin-readiness inspectors + 5 default-OFF env knobs)
- **W2** — React 19 host bump (`react` 18.3.1 → 19.2.6) + ADR 0005 deferring Tailwind v4 + cross-mount to v4.4
- **W3** — eval-harness nightly cron + ops polish + ADR 0006 — host-level regression sentinel default-OFF
