# Lessons Learned (pass-through fra agenti)

> Ogni agente aggiunge qui tutto ciò che ha scoperto durante l'implementazione e che
> può risparmiare tempo all'agente successivo. Ordine cronologico (più recente in alto).

---

## PR5 — Phase E (general-purpose agent, 2026-04-22)

- **Playwright + Orchestra Testbench sanity**: the E2E contract needs
  `APP_ENV=testing` at runtime so the `/testing/reset` and
  `/testing/seed` routes exist at all. Gate them BOTH in `routes/web.php`
  (conditional on `app()->environment('testing')`) AND in the controller
  (`abort_unless(app()->environment('testing'), 403)`). Defense in
  depth — if someone removes the routes-level guard in a refactor, the
  controller still 403s.
- **`Artisan::call('migrate:fresh')` inside `RefreshDatabase` trips
  SQLite**: the real endpoint runs migrate:fresh, which VACUUMs, which
  blows up under the SQLite :memory: transaction. Tests around the
  controller can't mock the Artisan facade either (Testbench's
  `Orchestra\Testbench\Console\Kernel` is `final` and Mockery refuses).
  Workaround: expose `protected function runMigrateFresh()` +
  `runDbSeed()` hooks in the controller and subclass them in the test
  file with a spy that records the intent without invoking artisan.
  The seam is tiny and the real controller stays one `$this->runX()`
  line away from the primary API call.
- **Custom remark node types need `RootContent` casts under strict TS.**
  Plain `as Text` on the first mapped segment is fine, but the custom
  `WikilinkNode` / `TagNode` / `CalloutNode` shapes don't satisfy
  mdast's `RootContent` union. Cast the full splice array to
  `RootContent[]` with a single `as unknown as RootContent` on the
  custom shape and TypeScript stops complaining without weakening the
  source-level typing (the node shape is still strictly enforced at
  the builder site).
- **`react-markdown` v10 + custom `hName` components**: pass custom
  node types through `data.hName` + `data.hProperties` so rehype emits
  a custom tag react-markdown picks up via its `components` map. The
  `components` map value prop names follow the emitted tag (i.e. we
  wrote `components={{ wikilink: …, tag: …, callout: … }}`). TypeScript
  can't type the custom tags so cast the map to `as never` at the call
  site — the types are enforced at the component boundary.
- **`recharts` is heavy**: dropping it into the main chunk bumped the
  gzipped bundle from 131kB → 192kB. PR5 does NOT yet render a
  recharts chart (the chat ~~~chart artifact path is ready but unused
  by default), so it's bundled for readiness. Recommendation for PR6:
  code-split the admin dashboard's chart usage via `React.lazy()` so
  recharts only loads on `/app/dashboard`, dropping the chat entry
  back under 150kB gz.
- **Wikilink resolver caching strategy**: TanStack Query's
  `queryKey: ['wikilink', project, slug]` + `enabled: hover` keeps the
  network quiet until the user actually hovers, and a 5-minute
  `staleTime` collapses repeated hovers on the same slug into one
  request. On a 100-message thread with 6 wikilinks per message, this
  cuts the request count from 600 potential fetches to ≤ `uniqueSlugs`.
- **Zustand + TanStack Query separation**: the store carries UI-only
  state (draft, isListening, showGraph, sidebarOpen, activeConversationId).
  Everything the server owns lives in the TanStack cache. The chat
  mutation hook does optimistic updates into `['messages', id]` then
  invalidates on success so the server's canonical message ids
  replace the negative placeholder. Don't duplicate server state in
  the store — it drifts.
- **DemoSeeder must bypass the AccessScopeScope**: under RBAC-enforced
  mode, `KnowledgeDocument::query()` returns nothing for an
  unauthenticated request (no memberships ⇒ `whereRaw('1=0')`). The
  seeder uses `withoutGlobalScopes()->where(slug, …)` to check existence
  before inserting; without that the seeder would keep creating
  duplicate rows.
- **Legacy rich-content kept around**: `npm run test:legacy` still
  imports `resources/js/rich-content.mjs`. Deleting it would break
  PR11's staged rollout (the Blade `/chat-legacy` route still uses
  the module). The TS port lives alongside; both are CI-gated.
- **React 18.3.1 + TanStack Router v1 `useParams({ strict: false })`**:
  the ChatView reads `conversationId` from the URL via
  `useParams({ strict: false })` so it works both at `/app/chat` (no
  param) and at `/app/chat/:conversationId`. Strict mode would
  reject the no-param case.
- **Playwright storage state + /testing endpoints**: the `setup`
  project POSTs to /testing/reset + /testing/seed BEFORE navigating
  to /login. That sequence gives us a clean demo DB + known admin
  credentials for the auth flow. Storage state is then written once
  to `playwright/.auth/admin.json` (gitignored) and every chromium
  test reuses it — no per-test login.
- **SSE streaming deferred**: the briefing flagged SSE as a bonus. It
  did not ship in PR5 because `/api/chat/stream` requires either a
  separate dispatcher pattern around the existing AiManager (no
  streaming contract today) or provider-level adapters. The React
  Composer falls back to the existing JSON POST, which is already
  fast enough in the demo seeded corpus. Candidate for PR5.1 if a
  non-blocking follow-up slot opens.
- **Raccomandazione per PR6 (Admin Dashboard)**: the chat endpoint
  contract is now the template — every new feature under
  `frontend/src/features/<x>/` must ship (1) a typed `x.api.ts`, (2)
  a TanStack Query hook file, (3) a Zustand store for UI-only state,
  (4) an `x.store.test.ts`, (5) a feature folder with testids on every
  actionable element, and (6) an `e2e/<x>.spec.ts` with at least one
  happy + one failure scenario. Don't regress to "Vitest only" — the
  Playwright gate is the user-facing contract. Also: code-split
  recharts via `React.lazy()` when adding the KPI charts.

---

## Bootstrap (orchestrator, 2026-04-23)

- **Worktree path:** `C:\Users\lopad\Documents\DocLore\Visual Basic\AskMyDocs-enh` (branch `feature/enh-orchestrator` è la zona safe, NON toccarlo — è qui solo per state).
- **Tutti i PR** girano su branch dedicati nel worktree, partendo dal branch del PR precedente.
- **Sessione parallela** sul worktree principale `Visual Basic/Ai/AskMyDocs` sta ancora lavorando su `feature/kb-canonical-phase-7` (merged PR #15, ma potrebbe esserci follow-up). **Mai toccare quel filesystem.**
- **PHP shim:** la user-memory dice che `php` è rimappato a `php84` tramite `php.bat`. PHPUnit via `vendor/bin/phpunit`.
- **Clean-code rules** (user memory): one-level indent, no else, bad-path-first, return early. **Rispettali sempre.**
- **Gitmoji style:** commit style del repo è `feat(area): short`, `fix(area): short`. Niente gitmoji, solo type(scope). Vedi `git log --oneline -20`.
- **Co-author trailer:** richiesto su ogni commit autonomo.
- **Design bundle** da Claude Design è in `docs/enhancement-plan/design-reference/`. Il layout UX/UI è vincolante: dark-first glassmorphism, violet→cyan, Geist Sans/Mono. Porta i tokens.css as-is in `frontend/src/styles/tokens.css` e usali a fianco di Tailwind (non convertire tutto in classi Tailwind — inline styles + tokens sono già ottimi).
- **Skill attive** per questo repo (da CLAUDE.md): R1 kb-path-normalization, R2 soft-delete-aware-queries, R3 memory-safe-bulk-ops, R4 no-silent-failures, R6 docs-match-code, R7 no-world-writable, R8 kb-path-prefix, R9 docs-match-code, R10 canonical-awareness. **Rispetta tutte** in ogni PR.
- **Main branch** è a `be42931` (Merge PR #15 canonical phase 7). Fetched e confermato.

---

## PR1 — Phase A (general-purpose agent, 2026-04-23)

- **Scheduler aveva 4 entry, non 3**: `kb:prune-embedding-cache` 03:10, `chat-log:prune` 03:20, `kb:prune-deleted` 03:30, `kb:rebuild-graph` 03:40. Le nuove entry sono 04:00 (`queue:prune-failed`) e 04:40 (`kb:prune-orphan-files --dry-run`). 04:20 e 04:30 sono riservati via TODO a PR3/PR9.
- **`notifications:prune` NON esiste in Laravel 13**: ho verificato che `php artisan help notifications:prune` produce "command not defined" e l'unico comando namespace `notifications:` è `notifications:table`. Il sostituto corretto quando serve pulizia: `model:prune --model=App\Models\DatabaseNotification` (richiede trait `Prunable` sul model). Vedi NOTE in `bootstrap/app.php`. Questa è una divergenza rispetto al briefing originale — documentata in PROGRESS + scheduler.
- **Command auto-discovery**: in questo repo **non** funziona. Tutti i comandi Artisan sono registrati esplicitamente in `App\Providers\AppServiceProvider::boot()` via `$this->commands([...])`. Un nuovo comando che non compare in quella lista viene respinto con "command not defined" sia in prod che nei test Orchestra Testbench. **Aggiungi ogni nuovo Artisan command a quel elenco**.
- **`Storage::fake('kb')` soffoca le failure**: `delete()` ritorna sempre `true`. Per testare R4 (return value handling) usa `Mockery::mock(Filesystem::class)` e `Storage::shouldReceive('disk')->andReturn($mock)`. Esempio funzionante in `tests/Feature/Commands/PruneOrphanFilesCommandTest::test_delete_failure_is_surfaced_as_nonzero_exit`.
- **`expectsOutputToContain` PendingCommand**: due chiamate consecutive funzionano solo se i substring atterrano su `doWrite` diversi. Se entrambe le substring finiscono sullo **stesso chunk** di output (es. stessa `$this->line(...)`), **solo la prima viene consumata** (limitazione interazione Mockery mock). Fix: unisci le substring in una sola chiamata `expectsOutputToContain('DRY-RUN: 2 of 5 orphan...')`.
- **`$this->info("[xxx] ...")` con parentesi quadre**: Symfony Console formatter tenta di interpretarle come tag colore. Preferisci `$this->line(sprintf(...))` per messaggi di summary che includono `[disk-name]`.
- **Memory-safe orphan scan pattern**: `array_chunk($paths, 1000)` + `whereIn('source_path', $chunk)->pluck(...)` + `array_diff` mantiene la memoria bounded anche con 1M+ righe e **evita l'N+1**. Non fare `->get()` su tutta la tabella `knowledge_documents`. Il limite 1000 è volutamente conservativo per non sforare i limiti dei placeholder IN su SQLite/SQL Server.
- **`KB_PATH_PREFIX` e `source_path` in DB**: `DocumentIngestor` stora il path **senza prefix** (`relativePath`, non `$prefix/relativePath`). Il job re-applica il prefix a tempo di lettura. Quindi `kb:prune-orphan-files` deve **strippare il prefix** prima di confrontare con DB. Vedi `IngestDocumentJob::handle()` linee 50-51.
- **Worktree senza vendor/**: il worktree `AskMyDocs-enh` parte vuoto di `vendor/`. Eseguire `composer install` (via `composer.bat` se bash non trova l'exe — è in `~/.config/herd/bin/`) come primo step. Anche `bootstrap/cache/` può servire `chmod 777` se Laravel si lamenta di is_writable() su Windows con spazi nel path.
- **Raccomandazione per PR2 (Auth)**: Laravel 13 registra automaticamente `EnsureFrontendRequestsAreStateful` sul group `web`. Basta settare `SANCTUM_STATEFUL_DOMAINS` e flippare `config/cors.php` `supports_credentials=true`. Non serve aggiungere middleware manualmente.
- **Raccomandazione per PR3 (RBAC)**: `composer require spatie/laravel-permission` su Laravel 13 richiede `^6.x`. Prima di committare, `composer why-not spatie/laravel-permission:6.0` per intercettare conflitti. Aggiungere anche lo scheduler `activitylog:clean --days=90` al posto del TODO in bootstrap/app.php.
- **Raccomandazione per PR3+ (wire-in di `KbDiskResolver`)**: il service è **introdotto ma non wired** nei servizi esistenti. Quando serve davvero il multi-tenant per-project disk, sostituisci `Storage::disk('kb')` / `Storage::disk(config('kb.sources.disk'))` con `Storage::disk(KbDiskResolver::forProject($projectKey))` in: `DocumentIngestor`, `DocumentDeleter`, `IngestDocumentJob`, `KbIngestFolderCommand`, `KbIngestController`, `KbDeleteController`, `KbDeleteCommand`. Test obbligatorio: un doc del progetto A non deve mai essere visibile leggendo dal disk del progetto B.

---

## PR2 — Phase B (general-purpose agent, 2026-04-23)

- **Sanctum SPA requires `web` middleware on auth routes**: Sanctum's
  `EnsureFrontendRequestsAreStateful` only fires for requests under the `web`
  middleware group (or explicitly declared stateful). Routes under
  `routes/api.php` are wrapped in the `api` group by Laravel 13's
  `withRouting(api: …)` — NOT the `web` group — so the idiomatic fix is
  `Route::middleware('web')->prefix('auth')->group(…)` inside `routes/api.php`.
- **CORS `supports_credentials=true` is mandatory** for cookie-based auth;
  without it the browser never sends the session cookie cross-origin.
  `allowed_origins=['*']` is INVALID when credentials is true (CORS spec) —
  list full origins explicitly and keep them in sync with
  `SANCTUM_STATEFUL_DOMAINS`.
- **TestCase must register `SanctumServiceProvider`**: the project's existing
  TestCase registers providers manually (Windows + spaces-in-path bug — see
  `getEnvironmentSetUp` comment) so Sanctum has to be added there too.
  Otherwise `auth:sanctum` middleware explodes with "guard [sanctum] is not
  defined" and `GET /sanctum/csrf-cookie` returns 404.
- **`config/auth.php` must declare `guards.sanctum` explicitly**: Sanctum
  registers its guard programmatically in `SanctumServiceProvider::register`,
  BUT TestCase rewrites the whole `auth` config after providers are
  registered (`$app['config']->set('auth', require ...config/auth.php)`),
  which drops the guard. Declaring `guards.sanctum` in `config/auth.php`
  survives the overwrite and keeps the middleware resolvable in test and
  prod alike.
- **Testbench `defineRoutes` needs manual `api` prefix + group**: the real
  bootstrap applies `Route::middleware('api')->prefix('api')->group(api_routes_file)`
  via `withRouting(api: …)`. Testbench's `defineRoutes` does NOT do that —
  it just registers whatever you write. In each feature test:
  `protected function defineRoutes($router): void { $router->middleware('api')->prefix('api')->group(__DIR__.'/…/routes/api.php'); }`.
- **Throttle-by-email must be lower-cased**: uppercase differences in the
  email input produce different throttle buckets. `mb_strtolower($email).'|'.$ip`
  collapses the bucket so five tries with `Test@x.com` and five tries with
  `test@x.com` trigger the 429 as expected.
- **Anti-enumeration on forgot-password**: return 204 regardless of whether
  the email exists. Don't include the user in the response, don't change
  the status code, don't change the latency. The `Password::broker()->sendResetLink`
  call itself is safe to invoke on non-existent emails — it no-ops and
  returns the `INVALID_USER` status.
- **FormRequest reuse across Blade + JSON**: existing Blade controllers can
  type-hint the new FormRequest classes directly — the validation fires
  the same way, and the controllers differ only in the response shape.
  Downstream tests that invoked `LoginController::login(Request)` must be
  updated to construct a `LoginRequest::create(...)` instead — PHP's
  type check on the contravariant parameter is strict.
- **Recommendation for PR3 (RBAC)**: the `AuthController@me` endpoint
  returns empty `roles`, `permissions`, `projects` arrays. PR3 must
  populate them from Spatie's `HasRoles` trait + the `project_memberships`
  table. Flip the controller's response to pull from
  `$user->allowedProjects()` and `$user->getAllPermissions()` once those
  methods exist on the User model.
- **Recommendation for PR4 (Frontend)**: the React SPA MUST call
  `GET /sanctum/csrf-cookie` once at app bootstrap before any POST to
  `/api/auth/*`. Axios: `axios.create({ baseURL: '/', withCredentials: true })`
  — Axios auto-reads the `XSRF-TOKEN` cookie and forwards it as the
  `X-XSRF-TOKEN` header. Without either step the POST returns 419 CSRF
  token mismatch.

---

## PR4 — Phase D (general-purpose agent, 2026-04-22)

- **React 18.3.1 (not 19) is the right baseline today.** React 19 stable
  is on npm, but the design reference bundle pins 18.3.1 via CDN and
  TanStack Router/Query's peerDeps still list `react@^18` at the time of
  this PR (`@tanstack/react-router@1.168.23`, `@tanstack/react-query@5.x`).
  Landing on 19 now means chasing peer-warnings and a future ReactDOM
  API rewrite in the chat port. PR5 can re-evaluate; for now 18.3.1
  keeps the dependency graph clean.
- **Root-level `vite.config.ts` is mandatory for `laravel-vite-plugin`.**
  The plugin resolves `public/build/` relative to the Laravel project
  root, so the config must live at repo root, not inside `frontend/`.
  The `frontend/` directory is purely a logical source folder —
  `package.json`, `vite.config.ts`, `tailwind.config.ts`, `postcss.config.js`,
  and `vitest.config.ts` all sit at root. Input points at
  `frontend/src/main.tsx`.
- **Two vitest configs coexist.** The legacy `vitest.config.mjs` runs
  the existing `tests/js/*.spec.mjs` suite (Node env, MJS modules,
  `resources/js/rich-content.mjs`). The new `vitest.config.ts` runs
  React specs (jsdom env, TSX). Exposed via `npm run test` (new) and
  `npm run test:legacy` (old) — CI runs both via `npm run test:all`.
  Do NOT remove the legacy one; PR5 migrates rich-content to TS.
- **CSS `@import` must precede `@tailwind` directives.** Vite's PostCSS
  pipeline warns "@import must precede all other statements" if the
  order is wrong, then silently drops the import in production. The
  fix lives in `frontend/src/styles/globals.css` — tokens first, then
  the three Tailwind directives. Cost me one build iteration.
- **Project-referenced `tsconfig.node.json` needs `rootDir: ".."` when
  it includes files from outside `frontend/`.** Otherwise `tsc -b`
  aborts with `TS6059: File ... is not under 'rootDir'`. Also set
  `noEmit: false` + `emitDeclarationOnly: true` so the referenced
  project complies with `TS6310: Referenced project ... may not
  disable emit`. The emitted `.d.ts` files are ignored via
  `frontend/.gitignore` and the root `.gitignore`.
- **`withoutVite()` is the right neutraliser in feature tests.**
  Testbench has no `public/build/manifest.json`, so `@vite` blows up
  with "Vite manifest not found". `$this->withoutVite()` in `setUp()`
  replaces the directive with a no-op and lets `view('app')` render
  the HTML shell unchanged.
- **TestCase already wires `view.paths` — don't re-check existence.**
  The first version of `SpaControllerTest` called `File::exists(base_path('resources/views/app.blade.php'))`
  which fails because Testbench's `base_path()` doesn't point at the
  project root. Since `TestCase::getEnvironmentSetUp` sets
  `view.paths` to `__DIR__.'/../resources/views'`, `view('app')` Just
  Works — no need for a filesystem precheck.
- **Axios + Sanctum: `withCredentials: true` + `X-Requested-With`
  header.** With both set, axios auto-forwards the `XSRF-TOKEN` cookie
  as `X-XSRF-TOKEN` and Laravel's `EnsureFrontendRequestsAreStateful`
  recognises the call as an SPA request. Without the
  `X-Requested-With` header Sanctum may fall through to the bearer-
  token code path. `ensureCsrfCookie()` runs once per app mount; a
  `resetCsrf()` helper forces re-prime after logout or a 419.
- **TanStack Router v1 code-based config: module declaration
  augmentation is mandatory.** Without
  `declare module '@tanstack/react-router' { interface Register { router: typeof router; } }`
  every `useNavigate()` / `useSearch()` call is typed as `unknown` and
  you lose autocomplete. Put it at the bottom of `routes/index.tsx`
  right after `createRouter()`.
- **`validateSearch` with zod catches malformed reset links early.**
  `?token=&email=` is required by the reset flow; with
  `z.object({ token: z.string().default(''), email: z.string().default('') })`
  the search schema is typed AND sanitised — missing params surface
  as empty strings that the page then shows as "Invalid reset link".
- **Storybook-style seed in `frontend/src/lib/seed.ts`.** Dev-only data
  typed as `Project` / `SeedUser` — the Sidebar/Topbar/ProjectSwitcher
  consume it directly for now; PR5-I wire real `/api/admin/*` calls.
  Keep this file minimal (no API pretending) and clearly labelled.
- **Inline `style={{...}}` + `var(--token)` is the primary styling
  path.** Not Tailwind utilities. The design bundle expresses every
  layout as inline styles; converting them to utility classes would
  mean maintaining a Tailwind-vs-tokens mapping that drifts. Tailwind
  sits alongside as the escape hatch for things the design doesn't
  already express (rare).
- **Raccomandazione per PR5 (Chat React):**
  - Move `resources/js/rich-content.mjs` to `frontend/src/lib/rich-content.ts`
    AND keep `tests/js/rich-content.spec.mjs` running via legacy config
    until the TS test covers the same surface. Don't delete the MJS
    file until the TS version is at feature parity.
  - Replace the `~~~chart` regex block with real recharts components —
    cleaner than the current canvas + Chart.js call, and the design
    bundle's DIY SVG charts already prove the visual target.
  - Wire `/api/kb/resolve-wikilink?project=&slug=` for the chat
    wikilink hover card; plan listed it as a backend endpoint to add
    in this PR.
  - Replace the seed `PROJECTS` with the real store's `projects` list
    in Sidebar/Topbar. The auth store already carries it; AppShell
    currently falls back to seed when the store is empty — that path
    can go away once the full admin wiring lands.

---

## PR3 — Phase C (general-purpose agent, 2026-04-23)

- **Spatie `^6.25` is the Laravel 13 sweet spot.** Version `7.x` dropped
  support for Laravel 11 and older; `6.25` spans `^8.12|^9|^10|^11|^12|^13`
  and still exposes the exact API we need (`HasRoles`, `Role::findByName`,
  `findOrCreate`, `syncPermissions`, `PermissionRegistrar::forgetCachedPermissions`).
  Pin with `^6.25` so patch updates stay available.
- **No package discovery in this repo.** `bootstrap/providers.php` lists
  providers explicitly (same reason TestCase registers Sanctum manually).
  Consequence: `php artisan vendor:publish --provider=Spatie\\...\\Permission\\PermissionServiceProvider`
  prints "No publishable resources" because the provider never booted.
  **Workaround:** copy `vendor/spatie/laravel-permission/config/permission.php`
  into `config/permission.php` and `vendor/.../database/migrations/create_permission_tables.php.stub`
  into `database/migrations/2026_04_23_000000_create_permission_tables.php`
  by hand. Also mirror the migration under `tests/database/migrations/`.
- **`tests/TestCase.php` needs the Spatie provider AND the config**: register
  `PermissionServiceProvider` alongside Sanctum and also
  `$app['config']->set('permission', require config/permission.php)` — the
  PR2 trick of rewriting the `auth` config after providers boot applies
  equally to `permission` (otherwise Spatie reads default array keys
  that don't exist in the overwritten config and throws "Target class
  [Spatie\\Permission\\Models\\Permission] does not exist"-style errors
  during role resolution).
- **`Database\\Seeders\\` namespace needs explicit PSR-4 registration.**
  Fresh Laravel 13 skeletons ship with `database/seeders/DatabaseSeeder.php`
  and an implicit namespace — this repo never had seeders. Added
  `"Database\\Seeders\\": "database/seeders/"` to `composer.json`'s
  autoload map and ran `composer dump-autoload`. Without that,
  `$this->seed(RbacSeeder::class)` in tests throws "Class not found".
- **`Spatie HasRoles` composes cleanly with `Authenticatable + HasApiTokens + Notifiable`.** The trait order doesn't matter, but if you forget
  to call `PermissionRegistrar::forgetCachedPermissions()` after a seeder
  that runs inside the same request lifecycle, the second test in the
  same file sees the previous run's cached permission list and role
  resolution silently returns `false` on `->can('kb.read.any')`. The
  RbacSeeder explicitly flushes the cache at the end of `run()` to avoid
  this flake.
- **Global scope compose order matters.** `SoftDeletes` trait registers
  its own scope in `booted()`. Calling `parent::booted()` in the
  override is NOT needed when `KnowledgeDocument` doesn't extend any
  class that defines a custom `booted()` — the SoftDeletes trait
  registers on its own via `static::bootSoftDeletes()`. Adding the
  `AccessScopeScope` via `static::addGlobalScope(new AccessScopeScope)`
  in the new `booted()` is enough; both scopes compose in the WHERE
  clause at query time.
- **`AccessScopeScope` must `$builder->whereRaw('1=0')` when the user has
  zero allowed projects.** Returning without filtering would leak all
  rows; returning `$builder->whereIn('project_key', [])` is ignored by
  some drivers (Laravel emits a no-op IN clause). `whereRaw('1=0')` is
  the explicit, portable "block everything" primitive.
- **Policy registration needs `Gate::policy()` here.** Laravel 13's
  convention auto-discovery only scans discovered providers; with
  explicit registration it's safer to call `Gate::policy(Model::class,
  Policy::class)` in `AppServiceProvider::boot()`. Don't try to call
  `$this->policies = [...]` — that property is only honoured by
  AuthServiceProvider's `registerPolicies()` which this repo doesn't use.
- **Raccomandazione per PR4 (Frontend)**: the React auth store should
  treat `roles`, `permissions` and `projects` as authoritative. The
  dashboard's Project Switcher reads the `projects` list; if empty,
  render "No projects — ask your admin for a membership" rather than
  silently sending every request with `X-Project-Key: default`. The
  `EnsureProjectAccess` middleware is fail-open on missing keys, so a
  missing header is NOT a hard error — use it only to scope UIs.
- **Raccomandazione per future PR di search/retrieval**: the global
  scope is a pure SQL filter. It does NOT apply the scope_allowlist
  folder_globs / tag intersection — that's in `KnowledgeDocumentPolicy::view()`.
  Bulk-listing endpoints that return 50+ rows must either paginate and
  apply the policy in PHP, or push a second SQL pass that joins
  `knowledge_document_tags` when the membership has tag filters.

---

## Template per nuova entry

```
## PR-N — Phase X (agent-name, YYYY-MM-DD)

- Scoperta 1: cosa, perché rilevante, dove vederla
- Trappola evitata: X che sembrerebbe Y ma non è
- Comando/snippet utile: `...`
- File-chiave toccati: `path/to/file.php`
- Raccomandazione per il prossimo PR: ...
```
