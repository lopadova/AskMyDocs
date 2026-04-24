# Performance audit plan — AskMyDocs SPA

> Phase J deliverable (PR #15). Target scores + how-to-run procedure
> for Lighthouse audits against the two user-visible entry points.
> **Status cells read `TBD` — populate before a release with the
> numbers from a real local Lighthouse run.** Agents cannot drive a
> browser engine for accurate scoring; this file is the contract
> the next human (or a CI visual-regression step) owns.

---

## Why this file exists

Phases D → I landed a ≈ 1.26 MB uncompressed main React bundle (gzip
≈ 393 KB). That's within budget for an authenticated admin SPA but
comfortably exceeds the Lighthouse "large bundle" heuristic at
250 KB gz. Performance, accessibility, best-practices, and SEO
budgets must be set explicitly so any regression has a clear bar
to clear — not an implicit "feels fine" gut check.

## Targets

| Route | Performance | Accessibility | Best-Practices | SEO |
|---|---|---|---|---|
| `/app/chat` | ≥ 85 | ≥ 95 | ≥ 90 | ≥ 90 |
| `/app/admin` | ≥ 85 | ≥ 95 | ≥ 90 | ≥ 90 |

Rationales:

- **Performance ≥ 85** — the SPA is already authenticated (no
  public-facing Core Web Vitals concern) and the primary perf cost is
  the recharts + CodeMirror payload. 85 is achievable without another
  round of code-splitting; 90+ would require lazier boundaries on
  every admin view (follow-up in PR #16 or beyond).
- **Accessibility ≥ 95** — every feature since PR5 carries
  `data-testid`, `role`, `aria-label`, `aria-live`, `aria-busy`.
  R11 (frontend-testid-conventions) keeps this honest. Copilot
  caught five a11y findings across PR #19 / #23 / #24 (tooltip
  focus, SVG empty-state NaN, `<div>` labels without `htmlFor`,
  `display:none` checkbox, tree search input no aria-label) — all
  fixed. The tree role / button-as-focusable-element contract must
  be preserved.
- **Best-Practices ≥ 90** — strict CSP via Laravel `VITE_SECURE_CSP`
  headers, HTTPS in prod, passive event listeners where they matter.
  The -10 buffer absorbs any dev-only console noise.
- **SEO ≥ 90** — the SPA is a single-route Blade shell so there is
  no meaningful SEO surface, but Lighthouse still checks
  `<title>`, `<meta name=viewport>`, and crawlability which we
  get "for free" from the Laravel blade wrapper.

## Current status

| Route | Performance | Accessibility | Best-Practices | SEO | Notes |
|---|---|---|---|---|---|
| `/app/chat` | TBD | TBD | TBD | TBD | Run locally before release |
| `/app/admin` | TBD | TBD | TBD | TBD | Run locally before release |

Date of last run: **not yet run** — replace this line with
`YYYY-MM-DD by <name>, commit <SHA>` after the first real run.

## How to run Lighthouse locally

One host, two terminals. Lighthouse drives a real Chromium
install, so you need the built SPA (not the Vite dev server — HMR
overhead skews the Performance score).

**Terminal 1 — serve the built SPA:**

```bash
npm run build             # writes public/build/manifest.json
php artisan serve         # binds 127.0.0.1:8000
```

**Terminal 2 — run Lighthouse:**

```bash
# Install once if you don't already have it:
#   npm install -g lighthouse

# /app/chat (authenticated route — see "Authenticated routes" below)
lighthouse \
  --chrome-flags="--headless=new" \
  --preset=desktop \
  --output=html \
  --output-path=./lighthouse-chat.html \
  http://127.0.0.1:8000/app/chat

# /app/admin — same shape, different route.
lighthouse \
  --chrome-flags="--headless=new" \
  --preset=desktop \
  --output=html \
  --output-path=./lighthouse-admin.html \
  http://127.0.0.1:8000/app/admin
```

Open the produced HTML reports in a browser; the headline
`Performance / Accessibility / Best-Practices / SEO` circles at the
top are the numbers to paste into the "Current status" table above.

### Authenticated routes

Both target routes are auth-gated. Lighthouse does not by default
sign in. Three practical options:

1. **Pre-authenticated storage state**: copy `playwright/.auth/admin.json`
   out to a Puppeteer-compatible cookie jar; pass
   `--extra-headers='{"Cookie":"XSRF-TOKEN=…; askmydocs_session=…"}'`
   to Lighthouse. Works but brittle across deploys.
2. **`--disable-storage-reset=true`**: keeps the Chromium profile
   between runs. Sign in manually via a headful Chromium, then re-run
   headless. Simplest for a pre-release audit pass; not automatable.
3. **Puppeteer wrapper**: write a 20-line script using
   `lighthouse.startFlow()` that logs in via `page.fill` + clicks
   before triggering the audit. Correct shape for CI; overkill for
   a one-off pre-release check.

Phase J does not prescribe one — the audit is a release gate, not a
per-PR gate. Pick whichever fits the maintainer's workflow.

### Mobile preset

The targets above are for `--preset=desktop`. A mobile preset audit
is useful for `/app/chat` (the chat UI is intentionally mobile-
friendly) but not for `/app/admin` (the admin shell is desktop-only
by design — the rail + wide tables don't shrink below ~ 1024 px).
Track mobile chat perf separately if that becomes a business goal.

## How to improve if Performance < 85

The main bundle at build time (Phase I Vite output):

- Main chunk: 1.26 MB uncompressed, 393 KB gzipped.
- Recharts chunk (code-split via `React.lazy` at each dashboard
  card): ≈ 400 KB uncompressed, ≈ 116 KB gzipped.
- CodeMirror chunk (code-split via the Source tab): ≈ 150 KB
  uncompressed, ≈ 45 KB gzipped.
- Markdown pipeline (react-markdown + remark plugins): bundled into
  the main chunk because `/app/chat` needs it synchronously.

Follow-up ideas if the number regresses below 85:

1. **Lazy-load `GraphTab.tsx`** — the SVG radial view is already hand-
   rolled (PR11 LESSONS call-out), so the chunk is small, but lazy-
   loading on tab click would shave first-paint on `/app/admin/kb`.
2. **Lazy-load `LogsView.tsx` tab panels** — the five tabs (chat /
   audit / app / activity / failed) are all importeagerly. Lazy-load
   per tab and first-render drops ≈ 20 KB.
3. **Split `react-markdown`** — it ships on `/app/chat` AND
   `/app/admin/kb` Preview. A shared `React.lazy()` wrapper would
   prevent double-parsing; not hot enough today to justify the churn.
4. **Split the Insights chart cards** — PR14 already landed every
   card with its own `<Suspense>` but the cards do NOT lazy-load
   recharts independently of the dashboard cards. If recharts is
   split into a shared chunk at Vite level, that's a one-liner in
   `vite.config.ts` (`manualChunks`).
5. **Serve a precompressed Brotli manifest** — Laravel + Nginx
   combo gains another ~ 15 % on the main chunk for free once the
   host serves `.br` variants.

None of these is in scope for PR #15 (Phase J is docs + one spec +
audit plan). If the first local run flags any of them, file a
follow-up ticket against PR #16 or later.

## How to improve if Accessibility < 95

Every regression here maps to one of the Copilot a11y findings
already caught (`COPILOT-FINDINGS.md` → `a11y` tag, count 7):

- Missing `aria-label` on icon-only buttons.
- `<label>` without matching `htmlFor` / `id` on the input.
- SVG empty-state producing `NaN` attributes (`Math.max(...[])`).
- Tooltip triggered only by mouse, not by focus.
- `display:none` on a checkbox (invisible to AT).
- `role=treeitem` on a non-focusable wrapper instead of the button.

The `frontend-a11y-checklist` skill (to be minted at PR #16) codifies
the pattern. Until then, grep any new component against
`.claude/skills/frontend-testid-conventions/` and the five bullets
above.

## Continuous audits (future)

`@lhci/cli` (Lighthouse CI) can run the audit on every PR with a
budget YAML. Not wired today because:

- CI budgets are a forcing function — the right time to enable them
  is AFTER PR #16 distills the final rules, so a lowered budget
  doesn't block Phase J's own merge.
- The `admin-journey.spec.ts` golden-path spec is a functional
  backstop; perf regressions are still visible in manual audits.

Track `@lhci/cli` as a PR #16+ deliverable if release cadence
demands it.
