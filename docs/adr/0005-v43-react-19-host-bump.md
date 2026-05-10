# ADR 0005 — v4.3/W2 React 19 host bump

**Status**: Accepted
**Date**: 2026-05-10
**Cycle**: v4.3 W2

## Context

ADR 0004 D5 (v4.2 GA, 2026-05-10) documented why every v4.2 admin SPA is
iframe-mounted: incompatible React majors / Tailwind versions between
the host SPA and the three padosoft admin packages.

| Surface | React major (v4.2) | Tailwind (v4.2) |
|---|---|---|
| AskMyDocs host SPA | 18.3 | v3.4 |
| `padosoft/laravel-pii-redactor-admin` v1.0.2 | **19** | **v4** |
| `padosoft/eval-harness-ui` v1.0.0 | **19** | v3.4 |
| `padosoft/laravel-flow-admin` v1.0.0 | n/a (Blade + Alpine) | v3.4 |

The iframe mount works and ships v4.2 GA cleanly, but it has measurable
costs:

1. **Bundle weight** — each admin SPA ships its own React + ReactDOM +
   query client, paid on every operator visit, never shared with the
   host's already-loaded copies.
2. **State isolation** — the host's TanStack Router cannot see the
   iframe's URL transitions, so back-button + deep-link UX in the
   admin SPAs is degraded relative to the rest of the shell.
3. **Style isolation tax** — the iframe's Tailwind v4 bundle never
   benefits from the host's purge pass. Two CSS engines load on the
   same page.
4. **Auth/CSRF round-trip** — the iframe re-bootstraps Sanctum every
   navigation because the cookie scope is path-restricted to the
   iframe URL.

React 19 has been GA since 2024-12; by this ADR date (2026-05) that is
roughly **17 months** of production wear at adopting shops. Vercel +
Vite + TanStack Router 1.81 + React Query 5 all stabilised on React 19
long ago. The window to bump cleanly has been open for many months;
v4.3/W2 is when AskMyDocs picks it up so v4.4 can move the admin SPAs
from iframe to cross-mount.

The v4.3/W2 milestone scope is the host React major bump only. The
follow-on cross-mount migration is a v4.4 deliverable.

## Decision

**Bump the AskMyDocs host SPA from React 18.3.1 to React 19.2.6** in
the v4.3/W2 PR. Defer the Tailwind v3 → v4 migration AND the actual
iframe → cross-mount migration to separate, scope-clean PRs (Tailwind
v4 in v4.3/W3 or v4.4; cross-mount in v4.4).

### Why this PR is scope-tight

- **Bump only**: `react`, `react-dom`, `@types/react`, `@types/react-dom`
  to v19.2.x. No code changes required — pre-flight grep confirmed
  zero `defaultProps` on function components, zero `findDOMNode`, zero
  `UNSAFE_*` lifecycles, zero `ReactDOM.render` (everything goes
  through `createRoot` already, and the React 19 `createRoot`
  signature is unchanged from React 18).
- **Vite plugin unchanged**: `@vitejs/plugin-react` ^4.3.3 supports
  React 18 AND React 19 from the same release line. No plugin bump.
- **Vitest unchanged**: `@testing-library/react` ^16 supports React 19;
  no test-runner changes required.
- **Tests pass green**: 304/304 Vitest tests pass post-bump with
  identical warning count to pre-bump (5 preexisting `act()` warnings
  in `CommandWizard.test.tsx` and `CommandPalette.test.tsx` —
  unchanged, not introduced by the bump).
- **Build clean**: `npm run build` succeeds; no chunk-size regression.

### Why Tailwind v4 stays deferred

Tailwind v4 uses a different config surface (`@tailwindcss/vite` plugin
instead of `tailwind.config.ts` PostCSS pipeline), a different
preflight reset, a different theme-token API (`@theme` directive
instead of `theme.extend.colors`), and migrates ~40 utility classes.
That migration is its own scope and its own review surface; bundling
it with the React major bump would conflate two independent risk
profiles. The pii-redactor-admin iframe continues to ship its own
Tailwind v4 inside the iframe boundary at no cost to the host.

Lorenzo's preference (locked 2026-05-10): land the React bump as a
small, clean, easy-to-review PR; treat Tailwind v4 + cross-mount as
v4.4 deliverables once v4.3 GA is out the door.

### Why cross-mount stays deferred to v4.4

Cross-mounting `padosoft/laravel-pii-redactor-admin` and
`padosoft/eval-harness-ui` into the host React tree requires:

1. Vendoring or aliasing the admin SPA bundles into the host Vite
   build so they share the React + ReactDOM singletons.
2. Threading the host's TanStack Router into the admin SPA route
   tables (or wrapping each admin SPA as a child route).
3. Threading the host's React Query client + Sanctum cookie scope
   into the admin SPA fetch layer.
4. Reworking the admin SPA storage-state setup in Playwright (the
   admin SPAs currently use their own `viewer.json` storage; cross-
   mount changes the cookie scope).
5. Reconciling Tailwind v3 (host) vs Tailwind v4 (pii-redactor-admin)
   — which is exactly why the Tailwind v4 migration is a hard
   prerequisite, hence the v4.4 ordering.

That work is significant and warrants its own ADR + closure cycle. The
v4.3/W2 PR delivers the **enabling** prerequisite (host on React 19)
without consuming the budget of the cross-mount migration itself.

## Consequences

### Immediate (this PR)
- Host SPA runs on React 19.2.6.
- All host SPA components type-clean against `@types/react` 19.2.x.
- 304/304 Vitest tests green.
- Production build green; bundle size unchanged from v4.2 GA baseline
  (within rounding — React 19's runtime is marginally larger but
  scheduler changes net out to no observable size delta).
- CI matrix unchanged (Playwright still runs against `php artisan
  serve` + Vite dev server with no new flag).

### Pending (v4.3/W3 or v4.4)
- Tailwind v3 → v4 host migration (separate PR; unlocks (a) shared
  CSS bundle with pii-redactor-admin, (b) preflight + theme-token
  consolidation across host + admin SPAs).
- Iframe → cross-mount of pii-redactor-admin + eval-harness-ui
  (v4.4 deliverable, gated on Tailwind v4 landing first).
- `flow-admin` stays iframe-mounted **forever**: it's Blade + Alpine,
  not React, so cross-mount does not apply.

### Compatibility risks watched
- `@ai-sdk/react` ^3 — peer-dep allows React 19 (`^18 || ~19.0.1 ||
  ~19.1.2 || ^19.2.1`). No issue.
- `@tanstack/react-router` ^1.81 — supports React 19 from 1.61+. No
  issue.
- `@tanstack/react-query` ^5.59 — supports React 19. No issue.
- `recharts` ^3.8 — React 19 compat shipped in 3.0+. No issue.
- `@codemirror/view` is React-agnostic. No issue.
- `react-hook-form` ^7.53 — React 19 compat in 7.52+. No issue.
- `react-markdown` ^10 — React 19 compat. No issue.

No transitive dep needed pinning, no peer-dep override required, no
`--legacy-peer-deps` flag added.

## Related ADRs

- **ADR 0004 D5** — sister-package iframe mount rationale (the
  motivating constraint this ADR resolves the prerequisite for).
- **Future ADR 0006** (planned, v4.4) — Tailwind v4 host migration.
- **Future ADR 0007** (planned, v4.4) — cross-mount admin SPAs.
