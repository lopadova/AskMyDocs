# Canonical Compilation — Lessons Learned

Running log of non-obvious findings, gotchas, and corrections during execution. Every agent must **read this file at the start** and the orchestrator must **append to it** whenever something surprising emerges.

Format per entry:

```
## YYYY-MM-DD — <short title> (Phase X, Task Y)
**What we found:**
**Why it matters:**
**How to handle:**
```

---

## 2026-04-22 — Baseline assumptions (before Phase 0)

**What we found:** The AskMyDocs codebase on `chore/upgrade-laravel-13` is remarkably clean in terms of Laravel 13 breaking changes — zero application code required modification during the upgrade. The codebase does **not** use `VerifyCsrfToken`, `HasUuids`, `upsert()`, `mergeIfMissing()`, `Schema::getTables()`, custom `Blueprint`/`Grammar`, `boot()` with nested instantiation, `Concurrency::run()`, `Js::from` tests, `'image'` validation, or any pagination-view references. This also means the canonical layer we're adding can use modern Laravel 13 idioms (attribute-based MCP tools, enum casts, readonly DTOs) without competing with legacy code.

**Why it matters:** We can write Phase 1–7 code in the most current style without concerns about mixing paradigms.

**How to handle:** use PHP 8.3+ features (readonly properties, `match`, `#[...]` attributes) freely.

---

## 2026-04-22 — MarkdownChunker is currently a placeholder

**What we found:** `app/Services/Kb/MarkdownChunker.php` does a naive `preg_split('/\n{2,}/', $md)` — it never populates `heading_path` (always null) and never honors `KB_CHUNK_TARGET_TOKENS` / `KB_CHUNK_HARD_CAP_TOKENS` config. Config keys exist but the service ignores them.

**Why it matters:** Phase 2 already plans to rewrite this file. The rewrite is **a hidden retrieval quality win** for the entire project — even non-canonical docs will get better chunking once Phase 2 ships. Mention this in the PR #11 description as a "side benefit".

**How to handle:** In Phase 2 Task 2.3, make sure the rewritten chunker also covers the non-canonical path (markdown with no frontmatter) — fall back to paragraph split but still populate `heading_path` from headings when present.

---

## 2026-04-22 — Reranker weights are already config-driven

**What we found:** `app/Services/Kb/Reranker.php` reads `kb.reranking.vector_weight`, `keyword_weight`, `heading_weight` from config — **not hardcoded**. So the plan's "fusion score formula" is actually already configurable today.

**Why it matters:** Phase 3 Task 3.4 (canonical boost/penalty) is purely additive. We don't rewrite the existing fusion — we add a post-fusion adjustment on top. Simpler than expected.

**How to handle:** Keep the existing fusion intact; append canonical-score-adjustment logic AFTER the existing `rerank_score` computation.

---

## 2026-04-22 — Test-mirror migrations use SQLite-compatible types

**What we found:** `tests/database/migrations/` replaces `vector(N)` with `TEXT` (JSON) for SQLite compatibility. Any new migration that uses pgsql-specific types must ship a SQLite-friendly variant in the test mirror.

**Why it matters:** The canonical columns we're adding are all plain types (`string`, `bool`, `smallint`, `json`) that work cleanly on both SQLite and pgsql — no special handling needed. `kb_nodes` / `kb_edges` also use plain types. No divergence expected.

**How to handle:** For Phase 1 migrations, create the test mirrors **identical** to production. Run the full PHPUnit suite after each migration to catch any SQLite edge cases.

---

## 2026-04-22 — Embedding cache is cross-project by design

**What we found:** `embedding_cache` table does NOT have `project_key` — it's a global cache keyed by `(text_hash, provider, model)`. Same text across different projects = one embedding call total.

**Why it matters:** This is an intentional optimization. Don't add `project_key` to `embedding_cache` "for consistency" — it would destroy the cache hit rate. Verified in the `EmbeddingCacheService` code.

**How to handle:** Leave `embedding_cache` alone. New canonical paths (RejectedApproachInjector) can use it too with no modification.

---

## 2026-04-22 — PHP runtime on dev machine is PHP 8.4 via Herd shim

**What we found:** Dev machine has PHP 8.2 / 8.4 / 8.5 installed via Herd. `php.bat` was remapped to `php84.bat` during the Laravel 13 upgrade. User approved.

**Why it matters:** If Herd auto-regenerates `php.bat` between sessions, future commands might silently use PHP 8.2 which is incompatible with Laravel 13. Agents running tests should verify `php --version` ≥ 8.3 at the start of each session.

**How to handle:** Orchestrator prepends `C:\Users\lopad\.config\herd\bin` to `$env:PATH` in PowerShell at session start. Any command that runs `composer` or `vendor/bin/phpunit` should ensure PHP 8.3+.

---

## 2026-04-22 — User chose aggressive defaults for graph + rejected

**What we found:** User selected "Entrambi opt-out (attivi di default)" for graph expansion AND rejected-approach injection. Both features ON by default post-upgrade.

**Why it matters:** This is more aggressive than the "opt-in" recommendation. It changes chat retrieval behavior for existing consumers immediately after installing the new version. Mitigation: when there are **zero canonical docs** in the project, both features are **no-ops** (graph is empty, rejected query returns empty collection). So for consumers who haven't canonicalized anything yet, behavior is unchanged.

**How to handle:** Document explicitly in the README and CHANGELOG: "graph expansion + rejected injection are on by default but degrade gracefully to no-op when no canonical docs exist". Provide `KB_GRAPH_EXPANSION_ENABLED=false` escape hatch in `.env.example` with a comment.

---

## 2026-04-22 — Phase 0 agent 0.2 entered plan mode, did no edits (Phase 0, Task 0.2)

**What we found:** When dispatching a `general-purpose` subagent to edit `config/kb.php` + `.env.example`, the subagent unexpectedly entered Plan mode and produced a plan file instead of applying the edits. Same run: agent 0.3 (for ADRs) completed its file writes before plan mode kicked in.

**Why it matters:** For simple deterministic "add N blocks to a file" tasks, dispatching a subagent is actually *slower* and less reliable than doing the edit inline via `Edit` — the subagent may get into plan-mode gating or other meta-behaviors.

**How to handle:** For purely mechanical config/env edits, the orchestrator applies them directly. Reserve subagents for:
- Larger multi-file builds (e.g. "create 5 Tool classes with their tests").
- Research/exploration tasks (Explore agents).
- Reviews (code-reviewer agent).

Future phases: dispatch subagents only when the task genuinely benefits from isolation (multiple files, independent tests, parallelizable work).

---

## 2026-04-22 — Copilot review on PR #10 surfaced 4 real bugs + 2 cleanups (Phase 2 integration)

**What we found:** Copilot flagged 6 issues on PR #10, 4 real bugs + 2 code-quality cleanups:

### Real bugs

1. **DocumentIngestor canonical re-ingest with changed content violates uq_kb_doc_slug / uq_kb_doc_doc_id.** The upsert is keyed on `(project_key, source_path, version_hash)` — when content changes, a NEW row is inserted. But the archived prior version still holds the canonical `slug` / `doc_id`, so the composite uniques reject the insert. Result: canonical docs could be ingested once but never updated. **Fix:** added `vacateCanonicalIdentifiersOnPreviousVersions()` — before the new `updateOrCreate`, null out `doc_id` / `slug` / `canonical_status` / `is_canonical` on any prior version for the same `(project_key, source_path)`. Preserves `canonical_type` + `frontmatter_json` on the archived row so history queries still reconstruct what was there.

2. **CanonicalIndexerJob rebuilt the graph from archived rows.** When a new version is ingested, the old version gets `status=archived` but the job doesn't check that flag. If it fires (late delivery, manual re-dispatch), it rebuilds edges from stale content. **Fix:** added `if ($doc->status === 'archived') return;` guard after the canonical checks.

3. **`ensureTargetNode()` race condition under concurrent jobs.** Read-then-create: two workers see "missing" → both `create()` → one wins, the other throws on the composite unique. **Fix:** replaced with `firstOrCreate` (atomic at the DB level). Regression test simulates two indexer runs targeting the same shared slug; asserts one node + two edges.

4. **DocumentDeleter cascade fails when canonical doc has slug but no doc_id.** `CanonicalParser::validate()` does NOT require `id` — a doc can legitimately have `slug: dec-x` with no `id`. Indexer creates a node with `source_doc_id=null`. Old cascade matched only `source_doc_id`, so the node was orphaned on hard delete. **Fix:** two-tier cascade — prefer `source_doc_id` match, fall back to `(project_key, node_uid=slug)`.

### Cleanups

5. **DocumentIngestor dead code — `firstLine(body)` always overwritten** by caller-supplied `$title`. Removed the first-line fallback + the helper method.

6. **Test helper `fakeEmbeddingCache(int $chunkCount)` never used the param.** Removed; added a docblock explaining it adapts to any input text count.

### Test counts
- Pre-fix: 271 / 787
- Post-fix: **275 / 803** (4 new regression tests).

### Key takeaway
Content-changing re-ingest of canonical docs was a **silent bug**: the first ingest works, every subsequent update fails. It would have shipped to users and broken the first editorial workflow ("I fixed a typo in my decision doc"). Copilot caught it via schema analysis — worth running Copilot reviews on every canonical-path PR.

---

## 2026-04-22 — Copilot review on PR #9 surfaced 4 architectural issues (Phase 2)

**What we found:** Copilot reviewed PR #9 and flagged (consolidated) 4 substantive issues plus a process issue. All legitimate.

### Issue 1 — heading regex not fence-aware (MarkdownChunker)
A line like `# this is a comment` inside a ```` ```bash ```` fence was incorrectly treated as an H1 heading, breaking section boundaries on any docs with shell/code examples. **Fix:** added a fence-state FSM (`FENCE_TOGGLE_RE = /^\s{0,3}(\`{3,}|~{3,})/`) in both `hasRealHeading()` and `advanceSectionState()`. Lines between fence toggles are copied to the buffer unchanged, never interpreted as headings. Tested with shell comments, tilde fences, and mixed real-heading + fenced-code cases.

### Issue 2 — `node_uid` / `edge_uid` globally unique breaks multi-tenancy
`kb_nodes.node_uid->unique()` and `kb_edges.edge_uid->unique()` are globally scoped. Two projects with the same canonical slug (e.g. `dec-cache-v2`) would collide. **Fix:** dropped global unique, added composite unique `(project_key, node_uid)` on kb_nodes and `(project_key, edge_uid)` on kb_edges. Regression test: `test_same_node_uid_coexists_in_two_projects` + `test_duplicate_node_uid_within_same_project_is_rejected`.

### Issue 3 — kb_edges FK cannot prevent cross-tenant edges
With globally-unique node_uid the FK `kb_edges.from_node_uid → kb_nodes.node_uid` works but relies on the global uniqueness. Once node_uid is per-project, the FK can't enforce tenant boundaries anymore. **Fix:** switched to **composite FKs** `(project_key, from_node_uid) → kb_nodes(project_key, node_uid)` and same for `to_node_uid`. Cross-tenant edges are now structurally impossible; regression test `test_cross_tenant_edge_is_structurally_impossible` verifies an attempted insert fails with a `QueryException`.

### Issue 4 — column naming inconsistent (`project_code` vs `project_key`)
Repository convention is `project_key` (knowledge_documents, knowledge_chunks, chat_logs, kb_canonical_audit). My kb_nodes/kb_edges migrations used `project_code`. R9 violation. **Fix:** renamed everywhere — migrations (prod + test mirror), models, test fixtures.

### Issue 5 — `scopeAccepted()` did not imply `canonical()`
A stray `canonical_status='accepted'` on an `is_canonical=false` row would leak into retrieval. **Fix:** `scopeAccepted()` now chains `canonical()` first. Regression test: `test_accepted_scope_implies_is_canonical`.

### Issue 6 (process) — PR title/body didn't match scope
PR #9 was titled "phase 0 foundations" but grew to include Phase 1 + Phase 2 parsing group. Updated title/body to reflect "phases 0–2 parsing group".

**Why it matters:** all 4 code issues would have been real bugs in production — fence-heading misparses on common docs, multi-tenant data leakage, silent schema inconsistency. Copilot is an effective review ally; DO take its suggestions seriously. **Also:** when a PR grows past its original scope, update its title/body immediately — reviewers assess risk based on what the PR claims to do.

**How to apply going forward:**
- Any migration that references tenant data uses `project_key` (NOT `project_code`, `tenant_id`, `workspace`, etc.). This is the repository's canonical name.
- Any relation between tenant-scoped tables uses a composite FK, not a single-column FK, when both tables hold the tenant column.
- Any new chunker / parser that looks at line-starting characters must track fenced code blocks.
- Any scope that narrows a "typed" row (canonical_status, canonical_type) must also assert the base flag (`is_canonical=true`) — compose scopes, don't rely on single-column filters.
- When a PR exceeds its announced scope, update the PR metadata before pushing.

---

## 2026-04-22 — CI PHP matrix 8.3/8.4/8.5 (Phase 2, CI hardening)

**What we found:** User asked to ensure the package is ready to ship for PHP 8.3, 8.4, and 8.5 — not just the default 8.3. Single-version CI means a dependency that silently requires a newer PHP (like symfony/yaml 8.x → PHP 8.4+) can pass CI on the single version and fail for consumers on other versions.

**Why it matters:** Prevents the exact class of bug that showed up on PR #9 (symfony/yaml constraint). Also: keeping all 3 supported PHP versions green is a hard quality gate before advertising multi-version support in the README.

**How to handle:**
- `.github/workflows/tests.yml` now uses `matrix.php: ['8.3', '8.4', '8.5']` with `fail-fast: false` so one failing version still reports status for the others.
- Composer cache is keyed per-PHP-version (`~/.composer/cache` + `php${{ matrix.php }}`) to avoid cross-pollution of resolved dependencies.
- Vitest job stays single-instance (Node 20) — JS tests are PHP-agnostic.
- README + CLAUDE.md should advertise "PHP 8.3+" (not "PHP 8.3") now that we have matrix coverage. Update in Phase 7.

---

## 2026-04-22 — symfony/yaml ^8.0 requires PHP 8.4; CI runs PHP 8.3 (Phase 2, CI)

**What we found:** PR #9 CI failed on `composer install` with:
```
symfony/yaml[v8.0.0, ..., v8.0.8] require php >=8.4 -> your php version (8.3.30) does not satisfy that requirement.
```
composer.lock is gitignored in this repo so CI resolves dependencies fresh. My initial `"symfony/yaml": "^8.0"` constraint was too strict: v8.x only runs on PHP 8.4+, but Laravel 13's floor is PHP 8.3 and CI uses 8.3.

**Why it matters:** Any dependency added in future phases must be checked against **both PHP 8.3 and PHP 8.4**. Don't write `^8.0`-style constraints on Symfony components without checking their PHP floor — Symfony 8.x bumped PHP requirement from 8.2 to 8.4.

**How to handle:**
- When adding a Symfony component, check its PHP requirement:
  - Symfony 7.x → PHP 8.2+ (safe for PHP 8.3+)
  - Symfony 8.x → PHP 8.4+ (NOT safe for PHP 8.3)
- Use the **bi-version constraint pattern** `^7.4|^8.0` — composer's SAT solver picks the right major per platform. orchestra/testbench uses this pattern and it works cleanly.
- Alternative: commit `composer.lock` (applications conventionally do). Not the path chosen here (gitignore still excludes it), but an option if the constraint matrix gets too complex.

---

## 2026-04-22 — SQLite `dropColumn` fails if the column still has an index (Phase 1, migrations)

**What we found:** The first migration iteration for `knowledge_documents` canonical columns failed on `RefreshDatabase` rollback with:

```
SQLSTATE[HY000]: General error: 1 error in index knowledge_documents_doc_id_index
after drop column: no such column: "doc_id"
SQL: alter table "knowledge_documents" drop column "doc_id"
```

Laravel 13 Blueprint's `dropColumn` on SQLite does NOT automatically drop per-column indexes created via `->index()`. Composite unique constraints dropped with `dropUnique()` work fine, but plain `->index()` indexes must be dropped explicitly with `dropIndex('<table>_<column>_index')` **before** `dropColumn`.

**Why it matters:** every migration that adds `->index()` on a column on an *existing* table (i.e. `Schema::table`, not `Schema::create`) needs symmetric `dropIndex` calls in `down()`. Missing these breaks rollback and therefore `RefreshDatabase` tests. On pgsql the same down() works fine — only SQLite is affected.

**How to handle:** In every future Phase 1+ migration that touches an existing table and adds a per-column index, include the explicit `dropIndex('<table>_<column>_index')` calls in `down()` before `dropColumn`. Laravel's default index name convention is `<table>_<col>_index` (plain) and `<table>_<col>_unique` (unique).

---

## 2026-04-22 — commonmark + yaml were already installed transitively (Phase 0, Task 0.1)

**What we found:** `composer update league/commonmark symfony/yaml` said "Nothing to install". Verified via `composer show`: `league/commonmark 2.8.2` (pulled by a Laravel 13 dep) and `symfony/yaml 8.0.8` (pulled by Symfony Console polyfill chain) were already present. Adding them to `require` just promotes them from transitive to direct dependencies — correct behavior for a project that explicitly relies on them.

**Why it matters:** No new packages actually downloaded. `composer.lock` was still regenerated (promotion changes the lock's "packages" section), so we still commit lock changes.

**How to handle:** When adding a dep that's already transitive, don't be surprised by "Nothing to install". Confirm via `composer show <pkg>`. Still commit `composer.json` because the promotion IS a semantic change.

---

## 2026-04-22 — Skills are templates, not active in this repo

**What we found:** User chose "Solo nel repo AskMyDocs come template" for the 5 Claude skills. They live under `.claude/skills/kb-canonical/` as reference, not as active skills.

**Why it matters:** The skills must NOT have a `description` that would auto-activate them when a developer is working on the AskMyDocs codebase itself. Their trigger text should be explicit about targeting consumer repos.

**How to handle:** In Phase 6, write skill descriptions like "Use this skill WHEN WORKING ON THE CONSUMER KNOWLEDGE BASE (not on AskMyDocs itself)...". Also add a big banner in the skill README clarifying this.
