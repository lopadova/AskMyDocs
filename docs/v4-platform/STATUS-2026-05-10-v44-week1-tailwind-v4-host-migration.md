# v4.4 Week 1 closure — 2026-05-10 — Tailwind v3 → v4 host migration

W1 of the v4.4 cycle ships the **AskMyDocs frontend host SPA migration from
Tailwind v3.4 (PostCSS pipeline) to Tailwind v4** (`@tailwindcss/vite`
plugin). This is the **hard prerequisite** for the v4.4/W2 + v4.4/W3
cross-mount of `pii-redactor-admin` and `eval-harness-ui` per ADR 0005:
the admin SPAs ship Tailwind v4 + React 19 internally, so cross-mounting
them on a Tailwind v3 host would force two CSS engines to coexist on the
same page (the iframe-mount workaround the v4.2 cycle already pays for).
W1 unblocks W2/W3 by aligning the host's Tailwind major.

This document is the W1 closure artefact per R39. The §RC tag block
below captures the closure SHA at tag-creation time via `git rev-parse
origin/feature/v4.4` (run AFTER this docs PR merges and BEFORE any
subsequent commit lands on `feature/v4.4`); the resulting tag points at
an immutable commit per R39's "exact closure-commit SHA" convention.

## Sub-PR shipped (v4.4 W1)

| Sub-PR | Reference PR | Closure SHA on `feature/v4.4` | Scope |
|---|---|---|---|
| **W1** — Tailwind v3 → v4 host migration | [#136](https://github.com/lopadova/AskMyDocs/pull/136) | `860d0aa` | `tailwindcss` `^3.4.14` → `^4.0.0` (drops `autoprefixer` + `postcss` runtime deps; adds `@tailwindcss/vite` plugin). `vite.config.ts` adds the `tailwindcss()` plugin. `frontend/src/styles/globals.css` replaces the v3 `@tailwind base / components / utilities` directives with `@import "tailwindcss"` + a single `@theme` block (font stack + accent tokens) + a `@custom-variant dark` rule preserving the v3 `darkMode: ['class', '[data-theme="dark"]']` selector contract. `tailwind.config.ts` + `postcss.config.js` deleted (v4 auto-detects content; no PostCSS step). `frontend/tsconfig.node.json` purged of the deleted-file references. `package.json` declares `engines: { node: ">=20" }` (Tailwind v4's transitive `@tailwindcss/oxide` requires Node 20+). Iter 2 fixes 4 Copilot findings: HIGH `@theme` self-reference cycle (renamed `--font-sans` → `--font-stack-sans` in tokens.css; `@theme` reads from the new name); MEDIUM `@custom-variant dark` extended to also cover `.dark` class for forward-compat with cross-mounting sister-package SPAs; LOW `tsconfig.node.json` housekeeping; LOW `engines.node`. Copilot SWE auto-pushed an additive polish (accent-a/accent-b sourced from `--accent-a`/`--accent-b` tokens for single-source-of-truth). |

**Cycle test count delta on `feature/v4.4` HEAD:** 1408 (start of v4.4 from v4.3.0 GA) → **1408** (end of W1) — **no test count delta** (the migration is dependency + build-config only; existing tests cover the React 19 + Tailwind utility surface). All green across PHPUnit (PHP 8.3 / 8.4 / 8.5) + Vitest (react + legacy) + Playwright E2E + the RAG regression workflow.

## Why this PR is small

The migration is intentionally **scope-tight on the host SPA**:

- **Project uses Tailwind sparingly** — design system lives in `frontend/src/styles/tokens.css` with CSS variables; chat / admin / panels / popovers all use inline `style={{ ... var(--token) }}` instead of utility classes (per the convention documented at the top of the deleted `tailwind.config.ts`). Pre-flight grep confirmed: zero bare `border` / `ring` / `divide-x|y` utilities (which would have hit v4's default-color change to `currentColor`); zero `@apply` / `@layer` directives in `globals.css` / `tokens.css` (which would have hit v4's preflight reset surface); zero deprecated v3 utilities (`bg-opacity-*`, `text-opacity-*`, `border-opacity-*`, `placeholder-opacity-*`, `ring-opacity-*`, `divide-opacity-*`, `flex-grow`, `flex-shrink`, `decoration-slice`, `decoration-clone`, `overflow-ellipsis`).
- **Empirical CSS comparison**: build output v3 = 15.12 kB / gzip 4.28 kB → v4 = 30.21 kB / gzip 7.40 kB. The 2x growth is purely Tailwind v4's enriched `@layer properties` block + fuller preflight reset — purely additive, no removed selectors, no semantic regression.
- **Selector contract preserved**: `@custom-variant dark (&:where([data-theme="dark"], [data-theme="dark"] *, .dark, .dark *))` covers both v3 darkMode toggles (`<html class="dark">` AND `<html data-theme="dark">`) so every existing `dark:*` utility activates identically. Forward-compat for the W2/W3 cross-mount work where sister-package SPAs may set `class="dark"` instead of `data-theme`.

## R7 / R9 / R14 disciplines applied

- **R7**: zero `@`-silenced errors; build surfaces every Tailwind v4 warning loudly.
- **R9**: docs match code — pre-flight grep validated zero stale doc references to `tailwind.config.ts` / `postcss.config.js` after deletion. `frontend/tsconfig.node.json` `include` array purged of the deleted file paths.
- **R14**: build / dev-server / Vitest all surface failures loudly — no silent-fall-through paths.

## Default-off invariant preserved

The migration changes ZERO behaviour at runtime — the compiled CSS is functionally equivalent (modulo Tailwind v4's enriched preflight which only affects raw HTML elements, of which `react-markdown` output is the only consumer in the chat UI; both v3 and v4 reset h1-h6 to `inherit` so output is identical). A v4.3.0 host upgrading to v4.4.0-rc1 sees byte-identical visual rendering until subsequent W2/W3 cross-mount work changes the DOM tree.

## R36 review-loop summary

PR #136 took **2 effective iterations** plus **1 Copilot SWE auto-push** under the 5-iteration cap. Iter 1 surfaced 4 findings (1 HIGH `@theme` self-reference; 1 MEDIUM `@custom-variant dark` contract; 2 LOW housekeeping). Iter 2 (`10cf0fc`) addressed all 4. Copilot SWE auto-pushed a 4-line polish (`7eddb20`) sourcing accent-a/accent-b from existing `--accent-a`/`--accent-b` tokens for single-source-of-truth. The repo's "approval required for first-time bot pushes" setting paused CI on `7eddb20`; manually re-running unstuck the workflow. Merged at iteration 3 closure with all CI green and 0 outstanding must-fix.

## R39 RC tag

```bash
CLOSURE_SHA=$(git rev-parse origin/feature/v4.4)
gh release create v4.4.0-rc1 \
  --repo lopadova/AskMyDocs \
  --target "$CLOSURE_SHA" \
  --title "v4.4.0-rc1 — W1 milestone (Tailwind v4 host migration)" \
  --prerelease \
  --notes "Host SPA migrated from Tailwind v3.4 PostCSS pipeline to Tailwind v4 + @tailwindcss/vite plugin. Hard prerequisite for v4.4/W2 + v4.4/W3 cross-mount of pii-redactor-admin + eval-harness-ui per ADR 0005. tailwindcss ^3.4.14 -> ^4.0.0; drops autoprefixer + postcss; adds @tailwindcss/vite. tailwind.config.ts + postcss.config.js deleted (v4 auto-detects content). globals.css uses @import + @theme + @custom-variant dark to preserve the v3 darkMode contract (both [data-theme=\"dark\"] and .dark selectors). package.json declares engines.node >=20 for Tailwind v4's transitive @tailwindcss/oxide. Vitest (react + legacy) + full PHPUnit (PHP 8.3/8.4/8.5) + Playwright + RAG regression all green. 1 sub-PR (#136). Closure: docs/v4-platform/STATUS-2026-05-10-v44-week1-tailwind-v4-host-migration.md"
```

## What's next — W2

`v4.4.0-rc2` will close W2 and ship the **iframe → cross-mount of `padosoft/laravel-pii-redactor-admin`** at `/admin/pii-redactor`. Vendor / alias the admin SPA bundle into the host Vite build to share React + ReactDOM singletons; thread the host TanStack Router into the admin SPA route tables; thread the host React Query client + Sanctum cookie scope into the admin SPA fetch layer; rework Playwright storage-state setup. The 3 R30 strategies established in v4.2/W4 ADR 0004 D4 stay valid: pii-redactor-admin's supplementary migration + Eloquent observer pattern is unaffected by the mount-mode change.
