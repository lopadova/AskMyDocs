# v4.4 Week 2 closure — 2026-05-10 — Cross-mount of pii-redactor-admin

W2 of the v4.4 cycle ships the **iframe → cross-mount migration of
`padosoft/laravel-pii-redactor-admin`** at `/admin/pii-redactor`. The
admin SPA now renders directly inside the host React tree via vendored
source under `frontend/src/features/admin/pii-redactor/cross-mount/`,
sharing the host's React 19 + Sanctum cookie + axios client. The v4.2/W4
ADR 0004 D5 iframe-mount workaround is retired for this surface.

The W1 prerequisite (Tailwind v4 host migration, PR #136 merged at
`860d0aa`, tagged `v4.4.0-rc1` at `ac3bd49`) is satisfied — the host
SPA and the admin SPA now share the same Tailwind major, eliminating
the dual-CSS-engine cost the iframe pattern paid for.

This document is the W2 closure artefact per R39. The §RC tag block
below captures the closure SHA at tag-creation time via `git rev-parse
origin/feature/v4.4` (run AFTER this docs PR merges and BEFORE any
subsequent commit lands on `feature/v4.4`); the resulting tag points at
an immutable commit per R39's "exact closure-commit SHA" convention.

## Sub-PR shipped (v4.4 W2)

| Sub-PR | Reference PR | Closure SHA on `feature/v4.4` | Scope |
|---|---|---|---|
| **W2** — iframe → cross-mount of pii-redactor-admin | [#138](https://github.com/lopadova/AskMyDocs/pull/138) | `97f1186` | NEW `frontend/src/features/admin/pii-redactor/cross-mount/` directory: `App.tsx` (vendored from `vendor/.../resources/js/app.tsx`, drops `createRoot()` mount + local `dark` state — host owns theme, exports `default function PiiRedactorAdminApp({ config })`); `adminApi.ts` (vendored from `vendor/.../resources/js/api.ts`, replaces internal `fetch()` with the host shared axios instance from `frontend/src/lib/api.ts` — auto XSRF + Sanctum cookie); `types.ts` (extracted `Page` / `StatusPayload` / `DataRow` / `Abilities` / `PiiRedactorAdminConfig` shared types); `cross-mount.css` (vendored package CSS scoped under `.pra-shell` so host tokens aren't clobbered); `App.test.tsx` (8 vitest scenarios — overview default + nav + error + loading-state + ability gating + theme isolation + Ctrl+K aria-label + tri-state placeholders). REWROTE `frontend/src/features/admin/pii-redactor/PiiRedactorView.tsx` — strips iframe + readiness probe; derives package config host-side from `useAuthStore` (`userDisplay` from name/email; `abilities` derived from Spatie roles to mirror BE `registerPiiRedactorAdminGates()`). REWROTE `frontend/e2e/admin-pii-redactor.spec.ts` — strips iframe locators, asserts `data-mount="cross-mount"` + page-level testids; viewer-RBAC scenarios preserved. UPDATED `docs/v4-platform/INTEGRATION-ROADMAP-sister-packages.md` (pii-redactor-admin row: iframe → cross-mount, v4.4/W2). NEW dep `lucide-react@^1.14.0` (matching package's pinned version — only new top-level dep). Iter 2 fixes 2 Copilot findings: R14 Overview tri-state (no more definitive "Disabled" / "0" on unknown — gated behind `loading | error | ready` state, all 4 cards show `—` placeholder pre-resolve and `unavailable` on error); R15 Ctrl+K shortcut accessible name (`aria-label="Open playground"` + `aria-keyshortcuts="Control+K"`; Search icon `aria-hidden="true"`). |

**Cycle test count delta on `feature/v4.4` HEAD:** 1408 (start of W2 from W1 closure SHA `ac3bd49`) → **1411** (end of W2) — **+3 new vitest scenarios** for the cross-mount tri-state + a11y assertions. PHPUnit unchanged at 1408 (no BE changes — cross-mount is purely FE-side). All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest (react + legacy) + Playwright E2E + the RAG regression workflow.

## Why this PR is small

The cross-mount is **scope-tight on the FE shell**:

- **No vendor tree modifications** — the `vendor/padosoft/laravel-pii-redactor-admin/` directory is untouched. The cross-mount lives entirely under `frontend/src/features/admin/pii-redactor/cross-mount/` as a host-owned vendored adaptation. The package can ship updates and they remain pluggable via the iframe path; the host can also adopt updates at the cross-mount layer when the package SPA evolves.
- **No new BE config endpoint** — the package config payload (`apiBase`, `routePrefix`, `userDisplay`, `abilities`) is derived host-side from existing `useAuthStore` + known constants. `csrfToken` field dropped because the host axios auto-forwards `XSRF-TOKEN` cookie.
- **R30 BE-side strategy unchanged** — pii-redactor-admin's supplementary migration + Eloquent observer pattern (per ADR 0004 D4) stays as-is. Cross-mount only changes the FRONTEND mount; all Spatie role gates (`viewPiiRedactorAdmin`, `detokenisePiiRedactor`, `viewPiiRedactorRawSamples`) remain enforced server-side.
- **Single new dep** — `lucide-react@^1.14.0` (the package's icon set, not previously installed host-side). Pinned to match `vendor/.../package.json` to avoid icon-API drift.

## R7 / R11 / R12 / R13 / R14 / R15 / R16 / R30 disciplines applied

- **R7**: zero `@`-silenced errors on the API layer; surface 404/403 from `/admin/pii-redactor/api/*` to a toast / inline error rather than null-out (R14 inversion-aware).
- **R11**: every actionable element in the cross-mount has a stable `data-testid` for E2E + Vitest selectors. New `data-mount="cross-mount"` + `data-state="loading|error|ready"` + `data-status-state` + `data-page` attributes for observability.
- **R12 / R13**: Playwright spec rewritten to drive the cross-mount via real BE controllers (no `page.route(...)` interception of `/admin/pii-redactor/api/*`).
- **R14**: tri-state Overview (loading / error / ready) — never renders "Disabled" / "0" before `/status` resolves. Iter 2 fix.
- **R15**: every icon-only button has an explicit `aria-label`. Sidebar nav uses visible label text. Iter 2 fix.
- **R16**: every test name promises a behaviour the body strictly drives — pre-resolve frame test asserts NO definitive values; error path test asserts `data-state="error"`; Ctrl+K test reaches button via `getByRole('button', { name: 'Open playground' })`.
- **R30/R31**: BE-side R30 strategy unchanged (supplementary migration + Eloquent observer per ADR 0004 D4). FE doesn't introduce new tenant boundaries.

## Default-off invariant preserved

The cross-mount changes ZERO behaviour at runtime when an operator has not opted into pii-redactor-admin (`PII_REDACTOR_ADMIN_ENABLED=false`, default). The route `admin/pii-redactor` continues to abort 404 at the BE layer when disabled — the cross-mount React tree is never reached. Operators who have opted in see the same screens / actions / Spatie-gated permissions as the v4.2/W4 iframe version.

## R36 review-loop summary

PR #138 took **2 effective iterations** plus **1 Copilot SWE auto-push** under the 5-iteration cap. Iter 1 surfaced 2 valid findings (R14 Overview tri-state + R15 Ctrl+K aria-label). Iter 2 (`3300049`) addressed both. Copilot SWE auto-pushed a parallel duplicate commit (`3125169`) implementing the same fixes — became the new HEAD. Repo's "approval required for first-time bot pushes" setting paused CI on `3125169`; manually re-running unstuck the workflow. Merged at iteration 3 closure with all CI green and 0 outstanding must-fix.

## R39 RC tag

```bash
git fetch origin --prune  # ensure origin/feature/v4.4 reflects the post-merge HEAD
CLOSURE_SHA=$(git rev-parse origin/feature/v4.4)
gh release create v4.4.0-rc2 \
  --repo lopadova/AskMyDocs \
  --target "$CLOSURE_SHA" \
  --title "v4.4.0-rc2 — W2 milestone (cross-mount pii-redactor-admin)" \
  --prerelease \
  --notes "Iframe -> cross-mount migration of padosoft/laravel-pii-redactor-admin at /admin/pii-redactor. Admin SPA now renders directly inside the host React tree, sharing host React 19 + Sanctum cookie + axios client. Vendored source under frontend/src/features/admin/pii-redactor/cross-mount/ (App.tsx + adminApi.ts + types.ts + cross-mount.css + App.test.tsx). Replaces v4.2/W4 ADR 0004 D5 iframe-mount workaround. BE-side R30 strategy unchanged (supplementary migration + Eloquent observer per ADR 0004 D4). Single new dep: lucide-react@^1.14.0. Iter 2 fixed 2 Copilot findings (R14 Overview tri-state + R15 Ctrl+K aria-label). +3 vitest scenarios (1408 -> 1411). Vitest (react + legacy) + full PHPUnit (PHP 8.3/8.4/8.5) + Playwright + RAG regression all green. 1 sub-PR (#138). Closure: docs/v4-platform/STATUS-2026-05-10-v44-week2-cross-mount-pii-redactor-admin.md"
```

## What's next — W3

`v4.4.0-rc3` will close W3 and ship the **iframe → cross-mount of `padosoft/eval-harness-ui`** at `/admin/eval-harness` (non-prod-only). Same pattern as W2 (vendor SPA source + replace iframe + thread host axios + rework Playwright). Preserve the 3 fail-closed fences (env flag + APP_ENV + Gate) per ADR 0004 D5. `flow-admin` stays iframe-mounted forever (Blade + Alpine, not React, so cross-mount does not apply).
