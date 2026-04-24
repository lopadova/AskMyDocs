# Copilot Review Findings — Running Catalogue

> Single source of truth for every Copilot finding across enhancement PRs.
> Agents consume this at PR16 (Final distill) to mint rules / skills / agent
> prompts that make future reviews green-on-first-pass.
>
> **Protocol for future fix PRs**: after addressing a Copilot review, append
> one row per finding to the "Per-PR log" table below with
> `(pr, path, category, pattern, fix-SHA)`. Do NOT close the PR until the
> table is updated — the PR16 distillation depends on this being complete.

---

## How to use this file

1. During each Copilot-fix cycle the author (agent or human):
   - Runs `gh api repos/lopadova/AskMyDocs/pulls/<N>/comments --paginate` to
     harvest the raw findings.
   - Writes each finding into the table below with its **category tag** (see
     taxonomy). Tag is what drives the final distillation.
   - Records the fix commit SHA so the pattern→fix mapping is auditable.

2. PR16 (Final distill) MUST:
   - Re-harvest all Copilot comments across every PR (never trust the table
     alone — regression check the table against the live GitHub API).
   - Group findings by category tag.
   - For every tag with ≥ 3 occurrences across distinct PRs: mint a new
     numbered rule (R14+) in CLAUDE.md + a dedicated skill in
     `.claude/skills/<tag>/SKILL.md`.
   - For every tag with 1–2 occurrences: decide whether the pattern is
     load-bearing enough to skill (if yes, skill it; if no, document as an
     anecdote in LESSONS.md).
   - Verify that every rule is enforced via script, test, or both (R13's
     `verify-e2e-real-data.sh` is the template).

---

## Taxonomy — category tags

| Tag | Meaning | Example |
|---|---|---|
| `a11y` | Missing label/aria, role on wrong element, display:none on input | PR #24 TreeView search input no aria-label |
| `silent-200` | Endpoint returns 200 with empty/invalid body instead of 4xx/5xx | PR #25 printable() 200 on missing file |
| `r1-path` | KbPath::normalize() missing on disk write | PR #25 DemoSeeder raw concat |
| `r2-softdelete` | Query drifts because soft-delete scope missing | PR #22 total_chunks not joining knowledge_documents |
| `r3-bulk` | Bulk op without chunkById / paginate / OFFSET cap | PR #24 chunkById + orderBy conflict |
| `r4-silent` | Storage::put/delete return value ignored | PR #25 writeMarkdownForDoc no check |
| `r10-audit` | Audit row missing / stamped with wrong identifier | PR #26 audit on pre-edit doc_id |
| `r10-scope` | Raw WHERE on canonical columns instead of dedicated scope | PR #24 MODE_RAW inline where |
| `r11-testid` | Testid missing on actionable element, state surfaces on wrong branch | PR #25 DocumentDetail data-state only on ready |
| `r12-failure-path` | Test named "failure" but assertion doesn't prove the failure | PR #27 "empty" test asserting ready |
| `r13-real-data` | E2E stubs an internal route on the happy path | PR #21 ingest payload shape drift |
| `doc-drift` | Docblock / comment / PROGRESS.md drifts from code | PR #23 migration filename, PR #26 tab comment |
| `env-config-drift` | New env/config key not mirrored in .env.example / README | PR #17 CORS + Sanctum stateful |
| `injection-attack` | Input escaping incomplete (LIKE, regex, shell) | PR #23 _ in LIKE |
| `regex-literal` | Pattern is literal substring but grepped with -Eq (unescaped `.`) | PR #21 external patterns as regex |
| `route-model-binding` | Implicit binding missing withTrashed() / validation | PR #19 reset-password shape |
| `csrf-priming` | Stateful request without prior /sanctum/csrf-cookie | PR #19 logout() |
| `render-stale` | React cached state out of sync with latest props (needs dispatch) | PR #26 SourceTab CM EditorView |
| `hardcoded-subset` | UI lists a static subset of DB-derived values | PR #24 project filter hr-portal/engineering |
| `test-ordering-assumption` | Test passes under reversed order because assertion is weak | PR #25 history created_at order |
| `test-no-coverage` | Test name claims coverage the body doesn't exercise | PR #26 Save/Cancel enable test |

---

## Per-PR log

> One row per finding. Keep chronological order. When a fix commit lands,
> replace `(pending)` with the SHA.

### PR #16 — Phase A (Storage & Scheduler)

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `design-reference/README.md` | `doc-drift` | README references paths that don't exist in this repo | `0cc1dbc` |
| `PruneOrphanFilesCommand` | `r8-path-prefix` | `allFiles()` scans entire disk ignoring KB_PATH_PREFIX | `0cc1dbc` |
| `PruneOrphanFilesCommand` | `env-config-drift` | Windows `KB_PATH_PREFIX=kb\\proj` not normalized before comparison | `0cc1dbc` |
| `PruneOrphanFilesCommandTest` | `test-no-coverage` | Coverage doesn't exercise non-empty prefix | `0cc1dbc` |

### PR #17 — Phase B (Auth JSON API + Sanctum SPA)

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `LoginRequest` | `doc-drift` | throttleKey() docblock says "hashing email" but only lower-cases | (merged) |
| `routes/api.php` | `route-middleware` | `throttle:login` rate-limits successes + mismatches controller RateLimiter::clear | (merged) |
| `config/cors.php` | `env-config-drift` | Raw explode() on CORS_ALLOWED_ORIGINS; whitespace rounds into stateful list | (merged) |
| `config/sanctum.php` | `env-config-drift` | Raw explode() on SANCTUM_STATEFUL_DOMAINS | (merged) |
| `config/sanctum.php` | `doc-drift` | Unused import `Laravel\Sanctum\Sanctum` | (merged) |

### PR #18 — Phase C (RBAC foundation)

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `User::matchesAnyGlob()` | `injection-attack` | fnmatch() without FNM_PATHNAME; `*` matches `/` | (merged) |
| `AccessScopeScope` | `r10-scope` | ACL denies only checked for subject_type=user, not role/team | (merged) |
| `create_knowledge_document_acl_table` | `r3-bulk` | Composite index omits `permission` column used in lookup | (merged) |
| `RbacSeeder` | `r10-scope` | Viewer granted kb.read.any — effectively global bypass | (merged) |
| `RbacSeeder` | `hardcoded-subset` | backfillUser() grants ALL users access to ALL projects | (merged) |
| `User::hasDocumentAccess` | `doc-drift` | Permission name built as kb.{view}.any but policy passes `view` | (merged) |

### PR #19 — Phase D (Frontend scaffold + auth pages)

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `Tooltip.tsx` | `a11y` | Tooltip only mouse-triggered, no focus/blur | (merged) |
| `AreaChart.tsx` | `silent-200` | `Math.max(...[])` → -Infinity → NaN SVG | (merged) |
| `BarStack.tsx` | `silent-200` | Same empty-array Math.max | (merged) |
| `routes/web.php` | `route-model-binding` | SPA routes only under /app/* but React defines `/` | (merged) |
| `routes/index.tsx` | `route-model-binding` | reset-password shape `?token=` vs Laravel `/{token}` | (merged) |
| `auth.api.ts` | `csrf-priming` | logout() POST without priming CSRF cookie | (merged) |
| `lib/api.ts` | `doc-drift` | ensureCsrfCookie() docstring claims bootstrap but never called | (merged) |

### PR #20 — Phase E (Chat UI React)

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `e2e/helpers.ts` | `r13-real-data` | Locator.evaluate wrong arity | `ae19b2e` |
| `.github/workflows/tests.yml` | `env-config-drift` | Playwright job no webServer → unreachable baseURL | `ae19b2e` |
| `playwright.config.ts` | `env-config-drift` | No webServer block | `ae19b2e` |
| `WikilinkHover` | `silent-200` | Catch-all returns null — 500 becomes success | `ae19b2e` |
| `use-chat-mutation` | `render-stale` | Filter `m.id > 0` drops optimistic before refetch | `ae19b2e` |
| `ChatView` | `render-stale` | `NaN !== activeId` triggers render loop | `ae19b2e` |
| `MessageBubble` | `test-no-coverage` | `undefined ? undefined : undefined` dead code | `ae19b2e` |
| `Composer.test.tsx` | `doc-drift` | `React.ReactElement` without import | `ae19b2e` |
| `package.json` | `env-config-drift` | remark-parse transitive dependency | `ae19b2e` |
| `TestingControllerTest` | `test-ordering-assumption` | app()->detectEnvironment() not restored | `ae19b2e` |
| `routes/index.tsx` | `route-model-binding` | `$conversationId` nested under parent without Outlet | `ae19b2e` |
| `chat.spec.ts` | `r13-real-data` | Happy path hits OpenRouter without stub | `ae19b2e` |

### PR #21 — E2E rigor (skill + script + CI gate)

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `playwright-e2e/SKILL.md` | `doc-drift` | Layout references files not in repo | `369ea1c` |
| `playwright-e2e/SKILL.md` | `doc-drift` | config snippet = multi-role template not current shape | `369ea1c` |
| `verify-e2e-real-data.sh` | `r13-real-data` | Only greps `page.route`, misses `context.route` | `369ea1c` |
| `CLAUDE.md` | `doc-drift` | R13 lists SES but script allowlist doesn't | `369ea1c` |
| `verify-e2e-real-data.sh` | `regex-literal` | EXTERNAL_PATTERNS with grep -Eq treats `.` as regex | `369ea1c` |
| `verify-e2e-real-data.sh` | `silent-200` | Missing target path → exit 0 (silent bypass) | `369ea1c` |

### PR #22 — Phase F1 (Admin shell + Dashboard)

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `DashboardMetricsController` | `hardcoded-subset` | topProjects() hardcoded 7-day, cache key drift | `7b04a8d` |
| `HealthCheckService` | `silent-200` | kbDiskOk() hits S3 on remote driver every 15s | `7b04a8d` |
| `DashboardView` | `r11-testid` | data-state rollup falls back to kpiState | `7b04a8d` |
| `AdminMetricsService` | `r2-softdelete` | total_chunks counts soft-deleted chunks | `7b04a8d` |
| `AdminMetricsService` | `r2-softdelete` | storage_used_mb sums soft-deleted chunks | `7b04a8d` |

### PR #23 — Phase F2 (Users & Roles)

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `PROGRESS.md` | `doc-drift` | Wrong migration filename | `11d63b2` |
| `UserStoreRequest` | `doc-drift` | Docblock says live+trashed, rule is live-only | `11d63b2` |
| `UserController` | `injection-attack` | LIKE escape missing `_` and `\` | `11d63b2` |
| `UserForm` | `a11y` | `<div>` labels without htmlFor + input without id | `11d63b2` |
| `RoleDialog` | `a11y` | checkbox `display:none` invisible to AT | `11d63b2` |

### PR #24 — Phase G1 (KB Tree Explorer)

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `admin-kb.spec.ts` | `r13-real-data` | Ingest payload shape wrong (`project` vs `project_key`) | `be09dd6` |
| `TreeView` | `a11y` | Search input no aria-label, placeholder-only | `be09dd6` |
| `TreeView` | `a11y` | Mode `<select>` no accessible name | `be09dd6` |
| `TreeView` | `a11y` | role=treeitem on li, not on focusable button | `be09dd6` |
| `KbView` | `hardcoded-subset` | Project filter hardcoded hr-portal/engineering | `be09dd6` |
| `PROGRESS.md` | `doc-drift` | Branch parent / PR# mismatch on G1 row | `be09dd6` |
| `KbTreeService` | `r3-bulk` | chunkById with custom orderBy = cursor drift | `be09dd6` |
| `KbTreeService` | `r10-scope` | MODE_RAW uses raw where() instead of scope | `be09dd6` |

### PR #25 — Phase G2 (KB Document Detail)

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `KbDocumentController::printable` | `silent-200` | 200 with empty body on missing file | `9203bec` |
| `KbDocumentController::history` | `r10-audit` | `(doc_id OR slug)` merges unrelated audits | `9203bec` |
| `DemoSeeder::writeMarkdownForDoc` | `r1-path` + `r4-silent` | No normalize + put() return ignored | `9203bec` |
| `DocumentDetail` | `r11-testid` | data-state only on ready branch | `9203bec` |
| `KbDocumentController` | `r3-bulk` | COUNT query unconditional; paginate duplicates it | `9203bec` |
| `KbDocumentControllerTest` | `test-ordering-assumption` | Endpoints-only sample, reversed-safe | `9203bec` |

### PR #26 — Phase G3 (KB Source Editor)

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `DocumentDetail.tsx` | `doc-drift` | Block comment "Preview/Meta/History only" but Source added | `0ae82c4` |
| `KbView.tsx` | `doc-drift` | Same comment drift | `0ae82c4` |
| `SourceTab.tsx` | `render-stale` | CM EditorView never updated after raw change | `0ae82c4` |
| `KbDocumentController::updateRaw` | `silent-200` | Frontmatter `---` but parse()=null → skips validation | `0ae82c4` |
| `KbDocumentController::updateRaw` | `r10-audit` | Audit stamped with pre-edit doc_id/slug | `0ae82c4` |
| `SourceTab.test.tsx` | `test-no-coverage` | "enables Save/Cancel" never simulates edit | `0ae82c4` |
| `SourceTab.test.tsx` | `test-no-coverage` | 422 error test never clicks Save | `0ae82c4` |

### PR #27 — Phase G4 (KB Graph + PDF)

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `admin-kb-graph.spec.ts` | `r12-failure-path` | Test named "empty" asserts "ready" | `d079942` |
| `DompdfPdfRenderer` | `silent-200` | Non-string output → empty string fallback | `d079942` |
| `BrowsershotPdfRenderer` | `silent-200` | Same pattern | `d079942` |
| `KbDocumentController::graph` | `r3-bulk` | `whereIn(...)->limit(cap)` unordered — can drop seed | `d079942` |
| `KbDocumentController::exportPdf` | `hardcoded-subset` | `basename($path, '.md')` misses `.markdown` | `d079942` |

### PR #28 — Phase H1 (Log Viewer)

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `LogTailService::readTail` | `silent-200` | `key() === 0` drops single-line file; use `filesize()` | `39719b9` |
| `FailedJobsTab` | `r11-testid` | Pagination prev/next lack testids | `39719b9` |
| `admin-logs.spec.ts` | `test-ordering-assumption` | `waitForTimeout(500)` flaky; use `waitForResponse` | `39719b9` |
| `LogTailServiceTest` | `r7-silence` | `@unlink()` hides failure (R7 ban on @-silenced calls) | `39719b9` |
| `LogViewerControllerTest` | `r7-silence` | `@mkdir/@unlink` hides filesystem errors | `39719b9` |
| `ApplicationLogTab` | `doc-drift` | `?live=1` toggle reads URL but never writes it on flip | `39719b9` |
| `LogViewerController::application` | `silent-200` | 404/500 chosen by message-prefix sniffing; brittle | `39719b9` |
| `ActivityLogResource::jsonishValue` | `silent-200` | Returns raw JSON string when DB::table bypasses Eloquent casts | `39719b9` |
| `AuditTab.tsx` rows.map | `render-stale` | `<>` fragment can't carry key; React warning + reconcile drift | `39719b9` |
| `FailedJobsTab.tsx` rows.map | `render-stale` | Same React fragment key issue | `39719b9` |
| `FailedJobsTab` | `r11-testid` | `data-state="not-installed"` outside `{idle,loading,ready,empty,error}` contract | `39719b9` |
| `ActivityTab` | `r11-testid` | Same `not-installed` state contract drift | `39719b9` |
| `AuditTab` | `r11-testid` | Pagination prev/next lack testids | `39719b9` |
| `ActivityTab` | `r11-testid` | Same pagination testid gap | `39719b9` |

**New category surfaced this PR**: `r7-silence` — `@`-silenced filesystem calls. Already covered by CLAUDE.md R7 but hadn't appeared as a standalone tag in the taxonomy until H1 tests. Not a new rule, just a tag the taxonomy table was missing.

### PR #29 — Phase H2 (Maintenance Panel)

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `CommandRunnerService::invokeArtisan` | `route-model-binding` | `--` prepended to every arg key; positional args never populate (kb:delete {path}, queue:retry {id}, kb:ingest-folder {path?}) | `59d95bc` |
| `MaintenanceCommandController::schedulerStatus` | `doc-drift` | Returned `admin-audit:prune --days=365` but scheduler runs bare `admin-audit:prune` | `59d95bc` |
| `config/admin.php` command_runner docblock | `doc-drift` | Said tokens are `Crypt::encryptString + sha256 nonce` + TTL=0 disables; real impl is plain random + TTL=0 ≠ disable | `59d95bc` |
| `CommandHistoryTable` rows.map | `render-stale` | `<>` fragment can't carry key; key-on-inner-<tr> is a React reconciliation bug | `59d95bc` |
| `LESSONS.md` | `doc-drift` | Table name `admin_command_audits` (plural) vs real `admin_command_audit` (singular) | `59d95bc` |
| `migration docblock` | `doc-drift` | Said `token_hash` is sha256 of encrypted signed body; real impl is sha256 of raw random | `59d95bc` |
| `PROGRESS.md` | `doc-drift` | Migration filenames without timestamp prefix, don't match the real files on disk | `59d95bc` |
| `CommandRunnerService::consumeConfirmToken` | **`security`** (NEW) | `lockForUpdate()` inside transaction, `used_at` update OUTSIDE → race window where 2 concurrent /run succeed with same token, breaking single-use guarantee | `59d95bc` |

**New category surfaced this PR**: **`security`** — concurrency or crypto invariant violations that turn into RCE / single-use bypasses. Highest priority tag. Any future finding tagged `security` MUST land in a distilled skill + dedicated rule at PR16 (not a "nice to have" anecdote). The concurrent-consume bug here is a textbook instance — documented at length in the fix commit message (`59d95bc`) so the pattern is searchable.

### PR #30 — Phase I (AI Insights)

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `AiInsightsService` constructor | `doc-drift` | PromotionSuggestService injected but never called — dead dep | `bd40780` |
| `AiInsightsService::suggestTagsBatch` | `hardcoded-subset` | Picks first N canonical docs without "missing tags" SQL filter → wasteful LLM calls | `bd40780` |
| `AiInsightsService::qualityReport` | `r3-bulk` **(CRITICAL)** | `GROUP BY LENGTH(chunk_text)` produces up to N groups; bucket/outlier logic in PHP | `bd40780` |
| `AdminInsightsController::byDate` | `silent-200` | `Carbon::parse` permissive (accepts 2026-02-30); 422 vs docstring's 404 | `bd40780` |
| `PROGRESS.md` row 14 | `doc-drift` | "4 new Playwright scenarios" but enumerates 6 | `bd40780` |
| `PromotionSuggestionsCard.test.tsx` | `test-ordering-assumption` | `Object.defineProperty(window, 'location')` not restored in afterEach — cross-suite pollution | `bd40780` |
| `MetaTab.tsx` AiSuggestionsBlock | `doc-drift` | Block comment says "render nothing while loading" but impl renders explicit loading UI | `bd40780` |
| `admin_insights_snapshots` test migration | `doc-drift` | Redundant explicit index on `snapshot_date` (unique already creates one) | `bd40780` |
| `AiInsightsService::detectOrphans` | `r3-bulk` **(CRITICAL)** | Per-doc `chunks()->count()` + `KbEdge::exists()` = N+1 (thousands of queries on 10k-doc corpus) | `bd40780` |
| `admin_insights_snapshots` prod migration | `doc-drift` | Same redundant index | `bd40780` |

---

## Category frequency snapshot (PR #16 → PR #29)

| Tag | Count | Enough to skill? |
|---|---|---|
| `doc-drift` | 18 | **YES** — already a skill (`docs-match-code`) but needs expansion to include comment-drift + PROGRESS.md + migration filename drift + docblock/implementation drift |
| `silent-200` | 11 | **YES** — new skill `surface-failures-loudly` |
| `r13-real-data` | 7 | covered by `playwright-e2e` skill |
| `a11y` | 7 | **YES** — new skill `frontend-a11y-checklist` |
| `r10-scope` / `r10-audit` | 6 | covered by `canonical-awareness` skill, but add audit-identifier-on-edit rule |
| `env-config-drift` | 6 | covered by `docs-match-code` skill; strengthen env parsing pattern |
| `render-stale` | 5 | **YES** — new skill `react-effect-sync-cached-state` (emphasise Fragment-key pattern — caught THREE times PR26/PR28/PR29) |
| `r7-silence` | 2 | covered by existing skill (R7) / CLAUDE.md rule — taxonomy tag added in PR28 for consistency |
| `r11-testid` | 6 | covered by `frontend-testid-conventions` skill; emphasise `data-state` value contract |
| `r3-bulk` | 4 | covered by `memory-safe-bulk-ops` skill; add chunkById+orderBy gotcha |
| `injection-attack` | 3 | **YES** — new skill `input-escape-complete` (LIKE + fnmatch + regex) |
| `hardcoded-subset` | 5 | **YES** — new skill `derive-from-db-not-literal` |
| `test-no-coverage` / `test-ordering-assumption` / `r12-failure-path` | 7 | **YES** — new skill `test-actually-tests-what-it-claims` |
| `r1-path` / `r4-silent` / `r2-softdelete` | 6 | covered by existing skills (R1/R2/R4) |
| `regex-literal` | 1 | anecdote in LESSONS |
| `route-model-binding` | 4 | **YES** — new skill `route-contracts-match-fe-shape` (also covers positional-vs-option Artisan invocation) |
| `csrf-priming` | 1 | anecdote |
| **`security` (NEW)** | 1 | **YES — HIGHEST PRIORITY** — mint a dedicated skill + rule `concurrent-invariants-hold-the-lock` even on a single occurrence: concurrency / crypto invariant bugs turn into RCE or single-use-bypass; never demote to anecdote |

---

## Appendix — protocol hooks

- Every `fix(enh-*): address Copilot review on PR #N` commit MUST touch this
  file in the same commit (append row(s), update fix SHA). The commit
  message should cite "COPILOT-FINDINGS.md updated" to make the hook
  obvious in retrospect.

- `scripts/verify-copilot-catalogue.sh` (future, ship in PR16): greps git
  log for `fix(enh-*): address Copilot review on PR #N` and cross-checks
  that `COPILOT-FINDINGS.md` has at least one row tagged with that PR
  number. Fails the build on mismatch.

- PR16 agent MUST NOT skip this file. Even if it looks complete, re-harvest
  via `gh api` to catch anything landed after the last human update.
