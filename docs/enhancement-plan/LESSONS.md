# Lessons Learned (pass-through fra agenti)

> Ogni agente aggiunge qui tutto ciò che ha scoperto durante l'implementazione e che
> può risparmiare tempo all'agente successivo. Ordine cronologico (più recente in alto).

---

## PR14 — Phase I (ai-insights agent, 2026-04-24)

- **Pre-computed snapshot vs on-demand is the ONLY viable design
  at LLM cost.** The naive version of "AI insights" is a service
  that accepts a request and fires N LLM calls on the spot — which
  at 10 tag proposals + 1 coverage-gap clustering per page load
  would cost $0.50 minimum per view on a production corpus and
  scale linearly with operator activity. Moving the compute into a
  scheduled `insights:compute` command that writes ONE row per
  calendar day and letting the SPA read that row turns the per-view
  cost into O(1) DB read. The `admin_insights_snapshots` table is
  the entire point of the phase — not a caching optimisation but
  the actual product shape. Future phases that add "real-time
  insights" would need an ADR overriding this boundary and the
  provider-bill math to back it.
- **Partial-failure null-column beats abort-on-any-failure.** Any
  single LLM call in `AiInsightsService` can trip on a provider
  timeout, quota, or 5xx. If the compute command aborted on the
  first failure, one flaky provider would zero the entire day's
  insights — the operator sees nothing. Instead the command wraps
  each function in try/catch at the boundary (`runInsight()`) and
  writes `null` to the affected column; the other 5 columns still
  populate. The migration's `->nullable()` on every payload column
  is load-bearing for this contract — drop it and the first
  timeout takes down the whole row. This is the same pattern
  H2's `CommandRunnerService::run()` uses for the audit row:
  write everything you can, record what you couldn't, never pretend.
- **Compose existing services, don't duplicate the prompt surface.**
  `AiInsightsService` is 700 lines not because its logic is novel
  but because six aggregation queries are intrinsically verbose.
  The LLM calls themselves go through the existing `AiManager` and
  `PromotionSuggestService` — the promotion scoring is the same code
  path the canonical pipeline's `/suggest` endpoint already uses;
  tag proposal is a small one-shot `AiManager::chat()` call. Do NOT
  inline a new provider transport here, do NOT copy the JSON-decode
  prompt stripping from `PromotionSuggestService`, do NOT build a
  second retry layer. Composition keeps the insights service
  dependency-free (two constructor args) and lets future phases
  swap the provider globally without revisiting this module.

---

## PR13 — Phase H2 (maintenance-panel agent, 2026-04-24)

- **Audit-before-execute is the invariant, not the optimisation.**
  `CommandRunnerService::run()` INSERTS the `admin_command_audits`
  row with `status='started'` **before** calling `Artisan::call()`.
  If the artisan call crashes (segfault, OOM, PHP fatal), the row
  survives with status=started and exit_code=null — forensically
  visible in the history table. Invert the ordering (insert after
  Artisan returns) and you lose the trail of the one class of
  failures you most want to investigate: the ones that kill the
  worker. The post-Artisan `update()` call can safely flip status
  to `completed` or `failed`; the invariant only requires the row
  to exist first. Every test in `CommandRunnerServiceTest.php`
  asserts the audit row exists even on exception paths — this is
  why.
- **Whitelist + signed confirm_token + args_hash are three
  INDEPENDENT gates, not a defence-in-depth joke.** Any one of
  them alone would be insufficient for an RCE-adjacent surface
  like arbitrary Artisan invocation. The whitelist (gate 1)
  ensures the command string is an array key in
  `config('admin.allowed_commands')` — shell metacharacters
  (`&&`, `$()`, etc.) will never match because they are not valid
  array keys. The confirm_token (gate 3) is a 64-char random
  string whose sha256 is stored in `admin_command_nonces`; the
  row carries an `args_hash` which lets `consumeConfirmToken()`
  reject the same token used with different args (bypass attempt).
  The permission gate (gate 4) applies the Spatie check AFTER the
  whitelist so "does this command exist" doesn't leak to a caller
  without permission. Collapsing any of these three into a single
  check would let an attacker who compromises one layer (e.g.
  steals a confirm_token via XSS) escalate all the way to Artisan.
- **Rate limit per-user, not per-IP.** `throttle:10,1` applied to
  the `POST /run` route defaults to per-user scope for authenticated
  routes — this is the right choice for an admin panel because
  the threat model is a rogue admin DoS'ing the worker with
  destructive commands, not an external attacker flooding from
  one IP. Per-IP throttling would paradoxically let the rogue
  admin rotate through VPNs to keep running commands, while
  blocking a legitimate admin+operations team sharing an office
  IP. Keep the docstring on `routes/api.php` explicit about this
  so the next reader doesn't "improve" it to `throttle:10,1,ip`.
- **Super-admin vs admin is a PERMISSION split, not just a role
  label.** The admin role holds `commands.run` (runs
  non-destructive commands like `kb:validate-canonical`). Only
  super-admin holds `commands.destructive` (required for
  `kb:prune-deleted`, `chat-log:prune`, etc.). This means
  destructive commands need their own Playwright project + storage
  state (`chromium-super-admin`) because admin storage state
  simply returns 403 on the preview path — it can't even get a
  confirm_token. Do NOT grant `commands.destructive` to the admin
  role "for convenience" — the entire point of Phase H2's two-tier
  design is that a compromised admin account cannot wipe the
  corpus. The separate DemoSeeder account (`super@demo.local`)
  exists so E2E coverage of the destructive path is possible
  without weakening the production RBAC invariant.

---

## PR12 — Phase H1 (log-viewer agent, 2026-04-24)

- **Reverse-seek via `SplFileObject::seek()` is the right tailer for
  an unbounded log file.** `file_get_contents()` / `file()` would
  load the whole log into memory — a multi-GB `laravel.log` on a
  production box would OOM the worker. `SplFileObject::seek(PHP_INT_MAX)`
  positions the iterator at one index past the last line; `key()`
  returns that index; then we walk backwards line-by-line via a
  decrementing `seek($cursor)` loop, accumulating only the lines
  we need. Note two subtleties: (a) a trailing `\n` makes
  `key()` return one past the last real line, so the first
  iteration returns an empty string which we silently skip;
  (b) `total_scanned` counts every iteration incl. the empty
  tail-sentinel, so test expectations should use
  `assertGreaterThanOrEqual($maxLines)` rather than an exact match.
  Line-number-based seek is `O(n)` in line-count (SplFileObject
  has no line-index cache), but for the 2000-line hard cap this is
  still pleasantly fast on ordinary disks.
- **Filename whitelist as a regex is more defensible than a
  string-comparison allowlist.** The LogTailService accepts only
  `laravel.log` / `laravel-YYYY-MM-DD.log`. A trivial allowlist
  check (`in_array($name, ['laravel.log'])`) would have forced us
  to enumerate every rotated daily file ahead of time; the regex
  `/^laravel(-\d{4}-\d{2}-\d{2})?\.log$/` expresses "today's log
  OR any dated rotation" in one line and blocks every
  path-traversal and null-byte-injection variant as a side effect.
  The test suite seeds a dozen rejection cases (`../`, trailing
  space, embedded `\0`, uppercase) to keep the regex honest —
  do this whenever a user-supplied filename is going to be
  concatenated with `storage_path()`.
- **Spatie laravel-activitylog v5 is the minimum version for
  Laravel 13.** v4.12 still caps at `illuminate/config ^11.0`, so
  composer refuses the install with a "requirements could not be
  resolved" message pointing at the Laravel constraint. v5.0.0
  (released late 2025) is the first release with Laravel 12/13
  support. Also: the package ships migrations in its own
  `database/migrations` folder but does NOT publish them (the
  `hasMigrations()` call auto-discovers them at framework load).
  To get the table into our SQLite test DB we needed to copy the
  stub into `database/migrations` + mirror it in
  `tests/database/migrations/` with the same shape — which has
  the bonus of letting the activity tab's "table missing" branch
  be exercised by `Schema::dropIfExists('activity_log')` in a
  feature test without touching the vendor dir.
- **The R13 verify script false-positives on any comment line
  that contains the literal substring `page.route(`.** I wrote the
  spec's file-header comment as "Failure-path scenarios that
  require a 5xx response use \`page.route(...)\` and …" — the
  scanner sees the substring in a comment and flags it alongside
  the actual failure injection. Rephrase to "request interception"
  (or similar) to pass the strict literal-string check. The
  scanner is deliberately dumb; that's the contract.

---

## PR11 — Phase G4 (kb-graph-pdf agent, 2026-04-24)

- **Strategy pattern + `class_exists()` guard keeps optional PDF
  engines truly optional.** Neither Dompdf nor Browsershot is a
  hard `require` — both sit under composer.json `suggest`, and
  each concrete renderer calls `class_exists('Dompdf\\Dompdf')` /
  `class_exists('Spatie\\Browsershot\\Browsershot')` before
  dereferencing them. When the class is missing we throw the same
  501 `PdfEngineDisabledException` as the `DisabledPdfRenderer`
  default, so operators see a single, actionable error state
  regardless of whether they forgot to flip `ADMIN_PDF_ENGINE` or
  forgot to run `composer require`. `PdfRendererFactory::resolve()`
  uses a `match` with a default arm that returns the disabled
  renderer, so a typo in the env var also lands softly — never a
  500 inside the container bind callback. The lesson: every
  strategy entry point that depends on an optional package must
  guard the class/interface reference AND have a default-safe arm
  in the factory; doing only one leaves a foot-gun.
- **Hand-rolled SVG radial layout vs a graph library.** reactflow
  and sigma both pull >150 KB gzipped for a feature where we show
  ≤ 50 nodes in a fixed circle. 20 lines of trigonometry
  (`angle = 2π·i/n`; `x = cx + r·cos(angle)`; `y = cy + r·sin(angle)`)
  plus a single `<svg>` element is enough. The real payoff is
  testability: every node is a `<g data-testid="kb-graph-node-<uid>">`
  with `data-role` + `data-type` + `data-dangling` baked in, so
  Playwright and Vitest assertions key off deterministic testids
  instead of fragile layout coordinates. Promote to a lib only when
  the graph grows past 50 nodes AND zoom/pan become mandatory — not
  before. The `dangling: true` payload from `kb_nodes` gets
  surfaced as a dashed-stroke circle in the same pass, so the
  operator sees "this wikilink points nowhere canonicalized yet"
  at a glance.
- **Tenant-scoped composite FK on `kb_edges` makes the graph
  endpoint trivially safe.** The controller's `graph()` method
  walks `kb_edges` with a bare `where('project_key', $project)`
  filter — no JOIN, no sub-query gymnastics, no risk of a
  cross-tenant leak. This is because the DB schema already
  enforces `(project_key, from_node_uid) → (project_key,
  node_uid)` via a composite FK, so an edge row in hr-portal
  literally CANNOT reference an engineering node. The tenant-
  scoping test (test_graph_returns_canonical_subgraph_tenant_scoped)
  deliberately seeds a colliding `remote-work` slug in BOTH hr-portal
  and engineering to prove the filter works — and the test proves
  the schema, not the controller. The lesson: when the DB enforces
  an invariant, the application code can lean on it; R10's
  "tenant-scoped composite FK" language is load-bearing, not
  decorative. Reviewers who ask for a sub-query or a JOIN for
  "defence in depth" are asking for code that's measurably slower
  (pgvector hash joins skip partial-index paths) and doesn't
  improve correctness.

---

## PR10 — Phase G3 (kb-editor agent, 2026-04-24)

- **Disk-write-FIRST, audit-AFTER ordering**: the PATCH /raw pipeline
  runs `Storage::put()` BEFORE `KbCanonicalAudit::create()` and BEFORE
  `IngestDocumentJob::dispatch()`. Inverting the order produces a
  "liar audit row": a forensic record that claims the doc was updated
  while the file on disk still holds the previous bytes, and a queued
  ingest job that crashes with `file not found on disk`. R4 is the
  first gate — if `Storage::disk($disk)->put(...)` returns false we
  return 500 immediately with `{message, path, disk}`, with NO audit
  row and NO dispatch. The audit is the compliance record; it must
  reflect what actually happened, not what we attempted. Re-trying
  the save after an operator fixes the disk is the correct recovery
  path; rolling back a ghost audit row is not.
- **CodeMirror 6 minimal bundle**: `@codemirror/state` +
  `@codemirror/view` + `@codemirror/lang-markdown` is all we need
  for a source editor with line numbers, line wrap, and markdown
  highlighting. Dropping `@codemirror/basic-setup` saves ~150 KB
  gzipped because it transitively pulls in `lang-javascript`,
  `lang-html`, `lang-css`, `autocomplete`, `search`, `commands`,
  `lint` etc. that have no role in a markdown-only editor. The
  toolbar buttons (Save / Cancel / Diff) render in plain React so
  we don't pay for a CM toolbar package either. `EditorView.updateListener.of`
  is the only extension point needed to flip `isDirty` — keep the
  buffer in a `useRef` and only publish `setState(isDirty)` when it
  actually changes, so keystrokes don't re-render the React tree
  and reset the CM cursor.
- **Hand-rolled diff panel vs external dep**: a line-by-line
  `max(left.length, right.length)` walk with "equal / changed /
  added / removed" classification is ~20 LOC and is enough for a
  "did I change what I think I changed" audit before Save. A real
  structural diff (LCS / Myers) would pull in `diff` or
  `diff-match-patch` — both >20 KB minified for a single feature.
  The naive approach misaligns when a block shifts by one line,
  but that's a feature for the editor's audit use-case: any
  realignment would hide "I accidentally nuked this paragraph"
  events. Keep it simple until someone actually asks for structural
  diff; then escalate in a separate PR with its own ADR.

---

## PR9 — Phase G2 (kb-detail agent, 2026-04-24)

- **Route-model binding + withTrashed() = admin-group-scoped override**:
  Laravel's implicit `Route::bind('document', ...)` attached to a
  `Model` class uses the default Eloquent scope (SoftDeletes hides
  trashed rows), which is the correct behaviour for user-facing
  endpoints. To let the admin's detail view inspect a soft-deleted
  doc, we register a `Route::bind('document', fn($id) =>
  KnowledgeDocument::withTrashed()->findOrFail($id))` **inside** the
  `role:admin|super-admin` group — it only affects routes declared
  after the `bind()` call within that group. User-side routes
  (`/api/kb/*`, `/api/kb/chat`) keep the default scoped binding
  because they're declared in the outer `auth:sanctum` group. R2
  is preserved precisely because the override is scoped: global
  `Route::bind()` in `app/Providers` would have leaked the trashed
  rows into chat.
- **Frontmatter pill-pack pattern**: extracting YAML scalars into
  small key-value pills ABOVE the rendered Markdown body gives the
  reader a tactile summary (`id`, `type`, `status`, `project`) at a
  glance without forcing them to read the fence. The parser is
  deliberately naive — only column-0 `key: value` lines become pills;
  nested blocks + lists are ignored and surface instead on the Meta
  tab via `frontmatter_json._derived`. The split prevents overloading
  the Preview header when a decision doc has 20-item `supersedes`
  lists, but still lets the user see the canonical stamps without
  tabbing away. A hand-rolled splitter is enough (12 lines) because
  remark-frontmatter already strips the fence from the body — no
  need for a full YAML parser on the client.
- **Audit pagination ordering + survival of hard-deletes**:
  `kb_canonical_audit` has **no FK** to `knowledge_documents` by
  design (CLAUDE.md §4). The history endpoint filters on
  `(project_key, doc_id, slug)`, NOT on `knowledge_document_id`,
  which is the column that would die with the parent row on force
  delete. The controller also ORDER BYs `(created_at desc, id desc)`
  — the `id desc` tiebreaker matters because audits written in the
  same second (e.g. when a bulk rebuild fires in one transaction)
  must still come back in a stable order or the paginator will
  produce out-of-order page seams that make reviewers think the
  trail is corrupt. `->paginate(20)` (R3) naturally carries the
  ordering because it's applied before limit/offset.

---

## PR8 — Phase G1 (kb-tree agent, 2026-04-24)

- **Assoc-then-positional tree walker**: `KbTreeService::build()` first
  writes into a string-keyed associative structure (one entry per
  segment name) during the `chunkById(100)` walk so every new doc
  lands in O(1), then `finaliseTree()` converts to a positional,
  sorted array (folders-first, alphabetical) just before the response
  leaves the controller. If you try to build the positional array
  directly you either end up with O(n) scans for every insertion or
  you drop the `chunkById` walker to a second pass. The two-stage
  shape is what keeps the 150-row memory-safe test green *and*
  deterministic.
- **DB is the source of truth for the tree, not the KB disk**: the
  canonical KB directories in consumer repos are authoritative on
  Git, but the admin-facing tree reads directly from
  `knowledge_documents`. Rationale: the DB is what
  `KbSearchService` + the graph tables query, so the explorer must
  match exactly what the RAG stack sees — including soft-deleted
  rows when `with_trashed=1`. A filesystem walker would drift from
  the retrieval plane the moment a `kb:prune-deleted` run happened
  without re-ingesting.
- **Phase G split into four microphases**: trying to ship
  tree + document detail + editor + graph + PDF in one PR (the
  original Phase G scope) produces an 8k-line patch that nobody
  reviews properly and has too many surfaces changing at once.
  G1 ships browsing; G2 adds detail tabs (Preview / Source / Graph
  placeholder / Meta / History); G3 adds the CodeMirror editor;
  G4 adds the graph viewer + PDF renderer (with the PdfRenderer
  scaffold that's currently in `git stash` — left there by a prior
  agent run, preserved for G4 as a checkpoint). Each microphase has
  its own ≤15-file PR that reviewers can actually hold in their
  heads.

---

## PR7 — Phase F2 (users-roles agent, 2026-04-24)

- **Spatie `$guard_name = 'web'` pin on `User`**: without it,
  Spatie falls back to guard discovery via `config('auth.guards.*.provider')`,
  and once Sanctum's `auth:sanctum` is on the request the effective guard
  becomes `sanctum`, NOT `web`. Role/permission syncs then look up
  roles under the `sanctum` guard (empty set), `syncRoles(['viewer'])`
  silently no-ops, and every "assign role on create" test fails with
  "0 roles assigned" even though `roles.*` validation passes against
  `Rule::exists('roles','name')`. Hard-code `$guard_name = 'web'` on
  the `User` model so every assign/sync/hasRole resolves under the
  web guard regardless of the request's auth driver.
- **`$attributes = ['is_active' => true]` mirrors DB default in
  memory**: the migration sets `default(true)` at the column level
  but an Eloquent `User::create([...])` that omits `is_active` saves
  the row as NULL and then the boolean cast coerces it to `false`
  on re-hydration. Only the `default(true)` attribute on the model
  itself survives `fresh()` / `assertJsonPath('data.is_active', true)`.
  Every soft-delete-aware model with a boolean default should pin
  the same way — otherwise the admin UI renders "inactive" for
  brand-new users and the test suite blames the controller.
- **Soft-delete + restore UX pattern**: the drawer/filter split
  (`with_trashed=1` toggle in the filter bar, `-restore` testid
  action inside the trashed row) lets the admin flip on visibility
  without leaving the list. Key insight: the table row carries a
  `data-trashed="true"` attribute, so the row ALSO renders a muted
  background — the same DOM communicates both states to the human
  and the Playwright selector. Don't render a separate
  "trash bin" view; the single table with a filter toggle keeps
  the mental model flat.
- **Permission matrix two-axis layout**: the `PermissionController`
  exposes a `grouped` map keyed by the dotted-prefix domain
  (`kb`, `users`, `roles`, `commands`, `logs`, `insights`, ...).
  Matching the UI to that shape — one card per domain, each card
  a `toggle-all` button + a flex-wrap of permission chips — lets
  the React testids mirror the backend payload exactly
  (`role-perm-kb-toggle-all`, `role-perm-kb.read.any`). The grouped
  structure is load-bearing: ad-hoc client-side grouping drifts
  the moment a new permission like `commands.run.retry` appears.
- **`scope_allowlist` JSON shape**: two optional arrays
  (`folder_globs: string[]`, `tags: string[]`) modelled as a single
  JSON column. The editor normalises `{ folder_globs: [], tags: [] }`
  down to `null` when both are empty — mirrors
  `MembershipStoreRequest` which accepts `null` as "no restriction".
  Canonical "no restriction" is strictly `null`, not `{}`; persisting
  an empty object round-trips as a "restricted — empty allowlist"
  (deny-all) on rehydration.
- **`playwright.config.ts` testMatch broadening**: the
  chromium-viewer project originally matched the single
  `admin-dashboard-viewer.spec.ts` filename. Every new
  RBAC denial spec required a config touch. Broadening to
  `/.*-viewer\.spec\.ts/` on both `testMatch` and the chromium
  `testIgnore` means PR7 (and PR8+) add a new `*-viewer.spec.ts`
  file and it auto-enrolls into the right project with zero
  config surgery. Suffix-by-convention beats explicit registry.
- **`verify-e2e-real-data.sh` greps for the literal `page.route(`
  token**: prose that quotes `page.route()` inside a
  top-of-file comment is flagged as an offending interception
  even though it isn't a live call. Keep explanatory comments
  using "request interception" phrasing; the script is correctly
  strict and we should not relax it.

---

## PR6 — Phase F1 (admin-dashboard agent, 2026-04-24)

- **Spatie `role:` middleware is NOT auto-registered in Laravel 11+
  bootstrap style.** The `PermissionServiceProvider::boot()` registers
  `Route::macro('role', ...)` but does NOT call `aliasMiddleware('role', ...)`,
  so `Route::middleware('role:admin')` throws `Target class [role] does
  not exist.` at request time. Register the alias explicitly in
  `bootstrap/app.php` (and mirror in `tests/TestCase.php` — Testbench
  does not execute `bootstrap/app.php`). Three aliases to register:
  `role`, `permission`, `role_or_permission`.
- **Recharts code-splitting pattern**: per-chart-card `React.lazy()`
  wrappers that build the full chart tree inside the `lazy()` factory
  (rather than lazy-loading each recharts primitive separately) yields
  one clean chunk per card. Exposing the loaded body as a named export
  + a single `<Suspense>` per card is enough — no need for
  `import('recharts')` boilerplate at every call site. Build confirmed
  the main bundle stayed at 645 kB gz 198 kB while recharts split into
  its own ~400 kB chunk.
- **`Cache::remember` with RefreshDatabase**: tests that exercise the
  cache path (e.g. `test_overview_is_cached_for_30_seconds`) MUST call
  `Cache::flush()` in `setUp()` — the default cache store is `array`
  under Testbench and survives `RefreshDatabase` rollbacks (it's not a
  DB table). Flush it or cross-test leakage will make cache hits look
  like data aggregation bugs.
- **Playwright `viewer-setup` + storage state isolation**: running an
  RBAC denial scenario alongside the admin happy path requires a
  separate `storageState` file — trying to reuse `admin.json` means the
  viewer request carries the admin cookie. Split into two storage
  files (`playwright/.auth/admin.json` + `.auth/viewer.json`) driven
  by two setup projects (`setup` → `chromium`, `viewer-setup` →
  `chromium-viewer`) with matching `testMatch` + `testIgnore` so each
  chromium project runs only its relevant spec file.
- **Seeders for E2E branching**: the three-seeder triad
  (`DemoSeeder` / `EmptyAdminSeeder` / `AdminDegradedSeeder`) lets the
  happy/empty/degraded Playwright scenarios switch datasets via the
  existing `/testing/seed` endpoint with zero ad-hoc model creation in
  the test code. Keep the `TestingController::SEEDER_ALIASES` allowlist
  in lock-step or `/testing/seed` returns 422. Each new seeder must
  also guard against missing roles via `RbacSeeder` fallback.
- **`Cache::remember` key shape**: tuple-style keys
  (`admin.metrics.<kind>.<project-or-all>.<days>`) beat JSON-encoded
  keys — they're readable in `cache:clear` diagnostics and let you
  target a single dimension when pruning. `?? 'all'` for the nullable
  project is load-bearing: two cache slots (`hr-portal` vs global)
  must never collide.

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
