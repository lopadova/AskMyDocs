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

## W2 — Weekly digest + stale-review ✅ (implementation complete, in review)

**Branch:** `feature/v8.7-W2-digest-stale-review` → PR (target `feature/v8.7`).

**Delivered (backend-only; the new event type is data-driven into the existing preferences grid, no FE change):**
- **Stale-review:** new `NotificationEvent::EVENT_KB_DOC_STALE_REVIEW` (registered in `eventTypes()` →
  auto-seeds prefs + appears in the grid) + `KbDocStaleReview` event + subject/summary labels +
  `NotificationPublisher::publishKbDocStaleReview()` (same tenant-safe ACL recipient pipeline as
  `KbDocumentChanged`) + `kb:stale-review-sweep` command (time-based, all doc types, `metadata.stale_review_notified_at`
  marker for per-content-version idempotency, `--months`/`--dry-run`/`--limit`, soft-delete + archived excluded).
- **Weekly digest (closes roadmap R6):** `notifications:digest-weekly` command aggregates the week's
  `notification_events` per tenant into a `notification_digests` row (one per `(tenant, week_start_date)`)
  + emails each email-opted-in user their OWN roundup via `WeeklyDigestMail` + `emails/weekly-digest.blade.php`,
  stamping `sent_at` + `recipients_count`.
- Two `TierOneSchedulerRegistrar` slots (`kb_stale_review_sweep` daily 03:55, `notifications_digest_weekly`
  Monday 07:00) + config (`kb_health.stale_review_months` default 6, both schedule slots) + `.env.example` vars.
  Commands registered in `AppServiceProvider::commands()`.

**Tests (all green locally):** `KbStaleReviewSweepCommandTest` (5) + `NotificationsDigestWeeklyCommandTest` (4);
regression: `tests/Feature/Notifications` + `tests/Feature/Console` + `tests/Architecture` = **115 tests / 376 assertions OK**.

## W3–W4 — AI deep-analysis on change ⏳
## W5 — Cloud Time Machine ⏳
## W6 — RC + GA ⏳
