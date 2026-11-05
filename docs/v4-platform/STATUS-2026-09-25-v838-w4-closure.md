# STATUS — AskMyDocs v8.38.0 GA — Document Intelligence W4 (Portable Wiki Export + Candidate-Only Import)

**Cycle:** v8.38 (Portable Wiki Export — fourth of five Document Intelligence workstreams;
W5 continues as v8.39, its own release per the per-Wn versioning established at v8.36
closure).
**Closed:** 2026-09-25. **GA tag:** `v8.38.0` (merge `feature/v8.38 → main`, R37 per-release).
**Origin:** continuation of the [Annota AI gap audit](AUDIT-2026-09-11-annota-ai-gap.md)'s
second named gap — a competitor exports its knowledge base as a portable, agent-readable
Markdown wiki with a live MCP connection back to the source; AskMyDocs had no export path
at all before this cycle. Plan:
[`PLAN-v8.36-document-intelligence-and-llm-wiki-export.md`](PLAN-v8.36-document-intelligence-and-llm-wiki-export.md)
§W4. Design: [ADR 0032](../adr/0032-v838-portable-wiki-export-and-candidate-only-import.md).

## Sub-tasks (merged into `feature/v8.38`, each with R40 local-critic + R36/cloud-fallback loop to 0 must-fix)

| Wn | Feature | PR | Base |
|---|---|---|---|
| W4a | Synchronous CLI export core (`kb:export-wiki`) — ACL/PII-scoped `wiki/`+`raw/` folder, `MANIFEST.json` hash chain (ADR 0032 §1-§4/§9/§10) | #503 | `feature/v8.38` |
| W4b | Async HTTP surface — idempotent `POST /api/admin/kb/exports`, `ExecuteKbWikiExportJob`, download re-authorization, `kb:prune-wiki-exports` sweep (ADR 0032 §5/§11/§12) | #506 | `feature/v8.38` |
| — | Security hotfix: bind the MCP token creator as the request principal (R33) — surfaced as a prerequisite for W4c's MCP tools, landed directly on `main` and ported into `feature/v8.38` | #507 (`main`), #508 (port) | `main` / `feature/v8.38` |
| W4c | `.mcp.json` + frontmatter round-trip + `KbWikiImportService` (candidate-only) + `kb:import-wiki` + MCP tools (`KbCreateExportTool`/`KbGetExportTool`/`KbImportWikiTool`) (ADR 0032 §4/§7/§11) | #509 | `feature/v8.38` |
| W4 closure | RC + GA close (this doc + README roadmap-row flip + Changelog + GA merge + `v8.38.0` tag) | — | `feature/v8.38 → main` |

The security hotfix (#507/#508) is cross-cutting rather than originally-scoped W4 work: it
was found while reviewing W4c's MCP tools (a token's bound principal — `EnforceMcpScope`'s
`created_by` — was not actually the user `Auth::user()` resolved to inside the tool, so
`KbCreateExportTool`'s explicit role re-check was checking the wrong identity). It shipped
first, directly to `main` as its own hotfix PR (#507, following the "hotfix bypasses R37's
per-cycle branch" precedent already established), then ported into `feature/v8.38` (#508)
so the integration branch never regressed the fix while W4c continued on top of it.

## New schema (2 new tenant-aware tables, R30/R31)

- **`kb_wiki_export_requests`** (W4b) — the async export job's status row: `id` (uuid),
  `tenant_id`, `project_key`, `requested_by`, `status` (`pending → running → completed |
  failed`), `idempotency_key` (`UNIQUE(tenant_id, idempotency_key)` — a repeat request with
  an unchanged corpus/authorization digest reuses the same row instead of re-exporting),
  `document_ids` (the exact set re-checked at download time — ADR 0032's second,
  independent authorization gate alongside the idempotency key), `staged_path`,
  `expires_at` (drives `kb:prune-wiki-exports`'s hourly sweep).
- **`kb_wiki_import_candidates`** (W4c) — one row per proposed re-import, mirroring
  `kb_text_correction_candidates`'s (v8.37/W3b) idempotency-key shape: `sha256` of the
  concatenation of the **per-field** `sha256` digests of `(tenant, project, slug,
  content_hash, actor_identity)` — hashing each field first keeps the key injective, same
  rationale as W3b. `UNIQUE(tenant_id, idempotency_key)` is the replay/dedup mechanism.
  `flow_run_id` (string — `FlowRun`'s id is a string, not an integer) links the row to the
  `PromotionFlow` run `KbWikiImportService::importDocument()` dispatches; the row never
  writes `knowledge_documents` itself (ADR 0003).

Both migrations are mirrored verbatim into `tests/database/migrations/` (SQLite test-schema
convention). Both models are registered in `TenantIdMandatoryTest` (R31) AND
`TenantReadScopeTest` (R30) — two SEPARATE enumerations that must independently stay in
sync; a full-suite run (not a filtered one) is what actually catches a miss on the second
list, which is how the `KbWikiImportCandidate` omission from `TenantReadScopeTest` was
caught during this cycle's own closure verification.

## New events / commands / endpoints (tri-surface, R44)

All surfaces adapt two core services: `KbWikiExportService`/`KbWikiExportRequestService`
(export) and `KbWikiImportService` (import), both tenant-scoped through
`KnowledgeDocument::forTenant()` (R30).

| Capability | PHP / CLI | HTTP | MCP |
|---|---|---|---|
| Synchronous export to a local folder | `kb:export-wiki {--tenant} {--project} {--as-user} {--output}` | — (CLI-only; local filesystem access is the point, ADR 0032 §1) | — |
| Start (or reuse) an async export | `KbWikiExportRequestService::requestExport()` | `POST /api/admin/kb/exports` | `KbCreateExportTool` (write) |
| Poll export status / download | `KbWikiExportRequestService::status()` | `GET .../exports/{id}`, `GET .../exports/{id}/download` | `KbGetExportTool` (read) |
| Prune expired export bundles | `kb:prune-wiki-exports [--dry-run]` (documented single-surface R44 exception, scheduler-only maintenance sweep) | — | — |
| Propose a document re-import from an edited wiki page | `KbWikiImportService::importDocument()` | `POST /api/admin/kb/imports` | `KbImportWikiTool` (propose only) |
| Bulk-propose every changed page under a local `wiki/` folder | `kb:import-wiki {folder} --tenant= --as-user=` | — (CLI-only, same rationale as export) | — |

Every import surface (HTTP + MCP + the CLI's per-file loop) delegates to the SAME
`KbWikiImportService::importDocument()` — no parallel implementations (R44). A proposal
never writes `knowledge_documents` directly: it dispatches `Flow::execute(PromotionFlow::NAME,
[...])`, the identical saga `KbPromotionController::promote()` already uses, pausing at an
approval gate exactly like every other promotion path (ADR 0003).

## `.mcp.json` and the frontmatter round-trip (ADR 0032 §4/§7)

`KbWikiExportService::buildMcpConfig()` writes a `.mcp.json` at the root of every exported
folder pointing at the tenant's live MCP server URL, with `${ASKMYDOCS_MCP_TOKEN}` and
`${ASKMYDOCS_TENANT_ID}` as **placeholder** environment-variable references — never an
embedded credential; a consuming agent supplies the real token through its own MCP client
config. `buildWikiPage()`'s frontmatter now round-trips through `CanonicalParser`: the
exported page's YAML carries `slug`/`id`/`type`/`status` ahead of the existing governance
fields, so a page pulled back through `kb:import-wiki` (or edited by hand and reproposed)
parses back into the same canonical identity it was exported with — proven by a new
regression test (`test_a_canonical_documents_exported_page_round_trips_through_canonical_
parser`) exporting a canonical document and re-parsing the emitted page.

## Concurrency, idempotency and path-safety hardening (R21, R30, CWE-22-adjacent)

- `KbWikiImportService::importDocument()` wraps the idempotency lookup + rate-limit
  check-and-hit in a per-actor `Cache::lock()`, mirroring the W3b correction-candidate
  flow's own construction.
- A replayed identical proposal (`idempotency_key` already recorded) returns `status:
  'replayed'` with `approval: null` — **not** a fresh reissued approval token.
  `ApprovalTokenManager::reissuePendingForStep()` is one-shot by construction (its query
  requires `previous_token_hash IS NULL`, which the first successful call already clears),
  so a second call for the same run/step returns `null`; the original design (attempting a
  reissue on replay) was corrected during implementation once this was discovered via a
  failing test, and every affected docblock (`KbWikiImportService`, `KbImportWikiTool`'s
  `#[Description]`) was rewritten to describe the actual one-shot behaviour rather than the
  originally-assumed one.
- `frontmatterDiffers()` — the "unchanged, nothing to propose" fast path originally compared
  only the Markdown **body**; a page whose `type`/`status`/`retrieval_priority` changed with
  an identical body was silently reported `unchanged` and the edit permanently lost,
  contradicting the code's own R14 warning comment. Fixed by gating the unchanged-check on a
  dedicated frontmatter comparison as well as the body diff. Regression test:
  `test_proposes_a_candidate_for_a_frontmatter_only_edit`.
- `importFolder()`'s path-containment checks (`realpath($wikiDir)` inside `realpath($root)`,
  and each walked file inside `$wikiDir`) originally used `str_starts_with($resolved, $root)`
  without a trailing directory separator — a CWE-22-adjacent prefix bug: a sibling directory
  named `{root}-evil` (or a symlink pointing at one) would pass the check. Fixed by comparing
  against `$root.DIRECTORY_SEPARATOR`. Regression test:
  `test_a_wiki_symlink_escaping_to_a_sibling_directory_is_refused` (creates `$root` and
  `$root-evil` as siblings, symlinks a `wiki` entry across the boundary, asserts
  `InvalidArgumentException`).
- `importFolder()` previously let a `KbWikiImportRateLimitedException` from one file's
  `importDocument()` call propagate straight out of the walking loop, discarding every prior
  file's already-computed result. Fixed to catch the exception per-file, record it as
  `status: 'rate_limited'` in that file's own result entry, and stop the walk (the budget is
  genuinely exhausted) while still returning everything accumulated so far.
- The diff lookup inside `importDocument()` runs bound to the IMPORTING user's own identity
  (`Auth::setUser($asUser)` in a `try/finally`), so `AccessScopeScope` (R33) applies to the
  "does this document already exist and how does it differ" query exactly as it would for
  that user's own reads — an actor scoped out of a document is told the page is "new," never
  "unchanged," for a document they cannot see.

## `kb:export-wiki` not registered as an Artisan command (caught in closure verification)

`KbImportWikiCommand` (the new `kb:import-wiki` CLI) was written and tested, but omitted
from `AppServiceProvider`'s explicit `$this->commands([...])` array — the project registers
commands explicitly rather than relying on directory auto-discovery. Every
`KbImportWikiCommandTest` scenario failed identically with
`Symfony\Component\Console\Exception\CommandNotFoundException` until this was found and
fixed during the same PR's review pass, before merge.

## Independent review (Copilot/Codex unavailable, R36 fallback)

Neither GitHub Copilot nor the `@codex review` fallback (R36) responded across two full
rounds on PR #509's review request — both cloud reviewers were unreachable for the duration
this PR was open. Per R36's "always-on local gate," an independent subagent review (Agent
tool, general-purpose, given the diff with no prior context from the implementing session)
carried the pre-merge safety net instead. It found two genuine must-fix issues — the
path-containment bypass and the frontmatter-only-edit data loss described above — both fixed
and covered by dedicated regression tests before merge, plus two non-blocking nits (a TOCTOU
window on `KbCreateExportTool`'s rate limiter, and `importFolder()` losing prior results on
a rate-limit exception) carried into the same push per the "carry plainly correct nits into
the next push" convention. A retroactive cloud review should still run once Copilot/Codex
budget is confirmed available again.

## Defaults / cost posture

- `KB_WIKI_EXPORT_ENABLED` default **OFF** (R43, both states tested) gates **every** export
  AND import surface — there is no separate import-specific flag; `.mcp.json` only makes
  sense once export exists, and import is meaningless without something to import from.
- `KB_WIKI_EXPORT_RETENTION_HOURS=24` (W4b, unchanged this cycle) — drives the export
  bundle's `expires_at` and the hourly `kb:prune-wiki-exports` sweep.
- `KB_WIKI_IMPORT_CANDIDATES_PER_HOUR=30` (new, W4c) — per-tenant rate limit on import
  proposals, checked **after** the idempotency-key lookup so a replay never spends the
  budget (same ordering discipline as W3b's `KB_REVIEW_CANDIDATES_PER_HOUR`).
- `KB_WIKI_EXPORT_CREATE_REQUESTS_PER_HOUR=10` (new, W4c) — per-principal rate limit on
  `KbCreateExportTool`'s MCP-initiated export requests, independent of the HTTP endpoint's
  own idempotency-driven reuse.

## Deferred (documented, per ADR 0032 §4/§7)

- **`llms.txt` / `llms-full.txt`** — the flat-file convention some agent tooling expects
  alongside a wiki export. Not started this cycle; the compiled `wiki/` tree plus
  `MANIFEST.json` is the shipped alternative.
- **`include_images` / `--format` variants** — both are explicitly rejected (a clear error,
  never silently ignored) by `KbWikiExportRequestService`; only `format="llm-wiki"` and
  `include_images=false` are implemented, exactly as `KbCreateExportTool`'s own
  `#[Description]` states.
- **R45 doc-site deep page** — `docs-site/portable-wiki-export.mdx` was extended this cycle
  (frontmatter-contract table, `.mcp.json` section, round-trip Mermaid diagram) as part of
  PR #509 rather than deferred; noted here only because the v8.36/v8.37 templates call out
  doc-site status explicitly.

## Tags — blocked by tooling, operator follow-up required

Same blocker documented at v8.36 and v8.37 closure: this session's git credential can push
branch commits and merge PRs, but `git push` of a **tag** ref returns `403` (confirmed
reproducible). `v8.36.0` and `v8.37.0` remain untagged despite both cycles being fully
merged to `main`. An operator with a tag-capable credential should run, once the v8.38 →
`main` GA merge (below) lands:

```bash
# v8.36.0 — already on main at the PR #492 merge commit
git tag -a v8.36.0 62dd0b25 -m "v8.36.0 — Document intelligence W1+W2 (OCR ingestion, conversion artifacts). See docs/v4-platform/STATUS-2026-09-16-v836-w1-w2-closure.md."
git push origin v8.36.0

# v8.37.0 — at the GA merge commit on main (PR #499)
git tag -a v8.37.0 ebc12f22 -m "v8.37.0 — Digitization Review (W3). See docs/v4-platform/STATUS-2026-09-20-v837-w3-closure.md."
git push origin v8.37.0

# v8.38.0 — at the GA merge commit on main (fill in the actual SHA once merged)
git tag -a v8.38.0 <GA-merge-sha-on-main> -m "v8.38.0 — Portable Wiki Export + candidate-only import (W4). See docs/v4-platform/STATUS-2026-09-25-v838-w4-closure.md."
git push origin v8.38.0
```

Per R39, an rc tag would normally precede the GA tag; given all three are already blocked
by the same credential scope and the code is stable (full CI green — 4896 tests, 22080
assertions, 0 failures — plus the independent subagent review clean), this closure skips
straight to proposing the GA tag rather than adding a redundant rc step an operator would
have to push twice.

Cycle plan: [`PLAN-v8.36-document-intelligence-and-llm-wiki-export.md`](PLAN-v8.36-document-intelligence-and-llm-wiki-export.md) §W4.
Design: [ADR 0032](../adr/0032-v838-portable-wiki-export-and-candidate-only-import.md).
