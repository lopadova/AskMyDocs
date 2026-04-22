AskMyDocs — Potenziamento Enterprise (RBAC + React SPA + Admin Backoffice + AI Insights)                                                                                    
                                     
 Context

 Oggi AskMyDocs è un RAG Laravel 13 solido lato backend (retrieval, ingestion, promozione canonica in corso sul branch feature/kb-canonical-phase-4), ma lato frontend è un
 monolite Blade+Alpine: una sola chat UI (resources/views/chat.blade.php, 388 righe), tre form auth in Blade, Tailwind caricato da CDN, zero build pipeline. Non esistono:

 - sistema di ruoli e permessi (oggi auth:sanctum accetta qualunque token; tutte le API sono aperte a qualunque utente autenticato)
 - UI amministrativa (utenti, ruoli, KB tree, log viewer, health, maintenance)
 - build pipeline frontend (niente Vite, niente TS, niente design system)
 - ACL granulare per progetto / folder / tag / documento, benché gli hook (project_key, access_scope, frontmatter owners/reviewers) esistano in DB dal giorno zero (§13 del
 roadmap)

 Obiettivo del piano: portare AskMyDocs a prodotto enterprise con:
 1. RBAC multi-scope (progetto / folder / tag / documento) basato su Spatie + ACL custom.
 2. React 19 + TypeScript + Tailwind SPA unica (chat utente + admin in un solo bundle con code-splitting), tema light/dark, design system shadcn/ui + Tremor.
 3. Admin backoffice con dashboard metriche, gestione utenti/ruoli, tree-view della KB, Obsidian-style viewer+editor markdown, log viewer, export PDF, pannello manutenzione
  per artisan whitelisted.
 4. Scheduler hardening (log pruning + storage prune + job queue retention).
 5. Dischi Laravel configurabili per-progetto (local / S3 / R2 / GCS / MinIO).
 6. AI Insights admin-side: suggerimenti di promozione canonica, gap-analysis, tag mancanti, alerting.

 Lavora in parallelo al canonical compilation branch senza sovrapporsi: nessuna modifica ai file app/Services/Kb/*, app/Jobs/*Canonical*, app/Mcp/*, migrazioni canoniche.
 Punti di contatto gestiti tramite composizione (global scope, middleware, frontmatter extension).

 ---
 Decisioni architetturali (confermate)

 ┌──────────────┬─────────────────────────────────────────────────────────────────────────────┬────────────────────────────────────────────────────────────────────────┐
 │  Decisione   │                                   Scelta                                    │                               Rationale                                │
 ├──────────────┼─────────────────────────────────────────────────────────────────────────────┼────────────────────────────────────────────────────────────────────────┤
 │              │ spatie/laravel-permission + tabelle custom project_memberships +            │ Spatie per ruoli/permessi globali (helper ->can(), UI matura). Tabelle │
 │ RBAC engine  │ knowledge_document_acl                                                      │  custom per lo scope tenant (progetto/folder/tag/doc) che Spatie non   │
 │              │                                                                             │ copre.                                                                 │
 ├──────────────┼─────────────────────────────────────────────────────────────────────────────┼────────────────────────────────────────────────────────────────────────┤
 │ Auth SPA     │ Sanctum stateful SPA (cookie) per /app/* + Bearer per /api/* esterne        │ Niente token in localStorage = immune XSS-theft. CSRF via cookie       │
 │              │                                                                             │ XSRF-TOKEN. Bearer restano per GitHub Action e MCP.                    │
 ├──────────────┼─────────────────────────────────────────────────────────────────────────────┼────────────────────────────────────────────────────────────────────────┤
 │ Layout SPA   │ Single SPA con route-guard, admin lazy-loaded                               │ Un solo design system, un solo build, bundle admin caricato on-demand. │
 ├──────────────┼─────────────────────────────────────────────────────────────────────────────┼────────────────────────────────────────────────────────────────────────┤
 │ PDF export   │ Browsershot (Chrome headless)                                               │ Resa tipografica nativa, CSS moderno, wikilink cliccabili come ancore; │
 │              │                                                                             │  accettiamo dipendenza node+chromium su server di prod.                │
 ├──────────────┼─────────────────────────────────────────────────────────────────────────────┼────────────────────────────────────────────────────────────────────────┤
 │ Frontend lib │ Vite 5 + React 19 + TS 5.6 + Tailwind 3.5 + shadcn/ui (Radix) + Tremor      │                                                                        │
 │  stack       │ (charts) + TanStack Query v5 + TanStack Router + Zustand + react-i18next +  │ Tutto best-in-class, ~400kB initial gzipped, zero CMS.                 │
 │              │ CodeMirror 6                                                                │                                                                        │
 └──────────────┴─────────────────────────────────────────────────────────────────────────────┴────────────────────────────────────────────────────────────────────────┘

 ---
 Architettura target (vista d'insieme)

 ┌─────────────────── Browser (React 19 SPA) ─────────────────────────┐
 │   /app            → Chat (utenti)                                  │
 │   /app/admin/*    → Admin (role:admin, lazy chunk)                 │
 │   /login,/forgot  → Auth pages (guest chunk)                       │
 │   Shell: Tailwind + shadcn/ui + dark mode + i18n (it/en)           │
 └──────────┬──────────────────────────────────────┬──────────────────┘
            │ cookie XSRF + session (SPA)          │ Bearer (API esterne)
            ▼                                      ▼
 ┌──────── Laravel 13 ─────────────────────────────────────────────────┐
 │  /sanctum/csrf-cookie, /api/auth/* (SPA)                            │
 │  /api/admin/*  (role=admin, EnsureProjectAccess mw, 2FA opzionale)  │
 │  /api/kb/*     (RBAC scope + global scope KnowledgeDocument)        │
 │  /api/chat/*   (scope utente)                                       │
 │                                                                      │
 │  Middleware Chain:                                                   │
 │    auth:sanctum → SetActiveLocale → EnsureProjectAccess             │
 │                → permission:<perm> → controller                     │
 │                                                                      │
 │  Services (nuovi):                                                  │
 │    AdminMetricsService     — KPI dashboard                          │
 │    CommandRunnerService    — whitelist artisan                      │
 │    MarkdownEditorService   — editor + diff + re-ingest              │
 │    PdfRenderer (Browsershot)                                        │
 │    AiInsightsService       — suggest/promote orchestration          │
 │    ActivityLogger          — spatie/activitylog adapter             │
 └──────────────────────────────────────────────────────────────────────┘
            │
            ▼  (global scope applied transparently to every Eloquent query)
 ┌──────── PostgreSQL ─────────────────────────────────────────────────┐
 │  Esistenti: users, knowledge_documents, knowledge_chunks,           │
 │             chat_logs, conversations, messages, embedding_cache,    │
 │             kb_nodes, kb_edges, kb_canonical_audit                  │
 │                                                                      │
 │  Nuove:                                                              │
 │    roles, permissions, model_has_roles, ... (Spatie)                │
 │    project_memberships (user_id, project_key, scope_json, role)     │
 │    knowledge_document_acl (doc_id, subject_type, subject_id, perm)  │
 │    kb_tags, knowledge_document_tags (pivot)                         │
 │    activity_log (Spatie)                                            │
 │    admin_command_audit (chi ha lanciato cosa, quando, esito)        │
 │    user_two_factor (opzionale Fase C+)                              │
 │    notifications (native Laravel)                                   │
 └──────────────────────────────────────────────────────────────────────┘

 ---
 Roadmap (10 fasi, 10 PR separate)

 Tutte le fasi sono PR indipendenti su branch dedicati partendo da main (NON da feature/kb-canonical-phase-4). Ogni branch: feature/enh-<phase>-<slug>. Ogni PR: test
 PHPUnit/Vitest + verifica end-to-end.

 Fase A — Storage & Scheduler hardening (PR #1, no-UI, ~2 giorni)

 Obiettivo: deps zero-risk foundation prima di toccare frontend.

 Cosa fare:
 1. config/filesystems.php: aggiungere blocchi r2 (Cloudflare), gcs (Google), minio pre-configurati; lasciare scegliere driver via env.
 2. Per-project disk override: config/kb.php aggiungere map project_disks => ['hr-portal' => 'kb-hr', 'legal-vault' => 'kb-legal']. Nuovo helper
 App\Support\KbDiskResolver::forProject(string $projectKey): string (fallback a KB_FILESYSTEM_DISK). Usato da DocumentIngestor, DocumentDeleter, KbIngestFolderCommand,
 KbPromoteCommand.
 3. Dual-disk mode per pipeline raw→canonical (Omega-inspired): disco kb-raw (per raw ingestion) vs kb (canonical, già esistente). Il promotion flow copia da raw a
 canonical.
 4. Nuovi scheduler prune:
   - php artisan activity-log:prune --days=90 (dopo install Spatie activitylog)
   - php artisan admin-audit:prune --days=365 (nuova tabella admin_command_audit)
   - php artisan queue:prune-failed --hours=48 (già Laravel builtin, da registrare)
   - php artisan notifications:prune --days=60 (Laravel builtin)
   - php artisan kb:prune-orphan-files --dry-run (nuovo — trova file sul disco senza row in DB)
 5. Aggiornare bootstrap/app.php withSchedule(...) con i nuovi cron (04:00-04:40, dopo i prune esistenti).

 File toccati:
 - config/filesystems.php — nuovi blocchi r2/gcs/minio
 - config/kb.php — project_disks map + raw/canonical separation
 - app/Support/KbDiskResolver.php (nuovo)
 - app/Console/Commands/PruneOrphanFilesCommand.php (nuovo)
 - bootstrap/app.php — nuovi scheduler
 - .env.example — esempi r2/gcs/minio + KB_PROJECT_DISKS
 - tests/Feature/Kb/KbDiskResolverTest.php (nuovo)
 - tests/Feature/Commands/PruneOrphanFilesCommandTest.php (nuovo)

 Rollback: revert singolo PR, nessuna migrazione distruttiva.

 ---
 Fase B — Auth API + Sanctum SPA stateful (PR #2, ~2 giorni)

 Obiettivo: esporre le auth action in JSON perché React le possa consumare.

 Cosa fare:
 1. Sanctum stateful SPA: config/sanctum.php → aggiungere stateful domains da env SANCTUM_STATEFUL_DOMAINS. Configurare config/cors.php con supports_credentials=true.
 Middleware group web già corretto in Laravel 13 (EnsureFrontendRequestsAreStateful auto-added).
 2. Creare app/Http/Controllers/Api/Auth/*:
   - AuthController@login → JSON {user, abilities} o 422
   - AuthController@logout → 204
   - AuthController@me → {user, roles, permissions, projects, preferences}
   - PasswordResetController@forgot + PasswordResetController@reset (mirror JSON dei Blade esistenti)
   - TwoFactorController@enable|verify|disable (stub opzionale, behind feature flag AUTH_2FA_ENABLED=false)
 3. Throttle: rate limit login 5/min per IP+email, forgot-password 3/min per IP.
 4. routes/api.php: nuovo gruppo Route::prefix('auth')->middleware('web')->group(...).
 5. Mantenere i vecchi Blade finché Fase D non è in prod (fallback no-JS). Deprecati dopo Fase E.
 6. Endpoint nuovo GET /sanctum/csrf-cookie (built-in Sanctum) — il React lo chiamerà all'avvio.

 File toccati:
 - config/sanctum.php, config/cors.php, .env.example
 - app/Http/Controllers/Api/Auth/AuthController.php (nuovo)
 - app/Http/Controllers/Api/Auth/PasswordResetController.php (nuovo)
 - app/Http/Controllers/Api/Auth/TwoFactorController.php (nuovo, stub)
 - app/Http/Requests/Auth/{Login,Forgot,Reset,TwoFactor}Request.php
 - routes/api.php — nuovo gruppo /auth/*
 - tests/Feature/Api/Auth/*Test.php

 Nota: LoginController e PasswordResetController Blade esistenti restano per fallback, ma condividono validazione tramite FormRequest in app/Http/Requests/Auth/
 riutilizzata da entrambi.

 ---
 Fase C — RBAC foundation (PR #3, ~4 giorni)

 Obiettivo: piattaforma RBAC pronta prima di aprirla in UI.

 Cosa fare:
 1. composer require spatie/laravel-permission (compat Laravel 13).
 2. Migrazioni:
   - Spatie standard (publish + run) → roles, permissions, model_has_roles, model_has_permissions, role_has_permissions.
   - project_memberships (user_id, project_key, role, scope_allowlist JSON — es. folder globs + tag list, timestamps, UNIQUE(user_id, project_key)).
   - kb_tags (id, project_key, slug, label, color; UNIQUE(project_key, slug)) + knowledge_document_tags pivot.
   - knowledge_document_acl (document_id, subject_type[user|role|group], subject_id, permission[view|edit|delete], timestamps).
 3. User model: trait HasRoles (Spatie) + relazioni projectMemberships(), allowedProjects(), allowedScopes(), hasDocumentAccess(KnowledgeDocument $d, string $perm): bool.
 4. Global Scope App\Scopes\AccessScopeScope su KnowledgeDocument (e a cascata KnowledgeChunk, KbNode, KbEdge) — applicato SOLO se auth()->check() e l'utente non ha
 permesso globale kb.read.any. Query filtra:
   - progetto ∈ user.allowedProjects()
   - E (tag ∈ scope_allowlist.tags OR nessun tag restrittivo)
   - E (folder prefix matcha scope_allowlist.folder_globs)
   - E document_acl non nega esplicitamente
 5. Middleware:
   - EnsureProjectAccess (parametro $projectKey da route o body) → 403 se non membro.
   - permission:<perm> (Spatie built-in).
 6. Gate/Policy KnowledgeDocumentPolicy — view, edit, delete, promote. Rispetta document_acl row-level.
 7. Seed iniziale (prima installazione): ruoli super-admin, admin, editor, viewer. Permessi: users.manage, roles.manage, kb.read.any, kb.edit.any, kb.delete.any,
 commands.run, logs.view, insights.view.
 8. Console auth:grant command (operatore CLI): php artisan auth:grant {email} {role} [--project=].
 9. Frontmatter extension: app/Services/Kb/Canonical/CanonicalParser (quando Fase 3/4 del canonical merge) deve leggere access_scope + allowed_roles e persistere in
 knowledge_document_acl (hook via event CanonicalDocumentPromoted). Coordinamento col branch canonical: aggiungere questa parte solo dopo il merge di kb-canonical-phase-4
 su main.
 10. Test: multitenant isolation (utente HR non vede docs finance), document-level ACL negativo, Spatie role check, project membership middleware.

 File toccati:
 - composer.json
 - database/migrations/2026_04_23_*.php (5 migrazioni)
 - app/Models/User.php — trait HasRoles + metodi
 - app/Models/ProjectMembership.php, KbTag.php, KnowledgeDocumentAcl.php (nuovi)
 - app/Scopes/AccessScopeScope.php (nuovo)
 - app/Http/Middleware/EnsureProjectAccess.php (nuovo)
 - app/Policies/KnowledgeDocumentPolicy.php (nuovo)
 - app/Providers/AuthServiceProvider.php — registrazioni
 - app/Console/Commands/AuthGrantCommand.php (nuovo)
 - database/seeders/RbacSeeder.php (nuovo)
 - config/permission.php (Spatie, publish + custom)
 - tests/Feature/Rbac/*Test.php

 Rollback: migrazioni reversibili (migrate:rollback --step=5); composer remove spatie/laravel-permission; revert codice. Global scope rimuovibile con flag
 RBAC_ENFORCED=false.

 ---
 Fase D — Frontend scaffold + Auth pages (PR #4, ~3 giorni)

 Obiettivo: bootstrap del React SPA.

 Cosa fare:
 1. frontend/ directory con Vite + React 19 + TS + Tailwind 3.5:
 frontend/
   src/
     app/           — router, providers, query-client
     components/ui/ — shadcn/ui primitives (button, dialog, input, toast, ...)
     components/    — custom (ThemeToggle, CommandPalette, AppShell, Sidebar)
     features/
       auth/        — Login, Forgot, Reset, Me store
       chat/        — (Fase E)
       admin/       — (Fasi F-I, lazy)
     lib/api.ts     — axios instance con CSRF cookie auto-handling
     lib/i18n.ts    — react-i18next it+en
     lib/theme.ts   — dark mode persistence
     styles/        — Tailwind input + theme tokens CSS
 2. Vite config: output to public/build/ (Laravel legge il manifest). Proxy /api, /sanctum, /login, /logout in dev.
 3. Laravel side: aggiungere route catch-all Route::get('/app/{any?}', SpaController::class)->where('any', '.*') che renderizza resources/views/app.blade.php (wrapper
 minimale con <div id="root"> + @vite(['frontend/src/main.tsx'])). Blade chat.blade.php vecchio resta su /chat per fallback durante la migrazione.
 4. Auth pages (Login, Forgot, Reset, VerifyEmail): shadcn/ui forms, zod validation, react-hook-form. Chiamano /sanctum/csrf-cookie e poi /api/auth/*.
 5. Shell: AppShell con ThemeToggle (dark class su html, persist in localStorage + preference @media (prefers-color-scheme)), Sidebar collapsible, Topbar con UserMenu +
 i18n toggle, Toaster globale.
 6. CSS design tokens: palette neutral+brand (es. indigo primary), --radius 0.75rem, shadow system, motion-safe animations. Effetto wow via: motion via framer-motion
 (subtle), glass/blur backgrounds, gradient accents.
 7. package.json script npm run build chiamato da CI e in composer.json > scripts > post-install-cmd opzionale.
 8. E2E smoke test con Playwright (minimo: login → logout → forgot password).

 File toccati:
 - frontend/** (tutto nuovo)
 - resources/views/app.blade.php (nuovo, wrapper minimale)
 - routes/web.php — route catch-all /app/*
 - vite.config.ts, tailwind.config.ts, postcss.config.js, tsconfig.json (nuovi)
 - package.json — deps + script build
 - composer.json — laravel/vite-plugin server-side (già presente di solito in Laravel 13)
 - .github/workflows/tests.yml — step npm ci && npm run build

 Rollback: rimuovere frontend/, public/build/, revert route catch-all, eliminare app.blade.php. Chat Blade continua a funzionare.

 ---
 Fase E — Chat UI React (PR #5, ~3 giorni)

 Obiettivo: migrare chat.blade.php → React, eliminare Alpine/CDN.

 Cosa fare:
 1. Porting di chatApp() Alpine → feature chat/ React:
   - ConversationList.tsx, MessageThread.tsx, MessageBubble.tsx, Composer.tsx, CitationsPopover.tsx, FeedbackButtons.tsx, VoiceInput.tsx (Web Speech API).
   - State: TanStack Query per server state (conversazioni, messaggi), Zustand per UI state (sidebar open, composer draft).
 2. Riuso di resources/js/rich-content.mjs (già testato Vitest): spostare in frontend/src/lib/rich-content.ts, convertire a TS, mantenere API invariata (renderRichContent,
 extractChartBlocks, extractActionBlocks, addCodeCopyButtons). Test Vitest esistenti ri-runnano dopo il move.
 3. Charts: recharts al posto di Chart.js (bundle più piccolo, API React-native, gestisce dark mode nativamente).
 4. Markdown renderer: react-markdown + remark-gfm + plugin custom per [[wikilink]] (resolve tramite API /api/kb/resolve-wikilink).
 5. Streaming opzionale: Fase E scope "bonus" — se il tempo basta, implementare SSE /api/chat/stream con EventSource → token-by-token renderer. Non bloccante per la
 migrazione.
 6. Dark mode nativo, a11y Radix-grade (aria-live sulla chat response, focus management).
 7. Deprecare chat.blade.php e layouts/app.blade.php: rimossi dopo QA (tag milestone, rimozione in PR di cleanup).

 File toccati:
 - frontend/src/features/chat/** (nuovo)
 - frontend/src/lib/rich-content.ts (mosso da resources/js/rich-content.mjs)
 - routes/web.php — redirect /chat → /app/chat
 - resources/views/chat.blade.php — marcato @deprecated, target rimozione PR successivo
 - Endpoint nuovi lato API:
   - GET /api/kb/resolve-wikilink?project=&slug= → 200 {document_id, title, source_path, preview} o 404.

 ---
 Fase F — Admin shell + Dashboard + Users/Roles (PR #6, ~4 giorni)

 Obiettivo: prima parte dell'admin: shell, dashboard KPI, CRUD utenti/ruoli/membership.

 Cosa fare:

 Backend:
 1. Controller app/Http/Controllers/Api/Admin/*:
   - UserController (index con filtri, show, store, update, destroy, resendInvite, toggleActive)
   - RoleController (Spatie-backed, CRUD + sync permissions)
   - PermissionController (index read-only, catalogo permessi)
   - ProjectMembershipController (assign/revoke utente↔progetto con scope JSON)
   - DashboardMetricsController (/api/admin/metrics?scope=global|project&project=&range=7d)
 2. app/Services/Admin/AdminMetricsService.php:
   - kpiOverview() → {total_docs, total_chunks, total_chats_24h, avg_latency_ms_7d, failed_jobs, pending_jobs, cache_hit_rate_7d, canonical_coverage_pct, storage_used_mb}
   - chatVolume(Range $r) → timeseries per giorno
   - tokenBurn(Range $r) → timeseries per provider/model
   - ratingDistribution(Range $r) → {positive, negative, none}
   - topProjects() → top 10 per volume chat
   - slowestQueries(limit) → p95 latency chats
   - healthChecks() → {db_ok, pgvector_ok, queue_ok, kb_disk_ok, embedding_provider_ok, chat_provider_ok}
 3. Route group routes/api.php → Route::prefix('admin')->middleware(['auth:sanctum','role:admin|super-admin'])->group(...).

 Frontend:
 1. frontend/src/features/admin/ lazy-loaded chunk (React.lazy + Suspense).
 2. Pagine:
   - /app/admin → Dashboard: grid Tremor con 6 KPI card, 2 timeseries (chat volume, token burn), donut rating, health strip in alto (pallini verdi/rossi), activity feed
 (ultimi 20 eventi da activity_log + kb_canonical_audit).
   - /app/admin/users → Tabella con search/filter (TanStack Table), bulk action, drawer edit con: profilo, ruoli (multi-select Spatie), membership progetti (accordion per
 progetto con scope JSON editor visuale: folder globs + tag chips).
   - /app/admin/roles → Tabella ruoli + dialog edit con permission matrix (checkboxes grouped per area).
 3. Effetti "WOW": motion.div per card entry, gradient backgrounds condizionali per health status, live-update dashboard via TanStack Query refetchInterval: 30_000,
 skeleton loader shadcn, toast + undo per delete.

 File toccati:
 - app/Http/Controllers/Api/Admin/*.php (5 controller)
 - app/Services/Admin/AdminMetricsService.php, HealthCheckService.php
 - app/Http/Requests/Admin/User*Request.php, etc.
 - routes/api.php — prefix admin
 - frontend/src/features/admin/{Dashboard,Users,Roles}/**
 - tests/Feature/Api/Admin/*Test.php

 ---
 Fase G — KB Tree Explorer + Obsidian viewer/editor (PR #7, ~5 giorni)

 Obiettivo: navigare, leggere, modificare, esportare la KB.

 Backend:
 1. app/Http/Controllers/Api/Admin/KbTreeController@index → /api/admin/kb/tree?project=&mode=canonical|raw|all restituisce albero JSON {type: folder|doc, name, path,
 children, meta}. Usa KbTreeService che walk-a Storage::disk('kb') + join con knowledge_documents (respecting global scope → vedrà solo docs permessi).
 2. KbDocumentController con:
   - show(id) → dettaglio doc: frontmatter, chunks count, tags, ACL, version_hash, kb_nodes correlati, kb_edges in/out, audit trail.
   - raw(id) → 200 {path, disk, content, content_hash, mime} (legge il file).
   - updateRaw(id) → salva, crea nuova version_hash, re-dispatch IngestDocumentJob, scrive row in kb_canonical_audit. Usa KbPath::normalize() e rispetta policy edit.
   - download(id) → stream file (rispetta disco).
   - exportPdf(id) → invoca PdfRenderer::renderDocument($doc) (Browsershot con template Blade resources/views/pdf/kb-doc.blade.php che include CSS print-optimized, TOC,
 wikilink come ancore PDF).
   - print(id) → 200 HTML print-ready (per window.print() client-side, senza PDF server).
   - graph(id) → sottografo correlato (reuse GraphExpander se già in main dal canonical branch).
 3. app/Services/Admin/PdfRenderer.php — wrapper Browsershot con timeout, font embedding, A4, header/footer (progetto + data).
 4. Nuovo endpoint POST /api/admin/kb/search?q=&project= — full-text + title semantic per la palette di comando in albero.

 Frontend:
 1. /app/admin/kb → split panel:
   - Sinistra: TreeView (shadcn/ui Collapsible + icone tipo: decision/runbook/...), drag-to-resize, search box con highlight, filtri (canonical/raw/trashed/status).
   - Destra: Tab [Preview | Source | Graph | Meta | History].
       - Preview: Obsidian-style markdown. react-markdown + plugin:
           - remark-frontmatter + remark-gfm
       - Custom remark-wikilink → <WikiLink> component che fetcha /api/kb/resolve-wikilink e diventa clickable; tooltip hover-preview.
       - Custom remark-obsidian-tag (#tag) → chip clickable.
       - Custom remark-callout (> [!note]).
       - Frontmatter renderizzato in alto come "pill pack" (type, status, owners badge, tags).
     - Source: CodeMirror 6 (lang-markdown + keymap default + linter frontmatter schema + autocomplete wikilink slug). Pulsanti Save / Discard / Preview-diff (side-by-side
 vs version_hash corrente).
     - Graph: sigma.js o react-flow con nodi/archi filtrati dal sottografo; click-to-navigate.
     - Meta: pannello di meta-dati, ACL editor (chi può vedere/modificare), tag manager.
     - History: lista kb_canonical_audit events.
 2. Toolbar doc: Download / Print / Export PDF / Re-ingest (force) / Soft delete / Restore (withTrashed) / Copy wikilink.

 File toccati:
 - app/Http/Controllers/Api/Admin/Kb{Tree,Document}Controller.php
 - app/Services/Admin/{KbTreeService,PdfRenderer,WikilinkResolver}.php
 - resources/views/pdf/kb-doc.blade.php (nuovo, template print)
 - frontend/src/features/admin/kb/{Tree,Viewer,Editor,Graph,Meta,History}.tsx
 - frontend/src/lib/markdown/{wikilink-plugin,tag-plugin,callout-plugin}.ts
 - tests/Feature/Api/Admin/KbDocumentControllerTest.php

 Dipendenze npm: codemirror, @codemirror/lang-markdown, react-markdown, remark-gfm, remark-frontmatter, unified, reactflow, framer-motion.

 ---
 Fase H — Log viewer + Maintenance panel (PR #8, ~3 giorni)

 Backend:
 1. LogViewerController:
   - chatLogs → paginated filter (project, model, latency>, tokens>, date range).
   - canonicalAudit → timeline per progetto + filtri event_type + actor.
   - applicationLog → tail ultime N righe di storage/logs/laravel-*.log (whitelist filename, no path traversal); supporta filtro level ([ERROR], etc.).
   - activityLog → tabella spatie/activitylog.
   - failedJobs → lista failed_jobs + retry singolo/bulk (php artisan queue:retry).
 2. CommandRunnerService + AdminCommandRunnerController:
   - Whitelist in config/admin.php:
   'allowed_commands' => [
     'kb:ingest-folder' => ['args_schema' => [...], 'requires_permission' => 'commands.run', 'destructive' => false],
     'kb:ingest' => [...],
     'kb:delete' => ['destructive' => true, 'requires_confirm' => true],
     'kb:rebuild-graph' => [...],
     'kb:validate-canonical' => ['destructive' => false],
     'kb:promote' => ['destructive' => true],
     'kb:prune-deleted' => ['destructive' => true, 'requires_confirm' => true],
     'kb:prune-embedding-cache' => ['destructive' => true],
     'kb:prune-orphan-files' => ['destructive' => true],
     'chat-log:prune' => ['destructive' => true],
     'queue:retry' => ['destructive' => false],
     'activity-log:prune' => ['destructive' => true],
 ],
   - run(command, args):
       - Policy check (commands.run + per-command permission).
     - Validazione args via schema.
     - Se destructive=true: require body confirm_token (one-time token generato dalla GET /preview).
     - Esegue via Artisan::call() → cattura output in admin_command_audit (user, command, args, started_at, completed_at, exit_code, output_head_200chars).
     - Long-running: dispatch in queue + websocket/SSE progress (Fase H scope extra: ammettiamo "pending" → check status periodico).

 Frontend:
 1. /app/admin/logs:
   - Tabs: Chat Logs, Canonical Audit, Application, Activity, Failed Jobs.
   - Filtri contextual, export CSV, live tail toggle (SSE).
   - Drawer detail per chat log: domanda, risposta, citations, prompt inviato, token breakdown.
 2. /app/admin/maintenance:
   - Cards per categoria (KB ingest, KB delete, KB graph, Pruning, Queue).
   - Per ogni card: pulsante "Run" → wizard:
       i. Preview (dry-run se disponibile, o descrizione + impatto stimato).
     ii. Confirm (checkbox "Ho capito", digitare nome comando se destructive).
     iii. Run → mostra output live + notification on complete.
   - Cronologia esecuzioni (tabella admin_command_audit).
   - "Scheduler status" widget (mostra prossime run + ultima esecuzione per ciascun scheduled).

 File toccati:
 - app/Http/Controllers/Api/Admin/{LogViewer,CommandRunner}Controller.php
 - app/Services/Admin/{LogService,CommandRunnerService}.php
 - config/admin.php (nuovo) — whitelist comandi
 - database/migrations/*_create_admin_command_audit_table.php
 - app/Models/AdminCommandAudit.php
 - frontend/src/features/admin/{logs,maintenance}/**
 - tests/Feature/Api/Admin/CommandRunnerControllerTest.php (unhappy paths: unknown cmd, destructive senza confirm, perm mancante)

 ---
 Fase I — AI Insights + Suggerimenti (PR #9, ~3 giorni)

 Obiettivo: sfruttare AiManager per valore admin-side.

 Backend:
 1. app/Services/Admin/AiInsightsService.php:
   - suggestPromotions(int $limit=10): cerca docs is_canonical=false con alto uso in citations ultimi 30gg → chiama KbPromotionController::suggest (già esistente) → ritorna
  candidati di promozione.
   - detectOrphans(): docs senza né kb_edges né citations ultimi 60gg.
   - suggestTags(KnowledgeDocument $d): prompt LLM che legge chunk + tag esistenti del progetto → propone tag mancanti.
   - coverageGaps(): cluster delle domande chat con 0 citations o low_confidence (proxy: chunks_count < 2) → LLM summarize i topic non coperti.
   - detectStaleDocs(): canonical con indexed_at > 180gg e contenuti richiamati spesso in chat con rating negativo.
   - qualityReport(): distribution chunk length, outlier (chunk <30 char o >2k char), docs senza titolo/frontmatter.
 2. AdminInsightsController espone endpoint per ciascuna funzione.
 3. Scheduler: insights:compute --daily alle 05:00 → salva snapshot in admin_insights_snapshots (JSON). La UI legge lo snapshot invece di ricalcolare on-demand (costa LLM).

 Frontend:
 1. /app/admin/insights:
   - Top card "Oggi ti consiglio di...": lista azionable (promote N docs, review M orphans, add tags a X docs).
   - Grid widget: Promotion suggestions, Orphan detection, Coverage gaps, Stale docs, Quality report.
   - Ogni widget ha "Deep-dive" modal con dettagli + azione one-click (ove applicabile, es. "Promuovi ora" usa /api/kb/promotion/promote).
 2. In /app/admin/kb tab Meta: pannello "AI suggestions for this doc" → LLM propone tag, link ad altri doc correlati, riassunto esecutivo.

 File toccati:
 - app/Services/Admin/AiInsightsService.php
 - app/Http/Controllers/Api/Admin/AdminInsightsController.php
 - app/Console/Commands/InsightsComputeCommand.php
 - database/migrations/*_create_admin_insights_snapshots_table.php
 - bootstrap/app.php — nuovo scheduler 05:00
 - frontend/src/features/admin/insights/**
 - tests/Feature/Api/Admin/AdminInsightsControllerTest.php

 ---
 Fase J — Docs + E2E + polish (PR #10, ~2 giorni)

 - Aggiornare README.md, CLAUDE.md, .github/copilot-instructions.md con le nuove aree (rispettando skill docs-match-code).
 - Aggiornare .env.example completo.
 - Skill nuove in .claude/skills/ se emergono pattern ricorrenti (es. admin-command-whitelist-hygiene).
 - Playwright E2E suite: login → dashboard → crea user → assegna ruolo → viewer KB → edit doc → export PDF → maintenance run + cronologia.
 - Lighthouse audit (target: ≥90 perf/a11y/best/seo su chat e admin).
 - Screenshot set per README.

 ---
 Rispetto delle regole di progetto (CLAUDE.md)

 Queste regole vanno rispettate in TUTTE le fasi e menzionate nei PR description:

 - R1 (KbPath::normalize) → Fase A (KbDiskResolver), Fase G (editor raw), Fase H (command runner args).
 - R2 (soft-delete aware) → Fase G (endpoint withTrashed per restore, tree-view trashed filter).
 - R3 (memory-safe bulk) → Fase I (insights compute usa chunkById), Fase H (log viewer paginato, mai get() senza limit).
 - R4 (no silent failures) → Fase G (updateRaw Storage::put return check), Fase I (PDF export).
 - R6 (docs coupling) → Fase A e J aggiornano .env.example insieme a config/*.
 - R7 (no 0777, no @-silence) → Fase A (Storage permissions), sempre.
 - R8 (KB_PATH_PREFIX) → Fase A (resolver), Fase G (editor).
 - R9 (docs match code) → Fase J prima del merge finale.

 Canonical awareness skill: Fase C aggiornamento CanonicalParser da fare SOLO dopo il merge di feature/kb-canonical-phase-4 su main — altrimenti attendere e segnalare nel
 PR description.

 ---
 File critici già esistenti da riutilizzare (non duplicare!)

 ┌───────────────────────┬────────────────────────────────────────────────────┬────────────────────────────────┐
 │        Utility        │                        Path                        │        Quando riusarla         │
 ├───────────────────────┼────────────────────────────────────────────────────┼────────────────────────────────┤
 │ KbPath::normalize()   │ app/Support/KbPath.php                             │ Ovunque si accetti source_path │
 ├───────────────────────┼────────────────────────────────────────────────────┼────────────────────────────────┤
 │ DocumentIngestor      │ app/Services/Kb/DocumentIngestor.php               │ Editor Fase G (re-ingest)      │
 ├───────────────────────┼────────────────────────────────────────────────────┼────────────────────────────────┤
 │ DocumentDeleter       │ app/Services/Kb/DocumentDeleter.php                │ Admin doc delete               │
 ├───────────────────────┼────────────────────────────────────────────────────┼────────────────────────────────┤
 │ AiManager             │ app/Ai/AiManager.php                               │ Fase I insights (NO nuovo SDK) │
 ├───────────────────────┼────────────────────────────────────────────────────┼────────────────────────────────┤
 │ KbSearchService       │ app/Services/Kb/KbSearchService.php                │ Chat React Fase E              │
 ├───────────────────────┼────────────────────────────────────────────────────┼────────────────────────────────┤
 │ ChatLogManager        │ app/Services/ChatLog/ChatLogManager.php            │ Mai rompere try/catch          │
 ├───────────────────────┼────────────────────────────────────────────────────┼────────────────────────────────┤
 │ KbPromotionController │ app/Http/Controllers/Api/KbPromotionController.php │ Insights Fase I "promote now"  │
 ├───────────────────────┼────────────────────────────────────────────────────┼────────────────────────────────┤
 │ rich-content.mjs      │ resources/js/rich-content.mjs                      │ Muovere in TS, Fase E          │
 └───────────────────────┴────────────────────────────────────────────────────┴────────────────────────────────┘

 ---
 Verification end-to-end

 Dopo tutte le fasi:

 # Backend
 vendor/bin/phpunit             # 200+ test (baseline + nuovi)
 vendor/bin/phpunit --testsuite Rbac     # isolamento multi-tenant
 vendor/bin/phpunit --testsuite Admin    # admin API

 # Frontend
 cd frontend && npm run build   # compila senza errori TS
 npm run test                   # Vitest (rich-content + unit)
 npm run e2e                    # Playwright (happy paths)

 # Full stack smoke
 php artisan serve &
 npm run dev &
 # 1. POST /sanctum/csrf-cookie            → 204
 # 2. POST /api/auth/login                 → 200 {user}
 # 3. GET  /api/auth/me                    → 200 {roles:[admin], projects:[...]}
 # 4. GET  /api/admin/metrics?scope=global → 200 (KPI)
 # 5. GET  /api/admin/kb/tree?project=demo → 200 (albero)
 # 6. POST /api/admin/kb/:id/raw           → 200 (re-ingest dispatched)
 # 7. POST /api/admin/kb/:id/export-pdf    → 200 (binary, aprire in viewer)
 # 8. POST /api/admin/commands/run         → 200 (con confirm token per destructive)
 # 9. GET  /api/admin/insights/daily       → 200 (snapshot)
 # Verificare in UI: login, dashboard con numeri reali, tree navigabile,
 # viewer che apre in dark mode, editor che salva e triggera re-ingest,
 # PDF scaricabile e ben formattato, command runner con audit trail.

 Performance SLO (non-regressione canonical):
 - /api/kb/chat p95 < baseline + 80ms (global scope con 1 utente membro di 3 progetti).
 - React SPA: LCP < 2.5s, TTI < 3.5s, bundle iniziale < 300kB gzipped (chat), admin chunk < 600kB gzipped.
 - Dashboard metrics query: < 500ms p95 (con 1M chat_logs, indici appropriati).

 ---
 Rischi & mitigazioni

 ┌───────────────────────────────────────────┬──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┐
 │                  Rischio                  │                                                       Mitigazione                                                        │
 ├───────────────────────────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
 │ Global scope rompe retrieval canonical    │ Test MultiTenantCanonicalScopeTest: user membro di proj X deve vedere solo doc X. Flag RBAC_ENFORCED=false per           │
 │                                           │ disabilitare in emergenza.                                                                                               │
 ├───────────────────────────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
 │ Merge conflict con                        │ Fase C aggiornamento CanonicalParser solo dopo merge canonical. Le altre fasi non toccano app/Services/Kb/*.             │
 │ feature/kb-canonical-phase-4              │                                                                                                                          │
 ├───────────────────────────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
 │ Browsershot ops-heavy                     │ Feature flag ADMIN_PDF_ENGINE=browsershot|dompdf|disabled. Fallback Dompdf per ambienti senza node.                      │
 ├───────────────────────────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
 │ Command runner = RCE attack surface       │ Whitelist rigorosa + schema args + confirm token + permesso granulare + audit log immutabile + rate limit 10/min.        │
 ├───────────────────────────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
 │ React SPA bundle bloat                    │ Code-splitting per feature, dynamic import delle librerie grosse (reactflow, codemirror), Vite                           │
 │                                           │ build.chunkSizeWarningLimit: 300.                                                                                        │
 ├───────────────────────────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
 │ Tailwind CDN→build = regressione stili    │ Fase D mantiene classi esatte delle Blade auth durante migrazione; visual diff screenshot in CI.                         │
 ├───────────────────────────────────────────┼──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┤
 │ Utenti esistenti senza ruolo dopo install │ Migrazione backfill: tutti gli utenti esistenti ottengono ruolo viewer + membership su tutti i project_key distinti di   │
 │  Spatie                                   │ knowledge_documents. Seeder rollback-safe.                                                                               │
 └───────────────────────────────────────────┴──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┘

 ---
 Non in scope (esplicito)

 - Multi-tenant hard isolation (DB-per-tenant). Rimaniamo su row-level scope.
 - 2FA full implementation — solo stub + feature flag (Fase B), implementazione reale in PR futuro.
 - WebSocket real-time collab su editor — polling via TanStack Query è sufficiente per ora.
 - i18n per lingue extra it/en — struttura pronta, altre lingue in PR futuri.
 - Elasticsearch backend — rimane pgvector+FTS come oggi.
 - Mobile native app — SPA responsive è sufficiente.