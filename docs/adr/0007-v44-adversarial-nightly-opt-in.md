# ADR 0007 — v4.4/W4 Adversarial nightly opt-in

**Status**: Accepted
**Date**: 2026-05-11
**Cycle**: v4.4 W4

## Context

ADR 0006 (v4.3/W3) shipped the `eval:nightly` Artisan command + scheduler
entry that runs the BASELINE factuality dataset
(`rag.askmydocs.factuality.fy2026`) once per UTC day. That ADR's
"Consequences" section explicitly deferred the adversarial lanes:

> Operators can dial up to nightly + adversarial by editing
> `EvalNightlyCommand` once the lane is trusted.

Two reasons for the deferral:

1. **Refusal-quality manifest stability.** The adversarial datasets
   (`out-of-corpus`, `contradicting-claims`, `rejected-approach-trigger`)
   score the RAG pipeline's REFUSAL behaviour. Under `Http::fake()` the
   canned responses are deterministic; under live mode the provider's
   refusal phrasing drifts run-to-run. Without a stable refusal-quality
   manifest, an adversarial regression alert at 05:30 UTC would more often
   reflect provider phrasing churn than a real RAG defect.
2. **Alert noise budget.** The baseline `macro_f1` regression is the loud
   ops signal — one alert per regression. Promoting three adversarial
   lanes to alert-firing without first proving them out would
   quadruple the alert surface for the same shipping signal-to-noise.

W4 is the v4.4 GA closure cycle. This sub-task adds the small
operational follow-up promised by ADR 0006.

## Decision

Add an opt-in adversarial pass to `eval:nightly`, gated by two new env
knobs:

- `EVAL_NIGHTLY_ADVERSARIAL` (default `false`) — master switch. When
  `true`, after the baseline run completes successfully the command
  also runs each enabled adversarial dataset.
- `EVAL_NIGHTLY_ADVERSARIAL_DATASETS` (default empty) — comma-separated
  allowlist of slug keys (`out-of-corpus`, `contradicting-claims`,
  `rejected-approach-trigger`). Empty = run every adversarial dataset
  configured under `eval-harness.askmydocs.golden.adversarial`. Unknown
  slugs are skipped with a `Log::warning()` so operator typos surface.

For each enabled slug, the command runs `eval-harness:run` twice (JSON
+ Markdown) with the `nightly` batch profile against
`rag.askmydocs.adversarial.<slug>`, persisting reports under
`storage/app/eval-harness/nightly/<YYYY-MM-DD>.adversarial.<slug>.{json,md}`
plus a `.summary.json` sidecar carrying `{exit_code, dataset, profile,
ran_at, json_path, md_path}` for forensic replay.

## Why advisory only (no Log::alert)

Adversarial regressions are written to the summary sidecar JSON, but
the command does **not** fire `Log::alert()` for them. Rationale:

- Adversarial scoring noise in live mode (per "Refusal-quality manifest
  stability" above) would generate false-alarm pages.
- The summary sidecar JSON is structured + diffable + queryable; ops can
  poll the directory or wire a separate digest alert when they want one.
- The baseline `macro_f1` alert remains the single loud signal that
  Pages on-call. Adversarial lanes are diagnostic data, not gating.

A future v4.5+ may promote individual lanes to alert-firing with a
per-lane env knob (e.g. `EVAL_NIGHTLY_ADVERSARIAL_ALERT_LANES=
out-of-corpus`) once that lane's refusal-quality manifest stabilises.
Out of scope for v4.4.

## Why baseline gates adversarial

A baseline failure short-circuits the adversarial pass entirely. Two
reasons:

1. **Failure prioritisation.** A baseline regression is the loud alert —
   adversarial detail is noise on top of it. An ops responder seeing
   both at 05:30 UTC needs to focus on the baseline.
2. **Cost containment.** A baseline failure often indicates a broken
   RAG pipeline (provider down, secret rotated, embedding column
   mismatch). Running 3× more adversarial passes against a known-broken
   pipeline burns provider budget for zero diagnostic value.

The implementation reaches the adversarial branch only after
`invokeEvalRun()` returned `SUCCESS` for the baseline AND the baseline
report was successfully read for the regression delta.

## Why per-slug failures are isolated

A `RuntimeException` from one adversarial slug runner call is captured
with `Log::warning()` and the loop continues to the next slug. Without
this isolation a single misbehaving lane (e.g. provider 5xx on
`contradicting-claims`) would skip every subsequent lane AND could
poison the scheduler heartbeat. Per-slug failures are NEVER promoted to
`Log::alert()` (advisory scope) and DO NOT affect baseline alerting,
which has already completed before the adversarial branch fires.

## Default-off invariant

When `EVAL_NIGHTLY_ADVERSARIAL=false` (the default — and the value
present in `.env.example`), `eval:nightly` behaviour is bit-identical
to the v4.3/W3 baseline-only path: exactly two runner invocations
(baseline JSON + Markdown), zero adversarial files written, zero
adversarial config touched. The first new test
(`test_adversarial_nightly_disabled_by_default_runs_baseline_only`)
enforces this invariant by asserting both the runner call count AND
the absence of any `.adversarial.` files on disk.

## Cost & disk impact

- **Provider spend**: ~$0.05/day baseline → ~$0.15/day with all 3
  adversarial slugs enabled (gpt-4o-mini class). Operators with
  budget pressure can opt-in to a subset via the allowlist.
- **Disk**: each adversarial slug adds ~3 files (JSON + MD + summary)
  per nightly run. At 90-day retention (`EVAL_NIGHTLY_RETENTION_DAYS`)
  that is ~270 extra files per slug, ~810 extra files total at
  ~80 KB each = ~65 MB additional disk. Trivial; the existing prune
  loop handles them automatically.

## Consequences

- Operators with a stable refusal-quality manifest get nightly adversarial
  signal without code changes — only env knobs.
- Adversarial regressions accumulate as queryable JSON sidecars; a
  follow-up reporting layer (eval-harness-ui dashboard, scheduled
  digest) can consume them without churning the alert channel.
- The `.summary.json` schema (`eval-harness.adversarial-summary.v1`)
  is stable: any future consumer can rely on `{slug, dataset,
  exit_code, ran_at, json_path, md_path}` being present.
- v4.5 lane-by-lane alert promotion is non-breaking: adding a new env
  knob to opt specific lanes into `Log::alert()` does not invalidate
  the v4.4 default-off invariant.

## Alternatives considered

- **Promote all adversarial lanes to alerting at the same time as the
  baseline**: rejected — see "Why advisory only" above. Refusal-quality
  noise budget needs to stabilise first.
- **Bake the adversarial pass into the baseline command without a
  switch**: rejected — would break the v4.3/W3 default-off invariant
  and force every host to budget for the extra provider spend.
- **Run adversarial as a separate `eval:nightly-adversarial` command at
  a different cron time**: rejected — duplicates 90% of the cost guard
  + disk plumbing for marginal benefit. The opt-in switch on the
  existing command is the smaller surface.
- **Use a different env-knob shape (per-lane booleans like
  `EVAL_NIGHTLY_ADVERSARIAL_OUT_OF_CORPUS=true`)**: rejected — three
  knobs to remember and three places to keep `.env.example` in sync.
  The CSV allowlist is one knob with explicit slugs.
- **Fire `Log::alert()` for adversarial regressions but at a lower
  level (`Log::warning`)**: deferred to v4.5 — needs the per-lane
  promotion mechanism, which is out of scope for v4.4.
