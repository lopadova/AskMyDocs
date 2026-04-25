# Screenshot manifest — AskMyDocs

> Phase J deliverable (PR #15). The README and the default PR
> template reference the 18 PNGs catalogued below. **This file is
> a contract, not a gallery.** Each entry specifies the exact
> repro: which account to sign in as, which seeder to run, which
> theme to pick, which viewport to size the browser to, and which
> state the page must be in when the capture is taken. Dropping
> in mismatched assets will land an a11y or visual-regression
> follow-up PR sooner or later — capture to spec.

---

## Capture conventions (apply to every shot below)

- **Host browser**: Chromium (latest stable). Firefox and Safari
  differ on glassmorphism blur behaviour.
- **Viewport**: 1440 × 900 unless a shot's entry explicitly says
  otherwise. The admin shell is desktop-only by design.
- **Device pixel ratio**: 2x on Retina displays; 1x on ordinary
  monitors. Pick one and be consistent — do not mix.
- **Theme**: dark by default (violet → cyan accent). Two shots
  explicitly cover the dark palette; the rest are also dark
  because the whole SPA is dark-first.
- **Seeded data**: run `php artisan migrate:fresh --force` then
  `php artisan db:seed --class=RbacSeeder` then the per-shot
  seeder named below. The RbacSeeder must land first.
- **Signed-in account**: `admin@demo.local` / `password` for every
  admin shot unless explicitly named otherwise.
  `viewer@demo.local` / `password` is for the RBAC-denial shot.
  `super@demo.local` / `password` for the destructive maintenance
  flow (captured as follow-up; not in this manifest).
- **Output format**: PNG (no JPEG). Use a lossless PNG
  optimiser (`pngcrush`, `oxipng`) before committing.
- **File naming**: exactly the name in the table. The README and
  PR template hardcode the paths; a renamed file breaks markdown
  in two places.
- **Alt text**: captured in the README's alt attribute, not
  embedded in the PNG. Re-read the README after replacing an
  asset to check the alt still describes the new capture.
- **No PII**: never screenshot production data. The DemoSeeder
  emails are all `@demo.local` — keep them visible to signal
  "this is synthetic".

## Manifest

| File | Page / state | Viewport | Seeder | Signed-in as | Notes |
|---|---|---|---|---|---|
| `dashboard-admin.png` | `/app/admin` with real data | 1440×900 | DemoSeeder | admin@demo.local | KPI strip + health strip + 3 populated recharts cards + top projects + activity feed. Wait for `data-state=ready` on `kpi-strip` before capturing. |
| `dashboard-viewer.png` | `/app/admin` forbidden state | 1440×900 | DemoSeeder | viewer@demo.local | `<AdminForbidden>` banner + "You don't have access…" copy. Viewer role cannot see the dashboard. |
| `users-table.png` | `/app/admin/users` table populated | 1440×900 | DemoSeeder | admin@demo.local | 5 seeded rows (admin, viewer, super-admin + 2 fixtures). Filter bar visible. `data-state=ready` on `users-table`. |
| `user-drawer-roles.png` | `/app/admin/users` edit drawer, Roles tab open | 1440×900 | DemoSeeder | admin@demo.local | Open any row's edit drawer, click the Roles tab. All four role chips visible (super-admin / admin / editor / viewer). |
| `roles-permission-matrix.png` | `/app/admin/roles` dialog with permission matrix | 1440×900 | DemoSeeder | admin@demo.local | Click Edit on the `editor` role to open the dialog. Every dotted-prefix domain card visible (kb / users / roles / commands / logs / insights). |
| `kb-tree.png` | `/app/admin/kb` tree explorer, nothing selected | 1440×900 | DemoSeeder | admin@demo.local | Tree expanded on hr-portal project. Left panel ≈ 360 px; right panel shows the placeholder empty state. |
| `kb-doc-preview.png` | `/app/admin/kb` with a doc selected, Preview tab | 1440×900 | DemoSeeder | admin@demo.local | Click `policies/remote-work-policy.md`. Preview tab shows the frontmatter pill pack above the rendered markdown body. |
| `kb-doc-source-editor.png` | `/app/admin/kb` Source tab with CodeMirror loaded | 1440×900 | DemoSeeder | admin@demo.local | Same doc as above, click Source. Editor shows the raw frontmatter + body. `data-state=ready` on `kb-source`. |
| `kb-doc-graph.png` | `/app/admin/kb` Graph tab with subgraph rendered | 1440×900 | DemoSeeder | admin@demo.local | Same doc as above, click Graph. SVG shows the `remote-work-policy` center node + the `pto-guidelines` neighbour connected by a `related_to` edge. |
| `kb-doc-history.png` | `/app/admin/kb` History tab with audit rows | 1440×900 | DemoSeeder | admin@demo.local | Same doc as above, click History. Shows at least the seeded `promoted` audit row; if the Source edit has been performed, also an `updated` row. |
| `logs-chat-tab.png` | `/app/admin/logs?tab=chat` populated | 1440×900 | DemoSeeder | admin@demo.local | 5 seeded chat_log rows. Filter bar visible. Do NOT apply a filter before capturing — show the "all rows" baseline. |
| `logs-app-tab.png` | `/app/admin/logs?tab=app` with a tailed log file | 1440×900 | DemoSeeder | admin@demo.local | Select the default `laravel.log` from the file picker. Level filter at `all`. Tail 200 lines. If the file is empty on a fresh install, trigger one request to the app first. |
| `maintenance-wizard-step1.png` | `/app/admin/maintenance` wizard step 1 — Preview | 1440×900 | DemoSeeder | admin@demo.local | Click the `kb:validate-canonical` "Run" card. `command-wizard` `data-step=preview`. Args form visible (project optional). |
| `maintenance-wizard-step2-confirm.png` | Wizard step 2 — Confirm type-in (destructive) | 1440×900 | DemoSeeder | **super@demo.local** | Start `kb:prune-deleted` wizard. Enter `--days=30`. Confirm step shows the type-to-confirm input and the warning banner. **Use super-admin account for this one** — admin cannot reach this step. |
| `maintenance-history.png` | `/app/admin/maintenance` history tab | 1440×900 | DemoSeeder + run one command | admin@demo.local | Run `kb:validate-canonical` once (any args) then click the History tab. Shows one `completed` row with the started/completed timestamps. |
| `insights-view.png` | `/app/admin/insights` with 6 widget cards | 1440×900 | DemoSeeder + AdminInsightsSeeder | admin@demo.local | `insights-view` `data-state=ready`. Every one of the six cards (promotions / orphans / suggested-tags / coverage-gaps / stale-docs / quality) renders with seeded data. |
| `login-dark-mode.png` | `/login` at dark theme | 1440×900 | — (unauthenticated) | guest | Capture the violet→cyan gradient + glassmorphism login card. Prime the CSRF cookie first so the "session expired" toast isn't lingering. |
| `chat-dark-mode.png` | `/app/chat` at dark theme with a short thread | 1440×900 | DemoSeeder | admin@demo.local | Open the seeded conversation. User asks "What is the remote work policy?"; assistant replies with a citation. Sidebar visible. `thread` element `data-state=ready`. |

## Post-capture checklist

After replacing a set of PNGs:

1. `grep -R "resources/screenshots/" README.md .github/` — every
   path listed here MUST exist on disk. A missing file breaks
   markdown rendering in GitHub and in the PR description.
2. Run the alt-text audit: open each markdown block in the
   README and ensure the alt attribute matches the actual image.
   Copilot has flagged alt/image drift before (category
   `doc-drift`).
3. Compress before committing. Target: ≤ 200 KB per PNG. A 2 MB
   screenshot in `resources/` inflates the clone weight for every
   new contributor.
4. Update this manifest's date stamp at the bottom of this file.

## Last refresh

Date: **not yet refreshed** — replace this line with
`YYYY-MM-DD by <name>, commit <SHA>` after the first real capture
pass.
