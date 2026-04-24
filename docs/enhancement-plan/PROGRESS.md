# Enhancement Progress Tracker

> Ogni agente, a fine task: aggiorna questa tabella + commit del file nel branch del task.
> Un PR NON è completo finché non si spunta "PR opened".

## Stato PR

| PR | Phase | Branch | Status | PR # | Branch parent | Ultimo aggiornamento | Note |
|----|-------|--------|--------|------|---------------|---------------------|------|
| 1  | A — Storage & Scheduler | `feature/enh-a-storage-scheduler` | ✅ PR opened | TBD | `origin/main` | 2026-04-23 | backend-only; 409 tests green |
| 2  | B — Auth JSON API + Sanctum SPA | `feature/enh-b-auth-api` | ✅ PR opened | TBD | PR1 | 2026-04-23 | 433 tests green; Sanctum stateful + 7 Api/Auth tests |
| 3  | C — RBAC foundation | `feature/enh-c-rbac-foundation` | ✅ PR opened | TBD | PR2 | 2026-04-23 | 457 tests green; Spatie ^6.25 + 25 Rbac tests + AccessScopeScope + Policy + middleware |
| 4  | D — Frontend scaffold + auth pages | `feature/enh-d-frontend-scaffold` | ✅ PR opened | TBD | PR3 | 2026-04-22 | 460 tests green (+3 Spa) + 21 Vitest + 18 legacy rich-content; Vite build verified (421 kB JS gz 131 kB) |
| 5  | E — Chat UI React | `feature/enh-e-chat-react` | ✅ PR opened | TBD | PR4 | 2026-04-22 | 473 tests PHP + 48 Vitest + 18 legacy + 5 Playwright scenarios authored; chat view + wikilink hover + rich-content TS + DemoSeeder |
| 6  | F1 — Admin shell + Dashboard | `feature/enh-f1-admin-dashboard` | ✅ PR opened | TBD | PR5 | 2026-04-24 | 500/500 PHP (+27) · 59/59 Vitest (+11) · 6 Playwright scenarios (4 admin + 2 viewer) · R13 green |
| 7  | F2 — Users & Roles | `feature/enh-f2-users-roles` | ✅ PR opened | TBD | PR6 | 2026-04-24 | 551/551 PHP (+45 Admin suites) · 70/70 Vitest (+11) · 12 new Playwright scenarios (9 admin + 3 viewer) · R13 green |
| 8  | G1 — KB Tree Explorer | `feature/enh-g1-kb-tree` | 🎉 merged | #24 | feature/enh-f2-users-roles (PR7) | 2026-04-24 | 562/562 PHP (+11) · 78/78 Vitest (+8) · 3 new Playwright scenarios (2 admin + 1 viewer) · R13 green · Phase G split into G1..G4 (tree / detail / editor / graph+PDF) |
| 9  | G2 — KB Document Detail | `feature/enh-g2-kb-document-detail` | ✅ PR opened | #25 | PR8 (G1) | 2026-04-24 | 575/575 PHP (+13) · 94/94 Vitest (+16) · 4 new Playwright scenarios · R13 green · read-only Preview/Meta/History; editor + graph + PDF deferred to G3/G4 |
| 10 | G3 — KB Source Editor | `feature/enh-g3-kb-editor` | ⏳ blocked | — | PR9 | — | CodeMirror + save pipeline |
| 11 | G4 — KB Graph + PDF Render | `feature/enh-g4-kb-graph-pdf` | ⏳ blocked | — | PR10 | — | Graph viewer + PDF renderer (PdfRenderer stashed) |
| 12 | H — Logs + Maintenance | `feature/enh-h-logs-maintenance` | ⏳ blocked | — | PR11 | — | |
| 13 | I — AI Insights | `feature/enh-i-ai-insights` | ⏳ blocked | — | PR12 | — | |
| 14 | J — Docs + E2E + polish | `feature/enh-j-docs-e2e-polish` | ⏳ blocked | — | PR13 | — | |

Legenda status: ⏳ pending / blocked · 🔨 in_progress · ✅ PR opened · 🎉 merged

## Checklist per PR corrente

Copiata dal template a inizio lavoro, spunta man mano.

### PR9 — Phase G2 (KB Document Detail) checklist

Microphase 2 of 4 of Phase G. Strictly read-only: Preview / Meta /
History tabs only. Source editor (G3), Graph tab + Export PDF (G4)
explicitly deferred. Target ≤ 15 files touched — this PR lands 14.

- [x] `app/Http/Controllers/Api/Admin/KbDocumentController.php` — show /
      raw / download / print / restore / destroy / history read endpoints
- [x] `app/Http/Resources/Admin/Kb/KbDocumentResource.php` — canonical-aware
      shape with chunks_count / audits_count / recent_audits aggregates
- [x] `app/Http/Resources/Admin/Kb/KbAuditResource.php` — immutable audit row
- [x] `resources/views/print/kb-doc.blade.php` — CSS @page print view with
      `id="doc-print"` (no external deps)
- [x] `routes/api.php` — admin-scoped `withTrashed()` binding shim +
      apiResource(['show','destroy']) + raw/download/print/restore/history
- [x] `tests/Feature/Api/Admin/Kb/KbDocumentControllerTest.php` — 13 scenarios
- [x] `database/seeders/DemoSeeder.php` — seed canonical markdown to KB disk
      and one `promoted` audit per canonical doc so G2 tabs paint on first open
- [x] `frontend/src/features/admin/admin.api.ts` — KbDocument / KbAudit /
      KbRaw / KbHistory types + `adminKbDocumentApi` client
- [x] `frontend/src/features/admin/kb/kb-document.api.ts` — 5 TanStack hooks
- [x] `frontend/src/features/admin/kb/DocumentDetail.tsx` — header + pills +
      actions + tab strip + confirm dialog
- [x] `frontend/src/features/admin/kb/PreviewTab.tsx` — frontmatter pill
      pack via `extractFrontmatterPills()` + Markdown body
- [x] `frontend/src/features/admin/kb/MetaTab.tsx` — canonical meta grid +
      tag chips (metadata.tags ∪ pivot tags)
- [x] `frontend/src/features/admin/kb/HistoryTab.tsx` — paginated audit
      list with expandable diff details
- [x] `frontend/src/features/admin/kb/KbView.tsx` — wire DocumentDetail;
      URL search params `?doc=ID&tab=preview|meta|history`
- [x] `frontend/src/features/admin/kb/DocumentDetail.test.tsx` (6 scenarios)
- [x] `frontend/src/features/admin/kb/PreviewTab.test.tsx` (6 scenarios)
- [x] `frontend/src/features/admin/kb/HistoryTab.test.tsx` (4 scenarios)
- [x] `frontend/e2e/admin-kb-detail.spec.ts` (4 scenarios)
- [x] `bash scripts/verify-e2e-real-data.sh` → OK (R13 green)
- [x] `php vendor/bin/phpunit` → **575/575** (562 baseline + 13 new)
- [x] `npm test` → **94/94** (78 baseline + 16 new)
- [x] `npx playwright test --list` → 32 scenarios across 11 files (+4 new)
- [x] Aggiornato `LESSONS.md` con scoperte Phase G2
- [x] Aggiornato `PROGRESS.md` → stato ⏳ → ✅
- [x] Commit su branch + `gh pr create` verso `feature/enh-g1-kb-tree`

### PR8 — Phase G1 (KB Tree Explorer) checklist

Phase G has been split into four microphases — G1 (tree browsing)
ships here; G2 adds document detail tabs; G3 the source editor;
G4 the graph viewer + PDF renderer (the stashed PdfRenderer lands
in G4, not here).

- [x] `app/Services/Admin/KbTreeService.php` — pure tree builder, canonical-aware scopes (R10), `chunkById(100)` walker (R3), soft-delete opt-in (R2)
- [x] `app/Http/Controllers/Api/Admin/KbTreeController.php` — GET `/api/admin/kb/tree?project=&mode=canonical|raw|all&with_trashed=0|1`
- [x] `routes/api.php` — `kb/tree` inside the admin `role:admin|super-admin` group
- [x] `tests/Feature/Api/Admin/Kb/KbTreeControllerTest.php` — 11 scenarios (empty / mode / with_trashed / project scope / 150-doc memory-safe walk / invalid mode 422 / RBAC 403 / guest 401)
- [x] `frontend/src/features/admin/admin.api.ts` — KbTree* types + `adminKbApi.tree`
- [x] `frontend/src/features/admin/kb/kb-tree.api.ts` — `useKbTree(q)` TanStack hook
- [x] `frontend/src/features/admin/kb/TreeView.tsx` — filter bar + expandable tree, `data-state` + `data-testid="kb-tree-node-<path>"` per node, canonical + trashed badges
- [x] `frontend/src/features/admin/kb/KbView.tsx` — split-panel shell, right panel shows placeholder or `DocSummary` (detail tabs land in G2)
- [x] `frontend/src/features/admin/shell/AdminShell.tsx` — rail "Knowledge" pivots to `/app/admin/kb`
- [x] `frontend/src/routes/index.tsx` — flat `adminKbRoute` at `/app/admin/kb` wrapped in `RequireRole`
- [x] `frontend/src/features/admin/kb/TreeView.test.tsx` — 8 Vitest scenarios (states / selection / filter / badge / trashed)
- [x] `frontend/e2e/admin-kb.spec.ts` — 2 scenarios (happy: seeded canonical node; failure: mode=canonical hides non-canonical draft)
- [x] `frontend/e2e/admin-kb-viewer.spec.ts` — 1 scenario (viewer → admin-forbidden)
- [x] `bash scripts/verify-e2e-real-data.sh` → OK (R13 green)
- [x] `php vendor/bin/phpunit` → **562/562** (551 baseline + 11 KbTreeControllerTest)
- [x] `npm test` → **78/78** (70 baseline + 8 TreeView.test.tsx)
- [x] `npx playwright test --list` → 28 scenarios across 10 files (+3 new)
- [x] Aggiornato `LESSONS.md` con scoperte Phase G1
- [x] Aggiornato `PROGRESS.md` → stato ⏳ → 🔨 in_progress → ✅ al merge
- [x] Commit su branch + `gh pr create` verso `feature/enh-f2-users-roles`

### PR7 — Phase F2 checklist

- [x] `database/migrations/2026_05_01_000001_add_soft_deletes_and_active_to_users.php` — SoftDeletes + is_active boolean default true
- [x] `app/Models/User.php` — SoftDeletes trait + `$guard_name = 'web'` + `$attributes = ['is_active' => true]` + cast `is_active` boolean
- [x] `app/Http/Controllers/Api/Admin/UserController.php` — index (q/role/active/with_trashed/only_trashed filters, `->paginate()`), show, store, update (409 last super-admin guard), destroy (soft + force), restore, resendInvite (202 stub until B2), toggleActive
- [x] `app/Http/Controllers/Api/Admin/RoleController.php` — Spatie-backed CRUD, protected `super-admin`/`admin` names
- [x] `app/Http/Controllers/Api/Admin/PermissionController.php` — flat + grouped-by-domain JSON
- [x] `app/Http/Controllers/Api/Admin/ProjectMembershipController.php` — index/store (upsert)/update/destroy with `scope_allowlist` JSON schema
- [x] `app/Http/Requests/Admin/*` — 6 form requests (User store/update, Role store/update, Membership store/update)
- [x] `app/Http/Resources/Admin/*` — UserResource, RoleResource, MembershipResource
- [x] `routes/api.php` — `/api/admin/users`, `/api/admin/roles`, `/api/admin/permissions`, `/api/admin/users/{u}/memberships`, `/api/admin/memberships/{m}` under `auth:sanctum + role:admin|super-admin`
- [x] `tests/Feature/Api/Admin/UserControllerTest.php` (19 scenarios)
- [x] `tests/Feature/Api/Admin/RoleControllerTest.php` (10 scenarios)
- [x] `tests/Feature/Api/Admin/PermissionControllerTest.php` (5 scenarios)
- [x] `tests/Feature/Api/Admin/ProjectMembershipControllerTest.php` (11 scenarios)
- [x] `frontend/src/features/admin/admin.api.ts` — extend with `adminUsersApi`, `adminRolesApi`, `adminPermissionsApi`
- [x] `frontend/src/features/admin/shared/Toast.tsx` + `errors.ts` — transient toast surface + 422 fieldErrors normaliser
- [x] `frontend/src/features/admin/users/` — UsersView / UsersTable / UsersTableRow / UserDrawer (3 tabs) / UserForm (rhf + zod) / MembershipEditor / users.api.ts hooks
- [x] `frontend/src/features/admin/roles/` — RolesView / RoleDialog (permission matrix with toggle-all) / roles.api.ts
- [x] `frontend/src/features/admin/shell/AdminShell.tsx` — rail pivots Users + Roles to dedicated admin routes
- [x] `frontend/src/routes/index.tsx` — flat `adminUsersRoute` + `adminRolesRoute` at `/app/admin/users` and `/app/admin/roles` wrapped in `RequireRole`
- [x] 3 Vitest files (UsersTable / UserForm / RoleDialog) — 11 new cases
- [x] `playwright.config.ts` — broaden `chromium-viewer` testMatch to `/.*-viewer\.spec\.ts/`
- [x] `frontend/e2e/admin-users.spec.ts` (6 scenarios: 4 happy + 1 failure + 1 flagged failure injection)
- [x] `frontend/e2e/admin-roles.spec.ts` (3 scenarios: 2 happy + 1 failure)
- [x] `frontend/e2e/admin-users-viewer.spec.ts` (3 scenarios: 2 UI forbidden + 1 API 403)
- [x] `bash scripts/verify-e2e-real-data.sh` → OK (R13 green)
- [x] `php vendor/bin/phpunit` → **551/551** (F2 suites 45/45; full suite 506 baseline + 45 new)
- [x] `npm test` → **70/70** (59 PR6 baseline + 11 new)
- [x] `npx playwright test --list` → 21 scenarios across 5 files
- [x] Aggiornato `LESSONS.md` con scoperte Phase F2
- [x] Aggiornato `PROGRESS.md` → stato ⏳ → ✅
- [x] Commit su branch + `gh pr create` verso `feature/enh-f1-admin-dashboard`

### PR6 — Phase F1 checklist

- [x] Rebase worktree on PR #21 (cherry-pick `65e72e5` from `feature/enh-e2e-rigor` — R13 enforcement script + skill)
- [x] `app/Services/Admin/AdminMetricsService.php` — kpiOverview/chatVolume/tokenBurn/ratingDistribution/topProjects/activityFeed (DB-aggregated, R2+R3 compliant)
- [x] `app/Services/Admin/HealthCheckService.php` — per-concern probes, no network calls
- [x] `app/Http/Controllers/Api/Admin/DashboardMetricsController.php` — 3 endpoints, 30s `Cache::remember` keyed by (kind, project, days)
- [x] `routes/api.php` — `admin/metrics/*` group under `auth:sanctum + role:admin|super-admin`
- [x] `bootstrap/app.php` — register Spatie `role` / `permission` / `role_or_permission` middleware aliases (mirror in tests/TestCase.php)
- [x] `tests/Feature/Admin/AdminMetricsServiceTest.php` (10 scenarios)
- [x] `tests/Feature/Admin/HealthCheckServiceTest.php` (10 scenarios)
- [x] `tests/Feature/Api/Admin/DashboardMetricsControllerTest.php` (7 scenarios: admin 200 / viewer 403 / guest 401 / cache hit / days clamp)
- [x] `frontend/src/features/admin/admin.api.ts` — typed axios client
- [x] `frontend/src/features/admin/dashboard/use-admin-metrics.ts` — TanStack Query hooks (30s data / 15s health)
- [x] `frontend/src/routes/role-guard.tsx` — `RequireRole` + `AdminForbidden` + 5 Vitest cases
- [x] `frontend/src/features/admin/shell/AdminShell.tsx` — secondary rail
- [x] `frontend/src/features/admin/dashboard/` — DashboardView + KpiStrip/KpiCard + HealthStrip + ChatVolumeCard/TokenBurnCard/RatingDonutCard (recharts lazy-loaded) + TopProjectsCard + ActivityFeedCard + ChartCard/EmptyChart + 6 Vitest cases
- [x] `frontend/src/routes/index.tsx` — flat `adminRoute` at `/app/admin` wrapped in `RequireRole`
- [x] `database/seeders/DemoSeeder.php` — seed `viewer@demo.local` + 5 ChatLog rows
- [x] `database/seeders/EmptyAdminSeeder.php` + `AdminDegradedSeeder.php` + TestingController allowlist
- [x] `playwright.config.ts` — new `viewer-setup` + `chromium-viewer` projects
- [x] `frontend/e2e/viewer.setup.ts` — viewer single-login
- [x] `frontend/e2e/admin-dashboard.spec.ts` (4 scenarios: happy path + 500 injection (R13-marked) + empty state + health degraded)
- [x] `frontend/e2e/admin-dashboard-viewer.spec.ts` (2 scenarios: UI 403 + API 403)
- [x] `bash scripts/verify-e2e-real-data.sh` → OK (R13 green)
- [x] `php vendor/bin/phpunit` → **500/500** (473 baseline + 27 new)
- [x] `npm test` → **59/59** (48 baseline + 11 new)
- [x] `npx playwright test --list` → 13 scenarios in 5 files
- [x] `npm run build` → main chunk 645 kB gz 198 kB, recharts split as `index-*.js` (398 kB gz 116 kB)
- [x] Aggiornato `LESSONS.md` con scoperte Phase F1
- [x] Aggiornato `PROGRESS.md` → stato ⏳ → ✅
- [x] Commit su branch + `gh pr create` verso `feature/enh-e-chat-react`

### PR5 — Phase E checklist

- [x] Checkout worktree sul branch `feature/enh-e-chat-react` da `feature/enh-d-frontend-scaffold`
- [x] `npm install --save-dev @playwright/test` + `npx playwright install chromium`
- [x] `npm install recharts react-markdown remark-gfm remark-frontmatter unified unist-util-visit`
- [x] `package.json` scripts: `e2e`, `e2e:ui`, `e2e:headed`, `e2e:report`
- [x] `playwright.config.ts` (root) — setup + chromium project, authed storage state
- [x] `frontend/e2e/auth.setup.ts` — single-login flow → `playwright/.auth/admin.json`
- [x] `frontend/e2e/fixtures.ts` — auto-reset + seed `DemoSeeder`
- [x] `frontend/e2e/helpers.ts` — composer/thread/sidebar locators
- [x] `app/Http/Controllers/Api/KbResolveWikilinkController.php` + 7 tests
- [x] `routes/api.php` — `/api/kb/resolve-wikilink` GET (auth:sanctum)
- [x] `app/Http/Controllers/TestingController.php` + 6 tests (env + allowlist guards)
- [x] `database/seeders/DemoSeeder.php` — admin@demo.local + 3 canonical docs + 1 conversation
- [x] `routes/web.php` — /chat → /app/chat redirect + /chat-legacy path + testing endpoints behind APP_ENV guard
- [x] `frontend/src/lib/rich-content.ts` (TS port) + 12 Vitest cases; legacy `.mjs` preserved via `test:legacy` (18 cases)
- [x] `frontend/src/lib/markdown/` — Markdown.tsx + 3 remark plugins (wikilink, tag, callout) + 7 Vitest cases
- [x] `frontend/src/features/chat/`:
  - `chat.api.ts` (typed client), `chat.store.ts` (Zustand), `use-chat-mutation.ts` (optimistic)
  - `ChatView.tsx` (root), `ConversationList.tsx`, `MessageThread.tsx`, `MessageBubble.tsx`
  - `Composer.tsx`, `VoiceInput.tsx`, `FeedbackButtons.tsx`, `MessageActions.tsx`
  - `CitationsPopover.tsx`, `ThinkingTrace.tsx`, `WikilinkHover.tsx`
- [x] `frontend/src/routes/index.tsx` — /app/chat + /app/chat/$conversationId route ChatView
- [x] R11 compliance: every button/input has `data-testid`; thread exposes `data-state`; errors surface with `data-testid="<field>-error"` or `chat-*-error`
- [x] R12 Playwright spec: `frontend/e2e/chat.spec.ts` with 1 happy + 4 failure paths
- [x] `.github/workflows/tests.yml` — Playwright job (needs [phpunit, vitest]) + browser cache + report upload on failure
- [x] `npm run build` → 623 kB JS gz 192 kB (warning about chunk size noted; code-split deferred)
- [x] `~/.config/herd/bin/php.bat vendor/phpunit/phpunit/phpunit` → 473/473 verdi (460 baseline + 13 new)
- [x] `npm run test` → 48/48 verdi (43 baseline + 5 chat)
- [x] `npm run test:legacy` → 18/18 (rich-content.spec.mjs preserved)
- [x] `npx playwright test --list` → 6 scenarios (1 setup + 5 chat)
- [x] Aggiornato `LESSONS.md` con scoperte Phase E
- [x] Aggiornato `PROGRESS.md` → stato ⏳ → ✅
- [x] Commit su branch, push, `gh pr create` verso `feature/enh-d-frontend-scaffold`

### PR1 — Phase A checklist

- [x] Checkout worktree sul branch `feature/enh-a-storage-scheduler` da `origin/main`
- [x] `config/filesystems.php` — blocchi r2/gcs/minio aggiunti, credenziali via env, commenti chiari
- [x] `config/kb.php` — `project_disks` map + `raw_disk` separato aggiunti
- [x] `app/Support/KbDiskResolver.php` — `forProject(string $projectKey): string`
- [x] `app/Console/Commands/PruneOrphanFilesCommand.php` — `kb:prune-orphan-files {--dry-run} {--disk=} {--project=}`
- [x] `bootstrap/app.php` — scheduler aggiunti:
  - [ ] `activitylog:clean --days=90` — **deferred to PR3** (spatie/laravel-activitylog non ancora installato) — TODO in-file
  - [ ] `admin-audit:prune --days=365` — **deferred to PR9** (tabella `admin_command_audit` arriva in PR9) — TODO in-file
  - [x] `queue:prune-failed --hours=48` at 04:00
  - [ ] `notifications:prune --days=60` — **skipped**: Laravel 13 non registra questo comando (solo `notifications:table`). Sostituzione consigliata: `model:prune --model=App\\Models\\DatabaseNotification` quando il model implementerà il trait `Prunable`. NOTE in bootstrap/app.php.
  - [x] `kb:prune-orphan-files --dry-run` at 04:40
- [x] `.env.example` — esempi r2/gcs/minio + `KB_PROJECT_DISKS` + `KB_RAW_DISK`
- [x] `tests/Feature/Kb/KbDiskResolverTest.php` — 10 test, default fallback, per-project override, env JSON parsing, canonical override
- [x] `tests/Feature/Commands/PruneOrphanFilesCommandTest.php` — 5 test (dry-run, normal, soft-delete awareness, delete-failure, per-project disk)
- [x] `vendor/bin/phpunit` → **409/409 verdi** (0 regressioni)
- [x] Aggiornato `LESSONS.md` con scoperte (notifications:prune, Mockery Storage, scheduler baseline)
- [x] Aggiornato `PROGRESS.md` → stato ⏳ → ✅
- [x] Commit su branch, push, `gh pr create` verso `main`

### PR4 — Phase D checklist

- [x] Checkout worktree sul branch `feature/enh-d-frontend-scaffold` da `feature/enh-c-rbac-foundation`
- [x] `package.json` (root) — React 18.3.1, TanStack Router/Query, Zustand, axios, react-hook-form, zod, Tailwind 3.4, Vite 5, Vitest 2, Testing Library; legacy `vitest.config.mjs` preserved via `npm run test:legacy`
- [x] `vite.config.ts` (root) con `laravel-vite-plugin`, input `frontend/src/main.tsx`, dev proxy `/api` + `/sanctum` + legacy auth paths
- [x] `tailwind.config.ts` + `postcss.config.js` — dark attribute selector, content glob su `frontend/src/**/*.{ts,tsx}`
- [x] `vitest.config.ts` — jsdom env, setup `@testing-library/jest-dom/vitest`
- [x] `frontend/tsconfig.json` + `tsconfig.node.json` (con rootDir `..` per i config root-level)
- [x] `frontend/src/styles/tokens.css` — copiato as-is da `design-reference/project/styles/tokens.css`
- [x] `frontend/src/components/Icons.tsx` — 47 icons tipizzate (IconName union esportato)
- [x] `frontend/src/components/charts/{Sparkline,AreaChart,BarStack,Donut}.tsx` — port TS con useMemo
- [x] `frontend/src/components/shell/{AppShell,Sidebar,Topbar,CommandPalette,TweaksPanel,ProjectSwitcher,Avatar,Tooltip,SegmentedControl}.tsx` + `hooks.ts` (useTheme/useDensity/useFontPair)
- [x] `frontend/src/components/sections/*Placeholder.tsx` — 7 placeholder tipizzati sotto `Placeholder` condiviso
- [x] `frontend/src/features/auth/{AuthLayout,LoginPage,ForgotPasswordPage,ResetPasswordPage}.tsx` + `auth.api.ts`
- [x] `frontend/src/lib/{api,auth-store,query-client,seed}.ts` — axios + CSRF bootstrap, Zustand store, TanStack Query client, dev seed
- [x] `frontend/src/routes/{index,guards}.tsx` — TanStack Router code-based, RequireAuth/RedirectIfAuth, zod `validateSearch` su /reset-password
- [x] `frontend/src/{App,main}.tsx` — QueryClientProvider > RouterProvider
- [x] `app/Http/Controllers/SpaController.php` + `resources/views/app.blade.php` + `routes/web.php` (catch-all `/app/{any?}`)
- [x] `.gitignore` — `public/build/`, `public/hot`, TS build artefacts root-level
- [x] Test Vitest (21 totali su 7 file): Icons, charts, Sidebar, CommandPalette, TweaksPanel, auth-store, LoginPage
- [x] Test legacy vitest (`tests/js/rich-content.spec.mjs`, 18 test) preservati via `npm run test:legacy`
- [x] Test PHPUnit `tests/Feature/Spa/SpaControllerTest.php` (3 test, 6 assertions) — `withoutVite()` per non leggere il manifest
- [x] `vendor/bin/phpunit` → **460/460 verdi** (da 457 baseline + 3 nuovi)
- [x] `npm run build` → manifest + bundle (~421 kB JS gz 131 kB) scritti in `public/build/`
- [x] Aggiornato `LESSONS.md` con scoperte Phase D
- [x] Aggiornato `PROGRESS.md` → stato ⏳ → ✅
- [x] Commit su branch, push, `gh pr create` verso `feature/enh-c-rbac-foundation`

### PR3 — Phase C checklist

- [x] Checkout worktree sul branch `feature/enh-c-rbac-foundation` da `feature/enh-b-auth-api`
- [x] `composer require spatie/laravel-permission:^6.25` (supports Laravel 13 + PHP 8.3)
- [x] `config/permission.php` (copiato da vendor; bootstrap/providers.php usa registrazione esplicita)
- [x] `config/rbac.php` — `enforced` master switch (env `RBAC_ENFORCED`, default true)
- [x] Migrazione Spatie `2026_04_23_000000_create_permission_tables.php` + mirror test
- [x] 4 migrazioni custom: project_memberships, kb_tags, knowledge_document_tags, knowledge_document_acl
- [x] Modelli `ProjectMembership`, `KbTag`, `KnowledgeDocumentAcl`
- [x] `User.php` — `HasRoles` trait + `projectMemberships()` + `allowedProjects()` + `allowedScopesFor()` + `hasDocumentAccess()`
- [x] `KnowledgeDocument.php` — global scope `AccessScopeScope` + relazioni `tags()` + `acl()`
- [x] `app/Scopes/AccessScopeScope.php` — project_key whitelist + deny-wins exclusion
- [x] `app/Http/Middleware/EnsureProjectAccess.php` + alias `project.access` in `bootstrap/app.php`
- [x] `app/Policies/KnowledgeDocumentPolicy.php` (view/edit/delete/promote) + `Gate::policy` in AppServiceProvider
- [x] `database/seeders/RbacSeeder.php` — 4 ruoli + 11 permessi + backfill viewer per utenti esistenti
- [x] `app/Console/Commands/AuthGrantCommand.php` — `php artisan auth:grant {email} {role} [--project=]`
- [x] `AuthController@me` ora popola `roles`, `permissions`, `projects` (era vuoto in PR2)
- [x] `tests/TestCase.php` — registra `SpatiePermissionServiceProvider` + carica `permission` + `rbac` config
- [x] `composer.json` — aggiunto `Database\\Seeders\\` al psr-4 autoload
- [x] `.env.example` — `RBAC_ENFORCED=true`
- [x] Tests `tests/Feature/Rbac/*Test.php` (23 test totali su 5 file) + `MeTest` esteso
- [x] `vendor/bin/phpunit` → **457/457 verdi** (0 regressioni, 24 test nuovi)
- [x] Aggiornato `LESSONS.md` con scoperte Phase C
- [x] Aggiornato `PROGRESS.md` → stato ⏳ → ✅
- [x] Commit su branch, push, `gh pr create` verso `feature/enh-b-auth-api`

### PR2 — Phase B checklist

- [x] Checkout worktree sul branch `feature/enh-b-auth-api` da `feature/enh-a-storage-scheduler`
- [x] `config/sanctum.php` — stateful domains parse da `SANCTUM_STATEFUL_DOMAINS`, guard `['web']`
- [x] `config/cors.php` — `supports_credentials=true`, paths `api/*` + `sanctum/csrf-cookie` + auth routes, origins parse da `CORS_ALLOWED_ORIGINS`
- [x] `config/auth.php` — declare `guards.sanctum` + `two_factor.enabled` flag
- [x] `.env.example` — `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, `AUTH_2FA_ENABLED`
- [x] `app/Http/Requests/Auth/{Login,ForgotPassword,ResetPassword,TwoFactor}Request.php`
- [x] Refactor Blade `Auth/{Login,PasswordReset}Controller` per type-hint FormRequest
- [x] `app/Http/Controllers/Api/Auth/{Auth,PasswordReset,TwoFactor}Controller.php`
- [x] `routes/api.php` — gruppo `auth/*` con middleware `web`, throttle login (5/min) + forgot (3/min)
- [x] `AppServiceProvider::boot` — registra RateLimiter `login` + `forgot`
- [x] TestCase — registra `SanctumServiceProvider`, carica sanctum/cors/auth config
- [x] Test migrations — aggiunte `sessions` + `password_reset_tokens`
- [x] Tests `tests/Feature/Api/Auth/*Test.php` (22 test totali su 7 file)
- [x] `vendor/bin/phpunit` → **433/433 verdi** (0 regressioni, 24 test nuovi)
- [x] Aggiornato `LESSONS.md` con scoperte Phase B (Sanctum guard survive, defineRoutes + api prefix, etc.)
- [x] Aggiornato `PROGRESS.md` → stato ⏳ → ✅
- [x] Commit su branch, push, `gh pr create` verso `feature/enh-a-storage-scheduler`

## Comando rapido per stato

```bash
grep -E "^\| [0-9]+" docs/enhancement-plan/PROGRESS.md
```
