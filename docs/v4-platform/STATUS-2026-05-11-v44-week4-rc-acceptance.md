# v4.4 Week 4 closure — 2026-05-11 — RC acceptance + GA merge

W4 is the final milestone of the v4.4 cycle. Three weeks of sub-package
deliverables (W1 Tailwind v4 host migration, W2 cross-mount of
pii-redactor-admin, W3 cross-mount of eval-harness-ui) closed inside the
2026-05-10 / 2026-05-11 window with their own status docs and RC tags.
W4 ships the **adversarial nightly opt-in** (sub-PR #142, merged at
`5be6f5a`) — the small operational follow-up on top of v4.3/W3 that
ADR 0006 deferred and ADR 0007 now records — then drives the once-per-major
`feature/v4.4` → `main` merge per R37 and tags `v4.4.0` GA at the merge SHA
per R39.

This document audits acceptance. The integration → main merge PR (W4.B)
and the GA tag itself land in a follow-up parent-session step.

## Sub-tasks shipped (cycle-wide, W1..W4.A)

| Wn | Deliverable | Reference PRs | Final merge SHA on `feature/v4.4` | Closure / artefact |
|---|---|---|---|---|
| W1 | Tailwind v3 → v4 host migration (host SPA + 4 Copilot iter-1 fixes) | #136 (sub-PR), #137 (W1 closure docs) | `860d0aa`, `ac3bd49` | `docs/v4-platform/STATUS-2026-05-10-v44-week1-tailwind-v4-host-migration.md` + Tagged [`v4.4.0-rc1`](https://github.com/lopadova/AskMyDocs/releases/tag/v4.4.0-rc1) |
| W2 | Iframe → cross-mount of pii-redactor-admin (vendored SPA, BE unchanged, 2 Copilot iter-1 fixes) | #138 (sub-PR), #139 (W2 closure docs) | `97f1186`, `76f4d85` | `docs/v4-platform/STATUS-2026-05-10-v44-week2-cross-mount-pii-redactor-admin.md` + Tagged [`v4.4.0-rc2`](https://github.com/lopadova/AskMyDocs/releases/tag/v4.4.0-rc2) |
| W3 | Iframe → cross-mount of eval-harness-ui (8-page SPA, NEW BE bootstrap-config endpoint, 6 Copilot iter-1 fixes) | #140 (sub-PR), #141 (W3 closure docs) | `1b36edc`, `c74fc1b` | `docs/v4-platform/STATUS-2026-05-11-v44-week3-cross-mount-eval-harness-ui.md` + Tagged [`v4.4.0-rc3`](https://github.com/lopadova/AskMyDocs/releases/tag/v4.4.0-rc3) |
| W4.A — sub-PR | eval-harness adversarial nightly opt-in (2 new env knobs, advisory-only, 3 Copilot iter-1 fixes) + ADR 0007 | #142 | `5be6f5a` | `docs/adr/0007-v44-adversarial-nightly-opt-in.md` |
| W4.A — closure | RC acceptance gates audit + closure status doc (this document) + INTEGRATION-ROADMAP refresh + README GA ribbon + Changelog | this PR | filled in on merge | `docs/v4-platform/STATUS-2026-05-11-v44-week4-rc-acceptance.md` (this) |
| W4.B | `feature/v4.4` → `main` integration merge + `v4.4.0` GA tag | follow-up PR | n/a until W4.B opens | Once-per-major event per R37 |

## RC tags audit

Every `v4.4.0-rcN` tag below was created via `gh release create … --prerelease` on the exact closure-commit SHA per R39 (using `git fetch origin --prune` + `git rev-parse origin/feature/v4.4` AFTER the closure docs PR merged).

| Tag | Pinned SHA | Closure milestone | GitHub release |
|---|---|---|---|
| `v4.4.0-rc1` | `ac3bd49ba73bbf9d4b4651d29177acb812932ace` | W1 closure (PR #137) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.4.0-rc1 |
| `v4.4.0-rc2` | `76f4d85efe4411760299e355934a479572a459d3` | W2 closure (PR #139) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.4.0-rc2 |
| `v4.4.0-rc3` | `c74fc1b882b18a35a4659fd34734051fe29392aa` | W3 closure (PR #141) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.4.0-rc3 |

## Acceptance gate checklist

Every box below was verified via `gh release` / `gh run` / `gh pr` / `gh api` queries against the live GitHub state on 2026-05-11. No speculation — each gate is paired with the discipline that confirmed it.

### A — Composer alignment (no version drift since v4.3.0 GA, except adding `react-router-dom`)

The v4.4 cycle is **scope-tight on host-side mount migrations** plus one operational opt-in (adversarial nightly). No sister-package version bumps.

- [x] `padosoft/laravel-ai-regolo` constraint unchanged at `^1.0`.
- [x] `padosoft/laravel-pii-redactor` constraint unchanged at `^1.2`.
- [x] `padosoft/laravel-flow` constraint unchanged at `^1.0`.
- [x] `padosoft/eval-harness` constraint unchanged at `^1.2.0` (require-dev).
- [x] `padosoft/laravel-pii-redactor-admin` constraint unchanged at `^1.0.2` (now CROSS-MOUNTED instead of iframed; v4.4/W2).
- [x] `padosoft/laravel-flow-admin` constraint unchanged at `^1.0` (stays iframe-mounted forever — Blade + Alpine, not React).
- [x] `padosoft/eval-harness-ui` constraint unchanged at `^1.0` (now CROSS-MOUNTED instead of iframed; v4.4/W3).
- [x] **NEW** dep `react-router-dom@^6.30.1` (~14 KB) — required by the eval-harness-ui cross-mount per ADR 0005 routing strategy. Only new top-level dep across the entire v4.4 cycle.
- [x] **NEW** dep `lucide-react@^1.14.0` — required by the pii-redactor-admin cross-mount (matches package's pinned version).
- [x] Tailwind upgraded `^3.4.14` → `^4.0.0` (W1) + `@tailwindcss/vite@^4.0.0` (W1) added; `autoprefixer` + `postcss` removed.
- [x] `package.json` declares `engines.node >= 20` (Tailwind v4's transitive `@tailwindcss/oxide` requirement).
- [x] `padosoft/laravel-patent-box-tracker` remains absent (external by design — see v4.2 ADR 0004 D1, unchanged).

### B — Test gates

- [x] PHPUnit (PHP 8.3 / 8.4 / 8.5) all green on every closure SHA. Cycle-wide test count: 1408 (start of v4.4 from v4.3.0 GA) → **1423** (end of W4.A) — **+15 new BE tests** (W1: +0 dependency-only; W2: +0 FE-side cross-mount; W3: +8 BE bootstrap-config endpoint tests; W4: +7 adversarial opt-in tests including iter-2 missing-artifact guard).
- [x] Vitest (react + legacy) all green on every closure SHA. React vitest count: 304 (start of v4.4) → **321** (end of W4.A) — **+17 react scenarios** (W1: +0; W2: +5; W3: +9; W4: +0 — adversarial is BE-only). Vitest legacy unchanged at 18.
- [x] Playwright E2E green on every closure SHA — including the rewritten cross-mount specs that replaced the v4.2/W4 iframe-locator pattern.
- [x] RAG regression workflow green on every PR — including the W4 sub-PR which extended the eval-harness scheduler entry without breaking the PR-time gate.

### C — R36 review-loop gates

- [x] Every sub-PR + every closure docs PR opened with `--reviewer copilot-pull-request-reviewer`.
- [x] Every iteration of every sub-PR ran the Copilot review loop until 0 outstanding must-fix + all CI green.
- [x] No PR merged on green CI alone — every merge waited for the Copilot review window AND addressed all iter1 findings.
- [x] Cycle-wide R36 cost: W1 = 2 effective iterations + 1 Copilot SWE auto-fix; W2 = 2 effective iterations + 1 Copilot SWE auto-fix; W3 = 2 effective iterations; W4.A = 2 effective iterations. All under the 5-iteration cap.

### D — R30/R31 cross-tenant isolation

The v4.4 cycle PRESERVED tenant-scoping discipline through all 3 admin SPA cross-mounts:

- [x] W2 — pii-redactor-admin: BE-side R30 strategy unchanged (supplementary migration + Eloquent observer per ADR 0004 D4). Cross-mount derives package config host-side from `useAuthStore`; abilities mirror BE Spatie gates.
- [x] W3 — eval-harness-ui: Iter 1 had a HIGH R30 violation (FE was sending stale `X-Eval-Harness-Tenant: 'active'` header which would BYPASS the BE `EvalHarnessUiTenantHeader` middleware injection). Iter 2 fixed by removing the FE-side header send entirely; BE middleware now correctly injects from `TenantContext::current()`. Vitest pins the contract: every recorded `api.request()` call MUST NOT carry the tenant header. R30 caught + fixed within the R36 loop, didn't ship.
- [x] W4 — adversarial nightly: BE-only; runs through existing `EvalRegistrar` which already pins `TenantContext` to 'default' (inherited from v4.2/W3). No new tenant boundary.

### E — R37 branch strategy

- [x] All sub-PRs targeted `feature/v4.4`, never `main`.
- [x] `main` HEAD remains at `4f375f1` (v4.3.0 GA) until W4.B fires the once-per-major merge.

### F — R39 RC-tag-per-week convention

- [x] 3 RC tags cut on closure SHAs (rc1 / rc2 / rc3), each pinned to immutable refs.
- [x] Final `v4.4.0` GA tag fires only AFTER `feature/v4.4` → `main` merge (W4.B).

### G — R7 / R14 / R26 disciplines

- [x] W1 — Tailwind v4 build surfaces every warning loudly; no `@`-silenced errors. Build clean.
- [x] W2 — pii-redactor cross-mount Overview tri-state (R14 from iter-2 review) — no more definitive "Disabled" / "0" before `/status` resolves.
- [x] W3 — eval-harness cross-mount mounts SPA in DEGRADED mode on bootstrap config fetch failure so the package's `<ErrorPanel role="alert" />` surfaces failures (R7/R14 inversion: cross-mount as observability layer, not load-bearing wall).
- [x] W4 — adversarial nightly missing-artifact guard (R14 from iter-2 review) — `Log::warning` + skip summary sidecar instead of recording exit_code=0 with an empty disk silently.

### H — Default-off invariant preserved across all new feature surfaces

- [x] W1 — Tailwind v4 migration is dependency + build-config only. ZERO behavioural change at runtime; `dark:*` selector contract preserved bit-identical.
- [x] W2 — pii-redactor cross-mount preserves `PII_REDACTOR_ADMIN_ENABLED=false` (default) — route aborts 404 at BE; FE cross-mount tree never reached.
- [x] W3 — eval-harness cross-mount preserves all 3 fail-closed fences (env flag + APP_ENV + Gate). Cross-mount changes nothing on the BE side.
- [x] W4 — 2 NEW env knobs (`EVAL_NIGHTLY_ADVERSARIAL`, `EVAL_NIGHTLY_ADVERSARIAL_DATASETS`) default OFF/empty. v4.3.0 hosts upgrading to v4.4.0 see byte-identical eval:nightly behaviour (baseline-only) until they explicitly opt in.

## Acceptance verdict

All eight gates (A–H) pass. The v4.4 cycle is **ready for GA merge**. W4.B fires the `feature/v4.4` → `main` merge per R37 and tags `v4.4.0` at the merge SHA.

## Notable parking-lot items (NOT blockers)

- **flow-admin iframe stays forever** — `padosoft/laravel-flow-admin` is Blade + Alpine, not React, so cross-mount does not apply (per ADR 0005). Iframe remains the canonical mount mode for this surface.
- **Per-lane adversarial alerting** — currently the W4 adversarial nightly opt-in is advisory-only (Log::warning + summary sidecar; no Log::alert on adversarial regression). Future v4.5+: when refusal-quality manifests stabilise, individual adversarial lanes can be promoted to alert-firing via a per-lane env knob (out of scope for v4.4 per ADR 0007).
- **eval-harness-ui sub-routes via host TanStack Router** — currently the cross-mount keeps the package's `BrowserRouter` for sub-page navigation (8 pages). Future polish PR could thread the host TanStack Router into the package's route tables for unified routing, but the current two-router-by-scope approach has zero functional impact (only ~14 KB bundle cost for `react-router-dom`).

## What's next — v4.5 backlog

- Per-lane adversarial alerting (small operational follow-up; gated on a few weeks of stable adversarial nightly baseline data).
- TanStack Router unification for the cross-mounted eval-harness-ui (cosmetic polish; zero functional impact).
- Whatever new sister-package versions ship in the v4.5 window — typically incremental v1.x bumps with no new integration scope expected.

## R39 GA tag (W4.B)

```bash
git fetch origin --prune
GA_SHA=$(git rev-parse origin/main)  # captured AFTER W4.B merge fires
gh release create v4.4.0 \
  --repo lopadova/AskMyDocs \
  --target "$GA_SHA" \
  --title "v4.4.0 — Tailwind v4 + admin SPA cross-mount + adversarial nightly opt-in GA" \
  --notes "v4.4.0 GA - Tailwind v4 host migration (W1) + iframe -> cross-mount of pii-redactor-admin (W2) + iframe -> cross-mount of eval-harness-ui (W3) + eval-harness adversarial nightly opt-in (W4). NO new sister packages or version bumps; only react-router-dom + lucide-react + Tailwind v4 + @tailwindcss/vite added on the FE. flow-admin stays iframe-mounted forever (Blade + Alpine, not React). 4 sub-PRs (#136 #138 #140 #142) + 4 closure docs PRs (#137 #139 #141 + this) + 3 weekly RC tags (rc1/rc2/rc3) + 1 GA merge PR (W4.B). +15 PHPUnit tests (1408 -> 1423), +17 react vitest scenarios (304 -> 321). ADR 0005 + ADR 0007. Closure: docs/v4-platform/STATUS-2026-05-11-v44-week4-rc-acceptance.md."
```
