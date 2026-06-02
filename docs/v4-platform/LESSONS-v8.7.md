# LESSONS — AskMyDocs v8.7

Running lessons log. Promote durable items into CLAUDE.md R-rules / `.claude/skills/` at cycle close.

## W1 — Synonym Expansion
- **Test migrations are a separate, mandatory mirror.** `tests/TestCase::loadMigrationsFrom`
  points the SQLite runner at `tests/database/migrations/` ONLY — the production
  `database/migrations/` set is NOT loaded in tests. Every new create-table migration needs a
  byte-equivalent mirror under `tests/database/migrations/` or the table simply does not exist in
  the test DB. (Already implied by R9/docs-match-code but worth an explicit gotcha entry.)
- **Vitest is configured at the REPO ROOT, not under `frontend/`.** Run single files with
  `npx vitest run --config vitest.config.ts frontend/src/.../X.test.tsx` from the repo root; the
  `include` glob is `frontend/src/**` relative to root. Running from `frontend/` finds nothing.
- **`php` is a PowerShell `.bat` shim (php84).** The Bash tool's `/usr/bin/env php` cannot see it;
  run PHPUnit/artisan via the PowerShell tool.
- **Synonym expansion proof targets the wiring, not fake-embedding semantics.** Asserting that
  `EmbeddingCacheService::generate()` receives the *expanded* text (Mockery `with`-matcher) is
  deterministic and driver-independent — a fake embedder gives no meaningful cosine drift, so an
  outcome-based retrieval assertion would be hollow.
- **TWO tenant-model enumerations must stay in sync.** A new model using `BelongsToTenant` must be
  added to BOTH `tests/Architecture/TenantIdMandatoryTest::TENANT_AWARE_MODELS` (R31) AND
  `tests/Architecture/TenantReadScopeTest::TENANT_AWARE_MODELS` (R30 read-scope completeness — it
  globs `app/Models` for the trait and `assertSame`s the sorted list). The second one is easy to
  miss because it lives in a different file and only the FULL suite (not a targeted run) catches it
  in CI. Run BOTH architecture tests, not just the obvious one, before pushing a new model.
- **Query expansion must key on the EFFECTIVE project, not the legacy `$projectKey` arg.** The chat
  path passes `projectKey = null` and scopes via `RetrievalFilters::projectKeys`; keying synonym
  lookup on the raw arg silently no-ops expansion for every filters-based query. Derive the project
  from `filters->projectKeys[0]` when the arg is null.
- **Token matching needs non-alnum word boundaries, not space padding.** `' '.$t.' '` substring
  checks miss punctuation-adjacent tokens (`k8s,` `(k8s)`); use a `(?<![\p{L}\p{N}])…(?![\p{L}\p{N}])`
  regex (preg_quote the member) so internal punctuation in jargon (`gp-2.0`) stays literal.
## W2 — Weekly digest + stale-review
- **Artisan commands are NOT auto-discovered here** — `AppServiceProvider::boot()` has an explicit
  `$this->commands([...])` list. A new command must be added there or `artisan('x')` 422s with
  `CommandNotFoundException` (only surfaces when a test invokes it, not at compile time).
- **`updateOrCreate` on a `date`-cast column re-INSERTs.** The `date` cast stores `Y-m-d 00:00:00`,
  but `updateOrCreate(['col' => 'Y-m-d'])` binds the WHERE as `Y-m-d` → no match → re-INSERT →
  composite-unique violation on re-run. Use `->whereDate('col', $ymd)->first() ?? new Model([...])`
  so the lookup matches regardless of the time component.
- **A new notification event type is mostly free** — register the const + `NotificationEvent::eventTypes()`
  arm and the preferences grid (FE), the per-user pref seeding, and the API all pick it up dynamically
  (R18). Only the `NotificationSubjects` + `NotificationSummaries` `match` arms need a manual line, and
  the `NotificationServiceProvider` Event::listen array needs the new event class.

## W1 — Synonym Expansion (continued)
- **FTS synonym OR-expansion stays injection-safe** by emitting one `plainto_tsquery(?, ?)` per
  phrase joined with the tsquery `||` operator — Postgres owns all lexeme parsing; no user string is
  interpolated. Collapses to the exact legacy single-`plainto_tsquery` query when no synonyms match.
