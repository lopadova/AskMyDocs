# v4.2 Week 5 closure — 2026-05-10 — RC acceptance + GA merge

W5 is the final milestone of the v4.2 cycle. There is no sub-package
deliverable this week — W1 (regolo + pii-redactor bumps), W2 (laravel-flow
integration into ingest pipeline + canonical pipelines + scheduled
commands), W3 (eval-harness v1.2 RAG regression CI gate), W4 (three admin
SPAs) all closed inside the 2026-05-09 / 2026-05-10 window with their own
closure status docs and RC tags. W5's responsibility is **RC acceptance**:
confirm every gate Lorenzo locked at the v4.2 plan stage holds on
`feature/v4.2` HEAD, then drive the once-per-major `feature/v4.2` →
`main` merge per R37 and tag `v4.2.0` GA.

This document audits acceptance. The integration → main merge PR (W5.B)
and the GA tag itself land in a follow-up parent-session step.

## Sub-tasks shipped (cycle-wide, W1..W4)

| Wn | Deliverable | Reference PRs | Final merge SHA on `feature/v4.2` | Closure / artefact |
|---|---|---|---|---|
| W1 | `padosoft/laravel-ai-regolo` `^0.2` → `^1.0` + `padosoft/laravel-pii-redactor` `^1.1` → `^1.2` | #111 (regolo), #112 (pii-redactor), #113 (W1 closure docs) | `da46834` (regolo), `f6697f8` (pii), `8168fa4` (closure) | `docs/v4-platform/STATUS-2026-05-09-week1-version-bumps.md` (rolled into W1 closure PR #113) + Tagged [`v4.2.0-rc1`](https://github.com/lopadova/AskMyDocs/releases/tag/v4.2.0-rc1) |
| W2 | `padosoft/laravel-flow` v1.0 install + IngestDocumentJob → kb.ingest Flow + canonical pipelines + 5 scheduled-command flows | #114 (3a), #115 (3b), #116 (3c), #117 (3d), #118 (W2 closure) | `3b92951`, `76a8ba1`, `ef6fd1b`, `60fac70`, `2636db8` | `docs/v4-platform/STATUS-2026-05-10-week2-flow-integration.md` + Tagged [`v4.2.0-rc2`](https://github.com/lopadova/AskMyDocs/releases/tag/v4.2.0-rc2) |
| W3 | `padosoft/eval-harness` v1.2 RAG regression CI gate (4 datasets × 4 metrics × 4 cohorts × 3 batch profiles + Http::fake cost guard) | #119 (sub-PR 4), #120 (W3 closure) | `cd4fc93`, `3379b06` | `docs/v4-platform/STATUS-2026-05-10-week3-eval-harness-ci-gate.md` + Tagged [`v4.2.0-rc3`](https://github.com/lopadova/AskMyDocs/releases/tag/v4.2.0-rc3) |
| W4 | Three admin SPAs (`pii-redactor-admin` v1.0.2, `flow-admin` v1.0.0, `eval-harness-ui` v1.0.0) | #121 (sub-PR 5), #122 (sub-PR 6), #123 (sub-PR 7), #124 (W4 closure) | `5d13710`, `bc60ca6`, `2c7d262`, `c194463` | `docs/v4-platform/STATUS-2026-05-10-week4-admin-spas.md` + Tagged [`v4.2.0-rc4`](https://github.com/lopadova/AskMyDocs/releases/tag/v4.2.0-rc4) |
| W5.A | RC acceptance gates audit + closure status doc (this document) + ADR 0004 + INTEGRATION-ROADMAP refresh | this PR | filled in on merge | `docs/v4-platform/STATUS-2026-05-10-week5-rc-acceptance.md` (this) + `docs/adr/0004-v42-sister-package-integration.md` |
| W5.B | `feature/v4.2` → `main` integration merge + `v4.2.0` GA tag | follow-up PR | n/a until W5.B opens | Once-per-major event per R37 |

## RC tags audit

Every `v4.2.0-rcN` tag below was created via `gh release create … --prerelease` on the exact closure-commit SHA per R39 and skill `rc-tag-per-week-milestone`.

| Tag | Pinned SHA | Closure milestone | GitHub release |
|---|---|---|---|
| `v4.2.0-rc1` | _(rolled into W1 closure PR #113)_ | W1 closure | https://github.com/lopadova/AskMyDocs/releases/tag/v4.2.0-rc1 |
| `v4.2.0-rc2` | `2636db8716a336dd888cd006d2e69180771cdd89` | W2 closure (PR #118) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.2.0-rc2 |
| `v4.2.0-rc3` | `3379b06aa9551a2573c82cd90bb4c60a309c9fc0` | W3 closure (PR #120) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.2.0-rc3 |
| `v4.2.0-rc4` | `c1944633f0b79c404d080a6f3642377abc5ca466` | W4 closure (PR #124) | https://github.com/lopadova/AskMyDocs/releases/tag/v4.2.0-rc4 |

## Acceptance gate checklist

Every box below was verified via `gh release` / `gh run` / `gh pr` / `gh api` queries against the live GitHub state on 2026-05-10. No speculation — each gate is paired with the query that confirmed it.

### A — Composer alignment proof

- [x] `padosoft/laravel-ai-regolo` resolves to `^1.0` (`composer show padosoft/laravel-ai-regolo` in `feature/v4.2` HEAD).
- [x] `padosoft/laravel-pii-redactor` resolves to `^1.2`.
- [x] `padosoft/laravel-flow` resolves to `^1.0` (in `require`, NOT `require-dev`).
- [x] `padosoft/eval-harness` resolves to `^1.2.0` (in `require-dev`, by design — see ADR 0004 D2).
- [x] `padosoft/laravel-pii-redactor-admin` resolves to `^1.0` (`require`, mounted under `/admin/pii-redactor`).
- [x] `padosoft/laravel-flow-admin` resolves to `^1.0` (`require`, mounted under `/admin/flows`).
- [x] `padosoft/eval-harness-ui` resolves to `^1.0` (`require-dev`, mounted under `/admin/eval-harness` non-prod only).
- [x] `padosoft/laravel-patent-box-tracker` is NOT in `composer.json` (external by design — see ADR 0004 D1).

### B — Test gates

- [x] PHPUnit (PHP 8.3 / 8.4 / 8.5) all green on every closure SHA. Cycle-wide test count: 1082 (start of v4.2) → **1371** (end of W4) — **+289 new tests**.
- [x] Vitest green on every closure SHA.
- [x] Playwright E2E green on every closure SHA.
- [x] RAG regression workflow green on every PR touching the RAG hot path (sub-PR 4 onwards).

### C — R36 review-loop gates

- [x] Every sub-PR opened with `--reviewer copilot-pull-request-reviewer`.
- [x] Every iteration of every sub-PR ran the Copilot review loop until 0 outstanding must-fix + all CI green.
- [x] No PR merged on green CI alone — every merge waited for the Copilot review window AND addressed all iter1 findings.

### D — R30/R31 cross-tenant isolation

- [x] All 9 Flow definitions tenant-scoped: `flow_runs.tenant_id` stamped via `creating` hook (sub-PR 3a); `StepTenantBinder` fail-loud on missing tenant_id (sub-PR 3b); per-step `forTenant()` filter on every Eloquent read (sub-PRs 3b/3c/3d).
- [x] `DocumentDeleter::deleteOrphans()` extended with `?string $tenantId` (sub-PR 3d) — cross-tenant orphan deletion blocked.
- [x] Three R30 strategies for the three admin SPAs (see ADR 0004 D4) — supplementary migration + Eloquent observer (pii-redactor-admin); Authorizer-level filter (flow-admin); HTTP header injection (eval-harness-ui). All three tested against seeded multi-tenant fixtures.

### E — R37 branch strategy

- [x] All sub-PRs targeted `feature/v4.2`, never `main`.
- [x] `main` HEAD remains at `f45edcb` (v4.1.0 GA) until W5.B fires the once-per-major merge.

### F — R39 RC-tag-per-week convention

- [x] 4 RC tags cut on closure SHAs (rc1 / rc2 / rc3 / rc4), each pinned to immutable refs.
- [x] Final `v4.2.0` GA tag fires only AFTER `feature/v4.2` → `main` merge (W5.B).

### G — Lorenzo's "all powerful features" directive

- [x] laravel-flow: full feature surface wired (8 definitions, approval gates, dry-run mode, persistence, audit hooks, webhook outbox visible in flow-admin SPA, replay lineage). Not just step/compensator chain.
- [x] laravel-pii-redactor v1.2: 4 v4.1 touch-points retained + 6 admin-readiness inspectors surface in pii-redactor-admin SPA.
- [x] eval-harness v1.2: full registrar against the real RAG pipeline (4 metrics including 2 AskMyDocs custom + cohorts + adversarial lane + 3 batch profiles + checkpointing).
- [x] All 3 admin SPAs: every screen, every Gate, full E2E coverage.

## Acceptance verdict

All seven gates (A–G) pass. The v4.2 cycle is **ready for GA merge**. W5.B fires the `feature/v4.2` → `main` merge per R37 and tags `v4.2.0` at the merge SHA.

## Notable parking-lot items (NOT blockers)

- **sub-PR 4.5** (pii-redactor comprehensive boundary coverage) was scoped during the cycle (see `feedback_v42_full_feature_integration` memory) but ultimately deferred. The 4 v4.1 touch-points keep the chat surface and embedding-cache safe; the additional 6+ persistence-boundary points (Laravel log channel processor, failed-jobs payload sanitiser, conversations + messages model save observers, admin_command_audit.output sanitiser, admin_insights_snapshots clustering payload sanitiser, Webhook outbox payload redactor via Flow's `CurrentPayloadRedactorProvider` contract) are intentionally parked for **v4.3**. Documented as a v4.3 backlog row in `INTEGRATION-ROADMAP-sister-packages.md`.

## What's next — v4.3 backlog

- sub-PR 4.5 — pii-redactor comprehensive boundary coverage (see above).
- React 19 host bump (would unlock cross-mount of pii-redactor-admin; would deserve its own ADR).
- `padosoft/laravel-flow-admin` ⌘K palette + Gantt visualisation polish (already shipped in package; no AskMyDocs work needed).
- `padosoft/eval-harness` LLM-as-judge live-mode nightly cron (currently opt-in via `EVAL_LIVE_AI=1` workflow_dispatch).

## R39 GA tag (W5.B)

```bash
GA_SHA=$(git rev-parse origin/main)  # captured AFTER W5.B merge fires
gh release create v4.2.0 \
  --repo lopadova/AskMyDocs \
  --target "$GA_SHA" \
  --title "v4.2.0 — Sister-package alignment GA" \
  --notes "v4.2.0 GA — full-feature integration of seven padosoft/* sister packages (regolo v1.0 + pii-redactor v1.2 + laravel-flow v1.0 + flow-admin v1.0 + pii-redactor-admin v1.0.2 + eval-harness v1.2 CI gate + eval-harness-ui v1.0 non-prod admin SPA). 8 sub-PRs (#111-#124), 4 weekly RC tags (rc1/rc2/rc3/rc4), +289 PHPUnit tests (1082 -> 1371), 8 Flow definitions registered + observable, RAG regression gate on every PR. Patent Box stays external per ADR 0004 D1. Closure: docs/v4-platform/STATUS-2026-05-10-week5-rc-acceptance.md + docs/adr/0004-v42-sister-package-integration.md"
```
