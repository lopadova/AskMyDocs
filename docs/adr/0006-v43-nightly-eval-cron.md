# ADR 0006 — v4.3/W3 Nightly eval-harness regression cron

**Status**: Accepted
**Date**: 2026-05-10
**Cycle**: v4.3 W3

## Context

`padosoft/eval-harness` v1.2 ships with the AskMyDocs RAG pipeline as
its system-under-test (`App\Eval\EvalRegistrar`). The PR-time CI gate
(`.github/workflows/rag-regression.yml`) drives the baseline + three
adversarial datasets through the pipeline using `Http::fake()` — fast,
deterministic, free, but blind to provider drift.

A real provider (OpenAI / OpenRouter / Regolo) re-tunes its models
behind any released name without notice. The CI fake catches RAG
plumbing regressions; it does NOT catch:

- A provider model rev that suddenly refuses on a previously-grounded
  question.
- A subtle change in citation-prefix conventions that breaks the
  AskMyDocs `CitationGroundednessMetric`.
- An embedding-space rotation that drops cosine similarity on the
  same canonical corpus.

A nightly run against the LIVE provider is the safety net.

## Decision

Add a host-application Artisan command `eval:nightly` that:

1. Runs once per UTC day at 05:30 (after `insights:compute` at 05:00),
   gated by `EVAL_NIGHTLY_ENABLED=true` in the scheduler closure.
2. When `EVAL_NIGHTLY_LIVE=true` AND a provider key is configured,
   overrides `eval-harness.askmydocs.live_ai=true` for the run only.
   The override is in-process (`Config::set`) and does not leak
   beyond the command instance.
3. Invokes `eval-harness:run rag.askmydocs.factuality.fy2026
   --registrar=App\Eval\EvalRegistrar --batch-profile=nightly` twice
   (JSON + Markdown), persisting both under
   `storage/app/eval-harness/nightly/<YYYY-MM-DD>.{json,md}`.
4. Compares the new JSON's `macro_f1` and per-metric means against
   the prior nightly file (or, on first run, the most recent regular
   report under `eval-harness/reports/`) via
   `App\Eval\Support\NightlyDeltaCalculator`.
5. When `macro_f1` drops by more than
   `EVAL_NIGHTLY_REGRESSION_THRESHOLD` (default 5%), fires
   `Log::alert()` AND writes a sidecar `<YYYY-MM-DD>.alert.json`
   carrying the offending metric breakdown for forensic replay.
6. Prunes nightly reports older than `EVAL_NIGHTLY_RETENTION_DAYS`
   (default 90) at the end of the run; `--prune-only` triggers a
   sweep without running the eval.
7. Exposes `--dry-run` (plan only) and `--status` (last run summary)
   for ops queries.

## Cost guard (defense-in-depth)

Two independent fences must both open before a single live token is
billed:

1. `EVAL_NIGHTLY_ENABLED=true` in `bootstrap/app.php` — without it,
   the scheduler never even registers the command.
2. `EVAL_NIGHTLY_LIVE=true` AND a provider key in env — without
   either, the command logs `Log::alert()` (so ops sees the missed
   run) AND exits 0 (so the scheduler heartbeat stays clean). It
   does NOT silently fall back to `Http::fake()`: the operator's
   intent (live signal) didn't match the host's capability, and a
   silent fallback would mask real configuration drift.

The `nightly` batch profile in `config/eval-harness.php` keeps the
sample count tight (40-sample baseline only, adversarial lanes still
gated on the PR-time workflow) so a typical nightly costs <$0.05 in
provider fees on `gpt-4o-mini`-class models. The retention sweep
keeps the disk footprint at <30 MB for a year of dated reports.

## Alerting

`Log::alert()` is the surface — operators already wire alerting off
the `alert` log channel (PagerDuty / Slack / opsgenie all have
`channels.log` integrations). The command does NOT introduce a
Notification class because:

- The alert audience is ops, not application users — the existing
  log channel is the right destination.
- Adding a Notification class introduces a queue dependency and a
  per-environment `mail` config requirement that nightly cron does
  not justify.
- The sidecar `.alert.json` is the durable forensic record; the log
  line is the realtime signal. Splitting concerns keeps both clean.

A future v4.4 may add `padosoft/laravel-eval-notify` if the alert
volume justifies it; for now the log channel covers it.

## Retention

90 days by default — long enough to backtrack a quarterly trend, short
enough that the on-disk footprint stays trivial. The sweep runs at the
end of every successful command invocation AND can be triggered
out-of-band with `eval:nightly --prune-only`. Failed-run reports are
NOT preserved beyond retention — the command writes the report file
BEFORE deciding regression, so the corresponding `.alert.json` ages
out together with the source `.json`.

## Why this lives in AskMyDocs and not in the package

`padosoft/eval-harness` is the pure compute engine: dataset loading,
metric scoring, batch execution, report rendering. Cron policy +
retention + alert channel selection are HOST concerns — they depend on
the host's scheduler, log infrastructure, retention policy, and
operator runbook. Pushing them into the package would force every
consumer to adopt AskMyDocs's exact conventions OR take an opt-in
config explosion. The clean split is:

- Package: "give me a SUT, I score it and emit a report."
- Host: "decide WHEN to ask, WHAT to do with the alert, WHERE to keep
  the artifact."

The same boundary applies to the `insights:compute` command (host
concern) vs. its underlying `AiInsightsService` (in-app, but could be
extracted; the cron is not).

## Consequences

- Operators with a configured provider key get a daily regression
  signal without writing any glue code beyond two env knobs.
- A regression that the PR-time gate misses (provider drift) surfaces
  within 24h instead of waiting for a user complaint.
- The `.alert.json` sidecar feeds future automation (auto-rollback,
  auto-open issue) without locking us into a notification surface
  today.
- Disk footprint: ~80 KB per nightly report × 90 days = ~7 MB steady
  state per host. Trivial.
- Provider spend: ~$0.05/day on `gpt-4o-mini`-class models for the
  40-sample baseline, ~$1.50/month. Operators can dial up to nightly
  + adversarial by editing `EvalNightlyCommand` once the lane is
  trusted.

## Alternatives considered

- **Push the cron INTO `padosoft/eval-harness`**: rejected — cron +
  alerting are environmental, not algorithmic. See "Why this lives in
  AskMyDocs" above.
- **Use Laravel Notifications instead of `Log::alert()`**: rejected
  for v4.3 — adds queue + mailer dependencies for zero current
  consumer benefit. Re-evaluate in v4.4 if the alert volume grows.
- **Run the live nightly inside `.github/workflows/rag-regression.yml`
  on a `schedule` trigger**: rejected — pinning provider keys in
  GitHub Secrets for a 24/7 cron multiplies the secret-rotation
  surface. The host already has the keys; running the cron there
  centralises the trust boundary.
- **Skip the sidecar JSON, log everything**: rejected — `.alert.json`
  is structured + diffable + parseable; a multi-line log message is
  none of those.
