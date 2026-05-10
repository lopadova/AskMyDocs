# v4.3 Week 1 closure — 2026-05-10 — PII redactor comprehensive boundary coverage

W1 of the v4.3 cycle ships **sub-PR 4.5** — the comprehensive
boundary-coverage extension of `padosoft/laravel-pii-redactor` v1.2 that
was scoped during v4.2 but parked until this cycle. AskMyDocs now has
**11 persistence-boundary touch-points + 6 admin-readiness inspectors
wired** — every place where chat / document content (or anything derived
from it) hits persistent storage now passes through the redactor when
the appropriate per-touchpoint env knob is opted on.

This document is the W1 closure artefact per R39. Closure SHA pinned in
§RC tag below.

## Sub-PR shipped (v4.3 W1)

| Sub-PR | Reference PR | Closure SHA on `feature/v4.3` | Scope |
|---|---|---|---|
| **4.5** — PII redactor comprehensive boundary coverage | [#127](https://github.com/lopadova/AskMyDocs/pull/127) | `9aa3bf7` | 7 NEW persistence-boundary touch-points (Monolog log processor; failed-jobs payload sanitiser via `JobFailed` listener with deterministic `failed_jobs.uuid` matching; `Conversation` + `Message` `saving` observers; `ChatLog::creating` observer for `answer` + `sources` JSON; `AdminCommandAudit::creating` observer; `AdminInsightsSnapshot::creating` observer walking 6 JSON columns; `AskMyDocsFlowPayloadRedactor` bound to laravel-flow's `CurrentPayloadRedactorProvider` contract — ONE wire covers run input + step results + audit + webhook outbox + approvals). 6 admin-readiness inspectors wired into existing AskMyDocs admin surfaces (`RedactorAdminInspector` → `AiInsightsService::redactionSnapshot()`; `DetectionReportFormatter` → `DashboardMetricsController::health()`; `CustomRulePackInspector` → `HealthCheckService::piiRedactorReport()`; `RedactionStrategyFactory` → new `PiiStrategyController` + `GET /api/admin/pii/strategy`; `TokenResolutionService` → `LogViewerController::chatDetokenize`; `DetokeniseResult` typed shape additive to existing detokenize endpoint). 5 NEW env knobs all default OFF (`KB_PII_REDACT_LOGS`, `KB_PII_REDACT_FAILED_JOBS`, `KB_PII_REDACT_ANSWERS`, `KB_PII_REDACT_COMMAND_AUDIT`, `KB_PII_REDACT_FLOW_PAYLOADS`). |

**Cycle test count delta on `feature/v4.3` HEAD:** 1371 (start of v4.3 from v4.2.0 GA) → **1397** (end of W1) — **+26 new tests** (10 feature tests covering each touch-point + 3 race-window / dpo-can-read / route-gate-middleware tests + 13 inspector / strategy contract tests). All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest + Playwright E2E + the RAG regression workflow.

## R30 / R7 / R14 disciplines applied

- **R30 / R31**: every Eloquent observer respects tenant scoping. Redaction itself is content-only (not tenant-aware); detokenise lookups via `TokenResolutionService` scope by tenant.
- **R7**: no `@`-silenced errors. Every observer / listener / processor catches its own `Throwable`s and falls through to the original write — the redactor is a **safety net**, never a load-bearing wall (R14 inversion).
- **R14**: failures loud at the Gate / route layer (404 when env disabled, 403 when role insufficient); silent-fall-through at the redaction layer so a redactor regression NEVER blocks user-facing flows.
- **R16**: every test body actually triggers the path the test name promises. The race-window test for `RedactFailedJobPayload` fires two simultaneous failures on the same queue and asserts the right rows are redacted via deterministic `failed_jobs.uuid` matching.
- **R26**: every persistence-boundary test asserts no leak via the canonical regex (`/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/`) on persisted values.

## Default-off invariant preserved

All 5 new env knobs default `false`. v4.2 hosts upgrading to v4.3.0-rc1 see byte-identical behaviour until they explicitly opt in via `KB_PII_REDACT_*=true`. Operators can opt in per-touchpoint without an all-or-nothing cliff.

## R36 review-loop summary

PR #127 took **2 effective iterations** (1 mine + 1 Copilot SWE auto-fix) under the 5-iteration cap. Iter 1 surfaced 11 findings (1 substantive race window, 2 Gate-alignment fixes, 1 Monolog double-attach, 4 doc-accuracy, 3 unused imports / dead-asserts). Iter 2 fixed all 11 + Copilot SWE auto-pushed a 1-line docblock polish on `PiiBoundaryCoverageServiceProvider`. Merged at iteration 3 closure with all CI green and 0 outstanding must-fix.

## R39 RC tag

```bash
CLOSURE_SHA=$(git rev-parse origin/feature/v4.3)
gh release create v4.3.0-rc1 \
  --repo lopadova/AskMyDocs \
  --target "$CLOSURE_SHA" \
  --title "v4.3.0-rc1 — W1 milestone (PII redactor comprehensive boundary coverage)" \
  --prerelease \
  --notes "PII redactor comprehensive boundary coverage: 7 new persistence-boundary touch-points (Monolog log processor; failed-jobs payload sanitiser; Conversation + Message + ChatLog + AdminCommandAudit + AdminInsightsSnapshot observers; Flow payload redactor via CurrentPayloadRedactorProvider contract — one wire covers run input + step results + audit + webhook outbox + approvals) + 6 admin-readiness inspectors wired into existing admin surfaces. 5 new env knobs all default OFF. 1 sub-PR (#127). +26 PHPUnit tests (1371 -> 1397). Closure: docs/v4-platform/STATUS-2026-05-10-v43-week1-pii-boundary-coverage.md"
```

## What's next — W2

`v4.3.0-rc2` will close W2 and ship the **React 19 host bump**: bump the AskMyDocs frontend host from React 18 to React 19 to unlock cross-mount of the three admin SPAs (currently iframe-mounted per ADR 0004 D5). Includes a new ADR documenting the React 19 + Tailwind v4 migration decisions. Substantial frontend refactor.
