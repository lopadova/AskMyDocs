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
| 5  | E — Chat UI React | `feature/enh-e-chat-react` | ⏳ ready | — | PR4 | — | parte dal branch PR4 |
| 6  | F1 — Admin shell + Dashboard | `feature/enh-f1-admin-dashboard` | ⏳ blocked | — | PR5 | — | |
| 7  | F2 — Users & Roles | `feature/enh-f2-users-roles` | ⏳ blocked | — | PR6 | — | |
| 8  | G — KB Tree + Viewer + Editor | `feature/enh-g-kb-viewer-editor` | ⏳ blocked | — | PR7 | — | |
| 9  | H — Logs + Maintenance | `feature/enh-h-logs-maintenance` | ⏳ blocked | — | PR8 | — | |
| 10 | I — AI Insights | `feature/enh-i-ai-insights` | ⏳ blocked | — | PR9 | — | |
| 11 | J — Docs + E2E + polish | `feature/enh-j-docs-e2e-polish` | ⏳ blocked | — | PR10 | — | |

Legenda status: ⏳ pending / blocked · 🔨 in_progress · ✅ PR opened · 🎉 merged

## Checklist per PR corrente

Copiata dal template a inizio lavoro, spunta man mano.

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
