# ADR 0004 — v4.2 sister package integration

**Status**: Accepted
**Date**: 2026-05-10
**Cycle**: v4.2 GA

## Context

Between **2026-05-05 and 2026-05-06**, all nine `padosoft/*` sister packages graduated from v0.1 scaffolds to v1.0+ stable releases on Packagist:

| Package | v4.0 era | v4.2 GA target |
|---|---|---|
| `padosoft/laravel-ai-regolo` | v0.2.2 (wired in `require`) | **v1.0.0** (require) |
| `padosoft/laravel-pii-redactor` | v1.1.0 (wired in `require`, v4.1) | **v1.2.0** (require) |
| `padosoft/laravel-flow` | v0.1.0 (`require-dev`, vendored, zero call sites) | **v1.0.0** (require, integrated) |
| `padosoft/eval-harness` | v0.1.0 (`require-dev`, vendored, zero call sites) | **v1.2.0** (`require-dev`, CI gate only) |
| `padosoft/laravel-pii-redactor-admin` | not installed | **v1.0.2** (require, mounted) |
| `padosoft/laravel-flow-admin` | not installed | **v1.0.0** (require, mounted) |
| `padosoft/eval-harness-ui` | not installed | **v1.0.0** (`require-dev`, mounted non-prod only) |
| `padosoft/laravel-patent-box-tracker` | not installed (external) | **stays external** |
| `padosoft/laravel-patent-box-tracker-admin` | not installed (external) | **stays external** |

The v4.2 cycle had to decide, for each package, whether to integrate, where in the AskMyDocs `app/` to wire it, and what tenant-scoping strategy to use. Several decisions warrant an ADR because they would be expensive to reverse (composer dependency promotion, `require-dev` vs `require` placement, tenant-isolation strategy) and because v4.3+ contributors need the rationale to extend the pattern.

## Decisions

### D1 — Patent Box stays EXTERNAL to AskMyDocs

`padosoft/laravel-patent-box-tracker` and its admin SPA remain **uninstalled in AskMyDocs's `composer.json`**. Operators who need Italian Patent Box dossier generation install the tracker package in their own separate Laravel project and run `php artisan patent-box:cross-repo /path/to/AskMyDocs/tools/patent-box/2026.yml` from there. AskMyDocs is the **subject** of the dossier, never the tooling host.

**Why**: Patent Box is Lorenzo's personal Padosoft compliance dossier — it spans 5 repositories (AskMyDocs + 4 sister packages) and is regenerated 2-3 times per year for tax filings. Bundling the tracker into AskMyDocs would (a) couple AskMyDocs's release cadence to Italian tax law changes, (b) leak Lorenzo's business documents into every consumer's `vendor/`, and (c) violate the standalone-agnostic invariant codified in `feedback_packages_standalone_agnostic` (every `padosoft/*` package is 100% standalone, AskMyDocs USES them, never the other way around). Confirmed by Lorenzo on PR #110 (v4.1.0 GA) as the binding stance for the entire v4.x train.

### D2 — `padosoft/eval-harness` stays in `require-dev`

Despite v1.2.0 being GA-stable on Packagist, `padosoft/eval-harness` stays in AskMyDocs's `require-dev` block. The package is wired ONLY in CI (the `rag-regression` workflow on every PR touching the RAG hot path) and in the optional non-prod admin SPA `padosoft/eval-harness-ui` (sub-PR 7).

**Why**: eval-harness is a **regression detection tool**, not a runtime feature. It needs the seeded test corpus, the LLM-as-judge prompt templates, and the `Http::fake()` cost guard to make sense. Promoting it to `require` would (a) ship 200+ KB of unused autoload entries to every production deploy, (b) expose `eval-harness:run` as a callable artisan command in production where it would burn provider tokens against unseeded data, and (c) entangle eval-harness's release cadence with the AskMyDocs production train. Sub-PR 4 wires it as a CI-only dependency; sub-PR 7's admin SPA respects the same require-dev placement and uses a `class_exists()` guard in `bootstrap/providers.php` to prevent boot crashes when `composer install --no-dev` runs in production.

### D3 — `padosoft/laravel-flow` is the canonical multi-step orchestrator

Every multi-step background pipeline in AskMyDocs runs through a Flow definition (9 definitions registered in `App\Providers\FlowServiceProvider`):

1. `kb.ingest` — IngestDocumentJob refactored to thin Flow dispatcher (sub-PR 3b)
2. `kb.canonical-index` — CanonicalIndexerJob (sub-PR 3c)
3. `kb.promote` — Approval-gated promotion pipeline (sub-PR 3c)
4. `kb.delete` — DocumentDeleter (sub-PR 3c)
5. `kb.prune-deleted`, `kb.prune-embedding-cache`, `kb.prune-chat-logs`, `kb.rebuild-graph`, `kb.ingest-folder` — 5 scheduled-command flows + folder fan-out (sub-PR 3d)

**Why**: every one of these workflows used to be an imperative `Job` or `Command` with handcrafted retry/compensation/audit. Flow gives uniform semantics (idempotency keys + try/finally TenantContext restore + per-step `flow_steps` rows + per-event `flow_audit` rows + reverse-order compensation chain) for free. The 6-PR R36 review loop on sub-PRs 3a/3b/3c/3d hardened the integration against R30 cross-tenant reads + cross-flow approval-token replay + audit-write atomicity. The future `padosoft/laravel-flow-admin` SPA (sub-PR 6) makes every Flow run + every approval gate + every webhook outbox visible in a Blade+Alpine cockpit. Flow is now load-bearing for every observable AskMyDocs background pipeline.

### D4 — Three R30 strategies for the three admin SPAs

Each `*-admin` SPA needed a different tenant-isolation approach because each ships a different read-model surface:

| Package | Strategy | Why |
|---|---|---|
| `pii-redactor-admin` | Supplementary migration adds `tenant_id` to package's audit table + `creating` Eloquent observer stamps from `TenantContext` | Package owns its own audit-events table; we extend it. Simple + complete. |
| `flow-admin` | Authorizer-level filter (`AskMyDocsFlowAuthorizer`) — every row-scoped action method reads `tenant_id` via raw `DB::table` lookup and rejects on mismatch | Package's `ReadModelAdapter` API does not expose query wrapping cleanly without forking. |
| `eval-harness-ui` | TenantContext → HTTP header injection middleware (`X-Eval-Harness-Tenant`) | Package is read-only and consumes its own backend API via the configurable header. |

**Why three strategies, not one**: the packages were designed independently and ship different shapes (audit table vs. ReadModelAdapter vs. tenant-aware HTTP API). Forcing a single strategy across all three would have required forking at least one package. The three-strategy approach is documented in `docs/v4-platform/STATUS-2026-05-10-week4-admin-spas.md` with seeded multi-tenant fixture tests proving cross-tenant isolation.

### D5 — Iframe mount across all three admin SPAs

All three admin SPAs (pii-redactor-admin, flow-admin, eval-harness-ui) are iframe-mounted inside the AskMyDocs admin shell, NOT cross-mounted into the React 18 host SPA.

**Why**: pii-redactor-admin targets React 19 + Tailwind v4 (incompatible bundle); flow-admin is Blade + Alpine (no React at all); eval-harness-ui ships its own React + Vite bundle that hydrates against a Blade bootstrap. Cross-mounting any of them would require either bumping the AskMyDocs host to React 19 (out of scope for v4.2 — would deserve its own ADR) or maintaining three vendored bundle copies in our Vite config. Iframe isolation is the cleanest fail-closed mount: an exploit in the iframe can't reach the host React tree, and the host's TanStack Router never sees the iframe's URL transitions.

### D6 — Strict mixed-import Playwright pattern for admin specs

Every admin SPA spec MUST use `seededTest from './fixtures'` for the admin block AND `baseTest from '@playwright/test'` for the viewer block (mixed imports in the same file). Single-import patterns reset the DB via the `seeded` auto-fixture and invalidate the `viewer.json` storage state, cascading failures across all viewer-RBAC specs in the suite.

**Why**: established during sub-PR 5 iter 1 + sub-PR 6 iter 1, both bitten by the same regression. The pattern is captured inline in the file headers of all three new specs. The lesson sits adjacent to R12 (E2E coverage) and R13 (real-data E2E) in the project rules.

## Consequences

- AskMyDocs v4.2 GA ships with **all 7 in-scope sister packages** integrated (regolo + pii-redactor + flow + flow-admin + pii-redactor-admin + eval-harness CI + eval-harness-ui). Patent Box stays external (D1).
- The `feature/v4.2` branch ran 4 RC tags (`v4.2.0-rc1`/`rc2`/`rc3`/`rc4`) over the 4 weekly milestones, each pinned to an immutable closure SHA per R39, before merging to `main` and tagging `v4.2.0` GA per R37.
- The Flow integration (D3) is now load-bearing for every observable AskMyDocs background pipeline. Future operators monitoring the system have a single observable surface (`/admin/flows`) instead of poking at queue dashboards + log greps.
- v4.3 contributors adding any new admin SPA from the `padosoft/*` family follow the iframe + mixed-import + 3-strategy R30 pattern documented here. Cross-mount is allowed only when the package's React major matches the host (currently React 18 — would change with the React 19 bump ADR).

## Related ADRs

- ADR 0001 — canonical knowledge layer
- ADR 0002 — knowledge graph model
- ADR 0003 — promotion pipeline (sub-PR 3c approval-gate work in v4.2 builds on this)

## Closure artefacts

- `docs/v4-platform/STATUS-2026-05-10-week2-flow-integration.md` (W2)
- `docs/v4-platform/STATUS-2026-05-10-week3-eval-harness-ci-gate.md` (W3)
- `docs/v4-platform/STATUS-2026-05-10-week4-admin-spas.md` (W4)
- `docs/v4-platform/STATUS-2026-05-10-week5-rc-acceptance.md` (W5 — this RC acceptance + GA merge)
