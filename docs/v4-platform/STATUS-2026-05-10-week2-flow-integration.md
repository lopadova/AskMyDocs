# v4.2 Week 2 closure — 2026-05-10 — `padosoft/laravel-flow` v1.0 integration

W2 of the v4.2 cycle migrates AskMyDocs's multi-step background pipelines
onto `padosoft/laravel-flow` v1.0 (saga / compensation engine), graduating
the package from `require-dev` v0.1 (vendored, zero call sites in v4.0)
to `require` v^1.0 with **9 Flow definitions** orchestrating the entire
ingest, canonical-graph, promotion, delete, and scheduled-maintenance
surface area.

This document is the W2 closure artefact per R39 (RC tag per weekly
milestone). Closure SHA pinned in §RC tag below.

## Sub-PRs shipped (W2)

| Sub-PR | Reference PR | Closure SHA on `feature/v4.2` | Scope |
|---|---|---|---|
| **3a** — laravel-flow v1.0 install + migrations | [#114](https://github.com/lopadova/AskMyDocs/pull/114) | `3b92951` | Composer constraint move (`require-dev` `^0.1` → `require` `^1.0`); 4 published migrations + tenant-scoped supplementary migration adding `tenant_id` to all 5 Flow tables + composite UNIQUE `(tenant_id, idempotency_key)` on `flow_runs` |
| **3b** — IngestDocumentJob → IngestDocumentFlow refactor | [#115](https://github.com/lopadova/AskMyDocs/pull/115) | `76a8ba1` | 5-step `kb.ingest` saga (parse-markdown → chunk-document → embed-chunks → persist-chunks → maybe-dispatch-canonical-indexer); `RollbackChunksCompensator`; `IngestDocumentJob` becomes thin Flow dispatcher with try/finally TenantContext restore; idempotency key `(tenant_id:project_key:source_path:version_hash)`; full R30 audit |
| **3c** — Flow-orchestrate canonical pipelines | [#116](https://github.com/lopadova/AskMyDocs/pull/116) | `ef6fd1b` | 3 new Flow definitions: `kb.canonical-index` (3 steps + `RollbackCanonicalNodesCompensator`), `kb.promote` (4 steps with `approval-gate` primitive — first use of approval token in AskMyDocs; `WriteCanonicalMarkdownStep` + `DeleteCanonicalMarkdownCompensator`), `kb.delete` (4 steps + `RestoreSoftDeletedCompensator`); `KbPromotionController::approve` / `reject` endpoints; `flow_audit` → `kb_canonical_audit` bridge for `rejected_promotion` events |
| **3d** — Flow-orchestrate scheduled commands + folder fan-out | [#117](https://github.com/lopadova/AskMyDocs/pull/117) | `60fac70` | 5 new Flow definitions: `kb.prune-deleted`, `kb.prune-embedding-cache` (with conditional approval gate — pauses only when projected evictions > `KB_EMBEDDING_CACHE_APPROVAL_THRESHOLD`, default 5000; auto-resolves under threshold; dry-run always bypasses), `kb.prune-chat-logs`, `kb.rebuild-graph` (3-step fan-out), `kb.ingest-folder` (3-step fan-out with optional orphan prune); 5 CLI commands refactored to thin Flow wrappers preserving CLI signatures verbatim; `DocumentDeleter::deleteOrphans()` extended with `?string $tenantId` parameter (R30 cross-tenant orphan isolation) |

**Cycle-wide test count delta on `feature/v4.2` HEAD:** 1198 (start of W2) → 1306 (end of W2) — **+108 new tests**, all green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest + Playwright E2E.

## Flow definitions registered (9 total at end of W2)

The table reflects what `App\Providers\FlowServiceProvider::registerDefinitions()`
actually registers and what the corresponding callers actually pass to
`FlowExecutionOptions::make()`. Where the column reads "—" for the
idempotency key, the caller intentionally passes only `correlationId`
(typically the tenant id) so re-runs always re-execute — for `kb.promote`
because each promotion is a deliberate operator action, and for `kb.delete`
because the underlying state mutation is itself idempotent (soft-delete +
forceDelete).

| Definition | Steps | Approval gate | Compensator(s) | Idempotency-key shape |
|---|---|---|---|---|
| `kb.ingest` | 5 | — | `RollbackChunksCompensator` | `{tenant}:{project}:{relative_path}` (SHA-256-hashed tail when raw key > 200 chars; `version_hash` is intentionally excluded — saga must dedupe before parse-step reads bytes) |
| `kb.canonical-index` | 3 | — | `RollbackCanonicalNodesCompensator` | `canonical-index:{tenant}:{doc_id}:{version_hash}` (or `+hrtime nonce` when `forceReindex=true`, used by `kb:rebuild-graph`) |
| `kb.promote` | 4 | `approval-gate` (always) | `DeleteCanonicalMarkdownCompensator` | — (only `correlationId={tenant}`; promotions are deliberate operator actions, never auto-replayed) |
| `kb.delete` | 4 | — | `RestoreSoftDeletedCompensator` | — (only `correlationId={tenant}`; soft-delete + forceDelete are idempotent at the data layer) |
| `kb.prune-deleted` | 2 | — | none (irreversible by design) | `prune-deleted:{tenant}:{cutoff_iso}` |
| `kb.prune-embedding-cache` | 3 | conditional via `paused()` | none (cache rebuilds on miss) | `prune-embedding-cache:{tenant}:{cutoff_iso}` |
| `kb.prune-chat-logs` | 2 | — | none (operational telemetry) | `prune-chat-logs:{tenant}:{cutoff_iso}` |
| `kb.rebuild-graph` | 3 | — | none (markdown is source-of-truth) | `rebuild-graph:{tenant}:{project-or-all}:{hrtime nonce}` |
| `kb.ingest-folder` | 3 | — | per-file kb.ingest sub-flow owns its own | `ingest-folder:{tenant}:{disk}:{base}:{hrtime nonce}` |

## R36 review-loop summary

The four sub-PRs took **3, 5, 4, 2** Copilot review iterations respectively
(under the 5-iteration cap). Recurring class of bug across iterations 1
of every PR: **R30 reads on tenant-aware tables**. The `BelongsToTenant`
trait auto-fills `tenant_id` only on CREATE, not on READ — every
`Eloquent::find()` / `where()` / `count()` / `update()` / `delete()` on
a tenant-aware table needs an explicit `forTenant($tenantId)` filter, and
the iteration-1 reviews on every PR caught at least one unscoped read.
Documented as a permanent reminder inside `feedback_v42_full_feature_integration.md`.

Sub-PR 3d iteration 1 also caught an R30 hole in
`DocumentDeleter::deleteOrphans()` (a method that pre-dates the v4.2
work — fix landed inside this PR rather than as a follow-up so the new
fan-out path is safe from day 1).

## R39 RC tag

```bash
CLOSURE_SHA=$(git rev-parse origin/feature/v4.2)
gh release create v4.2.0-rc2 \
  --repo lopadova/AskMyDocs \
  --target "$CLOSURE_SHA" \
  --title "v4.2.0-rc2 — W2 milestone (laravel-flow v1.0 integration)" \
  --prerelease \
  --notes "Laravel Flow v1.0 integration: 8 Flow definitions orchestrate the entire AskMyDocs background pipeline surface (ingest, canonical-index, promote with approval gate, delete, 5 scheduled commands + folder fan-out). 4 sub-PRs (#114, #115, #116, #117). +108 PHPUnit tests. Closure: docs/v4-platform/STATUS-2026-05-10-week2-flow-integration.md"
```

The tag is captured BEFORE the docs PR merges, then attached to the
exact `feature/v4.2` HEAD that closed W2 (per R39 the RC must be pinned
to a frozen SHA — never the moving branch ref).

## What's next — W3

`v4.2.0-rc3` will close W3 and ship sub-PR 4: `padosoft/eval-harness`
v0.1 → v1.2 bump + a real RAG regression CI gate
(`.github/workflows/rag-regression.yml`). Cost guard: registrar uses
`Http::fake()` in CI; live mode opt-in via `EVAL_LIVE_AI=1` env var
(mirrors `feedback_package_live_testsuite_opt_in`).
