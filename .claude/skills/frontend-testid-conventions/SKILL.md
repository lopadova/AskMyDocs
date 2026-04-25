---
name: frontend-testid-conventions
description: Every React component in the AskMyDocs frontend must expose stable data-testid attributes on actionable elements, meaningful ARIA landmarks and labels, and predictable network/error states so Playwright E2E tests can exercise complex UI flows deterministically. Trigger when editing any file under frontend/src/, when writing new components, when refactoring markup, or when adding API calls / error handling / async boundaries in the SPA.
---

# Frontend test-friendly conventions

## Rule

Every interactive element in the React SPA **must** carry a stable
`data-testid` and appropriate ARIA semantics. Every async operation
(API call, lazy load, transition) must expose observable state
(`data-state`, `aria-busy`, `aria-live`) so Playwright can wait on it
instead of relying on timeouts.

## Why this exists

The admin UI (Phases F-I) is visually dense — dashboard, tree, editor,
logs, maintenance wizard, insights deep-dives — and interactions cross
many boundaries (HTTP ↔ Zustand ↔ TanStack Query ↔ React state ↔ URL).
Unit tests catch component logic but miss the glue. Without stable
testids and observable states, Playwright selectors drift on every
cosmetic refactor and test suites become brittle. User explicitly asked
(Apr 23) that all FE work from PR5 onward be E2E-covered.

## Conventions

### 1. Stable `data-testid` on every actionable element

Required on:

- Every button, link-that-acts-like-a-button, form submit
- Every form input (`data-testid` + `name` + matching `<label>`)
- Every dialog/drawer/modal root
- Every navigable row in tables, lists, tree nodes
- Every toast/notification
- Every loading placeholder / skeleton
- The container of any async-updating region

Naming: `<feature>-<role>-<id?>`. Kebab case, no spaces. Examples:

```tsx
<button data-testid="login-submit">Sign in</button>
<input data-testid="login-email" name="email" type="email" aria-label="Email"/>
<tr data-testid={`user-row-${user.id}`}>…</tr>
<div data-testid="kb-tree" role="tree">…</div>
<div data-testid="dashboard-kpi-docs" aria-label="Total documents">{count}</div>
<div data-testid="toast-success">…</div>
<div data-testid="chat-composer-loading" aria-busy={isSending}>…</div>
```

Do **not** reuse the same `data-testid` on sibling elements unless they
are list items (in which case include an index or id suffix).

### 2. ARIA & semantic HTML

- Every page has exactly one `<main>` with `aria-label` or an `<h1>`.
- Dialogs use `role="dialog"` + `aria-modal="true"` + `aria-labelledby`.
- Tabs use `role="tablist" / tab / tabpanel` with `aria-selected`.
- Async regions use `aria-live="polite"` (status) or `assertive` (errors).
- Every button has a visible label or `aria-label`; icon-only buttons
  require `aria-label`.
- Every form input has a `<label>` bound via `htmlFor`/`id` OR an
  `aria-label`. Never rely on placeholder as the only label.

### 3. Observable async states

On any async surface, expose at least two data attributes so Playwright
can wait precisely:

```tsx
<div
  data-testid="users-table"
  data-state={isLoading ? 'loading' : isError ? 'error' : 'ready'}
  aria-busy={isLoading}
>
  {/* content */}
</div>
```

Acceptable `data-state` values: `idle | loading | ready | error | empty`.

### 4. API error surfacing

- Never silently swallow API errors. Every `useMutation`/`useQuery` with
  a user-facing side-effect must render an error UI with
  `data-testid="<feature>-error"` containing the error message.
- Use the shared `ErrorBoundary` for synchronous render errors; assign
  `data-testid="error-boundary"` to its fallback.
- HTTP 422 (validation) surfaces per-field errors with
  `data-testid="<field>-error"` next to the input.
- HTTP 401/403/429 surface via the global toast system with
  `data-testid="toast-auth-error"` / `toast-throttle` / `toast-forbidden`.

### 5. Routing observability

- The URL is the source of truth. Never render different content for
  the same URL based on internal state alone.
- When navigating programmatically, log the destination into a
  `data-testid="route-marker"` hidden element (updated by router listener).
- Guard redirects (e.g., `RequireAuth`) must set
  `data-testid="redirect-to-login"` on the redirecting placeholder
  (even if it's a fragment rendered for <50ms).

### 6. Design-first selectors, inline styles, and testids

The Claude Design port uses inline `style={{}}` extensively. That is
fine — `data-testid` is ORTHOGONAL to styling and survives refactor.
Never select elements in Playwright by class name or tag — always by
`data-testid` or `getByRole` + accessible name.

### 7. Test companion files

Alongside each feature, a `*.e2e.ts` file under
`e2e/<feature>.spec.ts` covers at least the happy path + one failure
case. Even when the feature's own Vitest tests pass, the E2E gate must
be green before a PR is opened.

## Counter-examples (DO NOT)

```tsx
// ❌ No testid on the submit button — selector must fall back to text
<button type="submit">Send</button>

// ❌ Placeholder used as label — screen readers miss it
<input placeholder="Email"/>

// ❌ Silent error — user sees nothing when POST fails
const { mutate } = useMutation({ mutationFn: api.save })

// ❌ Same testid on multiple rows — Playwright's getByTestId throws
{rows.map(r => <tr data-testid="user-row">{…}</tr>)}

// ❌ Spinner without observable state — test must sleep
{isLoading && <Spinner/>}
```

## Quick checklist before PR

- [ ] Every new button/input has a `data-testid` + accessible name.
- [ ] Every async region has `data-state` + `aria-busy`.
- [ ] Every error path renders a testid-tagged error element.
- [ ] No new selector in any existing E2E file broke (run `npm run e2e`).
- [ ] New feature has at least one `*.spec.ts` in `e2e/`.
- [ ] `npm run lint` clean, `npm run typecheck` clean, `npm run test` green.

See also `.claude/skills/playwright-e2e/SKILL.md` for the test authoring
patterns.

---

## Extension: the `data-state` value contract is enumerated — do not invent

Distilled from PR16 `r11-testid` occurrences (6 across PR28 alone).
Multiple PRs invented ad-hoc `data-state` values (`not-installed`,
`partial`, `stale`) that break the canonical async-state contract
Playwright / helpers rely on. The canonical enumeration is:

```
data-state ∈ { idle, loading, ready, empty, error }
```

Any state the UI needs to communicate that isn't one of these five
goes on a SECOND attribute — not by overloading `data-state`.
Examples:

- `<div data-testid="failed-jobs-tab" data-state="ready" data-feature="not-installed">` — the panel loaded cleanly; the FEATURE is not installed. Use a separate attribute so the five-value contract survives.
- `<div data-testid="activity-tab" data-state="empty" data-feature-gate="needs-spatie">` — the panel rendered, the DB returned zero rows, an opt-in feature hasn't shipped.
- `<div data-testid="chat-thread" data-state="loading" data-phase="retrieving">` — loading sub-state surfaces via a `data-phase` attribute, not `data-state="retrieving"`.

Rationale: `waitForReady()` in `helpers.ts` polls on
`data-state !== 'loading'`. Introducing a sixth value makes that
helper either "wait forever" (if the new value behaves like loading)
or "never wait" (if it's treated as terminal). Every Playwright
scenario in the repo silently depends on the five-value contract.

### Symptoms in a review diff

- `data-state="not-installed"` (PR #28 FailedJobsTab + ActivityTab).
- `data-state="partial"` — half loaded, half errored? Pick one of the
  five; surface "half" via a secondary attribute.
- `data-state="stale"` — the data is ready but old? Use
  `data-state="ready" data-stale="true"` or a `data-fetched-at` timestamp.
- `data-state="warning"` — degraded health? The dashboard health strip
  uses `data-state="ready"` for all states and a `data-health-level`
  attribute for the level.

### Detection recipe

```bash
# Any data-state value outside the five canonical values
rg -n 'data-state="[^"]+"' frontend/src/ \
  | rg -v 'data-state="(idle|loading|ready|empty|error)"'
```

A clean run returns zero rows.

### Pagination testid convention

Still a R11 miss even at PR28 — both `FailedJobsTab`, `AuditTab`, and
`ActivityTab` shipped without testids on their pagination buttons.
The canonical shape mirrors `ChatLogsTab`:

```tsx
<button
  data-testid="<feature>-pagination-prev"
  disabled={page === 1}
  onClick={() => setPage(p => p - 1)}
>Prev</button>
<button
  data-testid="<feature>-pagination-next"
  disabled={page === last}
  onClick={() => setPage(p => p + 1)}
>Next</button>
```

E2E scenarios rely on `getByTestId(`${feature}-pagination-next`)` to
navigate pages. Missing the testid forces the test into a CSS /
text-match fallback, which is the whole problem R11 exists to
prevent.
