# AskMyDocs Enhancement — Orchestrator Master Plan

> **Fonte di verità per l'orchestrazione.** Ogni agente che esegue un PR DEVE leggere
> questo file, poi `PROGRESS.md` e `LESSONS.md`, prima di iniziare.

## Worktree & branch strategy

- **Worktree path:** `C:\Users\lopad\Documents\DocLore\Visual Basic\AskMyDocs-enh`
- **Base branch:** `origin/main` (attualmente a `be42931` — canonical phase 7 merged)
- **PR chain:** ogni PR parte dal branch del PR precedente (non da main).
  L'utente recensirà tutti i PR in sequenza alla fine.
- **Repo:** `lopadova/AskMyDocs`
- **Altra sessione attiva** nel worktree principale (`Visual Basic/Ai/AskMyDocs`)
  sul branch `feature/kb-canonical-phase-7`. **Non toccare quel worktree.**

## Branch naming

| PR  | Branch                              | Parent branch                    |
| --- | ----------------------------------- | -------------------------------- |
| 1   | `feature/enh-a-storage-scheduler`   | `origin/main`                    |
| 2   | `feature/enh-b-auth-api`            | `feature/enh-a-storage-scheduler`|
| 3   | `feature/enh-c-rbac-foundation`     | `feature/enh-b-auth-api`         |
| 4   | `feature/enh-d-frontend-scaffold`   | `feature/enh-c-rbac-foundation`  |
| 5   | `feature/enh-e-chat-react`          | `feature/enh-d-frontend-scaffold`|
| 6   | `feature/enh-f1-admin-dashboard`    | `feature/enh-e-chat-react`       |
| 7   | `feature/enh-f2-users-roles`        | `feature/enh-f1-admin-dashboard` |
| 8   | `feature/enh-g-kb-viewer-editor`    | `feature/enh-f2-users-roles`     |
| 9   | `feature/enh-h-logs-maintenance`    | `feature/enh-g-kb-viewer-editor` |
| 10  | `feature/enh-i-ai-insights`         | `feature/enh-h-logs-maintenance` |
| 11  | `feature/enh-j-docs-e2e-polish`     | `feature/enh-i-ai-insights`      |

## Task tree (11 PR)

### PR1 — Phase A: Storage & Scheduler hardening (backend-only)
- `config/filesystems.php`: aggiungere blocchi `r2`, `gcs`, `minio` pre-configurati
- `config/kb.php`: map `project_disks => ['hr-portal' => 'kb-hr', ...]` + dual-disk raw/canonical
- `app/Support/KbDiskResolver.php` (nuovo)
- `app/Console/Commands/PruneOrphanFilesCommand.php` (nuovo, con `--dry-run`)
- `bootstrap/app.php`: scheduler per `activity-log:prune`, `admin-audit:prune`, `queue:prune-failed`, `notifications:prune`, `kb:prune-orphan-files`
- `.env.example`: esempi r2/gcs/minio + `KB_PROJECT_DISKS`
- Test: `tests/Feature/Kb/KbDiskResolverTest.php`, `tests/Feature/Commands/PruneOrphanFilesCommandTest.php`
- **Verifica:** `vendor/bin/phpunit --filter=KbDiskResolver` + `--filter=PruneOrphan` verdi
- **Nessuna UI.** Gate per tutti i PR successivi.

### PR2 — Phase B: Auth JSON API + Sanctum SPA stateful
- `config/sanctum.php`: stateful domains via `SANCTUM_STATEFUL_DOMAINS`
- `config/cors.php`: `supports_credentials=true`, paths `api/*`, `sanctum/csrf-cookie`
- `app/Http/Controllers/Api/Auth/{Auth,PasswordReset,TwoFactor}Controller.php` (TwoFactor è stub)
- `app/Http/Requests/Auth/{Login,Forgot,Reset,TwoFactor}Request.php`
- `routes/api.php`: gruppo `auth/*` con middleware `web`
- Throttle: login 5/min/IP+email, forgot 3/min/IP
- Test: `tests/Feature/Api/Auth/{Login,Logout,Me,Forgot,Reset}Test.php`
- **Verifica end-to-end:** `curl -c cookies.txt /sanctum/csrf-cookie`, poi `curl -b cookies.txt POST /api/auth/login` deve restituire 200+user, poi `GET /api/auth/me` deve restituire 200+roles+projects

### PR3 — Phase C: RBAC foundation (Spatie + custom ACL tables)
- `composer require spatie/laravel-permission` (versione compatibile Laravel 13)
- Publish + run migrazioni Spatie → 5 tabelle
- Migrazioni custom: `project_memberships`, `kb_tags`, `knowledge_document_tags` (pivot), `knowledge_document_acl`
- `app/Models/User.php`: trait `HasRoles` + metodi `projectMemberships()`, `allowedProjects()`, `allowedScopes()`, `hasDocumentAccess()`
- `app/Models/{ProjectMembership,KbTag,KnowledgeDocumentAcl}.php` (nuovi)
- `app/Scopes/AccessScopeScope.php` (global scope su KnowledgeDocument)
- `app/Http/Middleware/EnsureProjectAccess.php`
- `app/Policies/KnowledgeDocumentPolicy.php` — view, edit, delete, promote
- `app/Providers/AuthServiceProvider.php` — registrazioni
- `app/Console/Commands/AuthGrantCommand.php` — `php artisan auth:grant {email} {role} [--project=]`
- `database/seeders/RbacSeeder.php` — ruoli super-admin/admin/editor/viewer + permessi base
- `config/permission.php` — publish Spatie
- Feature flag: `RBAC_ENFORCED=true` (default); se false, global scope bypassa
- Test: `tests/Feature/Rbac/{MultitenantIsolation,PolicyAccess,MembershipMiddleware}Test.php`
- **Verifica:** utente HR non vede doc finance; doc ACL nega specifico; seeder crea 4 ruoli

### PR4 — Phase D: Frontend scaffold + auth pages
- Directory `frontend/` con Vite 5 + React 19 + TS 5.6 + Tailwind 3.5
- `frontend/package.json`: react, react-dom, react-router-dom, @tanstack/react-query, @tanstack/react-router, zustand, axios, zod, react-hook-form, framer-motion, react-i18next, lucide-react (backup), recharts
- `frontend/vite.config.ts` con `laravel-vite-plugin`, output `public/build/`
- `frontend/tailwind.config.ts` che legge tokens da `styles/tokens.css` (palette custom)
- Porta `design-reference/project/styles/tokens.css` → `frontend/src/styles/tokens.css` (as-is)
- Porta `design-reference/project/components/icons.jsx` → `frontend/src/components/Icons.tsx` (TS conversion)
- Porta `design-reference/project/components/charts.jsx` → `frontend/src/components/charts/` (TS)
- Porta `design-reference/project/components/shell.jsx` → `frontend/src/components/shell/{AppShell,Sidebar,Topbar,CommandPalette,TweaksPanel,ProjectSwitcher,Tooltip,Avatar}.tsx`
- `frontend/src/main.tsx` + `App.tsx` con router (chat, dashboard, kb, insights, users, logs, maintenance)
- `frontend/src/features/auth/{LoginPage,ForgotPage,ResetPage}.tsx` — form tailwind matching tokens
- `frontend/src/lib/api.ts` — axios instance con `withCredentials: true`, bootstrap CSRF cookie
- `frontend/src/lib/auth-store.ts` — Zustand store con user/roles/projects
- `app/Http/Controllers/SpaController.php` (nuovo)
- `resources/views/app.blade.php` (nuovo, wrapper minimale con @vite)
- `routes/web.php`: `Route::get('/app/{any?}', SpaController::class)->where('any','.*')`
- `package.json` (root): aggiornato con deps React/TS/Tailwind oppure delega a frontend/
- `.github/workflows/tests.yml`: step `npm ci && npm run build`
- Test: Vitest smoke test su AppShell rendering + tokens disponibili + LoginPage render

### PR5 — Phase E: Chat UI React (port + API wiring)
- Porta `design-reference/project/features/chat.jsx` → `frontend/src/features/chat/` TS
  - `ChatView.tsx`, `ConversationList.tsx`, `MessageThread.tsx`, `MessageBubble.tsx`, `Composer.tsx`, `CitationsPopover.tsx`, `VoiceInput.tsx`, `WikilinkHover.tsx`, `ThinkingTrace.tsx`, `MessageActions.tsx`
- Usa TanStack Query per conversations/messages, Zustand per UI state
- Sposta `resources/js/rich-content.mjs` → `frontend/src/lib/rich-content.ts` (TS)
- Markdown: `react-markdown` + `remark-gfm` + plugin custom wikilink (fetcha `/api/kb/resolve-wikilink`)
- Charts inline: recharts (replace dei ~~~chart JSON blocks di rich-content)
- Backend: nuovo endpoint `GET /api/kb/resolve-wikilink?project=&slug=` → `{document_id, title, source_path, preview}` o 404
- Backend: SSE opzionale `POST /api/chat/stream` con `EventSource` (bonus, se tempo basta)
- Voice input: Web Speech API wrapper
- Test: Vitest component tests + rich-content migrati

### PR6 — Phase F1: Admin shell + Dashboard
- Backend:
  - `app/Http/Controllers/Api/Admin/DashboardMetricsController.php`
  - `app/Services/Admin/AdminMetricsService.php` (kpiOverview, chatVolume, tokenBurn, ratingDistribution, topProjects, slowestQueries, healthChecks, activityFeed)
  - `app/Services/Admin/HealthCheckService.php` (db_ok, pgvector_ok, queue_ok, kb_disk_ok, embedding_provider_ok, chat_provider_ok)
  - Route group `Route::prefix('admin')->middleware(['auth:sanctum','role:admin|super-admin'])`
- Frontend (porta `design-reference/project/features/dashboard.jsx` → `frontend/src/features/admin/dashboard/`):
  - KPI cards con sparkline, health strip, activity feed, chat volume area chart, token burn bars, rating donut
  - TanStack Query `refetchInterval: 30_000` per live update
- Test: backend metrics endpoint, Vitest dashboard render

### PR7 — Phase F2: Users & Roles
- Backend:
  - `app/Http/Controllers/Api/Admin/{User,Role,Permission,ProjectMembership}Controller.php` (CRUD Spatie-backed)
  - `app/Http/Requests/Admin/{UserStore,UserUpdate,RoleStore,RoleUpdate,MembershipAssign}Request.php`
- Frontend (porta `design-reference/project/features/admin.jsx` Users section):
  - Tabella TanStack Table, bulk actions, drawer edit con ruoli + membership + scope JSON editor (folder globs + tag chips)
  - Permission matrix per ruoli
- Test: user CRUD + role assign + scope matrix enforcement

### PR8 — Phase G: KB Tree + Viewer + Editor + Graph + Meta + History
- Backend:
  - `app/Http/Controllers/Api/Admin/{KbTree,KbDocument}Controller.php`
  - `app/Services/Admin/{KbTreeService,WikilinkResolver,PdfRenderer}.php`
  - `PdfRenderer`: Browsershot wrapper con template `resources/views/pdf/kb-doc.blade.php`
  - Endpoints: tree, show, raw, updateRaw, download, exportPdf, print, graph, search
- Frontend (porta admin.jsx KB section):
  - Split panel Tree | [Preview|Source|Graph|Meta|History]
  - Preview: react-markdown + plugin wikilink + tag + callout + frontmatter pill pack
  - Source: CodeMirror 6 (markdown lang, linter frontmatter, autocomplete wikilink, diff side-by-side)
  - Graph: reactflow con sottografo
  - Meta: ACL editor + tag manager
  - History: lista `kb_canonical_audit`
- Test: tree service, updateRaw triggers re-ingest, PDF generates

### PR9 — Phase H: Logs + Maintenance panel
- Backend:
  - `app/Http/Controllers/Api/Admin/{LogViewer,CommandRunner}Controller.php`
  - `app/Services/Admin/{LogService,CommandRunnerService}.php`
  - Migrazione `admin_command_audit` + model
  - `config/admin.php` — whitelist comandi artisan con flag destructive/confirm/permission
- Frontend (porta admin.jsx Logs + Maintenance):
  - 5 tabs log: chat logs, canonical audit, application log tail, activity log, failed jobs
  - Maintenance: cards per categoria, wizard preview→confirm→run
  - Scheduler status widget
- Test: command runner whitelist/confirm token/destructive flow unhappy paths

### PR10 — Phase I: AI Insights
- Backend:
  - `app/Services/Admin/AiInsightsService.php` (suggestPromotions, detectOrphans, suggestTags, coverageGaps, detectStaleDocs, qualityReport)
  - `app/Http/Controllers/Api/Admin/AdminInsightsController.php`
  - `app/Console/Commands/InsightsComputeCommand.php` — `insights:compute --daily` (scheduler 05:00)
  - Migrazione `admin_insights_snapshots` (JSON column con timestamp)
- Frontend (porta admin.jsx Insights section):
  - "Today we suggest" hero card
  - Grid widget (promote/orphan/tags/gaps/stale/quality)
  - Deep-dive modal con action one-click
- Test: insights service + controller

### PR11 — Phase J: Docs + E2E + polish
- README aggiornato con screenshot del nuovo admin
- CLAUDE.md aggiornato (rispetta skill `docs-match-code`)
- `.env.example` completo
- Playwright E2E: login → dashboard → crea user → assegna ruolo → viewer KB → edit doc → export PDF → maintenance run → cronologia
- Lighthouse audit (target ≥90 perf/a11y/best/seo)
- Eventuali nuove skill in `.claude/skills/`

## Parallelismo ammesso

- **Tra PR**: serializzato. Ogni PR parte dal branch del precedente.
- **Dentro un PR**: agente unico di implementazione (sicuro su un solo branch).
  L'agente può parallelizzare internamente se tocca aree indipendenti (backend vs frontend).

## Regole non negoziabili (rispetto del progetto)

- **R1–R10** in CLAUDE.md. Verifica skill attive prima di modificare file nelle aree coperte.
- **Nessun force-push** su main o su branch già pushati.
- **No amend** se hook fallisce — fare un nuovo commit fix.
- **No `--no-verify`** mai.
- **Add esplicito** dei file (no `git add -A`).
- **Co-author trailer** sui commit come da instruction.
- Ogni PR: descrizione con checklist regole rispettate + screenshot UI (da PR4 in poi).

## File di stato

- `docs/enhancement-plan/00-ORCHESTRATOR.md` (questo)
- `docs/enhancement-plan/PROGRESS.md` — avanzamento per PR
- `docs/enhancement-plan/LESSONS.md` — apprendimenti pass-through
- `docs/enhancement-plan/RESUME.md` — come riprendere dopo crash
- `docs/enhancement-plan/design-reference/` — bundle design originale (Claude Design)

## Comando "next" per l'orchestratore

Quando un PR è chiuso (merged o in attesa review), l'orchestratore:

1. Legge `PROGRESS.md` → trova il prossimo PR pending
2. Checkout branch parent (il branch del PR appena completato)
3. Crea nuovo branch come da tabella sopra
4. Dispatch implementation agent con briefing completo
5. Aspetta risultato, aggiorna `PROGRESS.md` + `LESSONS.md`, apre PR con `gh`
6. Ripete

## Verifica pre-merge per ogni PR

- `vendor/bin/phpunit` — suite completa verde
- (da PR4 in poi) `cd frontend && npm run build && npm run test` — verde
- Controllo manuale description PR con checklist
- Gh PR opened verso main
