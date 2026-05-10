# v4.3 Week 2 closure — 2026-05-10 — React 19 host bump

W2 of the v4.3 cycle bumps the AskMyDocs frontend host SPA from
React 18.3.1 to React 19.2.6, plus a new ADR (`docs/adr/0005-v43-react-19-host-bump.md`)
documenting the decision and the deferred Tailwind v4 + cross-mount
work. The bump is intentionally scope-tight: pre-flight grep confirmed
zero `defaultProps` on function components, zero `findDOMNode`, zero
`UNSAFE_*` lifecycles, zero `ReactDOM.render` — every host component
was already React 19 compatible. No code changes required outside
the dependency manifests.

This document is the W2 closure artefact per R39. Closure SHA pinned
in §RC tag below.

## Sub-PR shipped (v4.3 W2)

| Sub-PR | Reference PR | Closure SHA on `feature/v4.3` | Scope |
|---|---|---|---|
| **W2** — React 19 host bump + ADR 0005 | [#129](https://github.com/lopadova/AskMyDocs/pull/129) | `c5f8e1b` | `react` 18.3.1 → 19.2.6, `react-dom` 18.3.1 → 19.2.6, `@types/react` 18.3.12 → 19.2.x, `@types/react-dom` 18.3.1 → 19.2.x. `@vitejs/plugin-react` ^4.3.3 unchanged (supports both majors). `@testing-library/react` ^16 unchanged (supports React 19). Vitest (react + legacy suites) green; full PHPUnit + Playwright + RAG regression all green post-bump. ADR 0005 documents the deferral of Tailwind v3 → v4 (separate scope) and iframe → cross-mount migration (v4.4 deliverable, gated on Tailwind v4 landing first). |

**Cycle test count delta on `feature/v4.3` HEAD:** 1397 (start of W2 from W1 closure SHA `9f7aa47`) → **1397** (end of W2) — **no test count delta** (the bump is dependency-only; existing tests cover the React 19 surface). All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest + Playwright E2E + the RAG regression workflow.

## Why this PR is small

ADR 0005 documents the scope rationale in detail. Summary:

- **Bump only**: react/react-dom/@types/* — no code changes required.
- **Vite plugin unchanged**: `@vitejs/plugin-react` ^4.3.3 supports both React 18 and 19.
- **Vitest unchanged**: `@testing-library/react` ^16 supports React 19.
- **Tests pass green**: Vitest (react + legacy suites), full PHPUnit + Playwright + RAG regression.
- **No transitive dep needed pinning**: every peer-dep already supports React 19.

## Why Tailwind v4 + cross-mount stay deferred

ADR 0005 is explicit: Tailwind v3 → v4 migration is its own scope (different config surface, different preflight reset, different theme-token API, ~40 utility classes migrated). Cross-mount of `pii-redactor-admin` + `eval-harness-ui` requires shared React + ReactDOM singletons, threaded TanStack Router + React Query + Sanctum cookie scope, and Playwright storage-state rework — significant work that warrants its own ADR + closure cycle.

Lorenzo's preference (locked 2026-05-10): land the React bump as a small, clean, easy-to-review PR; treat Tailwind v4 + cross-mount as v4.4 deliverables once v4.3 GA is out the door.

## R36 review-loop summary

PR #129 took **2 effective iterations** (1 mine + 1 Copilot SWE auto-fix) under the 5-iteration cap. Iter 1 surfaced 1 finding (ADR 0005 timeline inconsistency: "six months of production wear" vs React 19 GA on 2024-12 = ~17 months). Iter 2 fixed the prose + Copilot SWE auto-pushed a 6-line wording polish to clarify the timeline calculus. Merged at iteration 3 closure with all CI green and 0 outstanding must-fix.

## R39 RC tag

```bash
CLOSURE_SHA=$(git rev-parse origin/feature/v4.3)
gh release create v4.3.0-rc2 \
  --repo lopadova/AskMyDocs \
  --target "$CLOSURE_SHA" \
  --title "v4.3.0-rc2 — W2 milestone (React 19 host bump)" \
  --prerelease \
  --notes "Host SPA bumped from React 18.3.1 to 19.2.6. ADR 0005 documents the decision + the deferred Tailwind v4 (separate scope) + iframe -> cross-mount migration (v4.4, gated on Tailwind v4 first). Bump is dependency-only — zero code changes required. Vitest (react + legacy) + full PHPUnit + Playwright + RAG regression all green. 1 sub-PR (#129). Closure: docs/v4-platform/STATUS-2026-05-10-v43-week2-react-19-host-bump.md"
```

## What's next — W3

`v4.3.0-rc3` will close W3 and ship the **eval-harness LLM-as-judge nightly cron + ops polish**: scheduled nightly run via Laravel scheduler that runs eval-harness with `EVAL_LIVE_AI=1` against the real RAG pipeline (cost-controlled by smaller adversarial subset; alerting on regression delta). Operational polish items also land here.
