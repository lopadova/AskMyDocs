# ADR 0013 — v8.8 "KB Lifecycle Intelligence — Plus"

- **Status:** Accepted
- **Date:** 2026-06-03
- **Cycle:** v8.8 (W1–W7, PRs #250–#255)

## Context

The v8.7 cycle shipped the first KB-lifecycle-intelligence features (synonyms,
stale-review, AI deep-analysis on ingest/modify, Cloud Time Machine). v8.8
extends that with the delete trigger, a per-(tenant, project) gate, and three
gaps surfaced by a line-by-line audit of the 2026 Affine KB buyer's guide
(content-gap analytics, multilingual FTS, chat-side graph navigation). This ADR
records the load-bearing decisions.

## Decisions

### D1 — Delete-trigger deep-analysis runs off a PRE-delete snapshot
The obsolescence-impact analysis must run when a doc is deleted, but the doc's
content + chunks are gone after a hard delete (and hidden after a soft delete).
`DocumentDeleter` captures a snapshot (identity + text reconstructed from chunks
via `cursor()`, bounded) **before** the deletion mutates anything, and dispatches
`AnalyzeDocumentDeletionJob` with it. Only the user-initiated single delete
opts in (`analyzeImpact: true`); bulk orphan/prune sweeps never trigger the LLM.
Recipients resolve from a `withTrashed()` lookup, falling back to a transient
model hydrated off the snapshot (carrying `source_path` for the ACL folder-glob
check) when the doc was hard-deleted.

### D2 — One layered gate (`ChangeAnalysisGate`), config → tenant `*` → project
The deep-analysis on/off decision (change AND delete paths, both jobs AND the
deleter) routes through a single `ChangeAnalysisGate`. Resolution is layered,
most-specific-wins, each NULL field inheriting the next level up:
`config('kb.change_analysis.*')` → `kb_analysis_settings (tenant, '*')` →
`kb_analysis_settings (tenant, project)`. When the master switch resolves OFF,
the effective dependent knobs are net-OFF too so the admin "effective" display
agrees with `allows()`. Composite `UNIQUE(tenant_id, project_key)`; `project_key='*'`
is the tenant-wide default.

### D3 — Multilingual FTS is confident-or-fallback (R14)
`QueryLanguageDetector` is a dependency-free, deterministic stopword heuristic.
Its stopword sets are curated to DISCRIMINATIVE words only — a function word
shared across languages (`la`/`le`/`un`/`de`/`que`) is omitted so a shared
article can't force a confident wrong-language detection. It returns a dictionary
ONLY on a clear, language-specific winner; on any inconclusive signal it returns
null and `KbSearchService` stems with the configured default — **never silently
with the wrong dictionary**. Default OFF (`KB_FTS_LANGUAGE_DETECTION`).

### D4 — Content-gap recording is a side-channel from every refusal path
`SearchFailureRecorder` records refused queries into `kb_search_failures` from
ALL refusal funnels — `KbChatController`, `MessageController`, AND
`MessageStreamController::streamRefusal()` (the SPA's actual path). Like
`ChatLogManager`, it NEVER breaks the chat hot path (every failure swallowed +
logged). The upsert is a try-INSERT / on-`UniqueConstraintViolationException`
atomic `occurrences = occurrences + 1` UPDATE — portable across pgsql + sqlite,
no lost counts under concurrency (a `lockForUpdate` read+write does NOT cover the
missing-row race). Reason-filter options derive from the DB (R18).

### D5 — Citations carry `slug` + `project_key` (additive) for ACL-safe graph nav
The chat-side Related panel needs the cited canonical slugs + project. Rather than
prop-drill the active project through the streaming chat UI, the citation builder
adds `slug` + `project_key` (R27 additive). `RelatedGraphService` resolves neighbour
titles through `KnowledgeDocument` so `AccessScopeScope` applies — **a neighbour the
user can't access shows its slug but never its title**. The endpoint is config-gated
by the existing `KB_GRAPH_EXPANSION_ENABLED` and no-op without a canonical graph.

### D6 — Test teardown rolls the DB back BEFORE `Mockery::close()` (rule R41)
A custom `tearDown()` must run `parent::tearDown()` (the RefreshDatabase rollback)
before any throwing cleanup (`Mockery::close()`). An unmet `->once()` expectation
that throws before the rollback leaks the transaction and cascades an "active
transaction" suite-wide failure that reads as flake. 38 fragile teardowns were
reordered; the base `TestCase::setUp()` resets the `TenantContext` singleton so no
test can leak tenant state into a sibling.

## Consequences

- The gate is the single extension point for any future per-scope analysis policy.
- Content-gap + obsolescence analytics close the governance loop the Affine guide
  demands; SSO/SCIM + bulk export (also surfaced by the audit) are deferred to a
  dedicated cycle.
- Adding a tenant-aware model now requires updating BOTH `TenantIdMandatoryTest`
  and `TenantReadScopeTest` completeness lists (lesson from W3/W4 CI).
