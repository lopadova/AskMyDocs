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

> Retrospective note — PR15's `gh api repos/<org>/<repo>/pulls/30/comments`
> call returned zero finding-shaped rows at Phase J branch time. The
> PR16 live re-harvest (via the same API) surfaced 10 rows. All 10 were
> fixed on Phase J's branch as `bd40780 fix(enh-i): address Copilot
> review on PR #30 (10 findings incl. 2 perf-critical)` and merged into
> this branch as part of the PR16 rebase-on-upstream pass.

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

### PR #31 — Phase J (Docs + E2E + polish)

Live re-harvest ran against PR #31 (Phase J). Copilot returned zero
finding-shaped rows. This is consistent with Phase J being a docs +
one-spec + manifest PR with no new routes / migrations / controllers.
Table kept empty — future regressions against PR #31 should append
here in normal order.

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|

> Table intentionally empty — PR #31 had zero Copilot findings.
> The verifier in `scripts/verify-copilot-catalogue.sh` skips
> separator-only rows (`|---|...|`), so this header alone satisfies
> the "PR #31 block exists" check without falsely advertising a
> placeholder row. Future regressions against PR #31 should append
> real rows above this note.

### PR #32 — Final distill (R14..R21 + skills + CI gate)

Live re-harvest of PR #32 (the final distill PR) returned **7
finding-shaped rows**. Findings target the meta-deliverables of
PR16 itself: the new CI gate, the new sub-agent doc, and an example
in one of the 8 new skills. None touched runtime code (PR #32 is
docs + scripts + templates).

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `docs/enhancement-plan/COPILOT-FINDINGS.md` | `test-fragility` | `(none)` placeholder row in PR #31 block satisfied verifier without being a real finding row | _this PR_ |
| `.claude/agents/copilot-review-anticipator.md` | `doc-drift` | Doc described a `.claude/hooks/pre-push.sh` that doesn't ship with the repo | _this PR_ |
| `.claude/skills/input-escape-complete/SKILL.md` | `doc-drift` | LIKE-escaping example used a 5-arg `User::where()` signature that doesn't compile | _this PR_ |
| `scripts/verify-copilot-catalogue.sh` | `portability` | `readarray` is bash 4+, missing on macOS default bash 3.2 — script broke for local contributors | _this PR_ |
| `.github/workflows/tests.yml` | `silent-noop` | Default shallow checkout (depth=1) hides individual commits → catalogue gate silently passes in CI | _this PR_ |
| `scripts/verify-copilot-catalogue.sh` | `silent-noop` | Exited 0 when catalogue file was missing → renaming/deleting the catalogue bypassed the gate | _this PR_ |
| `scripts/verify-copilot-catalogue.sh` | `silent-noop` | Full-history scan branch silently became partial-history under shallow clones → no shallow-detection guard | _this PR_ |

**New category surfaced this PR**: **`portability`** — bash builtin
assumed without checking against the lowest-common-denominator shell
contributors actually run (macOS bash 3.2). One occurrence; not a
rule on its own yet.

**Pattern noted across 3 of the 7 findings**: the new CI gate (and
the workflow that invokes it) had three independent ways to silently
become a no-op — missing catalogue file, shallow clone with implicit
full-history scan, and shallow clone in CI hiding the fix commits.
This reinforces R14 (surface failures loudly): a gate that exits 0
on its own absence is worse than no gate at all because the green
checkmark is misleading. Distillation note for any future CI gate
work — fail closed, not open, when the gate's preconditions are
unmet.

### PR #33 — Final integration to main (rollup of Phases A..J + distill)

Live re-harvest of PR #33 (the rollup-to-main PR that brings 16
phases worth of accumulated work into `main`) returned **12
finding-shaped rows** plus one runtime CI failure (missing
`public/index.php`) caught by the new pgvector-enabled Playwright
job. Findings split:
- 6 frontend a11y / SVG-id / empty-data / state-derivation issues
- 2 backend doc-drift / wrong-column-mapping issues
- 1 CI dependency / DB driver issue chain
- 1 missing scaffolding (`public/index.php`) that had been
  un-versioned for the entire enhancement series

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `frontend/src/components/charts/BarStack.tsx` | `render-stale` | `Math.max(...[])` returns `-Infinity` on empty data → NaN/Infinity SVG geometry | _this PR_ |
| `frontend/src/components/charts/AreaChart.tsx` | `render-stale` | Same empty-data issue + `labels.length - 1` → `-1`/`0` divisor for 0/1 labels → NaN x positions | _this PR_ |
| `frontend/src/components/charts/Sparkline.tsx` | `dom-id-collision` | Hard-coded `<linearGradient id="...">` IDs collide on multi-instance pages → wrong gradient resolution | _this PR_ |
| `frontend/src/components/charts/BarStack.tsx` | `dom-id-collision` | Hard-coded `bar-a`/`bar-b` gradient IDs → multi-instance collision | _this PR_ |
| `frontend/src/components/charts/AreaChart.tsx` | `dom-id-collision` | Hard-coded `area-grad`/`area-line` → multi-instance collision | _this PR_ |
| `frontend/src/components/shell/AppShell.tsx` | `derive-from-store` | `projectCount` from `storeProjects` but `Topbar`/switcher rendered seeded `PROJECTS` → count + list out-of-sync; also `projectIndex` not bound-clamped | _this PR_ |
| `frontend/src/components/shell/ProjectSwitcher.tsx` | `a11y` | `role="listbox"` without ArrowUp/Down/Enter/Escape keyboard model → screen-reader trap; switched to ARIA `menu` + `menuitemradio` pattern + Escape close + focus-return | _this PR_ |
| `frontend/src/components/shell/CommandPalette.test.tsx` | `test-flake` | Sync assertion right after `keyboard('{Escape}')` would race React's state-update tick → use `waitForElementToBeRemoved` | _this PR_ |
| `frontend/src/components/shell/ProjectSwitcher.tsx` | `test-coverage` | Non-trivial interaction (open/close/select/escape/outside-click) shipped without a Vitest+RTL test | _this PR_ |
| `app/Http/Resources/Admin/Kb/KbDocumentResource.php` | `route-contracts` | Mapped tags as `['name' => $t->name]` but `KbTag` has `slug`/`label`/`color` (no `name` column) → null on every tag | _this PR_ |
| `app/Http/Controllers/TestingController.php` | `doc-drift` | Docblock said `/testing/reset` "clears cached config" but `runMigrateFresh()` only runs `migrate:fresh` | _this PR_ |
| `composer.json` | `dep-pin-too-tight` | `spatie/laravel-activitylog ^5.0` requires PHP 8.4 — broke PHP 8.3 CI; widened to `^4.12|^5.0` | `42255b4` |
| `.github/workflows/tests.yml` + 2 migrations | `ci-driver-mismatch` | `migrate --force` ran SQLite then later mysql/postgres on same job; pgvector calls only valid on pgsql; cache=database needed missing cache table; `public/index.php` was never committed | `c48fa27` + `f0db3c5` + _this PR_ |

**New categories surfaced this PR**:
- `dom-id-collision` — SVG/HTML elements with hard-coded ID
  attributes that clash when the component renders multiple times.
  React 18+ has `useId()` for exactly this; reach for it on every
  `<linearGradient>` / `<filter>` / `<clipPath>` you author.
- `dep-pin-too-tight` — exact-major package constraint that locked
  out lower PHP versions in CI. `^X.Y` means "≥X.Y, <X+1" — when
  the package's next major bumps the platform requirement, the lower
  bound silently becomes unsatisfiable. Use `^X.Y|^Z.0` patterns
  for libraries with stable APIs across majors.
- `ci-driver-mismatch` — workflow steps disagreed about which DB
  engine was active. Production migrations + pgvector-only
  constructs require pgsql; the historical SQLite-via-config swap
  worked for PHPUnit (which has parallel test migrations) but broke
  for Playwright (which runs production migrations directly).
- `route-contracts` is reinforced — same R20 violation (FE/BE
  shape divergence) that PR16 already minted as a rule.

**Meta-pattern across 5 of the 12 findings**: SVG chart components
shipped without thinking about edge cases (empty arrays, single-element
arrays, multiple instances on the same page). Worth a checklist entry
for any future SVG-based component:
1. What happens when input array is `[]`?
2. What happens when input array length is 1?
3. What happens when 2+ instances render on the same page?
4. Are gradient/filter/clipPath IDs scoped per-instance?

Distillation note for a future PR16-style pass: this likely deserves
a dedicated `svg-chart-component-checklist` skill.

### PR #34 — README enterprise edition v2.0.0 (docs-only)

Documentation-only PR rewriting Key Features / Quick Start / Changelog
to reflect the v2.0 enterprise scope. Live re-harvest of the Copilot
review surfaced **8 doc-drift findings** — every one a R6/R9 (docs/code
coupling + docs match code) violation. Zero code paths touched.

| Path | Category | Pattern | Fix SHA |
|---|---|---|---|
| `README.md` (line 62, Key Features) | `docs-drift` | Claimed React 19 but `package.json` pins `^18.3.1` → reader who diffs deps trusts wrong version | _this PR_ |
| `README.md` (line 74, Admin pages) | `docs-drift` | Called Spatie activitylog "soft dep" but `composer.json` lists it under `require` (not `suggest`); rephrased to "Spatie activity log" | _this PR_ |
| `README.md` (Quick Start "skip seeder" recipe) | `r6-docs-config-drift` | Suggested `KB_DISK_DRIVER=s3/r2/gcs/minio`, but the actual backend selector is `KB_FILESYSTEM_DISK`. `KB_DISK_DRIVER` only configures the built-in `kb` disk's driver/root — see `.env.example` + `config/filesystems.php` | _this PR_ |
| `README.md` (changelog Phase A scheduler) | `docs-drift` | Listed `activity-log:prune` and `notifications:prune` as new scheduler entries; neither exists. The Spatie cron is `activitylog:clean` (currently stubbed as a comment in `bootstrap/app.php`); Laravel 13 doesn't ship `notifications:prune` at all. Replaced with the actual `bootstrap/app.php` schedule list | _this PR_ |
| `README.md` (changelog Phase A heading + 9 other phase headings) | `docs-drift` | PR numbers `#1`–`#15` referenced as enterprise phase PRs, but those are the original v1.x repo PRs (`#1` = ImgBot, `#2` = test scaffolding, etc.). The actual enterprise series is PR #16 → PR #33. Updated every phase heading to the real PR number | _this PR_ |
| `README.md` (changelog Phase C RBAC) | `docs-drift` | Said "+ 13 permissions"; `Database\Seeders\RbacSeeder::PERMISSIONS` defines exactly 12. Listed the full set inline so future drift is greppable against the seeder | _this PR_ |
| `README.md` (changelog Phase D scaffold) | `docs-drift` | Called out "React 19 + Tailwind 3.5" but `package.json` is `react ^18.3.1` + `tailwindcss ^3.4.14`. Aligned both | _this PR_ |
| `README.md` (changelog Phase H1) | `docs-drift` | Repeated the "Spatie activitylog (soft dep)" framing — same fix as the line-74 entry, applied for consistency | _this PR_ |

**Theme**: every finding is a R9 violation (docs out of sync with code).
The PR #34 lesson is operational, not a new rule:

- When the README quotes a column / env var / version / PR number / count,
  diff it against the source (migration / `package.json` / `composer.json` /
  `gh pr list`) BEFORE the commit lands. The pre-existing R9 skill
  already covers this; PR #34 is a reminder that R9 applies to the
  README itself, not just to code-adjacent docs (`CLAUDE.md`,
  `copilot-instructions.md`).

No new categories minted. No new rules. The fix simply tightens the
README to match the v2.0 codebase verbatim.

---

## Category frequency snapshot (PR #16 → PR #31, regenerated at PR16)

Live harvest via `gh api /repos/lopadova/AskMyDocs/pulls/<N>/comments`
for N ∈ [16..31] on 2026-04-24: **110 total finding-shaped rows**
(catalogue prior to this PR logged ~100 — the 10-row delta is the
retrospective PR #30 block above).

Per-PR breakdown: PR #28 = 14 · PR #20 = 12 · PR #30 = 10 (raw) / 11
(catalogued — origin's PR30-fix commit split the migration drift into
prod + test rows) · PR #24 = 8 · PR #29 = 8 · PR #19 = 8 · PR #26 = 7 ·
PR #18 = 7 · PR #25 = 6 · PR #21 = 6 · PR #27 = 5 · PR #23 = 5 · PR #22
= 5 · PR #17 = 5 · PR #16 = 4 · PR #31 = 0.

Per-tag frequency (after the PR #30 re-harvest; rule column reflects
PR16 mint decisions):

| Tag | Count (PR#16→#31) | Rule? | Skill action |
|---|---|---|---|
| `doc-drift` | 22 | R9 (existing) | EXTEND `docs-match-code` — add comment-drift + PROGRESS.md row drift + docblock/impl drift + migration-filename drift |
| `silent-200` | 12 | **R14 (new)** | NEW `surface-failures-loudly` |
| `a11y` | 7 | **R15 (new)** | NEW `frontend-a11y-checklist` |
| `r13-real-data` | 7 | R13 (existing) | EXTEND `playwright-e2e` — ban `waitForTimeout`, cover `context.route` |
| `test-no-coverage` + `test-ordering-assumption` + `r12-failure-path` | 9 | **R16 (new)** | NEW `test-actually-tests-what-it-claims` |
| `r11-testid` | 6 | R11 (existing) | EXTEND `frontend-testid-conventions` — enumerate `data-state` contract |
| `r3-bulk` | 6 | R3 (existing) | EXTEND `memory-safe-bulk-ops` — chunkById+orderBy pitfall + N+1-in-chunk |
| `env-config-drift` | 6 | R6 / R9 | EXTEND `docs-match-code` — CSV env var parsing discipline |
| `render-stale` | 6 | **R17 (new)** | NEW `react-effect-sync-cached-state` — emphasise Fragment-key pattern (caught at PR26/28/29) |
| `r10-scope` + `r10-audit` | 6 | R10 (existing) | covered by `canonical-awareness` — add audit-identifier-on-edit note |
| `r1-path` + `r4-silent` + `r2-softdelete` | 6 | R1/R2/R4 (existing) | covered by existing skills; no change |
| `hardcoded-subset` | 5 | **R18 (new)** | NEW `derive-from-db-not-literal` |
| `route-model-binding` | 4 | **R20 (new)** | NEW `route-contracts-match-fe-shape` (positional-vs-option Artisan + FE↔BE payload shape) |
| `injection-attack` | 3 | **R19 (new)** | NEW `input-escape-complete` (LIKE + fnmatch + regex + whitespace CSV) |
| `r7-silence` | 2 | R7 (existing) | tag added in PR28 for consistency |
| `r8-path-prefix` | 1 | R8 (existing) | covered |
| `route-middleware` | 1 | route-contracts (R20) | merged into R20 skill |
| `regex-literal` | 1 | anecdote | LESSONS only |
| `csrf-priming` | 1 | anecdote | LESSONS only |
| **`security`** | 1 | **R21 (new — HIGHEST)** | NEW `security-invariants-atomic-or-absent` — single-use-consume race in `CommandRunnerService::consumeConfirmToken` (PR #29 `59d95bc`). Security findings never demote to anecdote. |

Count totals 112 vs 110 harvested — two rows have cross-tagged categories
(e.g. PR #25 DemoSeeder `r1-path` + `r4-silent`, PR #17 route-middleware
+ env-config-drift) and are double-counted on purpose so every mint
decision is traceable.

---

## v3.0 (PRs #36–#74)

**Period:** 2026-04-26 → 2026-04-27 (~2 days, ~30 sub-tasks, ~25 PRs).
**Source-of-truth digest:** [docs/v3-platform/LESSONS-v3.0-digest.md](../v3-platform/LESSONS-v3.0-digest.md).

### Recurring categories caught (frequency)

| Category | Count | Rule (new in v3.0) | Skill (new in v3.0) |
|---|---|---|---|
| optimistic-mutation-render-race | 1 | **R25 (new)** | NEW `optimistic-mutation-dedupe` — only caught after PR #72 mitigation revealed the bug; fixed in PR #74 by deduping by id in `useChatMutation.onSuccess` |
| pluggable-pipeline-overlap | 1 | **R23 (new)** | NEW `pluggable-pipeline-registry` — caught by T1.7 mutex test; preempts boot-time silent FQCN/supports() drift |
| refusal-skip-LLM-call | 0 (preempted) | **R26 (new)** | NEW `refusal-not-error-ux` — invariant proved by `shouldNotReceive`, never `Http::assertNothingSent` |
| response-shape-additive | 0 (preempted) | **R27 (new)** | T3.5 explicitly chose `latency_ms_breakdown` sibling rather than sub-objectifying `latency_ms` |
| per-project-taxonomy | 0 (preempted) | **R28 (new)** | T2.10 baked in cascade test on `knowledge_document_tags` pivot |
| per-reason-i18n-fallback | 0 (preempted) | **R24 (new)** | T3.8-BE designed the hierarchy + fallback from day 1 |
| testid-hierarchy | 0 (preempted) | **R29 (new)** | All FE PRs (T2.7, T2.8, T2.9-FE, T2.10, T3.6/T3.7) followed `feature-resource-{id}-{action}` from start |

**Net new lessons learned in v3.0:** L17..L28 (12 numbered) + T1.x..T2.9 date-stamped (~13). Total ~25 lessons → 7 permanent rules + 3 new skills + ~15 project-internal digest entries.

### Notable findings

- **PR #69 orphan recovery (PR #72)**: PR #69 was stacked on PR #68. When GitHub squash-merged #68, the contents of #69 stayed on the dead `feature/v3.0-filters-frontend` branch and never reached `feature/v3.0`. Recovered via cherry-pick of the original commit. Pattern: **when stacking PRs, always re-target the base after the parent merges, OR rebase-onto-master before merge**.

- **`useChatMutation` duplicate render (PR #74)**: PR #72 shipped `.first()` selectors as a Playwright mitigation for a brief duplicate-render. Root-caused in PR #74 — `onSuccess` filtered cache by optimistic id only, not by server-id. When the cache already contained the server-id (refetch race / fixture seed), the merge produced `[A, A]`. Strict-mode locators were the canary; lesson codified as L28 + R25 + skill. Pattern: **when a Playwright spec uses `.first()`, ask whether it's masking a real bug**.

- **Local Playwright infra (PR #72)**: `php artisan serve` fails to bind ports on Windows host (Symfony Process wrapper issue); `php -S 127.0.0.1:8000 -t public` works fine. Locally use `E2E_SKIP_WEBSERVER=1` + `php -S` directly. CI uses `artisan serve` via `playwright.config.ts` webServer block.

### Cycle policy retrospective

- All 25 sub-PRs in v3.0 went cycle-1 clean (Copilot self-cleared without filing comments). The revised cycle policy (T1.4 LESSONS) never had to escalate beyond cycle-1.

- One squash-divergence resolved via merge-from-wave (T3.2 PR #55): when a sub-PR is stacked on a sibling that squash-merges first, the local branch's history references the original commit which no longer exists on origin. Resolution: merge `origin/<wave-branch>` into the sub-PR branch (NOT rebase — force-push is blocked), resolve LESSONS.md conflict by keeping HEAD + cherry-picked content. Pattern: **sub-PRs stacked on a wave branch require this merge-from-wave every time the parent squash-merges before the child**.

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
