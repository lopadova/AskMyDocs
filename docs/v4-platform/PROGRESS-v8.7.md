# PROGRESS — AskMyDocs v8.7

Running progress log for the v8.7 cycle. One section per Wn.

## W1 — Synonym Expansion ✅ (implementation complete, in review)

**Branch:** `feature/v8.7-W1-synonym-expansion` → PR (target `feature/v8.7`).

**Delivered:**
- Schema: `kb_synonyms` (tenant+project scoped, R30/R31) — prod migration
  `2026_06_02_000001_create_kb_synonyms_table.php` + SQLite mirror
  `tests/database/migrations/0001_01_01_000048_…`.
- Model `App\Models\KbSynonym` (BelongsToTenant, casts synonyms→array). Registered in
  `TenantIdMandatoryTest` (R31).
- `App\Services\Kb\Retrieval\SynonymExpander` — bidirectional, group-based, per-(tenant,project),
  cached, no-op when disabled / no groups. `expandQueryText()` (embedding) + `expansionPhrases()` (FTS).
- `KbSearchService` wired: embeds synonym-expanded text (both `search()` + `searchWithContext()`),
  and OR-expands the FTS `tsquery` via `buildFtsTsquery()` (pgsql; collapses to the legacy single
  `plainto_tsquery` with no synonyms).
- Admin CRUD `SynonymController` + `apiResource kb/synonyms` (role:admin|super-admin). Added to
  `AdminAuthorizationMatrixTest` (R32).
- Config `kb.synonyms.{enabled,cache_ttl_seconds}` (default ON).
- FE: `synonyms.api.ts` (+ `parseSynonyms`), `SynonymsList.tsx`, `SynonymFormDialog.tsx`, route
  `/app/admin/kb/synonyms` + `AdminShell` rail entry `Synonyms` + `AdminSection` type.

**Tests (all green locally):**
- PHPUnit: `SynonymExpanderTest` (12) · `SynonymExpansionRetrievalTest` (2, Mockery embed-arg proof) ·
  `SynonymControllerTest` (15) · `TenantIdMandatoryTest` · `AdminAuthorizationMatrixTest`. Regression:
  full `tests/Feature/Kb` + `tests/Unit/Kb` + `TagControllerTest` = **370 tests / 1006 assertions OK**.
- Vitest: `SynonymsList.test.tsx` + `parseSynonyms` = 13 green (incl. list-query-error + delete-error paths). `tsc -b` clean.
- Playwright: `admin-synonyms.spec.ts` — lands + ARIA + full create→edit→delete round-trip + 422 duplicate.

## W2 — Weekly digest + stale-review ⏳
## W3–W4 — AI deep-analysis on change ⏳
## W5 — Cloud Time Machine ⏳
## W6 — RC + GA ⏳
