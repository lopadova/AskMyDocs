# v4.2 Week 4 closure — 2026-05-10 — Three admin SPAs (PII Redactor + Flow Admin + Eval Harness UI)

W4 of the v4.2 cycle mounts **three operator-facing admin consoles** inside
the AskMyDocs admin shell, one per stable-line `padosoft/*-admin` package
released on 2026-05-06: `padosoft/laravel-pii-redactor-admin`
v1.0.2, `padosoft/laravel-flow-admin` v1.0.0, and `padosoft/eval-harness-ui`
v1.0.0. All three mount as iframes (each package targets React 19 + Tailwind v4
or Blade + Alpine — incompatible with the AskMyDocs React 18 host SPA), all
three are deny-by-default behind explicit Spatie-role-backed Gates, and all
three carry R30 cross-tenant isolation either via supplementary migration +
observer (PII Redactor) or Authorizer-level filter (Flow Admin) or
TenantContext-bound HTTP header (Eval Harness UI).

This document is the W4 closure artefact per R39. Closure SHA pinned in
§RC tag below.

## Sub-PRs shipped (W4)

| Sub-PR | Reference PR | Closure SHA on `feature/v4.2` | Scope |
|---|---|---|---|
| **5** — pii-redactor-admin v1.0.2 | [#121](https://github.com/lopadova/AskMyDocs/pull/121) | `5d13710` | Mount under `/admin/pii-redactor`. 3 Spatie-role Gates: `viewPiiRedactorAdmin` (super-admin / dpo / admin), `detokenisePiiRedactor` (super-admin / dpo only), `viewPiiRedactorRawSamples` (super-admin only). New `dpo` role added to `RbacSeeder` (5 roles total) with admin.access + logs.view + pii.detokenize permissions. R30 supplementary migration adds `tenant_id` to package's audit table + `creating` Eloquent observer stamps from TenantContext. Iframe mount (React 19 / Tailwind v4 vs our React 18 host). |
| **6** — flow-admin v1.0.0 | [#122](https://github.com/lopadova/AskMyDocs/pull/122) | `bc60ca6` | Mount under `/admin/flows`. 1 outer-fence Spatie-role Gate `viewFlowAdmin` (super-admin / admin / dpo) PLUS 8 row-scoped `ActionAuthorizer` methods implemented in `AskMyDocsFlowAuthorizer` (`canViewKpis` / `canViewRuns` / `canViewRunDetail` / `canReplayRun` / `canCancelRun` / `canApproveByToken` / `canRejectByToken` / `canRetryWebhook`). R30 via the same authorizer — every row-scoped action reads `tenant_id` via `DB::table` lookup and rejects on cross-tenant. `FlowAdminEnabled` middleware aborts 404 when `FLOW_ADMIN_ENABLED=false` (default). Iframe mount (Blade + Alpine — not React). |
| **7** — eval-harness-ui v1.0.0 | [#123](https://github.com/lopadova/AskMyDocs/pull/123) | `2c7d262` | Mount under `/admin/eval-harness`. Single read-only Gate `eval-harness.viewer` (super-admin + admin + dpo + editor). Editor included so canonical editors can verify their canonical edits did not regress factuality. **3 independent fail-closed fences in series**: env flag `EVAL_HARNESS_UI_ENABLED=false` default → Package controller `abort(404)`; `EvalHarnessUiNonProduction` middleware `abort(404)` when `APP_ENV=production`; `can:eval-harness.viewer` middleware → 403 on viewer / anonymous. Tenant header injection via `EvalHarnessUiTenantHeader` middleware (reads from `TenantContext::current()`). `class_exists()` guard in bootstrap/providers.php so `composer install --no-dev` deploys don't crash. Iframe mount (React + Vite isolated bundle). |

**Cycle-wide test count delta on `feature/v4.2` HEAD:** 1328 (start of W4) → 1371 (end of W4) — **+43 new tests** across PHPUnit feature tests for Gates / mounting / tenant-scoping / 3-fence semantics + 3 new Playwright specs. All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest + Playwright E2E + the RAG regression workflow.

## R30 strategies recap

The three packages required three different tenant-isolation approaches because each ships a different read-model surface:

| Package | Strategy | Why |
|---|---|---|
| pii-redactor-admin | Supplementary migration + Eloquent observer | Package owns its own audit-events table; we add `tenant_id` and stamp on `creating`. Simple + complete. |
| flow-admin | Authorizer-level filter (`AskMyDocsFlowAuthorizer`) | Package's ReadModelAdapter API does not expose query wrapping cleanly without forking. Every row-scoped action method reads the row's `tenant_id` via raw `DB::table` lookup and rejects on mismatch. |
| eval-harness-ui | TenantContext → HTTP header injection middleware | Package is read-only and consumes its own backend API via the configurable `X-Eval-Harness-Tenant` header. We inject from `TenantContext::current()`, preserving operator-set inbound values. |

All three approaches are tested against seeded multi-tenant fixtures asserting that tenant B's data is never reachable from tenant A's session.

## Sidebar surface (end of W4)

The AskMyDocs admin sidebar now exposes the W4 SPAs alongside the existing admin sections, mirroring the actual `NAV_ITEMS` layout in `frontend/src/components/shell/Sidebar.tsx`:

```
Workspace
└── Chat

Admin
├── Dashboard
├── Knowledge
├── AI Insights
├── Users & Roles
└── PII Redactor       (new — sub-PR 5)

Operations
├── Flows              (new — sub-PR 6)
├── Eval Harness       (new — sub-PR 7)
├── Logs
└── Maintenance
```

All three new nav entries are rendered **unconditionally** in the sidebar; the fail-closed behaviour for `EVAL_HARNESS_UI_ENABLED=false` and `APP_ENV=production` is enforced server-side by the per-route fences (RequireRole + middleware-level `can:` Gates + env-flag `abort(404)` on disabled subsystems). Operators clicking the nav entry under disabled / unauthorised conditions land on the AdminForbidden screen instead of the package console.

Every nav entry uses the testid hierarchy convention (R29) so Playwright selectors stay stable: `admin-pii-redactor-host` / `admin-flows-host` / `admin-eval-harness-host` are the iframe-wrapper testids; the iframe elements themselves carry `*-iframe` testids.

## Playwright test discipline (R12/R13 mixed-import lesson)

All three new specs (`admin-pii-redactor.spec.ts`, `admin-flows.spec.ts`, `admin-eval-harness.spec.ts`) follow the **strict mixed-import pattern**:

- `seededTest` from `./fixtures` for the `'admin (mount + nav)'` describe block (auto-fixture re-runs DemoSeeder + re-logs admin per test — needed because earlier specs may have invalidated the admin storage state).
- `baseTest` from `'@playwright/test'` for the `'viewer (RBAC denied)'` describe block (uses pre-saved viewer.json storage state, no auto-fixture reset that would invalidate the cookie).

This pattern was hardened during sub-PR 5 iter 1 + sub-PR 6 iter 1 — both bitten by single-import patterns that reset the DB and cascaded failures across all viewer-RBAC specs. The pattern itself is captured inline in the file headers of all three new specs (each carries an explanatory comment block at the top citing the storage-state-invalidation mechanism described in `frontend/e2e/fixtures.ts`'s "Step 3 is non-obvious but load-bearing" note). The lesson sits adjacent to R12 (E2E coverage) and R13 (real-data E2E) in the project rules, NOT to R30 (cross-tenant isolation) — the storage-state mechanism is unrelated to tenant scoping.

## R36 review-loop summary

| Sub-PR | Iterations | Notable findings |
|---|---|---|
| 5 | 3 (1 mine + 2 Copilot SWE auto-fixes) | iter 1: storage-state invalidation from `from './fixtures'` import; iter 2: tenant-scope global scope on package model |
| 6 | 2 (1 mine + 1 Copilot SWE auto-fix) | iter 1: workflow ordering (mkdir bootstrap dirs before Prepare .env); iter 2: docstring polish + JSON probe migration on FlowsView |
| 7 | 2 (1 mine + Copilot SWE responded inline) | iter 1: `class_exists` guard for require-dev SP + JSON probe for readiness (matched FlowsView pattern) |

All three sub-PRs landed within the 5-iteration cap. Recurring class of finding: cross-package consistency on the readiness-probe pattern — once FlowsView established `Accept: application/json` + `redirect: 'manual'`, the PII Redactor and Eval Harness UI hosts were fixed to match. Documented as sub-pattern in the existing R14 (surface failures loudly) skill.

## R39 RC tag

```bash
CLOSURE_SHA=$(git rev-parse origin/feature/v4.2)
gh release create v4.2.0-rc4 \
  --repo lopadova/AskMyDocs \
  --target "$CLOSURE_SHA" \
  --title "v4.2.0-rc4 — W4 milestone (3 admin SPAs: PII Redactor + Flow Admin + Eval Harness UI)" \
  --prerelease \
  --notes "Three operator-facing admin consoles mounted inside the AskMyDocs shell: padosoft/laravel-pii-redactor-admin v1.0.2 at /admin/pii-redactor (3 Gates), padosoft/laravel-flow-admin v1.0.0 at /admin/flows (5 Gates + ActionAuthorizer), padosoft/eval-harness-ui v1.0.0 at /admin/eval-harness (1 read-only Gate + 3-fence chain). All iframe-mounted, all R30-tenant-scoped via 3 different strategies. 3 sub-PRs (#121, #122, #123) + W4 closure docs (this PR). +43 PHPUnit tests (1328 -> 1371) + 3 new Playwright specs. Closure: docs/v4-platform/STATUS-2026-05-10-week4-admin-spas.md"
```

## What's next — W5

`v4.2.0` GA tag (no more RCs). Sub-PR 8 — RC acceptance + GA prep:

- README final pass (Key Features ribbon ⇒ "v4.2.0 GA shipped"; collapse rc1/rc2/rc3/rc4 entries into a single `v4.2.0 GA` entry under Changelog with summary metrics).
- New ADR `docs/adr/0004-v42-sister-package-integration.md` — records the integration decisions (Patent Box stays external, eval-harness require-dev only, Flow as ingest orchestrator, 3 admin SPAs iframe-mounted).
- INTEGRATION-ROADMAP-sister-packages.md final refresh — every sister-package row marked GA-integrated.
- Final closure status doc `STATUS-2026-05-10-week5-rc-acceptance.md`.
- Once-per-major `feature/v4.2` → `main` merge per R37; tag `v4.2.0` GA at the merge SHA.
