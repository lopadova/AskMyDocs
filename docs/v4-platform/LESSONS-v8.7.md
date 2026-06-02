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
- **FTS synonym OR-expansion stays injection-safe** by emitting one `plainto_tsquery(?, ?)` per
  phrase joined with the tsquery `||` operator — Postgres owns all lexeme parsing; no user string is
  interpolated. Collapses to the exact legacy single-`plainto_tsquery` query when no synonyms match.
