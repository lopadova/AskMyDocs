# v4.4 Week 3 closure — 2026-05-11 — Cross-mount of eval-harness-ui

W3 of the v4.4 cycle ships the **iframe → cross-mount migration of
`padosoft/eval-harness-ui`** at `/admin/eval-harness` (non-prod-only).
The 8-page admin SPA (Dashboard / Reports / ReportDetail / Compare /
Trend / Adversarial / AdversarialDetail / LiveBatches) now renders
directly inside the host React tree via vendored source under
`frontend/src/features/admin/eval-harness/cross-mount/`, sharing the
host's React 19 + Sanctum cookie + axios client. The v4.2/W4 ADR 0004
D5 iframe-mount workaround is retired for this surface, completing the
v4.4 cycle's cross-mount work for the two React-based admin SPAs
(`flow-admin` stays iframe-mounted forever per ADR 0005 — Blade +
Alpine, not React).

The **3 fail-closed fences** are PRESERVED end-to-end: env flag
`EVAL_HARNESS_UI_ENABLED=false` (default) → package abort 404;
`EvalHarnessUiNonProduction` middleware → 404 when `APP_ENV=production`;
`can:eval-harness.viewer` Gate → 403 on viewer / anonymous. The
cross-mount only changes the FE shell; the BE three-fence pipeline is
unchanged.

This document is the W3 closure artefact per R39. The §RC tag block
below captures the closure SHA at tag-creation time via `git fetch
origin --prune` + `git rev-parse origin/feature/v4.4` (run AFTER this
docs PR merges and BEFORE any subsequent commit lands on
`feature/v4.4`); the resulting tag points at an immutable commit per
R39's "exact closure-commit SHA" convention.

## Sub-PR shipped (v4.4 W3)

| Sub-PR | Reference PR | Closure SHA on `feature/v4.4` | Scope |
|---|---|---|---|
| **W3** — iframe → cross-mount of eval-harness-ui | [#140](https://github.com/lopadova/AskMyDocs/pull/140) | `1b36edc` | NEW `frontend/src/features/admin/eval-harness/cross-mount/` directory (28 files / ~2300 LOC vendored from `vendor/.../resources/js/` modulo `@/...` → relative paths, package CSS classes scoped under `.ehu-*` so host tokens aren't clobbered, internal `fetch()` → host axios). The package's internal `BrowserRouter basename="/app/admin/eval-harness"` continues to own sub-page navigation (8 pages) — two routers coexist by scope: host TanStack owns the shell mount; package's `BrowserRouter` owns sub-routes (cost: ~14 KB for `react-router-dom@^6.30.1`, the only new top-level dep). REWROTE `EvalHarnessView.tsx` — drops iframe + readiness probe; fetches bootstrap config from new `GET /api/admin/eval-harness/bootstrap-config` endpoint at mount time; drives `data-state="loading|ready|error"`; mounts SPA in degraded mode on error so the package's `<ErrorPanel />` surfaces underlying API failures (R7/R14). NEW `app/Http/Controllers/Api/Admin/EvalHarnessUiBootstrapController.php` returning `config('eval-harness-ui')` JSON gated by `auth:sanctum` + `can:eval-harness.viewer` (mirrors `admin/pii/strategy` precedent). REWROTE `frontend/e2e/admin-eval-harness.spec.ts` — strips iframe locators; asserts `data-mount="cross-mount"` + page-level testids; viewer-RBAC + 3-fence assertions preserved. UPDATED `INTEGRATION-ROADMAP-sister-packages.md` (eval-harness-ui row: iframe → cross-mount, v4.4/W3). NEW dep `react-router-dom@^6.30.1`. Iter 2 fixes 6 Copilot findings: HIGH R30 tenant header bypass (FE no longer sends `X-Eval-Harness-Tenant`; BE middleware injects from `TenantContext::current()` correctly); HIGH R9+R14 hard-coded bootstrap config (replaced with BE config endpoint + 8 PHPUnit tests); LOW R9 basename comment mismatch; LOW R9 routeBase comment; MEDIUM i18n (formatters now accept `locale` parameter; new `useFormatters()` hook reads from `useAppContext().config.locale`); MEDIUM R11 testids (added to ReportsPage filters + ComparePage selects + TrendPage controls + LiveBatchesPage refresh + ReportDetailPage tabs). |

**Cycle test count delta on `feature/v4.4` HEAD:** 1411 (start of W3 from W2 closure SHA `76f4d85`) → **1416** (end of W3 PHPUnit, +8) + Vitest 1411 → **1421** (+5 react vitest scenarios for bootstrap fetch loading/ready/error + tenant-header NOT-sent + locale routing + R11 testids). All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest (react + legacy) + Playwright E2E + the RAG regression workflow.

## Why this PR is bigger than W2

eval-harness-ui has **30 source files / 2615 LOC** (vs pii-redactor-admin's 2 files / 421 LOC at W2). The package's internal architecture (`AppContext`, `AppShell`, hooks, 8 pages, `react-router-dom` for sub-routing, `useI18n` for en/it) is preserved verbatim — only the **mount + bootstrap + API client** layer is adapted:

- **Mount**: `main.tsx` → `main-entry.tsx` (drops `createRoot()`, exports `default function EvalHarnessUiApp({ config, apiBase, routeBase })`).
- **Bootstrap**: `<script type="application/json" id="eval-harness-ui-bootstrap">` JSON tag → host axios fetch from new `/api/admin/eval-harness/bootstrap-config` endpoint.
- **API client**: `services/evalHarnessApi.ts` internal `fetch()` → host shared axios instance from `frontend/src/lib/api.ts`.

The package's 8 pages, 5 components, 3 hooks, i18n surface, types, utils are **bit-identical** with the upstream — only path-aliasing (`@/...` → relative) differs. Future package updates can be re-vendored by re-copying + re-applying the same 3-layer adaptation.

## R7 / R9 / R11 / R14 / R30 disciplines applied

- **R7 / R14**: surface failures loudly. The cross-mount mounts the SPA in degraded mode on bootstrap config fetch failure — the package's existing `<ErrorPanel role="alert" />` surfaces API errors instead of silent null-out. `data-state` attribute on the host wrapper exposes `loading|ready|error` to assistive tech and E2E.
- **R9**: bootstrap config endpoint replays `config/eval-harness-ui.php` 1:1 — operator-tuned `metric_labels` / `polling` / `locale` reach the FE identically to the iframe version. Fixed iter-1 hard-coded values.
- **R11 / R29**: every interactive element on ReportsPage / ComparePage / TrendPage / LiveBatchesPage / ReportDetailPage now has stable `data-testid` per the convention (`eval-harness-{page}-{control}`). Iter 2 addressed coverage gaps.
- **R12 / R13**: Playwright spec drives the cross-mounted SPA via real BE controllers. Mixed-import pattern (`seededTest` + `baseTest`) preserved. Three-fence fail-closed assertions still hit the BE.
- **R16**: every test name promises a behaviour the body strictly drives — iter 2's tenant-header test asserts NO `X-Eval-Harness-Tenant` header is set client-side; locale tests assert `formatNumber(1234567)` returns `,`-grouped for en and `.`-grouped for it.
- **R30 / R31**: BE-side R30 strategy unchanged — `EvalHarnessUiTenantHeader` middleware injects `X-Eval-Harness-Tenant` from `TenantContext::current()`. Iter 1's bug had the FE shipping the header with a stale `'active'` literal which would have BYPASSED the BE middleware injection — caught by Copilot, fixed iter 2 by removing the FE-side header send entirely. New PHPUnit tests cover happy path / locale normalisation × 2 / blank tenant_header / dpo+editor pass / viewer 403 / guest 401.

## 3 fail-closed fences PRESERVED end-to-end

| Fence | Mechanism | Behaviour |
|---|---|---|
| Env flag | `EVAL_HARNESS_UI_ENABLED=false` (default) | Package abort 404 — cross-mount React tree never reached. |
| `APP_ENV` check | `EvalHarnessUiNonProduction` middleware | 404 when `APP_ENV=production` — independent of env flag. |
| Spatie Gate | `can:eval-harness.viewer` middleware | 403 on viewer / anonymous — independent of env + APP_ENV. |

All three are independently enforced; defeating one does NOT bypass the others. Cross-mount changes nothing on the BE side.

## Default-off invariant preserved

The cross-mount changes ZERO behaviour at runtime when an operator has not opted into eval-harness-ui (`EVAL_HARNESS_UI_ENABLED=false`, default). The route `admin/eval-harness` continues to abort 404 at the BE layer when disabled. Operators who have opted in see the same 8 screens / actions / Spatie-gated permissions as the v4.2/W4 iframe version.

## R36 review-loop summary

PR #140 took **2 effective iterations** under the 5-iteration cap. Iter 1 surfaced 6 valid findings (1 HIGH R30 tenant bypass + 1 HIGH R9 hard-coded bootstrap + 2 LOW comment mismatches + 2 MEDIUM i18n + R11 testids). Iter 2 (`d9425ff`) addressed all 6 + added a new BE bootstrap config endpoint + 8 PHPUnit tests + 4 vitest tests. Copilot iter 2 confirmed all addressed. Merged at iteration 3 closure with all CI green and 0 outstanding must-fix.

## R39 RC tag

```bash
git fetch origin --prune  # ensure origin/feature/v4.4 reflects the post-merge HEAD
CLOSURE_SHA=$(git rev-parse origin/feature/v4.4)
gh release create v4.4.0-rc3 \
  --repo lopadova/AskMyDocs \
  --target "$CLOSURE_SHA" \
  --title "v4.4.0-rc3 — W3 milestone (cross-mount eval-harness-ui)" \
  --prerelease \
  --notes "Iframe -> cross-mount migration of padosoft/eval-harness-ui at /admin/eval-harness (non-prod only). Admin SPA (8 pages: Dashboard / Reports / ReportDetail / Compare / Trend / Adversarial / AdversarialDetail / LiveBatches) now renders directly inside the host React tree, sharing host React 19 + Sanctum cookie + axios client. Vendored source under frontend/src/features/admin/eval-harness/cross-mount/ (28 files / ~2300 LOC). Replaces v4.2/W4 ADR 0004 D5 iframe-mount workaround; flow-admin stays iframe-mounted forever (Blade + Alpine, not React). 3 fail-closed fences PRESERVED (env flag + APP_ENV + Gate). NEW BE config endpoint /api/admin/eval-harness/bootstrap-config gated by auth:sanctum + can:eval-harness.viewer. Single new dep: react-router-dom ^6.30.1 (~14 KB). Iter 2 fixed 6 Copilot findings (HIGH R30 tenant header bypass + HIGH R9 hard-coded bootstrap + 4 medium/low). +8 PHPUnit tests (1408 -> 1416), +5 vitest scenarios. Vitest (react + legacy) + full PHPUnit (PHP 8.3/8.4/8.5) + Playwright + RAG regression all green. 1 sub-PR (#140). Closure: docs/v4-platform/STATUS-2026-05-11-v44-week3-cross-mount-eval-harness-ui.md"
```

## What's next — W4

`v4.4.0` GA will close W4 and ship:
1. **eval-harness adversarial-lane nightly opt-in** — small operational follow-up adding `EVAL_NIGHTLY_ADVERSARIAL=true` env knob (default false) so operators can promote select adversarial lanes from PR-time-only into the nightly cron once their refusal-quality manifests stabilise.
2. **RC acceptance audit** + **INTEGRATION-ROADMAP refresh** + **README GA ribbon** + **GA merge** of `feature/v4.4` → `main` per R37 + tag `v4.4.0` GA at the merge SHA per R39 (once-per-major event).
