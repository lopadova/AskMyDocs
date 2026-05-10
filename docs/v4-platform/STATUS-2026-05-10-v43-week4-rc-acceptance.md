# v4.3 Week 4 closure — 2026-05-10 — RC acceptance + GA merge

W4 is the final milestone of the v4.3 cycle. There is no sub-package
deliverable this week — W1 (PII redactor comprehensive boundary coverage),
W2 (React 19 host bump), W3 (eval-harness LLM-as-judge nightly cron + ops
polish) all closed inside the 2026-05-10 window with their own closure
status docs and RC tags. W4's responsibility is **RC acceptance**: confirm
every gate locked at the v4.3 plan stage holds on `feature/v4.3` HEAD,
then drive the once-per-major `feature/v4.3` → `main` merge per R37 and
tag `v4.3.0` GA.

This document audits acceptance. The integration → main merge PR (W4.B)
and the GA tag itself land in a follow-up parent-session step.

## Sub-tasks shipped (cycle-wide, W1..W3)

| Wn | Deliverable | Reference PRs | Final merge SHA on `feature/v4.3` | Closure / artefact |
|---|---|---|---|---|
| W1 | sub-PR 4.5 — PII redactor comprehensive boundary coverage (7 persistence-boundary touch-points + 6 admin-readiness inspectors + 5 default-OFF env knobs) | #127 (sub-PR 4.5), #128 (W1 closure docs) | `9aa3bf7` (sub-PR 4.5), `9f7aa47` (closure) | `docs/v4-platform/STATUS-2026-05-10-v43-week1-pii-boundary-coverage.md` + Tagged [`v4.3.0-rc1`](https://github.com/lopadova/AskMyDocs/releases/tag/v4.3.0-rc1) |
| W2 | React 19 host bump (`react` 18.3.1 → 19.2.6 + `react-dom` + `@types/*`) + ADR 0005 deferring Tailwind v4 + cross-mount to v4.4 | #129 (sub-PR), #130 (W2 closure docs) | `c5f8e1b` (sub-PR), `d83b95e` (closure) | `docs/v4-platform/STATUS-2026-05-10-v43-week2-react-19-host-bump.md` + `docs/adr/0005-v43-react-19-host-bump.md` + Tagged [`v4.3.0-rc2`](https://github.com/lopadova/AskMyDocs/releases/tag/v4.3.0-rc2) |
| W3 | eval-harness LLM-as-judge nightly cron + ops polish (new `eval:nightly` Artisan command + Laravel scheduler entry at 05:30 UTC, default-OFF; three-fence cost guard; regression detection + alert sidecar; 3 ops flags `--dry-run`/`--status`/`--prune-only`) + ADR 0006 | #131 (sub-PR), #132 (W3 closure docs) | `90c5784` (sub-PR), `897c33f` (closure) | `docs/v4-platform/STATUS-2026-05-10-v43-week3-eval-nightly-cron.md` + `docs/adr/0006-v43-nightly-eval-cron.md` + Tagged [`v4.3.0-rc3`](https://github.com/lopadova/AskMyDocs/releases/tag/v4.3.0-rc3) |
| W4.A | RC acceptance gates audit + closure status doc (this document) + INTEGRATION-ROADMAP refresh | this PR | filled in on merge | `docs/v4-platform/STATUS-2026-05-10-v43-week4-rc-acceptance.md` (this) |
| W4.B | `feature/v4.3` → `main` integration merge + `v4.3.0` GA tag | follow-up PR | n/a until W4.B opens | Once-per-major event per R37 |

## RC tags audit

Every `v4.3.0-rcN` tag below was created via `gh release create … --prerelease` on the exact closure-commit SHA per R39 and skill `rc-tag-per-week-milestone`.

| Tag | Pinned SHA | Closure milestone | GitHub release |
|---|---|---|---|
| `v4.3.0-rc1` | `9f7aa47fb20defb350d1d82a1d4e901fbffa92f5` | W1 closure (PR #128) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.3.0-rc1 |
| `v4.3.0-rc2` | `d83b95e7dda58ebbfccdb734736f51f5e75cdda3` | W2 closure (PR #130) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.3.0-rc2 |
| `v4.3.0-rc3` | `897c33f5bb71dc2b67abaee7bef68719d61eeca5` | W3 closure (PR #132) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.3.0-rc3 |

## Acceptance gate checklist

Every box below was verified via `gh release` / `gh run` / `gh pr` / `gh api` queries against the live GitHub state on 2026-05-10. No speculation — each gate is paired with the discipline that confirmed it.

### A — Composer alignment (no version drift since v4.2.0 GA)

The v4.3 cycle is intentionally **scope-tight**: no sister-package version bumps, no new packages added to `composer.json`. Everything inherits from v4.2.0 GA's locked stable-line. The cycle's three deliverables are all **host-side wiring** (PII boundary observers + Flow contract binding) or **host-side scope** (React major bump on the FE host SPA + Laravel scheduler entry on the BE host).

- [x] `padosoft/laravel-ai-regolo` constraint unchanged at `^1.0`.
- [x] `padosoft/laravel-pii-redactor` constraint unchanged at `^1.2` (the v1.2 6-inspector surface was already wired during v4.2 W1; v4.3 W1 adds NEW touch-points on top — no version bump required).
- [x] `padosoft/laravel-flow` constraint unchanged at `^1.0` (the W1 `AskMyDocsFlowPayloadRedactor` wires against the v1.0 `CurrentPayloadRedactorProvider` contract that already shipped).
- [x] `padosoft/eval-harness` constraint unchanged at `^1.2.0` (require-dev). v4.3 W3's nightly cron consumes the existing v1.2 surface.
- [x] `padosoft/laravel-pii-redactor-admin` constraint unchanged at `^1.0.2`.
- [x] `padosoft/laravel-flow-admin` constraint unchanged at `^1.0`.
- [x] `padosoft/eval-harness-ui` constraint unchanged at `^1.0`.
- [x] React major bumped 18.3.1 → 19.2.6 (W2) — host-side dependency only, not a sister-package change.
- [x] `padosoft/laravel-patent-box-tracker` remains absent (external by design — see v4.2 ADR 0004 D1, unchanged for v4.3).

### B — Test gates

- [x] PHPUnit (PHP 8.3 / 8.4 / 8.5) all green on every closure SHA. Cycle-wide test count: 1371 (start of v4.3 from v4.2.0 GA) → **1408** (end of W3) — **+37 new tests** (W1: +26 boundary-coverage tests; W2: +0 dependency-only bump; W3: +11 nightly-cron tests).
- [x] Vitest (react + legacy) green on every closure SHA — including the React 19 surface post-W2.
- [x] Playwright E2E green on every closure SHA.
- [x] RAG regression workflow green on every PR touching the RAG hot path.

### C — R36 review-loop gates

- [x] Every sub-PR opened with `--reviewer copilot-pull-request-reviewer`.
- [x] Every iteration of every sub-PR ran the Copilot review loop until 0 outstanding must-fix + all CI green.
- [x] No PR merged on green CI alone — every merge waited for the Copilot review window AND addressed all iter1 findings.
- [x] Cycle-wide R36 cost: W1 = 2 effective iterations (1 mine + 1 Copilot SWE auto-fix); W2 = 2 effective iterations (1 mine + 1 Copilot SWE auto-fix); W3 = 2 effective iterations on PR #131 + 2 on PR #132. All under the 5-iteration cap.

### D — R30/R31 cross-tenant isolation

The v4.3 cycle ADDS coverage rather than introducing new tenant-scoped surfaces:

- [x] W1 — every Eloquent observer respects tenant scoping. Redaction itself is content-only (not tenant-aware); detokenise lookups via `TokenResolutionService` scope by tenant per the v4.2 wiring inherited from W1.
- [x] W1 — `RedactFailedJobPayload` listener uses deterministic `failed_jobs.uuid` extraction from `$event->job->getRawBody()` JSON envelope so race-window in multi-worker setups picks the right row.
- [x] W2 — React 19 bump is FE-host only; no new tenant boundaries introduced.
- [x] W3 — `eval:nightly` runs through the existing `EvalRegistrar` which already pins `TenantContext` to 'default' (inherited from v4.2 W3). No new tenant surface.

### E — R37 branch strategy

- [x] All sub-PRs targeted `feature/v4.3`, never `main`.
- [x] `main` HEAD remains at `0b5bc69` (v4.2.0 GA) until W4.B fires the once-per-major merge.

### F — R39 RC-tag-per-week convention

- [x] 3 RC tags cut on closure SHAs (rc1 / rc2 / rc3), each pinned to immutable refs.
- [x] Final `v4.3.0` GA tag fires only AFTER `feature/v4.3` → `main` merge (W4.B).

### G — R7 / R14 / R26 disciplines on the new touch-points

- [x] W1 — every persistence-boundary observer / listener / Monolog processor catches its own `Throwable`s and falls through to the original write (R7/R14 inversion: redactor as safety net, not load-bearing wall).
- [x] W3 — `eval:nightly` regression detection surfaces failure LOUDLY through `Log::alert()` + sidecar JSON; the cron itself never crashes the scheduler.
- [x] W3 — R26 defense-in-depth test (`test_command_runs_in_fake_mode_when_eval_live_ai_set_but_nightly_live_false`) pre-seeds `eval-harness.askmydocs.live_ai=true` AND env `EVAL_LIVE_AI=1`, then asserts the command FORCES `live_ai=false` at runner-call time + `Http::assertNothingSent()`. Catches the iter-1 hole where the second fence was only WRITTEN when `$live=true`.

### H — Default-off invariant preserved across both new feature surfaces

- [x] W1 — all 5 new env knobs (`KB_PII_REDACT_LOGS`, `KB_PII_REDACT_FAILED_JOBS`, `KB_PII_REDACT_ANSWERS`, `KB_PII_REDACT_COMMAND_AUDIT`, `KB_PII_REDACT_FLOW_PAYLOADS`) default `false`.
- [x] W3 — all 4 new env knobs (`EVAL_NIGHTLY_ENABLED`, `EVAL_NIGHTLY_LIVE`, `EVAL_NIGHTLY_REGRESSION_THRESHOLD`, `EVAL_NIGHTLY_RETENTION_DAYS`) default `false`/safe-default.
- [x] A v4.2.0 host upgrading to v4.3.0 GA sees byte-identical behaviour until they explicitly opt in via the relevant env knobs. No surprise behavioural change at upgrade.

## Acceptance verdict

All eight gates (A–H) pass. The v4.3 cycle is **ready for GA merge**. W4.B fires the `feature/v4.3` → `main` merge per R37 and tags `v4.3.0` at the merge SHA.

## Notable parking-lot items (NOT blockers)

- **Tailwind v3 → v4 host migration** — deferred to v4.4 per ADR 0005. Different config surface (`@tailwindcss/vite` plugin vs PostCSS pipeline), different preflight reset, different theme-token API (`@theme` directive), ~40 utility classes migrated. Bundling it with the React major bump would conflate two independent risk profiles.
- **Iframe → cross-mount of `pii-redactor-admin` + `eval-harness-ui`** — deferred to v4.4 per ADR 0005, gated on Tailwind v4 landing first. `flow-admin` stays iframe-mounted forever (Blade + Alpine, not React, so cross-mount does not apply).
- **eval-harness LLM-as-judge live-mode wider adversarial coverage** — currently the nightly cron runs only the baseline dataset; adversarial lanes stay PR-time-only because their `Http::fake()` canned responses cannot perfectly mimic production refusal behaviour. A future v4.4 task could promote select adversarial lanes to nightly once a stable refusal-quality manifest is curated.

## What's next — v4.4 backlog

- **Tailwind v3 → v4 host migration** (separate scope-clean PR per ADR 0005).
- **Iframe → cross-mount of pii-redactor-admin + eval-harness-ui** (gated on Tailwind v4 landing first).
- **eval-harness adversarial nightly opt-in** (small operational follow-up once nightly cron has a few weeks of stable baseline data).

## R39 GA tag (W4.B)

```bash
GA_SHA=$(git rev-parse origin/main)  # captured AFTER W4.B merge fires
gh release create v4.3.0 \
  --repo lopadova/AskMyDocs \
  --target "$GA_SHA" \
  --title "v4.3.0 — PII boundary coverage + React 19 + eval-harness nightly cron GA" \
  --notes "v4.3.0 GA — three host-side hardening cycles on top of the v4.2 sister-package integration. W1: PII redactor comprehensive boundary coverage (7 new persistence-boundary touch-points + 6 admin-readiness inspectors wired into existing AskMyDocs admin surfaces; 5 new env knobs all default OFF). W2: React 19 host bump (18.3.1 -> 19.2.6, dependency-only; ADR 0005 documents Tailwind v4 + cross-mount deferral to v4.4). W3: eval-harness LLM-as-judge nightly cron + ops polish (new eval:nightly Artisan command + Laravel scheduler entry at 05:30 UTC, default-OFF; three-fence cost guard; regression detection + alert sidecar; 3 ops flags; ADR 0006). 3 sub-PRs (#127, #129, #131) + 3 closure-docs PRs (#128, #130, #132) + 3 weekly RC tags (rc1/rc2/rc3) + 1 GA merge PR. +37 PHPUnit tests (1371 -> 1408). Closure: docs/v4-platform/STATUS-2026-05-10-v43-week4-rc-acceptance.md."
```
