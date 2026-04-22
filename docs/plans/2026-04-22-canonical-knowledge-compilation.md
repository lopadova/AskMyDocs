# Canonical Knowledge Compilation for AskMyDocs — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Evolve AskMyDocs from a "flat-chunk RAG" into an enterprise **Knowledge Compilation Platform**: canonical typed documents with YAML frontmatter, a lightweight wikilink-based knowledge graph, graph-aware retrieval with rejected-approach injection, a full promotion pipeline (raw → curated → canonical), and 5 Claude skills that govern the editorial workflow — all without breaking the existing RAG hot path.

**Architecture:** Extend `knowledge_documents` with 8 nullable canonical columns (single source-of-truth, no double-write). Add 3 new tables (`kb_nodes`, `kb_edges`, `kb_canonical_audit`) for the graph layer and editorial audit trail. Upgrade `MarkdownChunker` to real section-aware chunking that parses YAML frontmatter + wikilinks. Inject a `GraphExpander` + `RejectedApproachInjector` into the existing `KbSearchService` retrieval pipeline. Add a `CanonicalPromotionController`, 3 new Artisan commands, 5 new MCP tools, and 5 Claude skill templates. Multi-tenant safe (scoped by `project_key`), soft-delete aware, memory-safe bulk ops. All behind feature flags `KB_CANONICAL_*`, `KB_GRAPH_*`, `KB_REJECTED_*` (graph expansion + rejected injection **on by default**, but no-op when no canonical docs exist, so zero regression for current consumers).

**Tech stack:** Laravel 13.6 / PHP 8.3+ / PostgreSQL 15+pgvector / Sanctum / Orchestra Testbench 11 / PHPUnit 12 / Vitest / Laravel MCP.

**Rollout:** Integration branch `feature/kb-canonical-compilation` + **one PR per phase** (9 PRs, one phase per PR). Each PR is self-contained, green, mergeable independently.

---

## 0. Context & Motivation

### Why now
The user pointed to [OmegaWiki](https://github.com/skyllwt/OmegaWiki) — Karpathy's LLM-Wiki idea scaled up — which contrasts "flat-chunk RAG where knowledge is *rediscovered on every query*" with a "wiki-centric model where knowledge is *compiled once, maintained forever*". AskMyDocs today does the former. A recurring enterprise frustration with plain RAG is that it has no typed memory: the same question produces the same retrieval, rejected approaches get re-proposed, decisions drift, no navigation exists beyond cosine similarity. Customers ask for a "proper knowledge base", not "semantic search over a pile of markdown".

### What we keep from OmegaWiki
1. **Compiled knowledge** — canonical markdown as stable source-of-truth, versioned in Git.
2. **Typed lightweight graph** — 9 entity types × 10 relation types, not research-oriented (no Paper/Claim/Experiment).
3. **Obsidian-style wikilinks** `[[slug]]` as the human-and-machine-friendly link format.
4. **Promotion pipeline** — raw → curated → canonical, with human review as a first-class gate.
5. **Anti-repetition memory** — rejected-approach documents explicitly fed back to the LLM as negative context.

### What we deliberately skip
- OmegaWiki's research-centric taxonomy (Paper, Experiment, Claim, Topic).
- Running the whole system inside Claude CLI — AskMyDocs stays the enterprise backend; Claude is the editorial assistant, not the runtime.
- Replacing the Laravel DB with a flat-file wiki — the DB is a **projection**, not the source-of-truth.

### What this does NOT change
- Idempotency anchor `(project_key, source_path, version_hash)` stays the contract.
- `IngestDocumentJob` pipeline unchanged — canonical handling is a hook *inside* `DocumentIngestor`, not a parallel path.
- `KbSearchService` public signature unchanged — graph expansion is additive and config-gated.
- Soft-delete semantics untouched (R2).
- All the R1–R9 rules in `CLAUDE.md` apply as-is and we'll add **R10 (canonical-awareness)**.

---

## 1. Strategic Rationale — the 4 adapted ideas

| OmegaWiki idea | How we adapt it | Primary win |
|---|---|---|
| Knowledge is *compiled*, not rediscovered | Typed canonical markdown + DB projection; retrieval_priority + canonical_status drive reranker bias | Stable answers for stable topics; no drift between queries |
| Typed graph of entities + relations | 9 entity types, 10 relation types, wikilink-derived, project-scoped, stored in `kb_nodes`/`kb_edges` | 1-hop context expansion beyond cosine; `related_to`/`decision_for`/`supersedes` edges guide retrieval |
| Obsidian wikilinks `[[slug]]` | Canonical Markdown parser extracts wikilinks → edges; slugs stable per project | Human-writable, machine-parseable, zero-lock-in |
| Every skill reads+writes the wiki | `POST /api/kb/promotion/{candidates,promote,suggest}` + 5 Claude skill templates + `kb:promote` CLI | Conversations & incidents promoted to persistent canonical docs, not lost in chat logs |
| (BONUS) Anti-repetition memory | `type: rejected-approach` docs are indexed and injected as "⚠ DO NOT REPEAT" context in the prompt | LLM stops re-proposing already-rejected options |

---

## 2. Target Architecture

```
                                   ┌─────────────────────────┐
                                   │  Consumer repo (client)  │
                                   │  kb/                     │
                                   │   ├ decisions/*.md       │
                                   │   ├ modules/*.md         │
                                   │   ├ runbooks/*.md        │
                                   │   ├ standards/*.md       │
                                   │   ├ incidents/*.md       │
                                   │   ├ integrations/*.md    │
                                   │   ├ domain-concepts/*.md │
                                   │   └ rejected/*.md        │
                                   │  .claude/skills/         │
                                   │   ├ promote-decision/    │
                                   │   ├ promote-module-kb/   │
                                   │   ├ promote-runbook/     │
                                   │   ├ link-kb-note/        │
                                   │   └ session-close/       │
                                   └─────────────┬────────────┘
                                                 │ git push / GH Action
                                                 ▼
┌────────────────────────────────────────────────────────────────────────────┐
│                            AskMyDocs (Laravel 13)                          │
│                                                                            │
│   POST /api/kb/ingest ─► IngestDocumentJob ─► DocumentIngestor            │
│                                                   ├─ MarkdownChunker v2   │
│                                                   │   (frontmatter + wl)  │
│                                                   ├─ EmbeddingCache       │
│                                                   └─ CanonicalIndexerJob  │
│                                                       ├─ kb_nodes upsert  │
│                                                       ├─ kb_edges rebuild │
│                                                       └─ audit log        │
│                                                                            │
│   POST /api/kb/chat ─► KbSearchService ─► Vector + FTS + Reranker         │
│                            │                                               │
│                            ├─ GraphExpander (1-hop, wikilink graph)       │
│                            ├─ RejectedApproachInjector                    │
│                            └─► kb_rag.blade.php (now: typed sections)     │
│                                                                            │
│   POST /api/kb/promotion/candidates  ─► CanonicalParser.validate()        │
│   POST /api/kb/promotion/promote     ─► CanonicalWriter.write() + ingest  │
│   POST /api/kb/promotion/suggest     ─► PromotionSuggest (LLM JSON)       │
│                                                                            │
│   MCP: enterprise-kb server (+5 new tools)                                 │
│   ├─ kb.graph.neighbors        ├─ kb.documents.by_slug                    │
│   ├─ kb.graph.subgraph         ├─ kb.documents.by_type                    │
│   └─ kb.promotion.suggest                                                  │
│                                                                            │
│   Artisan: kb:promote · kb:validate-canonical · kb:rebuild-graph          │
│   Scheduler: +kb:rebuild-graph daily at 03:40                              │
└────────────────────────────────────────────────────────────────────────────┘
```

### Canonical types (node_type) and relations (edge_type)

**9 node types:** `project`, `module`, `decision`, `runbook`, `standard`, `incident`, `integration`, `domain-concept`, `rejected-approach`.

**10 edge types:** `depends_on`, `uses`, `implements`, `related_to`, `supersedes`, `invalidated_by`, `decision_for`, `documented_by`, `affects`, `owned_by`.

### Canonical statuses
`draft`, `review`, `accepted`, `superseded`, `deprecated`, `archived`. Only `accepted` is boosted in retrieval; `superseded`/`deprecated`/`archived` are penalized; `rejected-approach` gets special injection treatment regardless of status.

---

## 3. Design Decisions (the 8 that shape everything)

### D1. Extend `knowledge_documents` — do NOT add parallel `kb_documents`

**Chosen:** add 8 nullable columns to `knowledge_documents`:
`doc_id` (stable business id like `DEC-2026-0001`, unique scoped by project), `slug` (unique scoped by project), `canonical_type` (enum), `canonical_status` (enum), `is_canonical` (bool, default false — back-compat), `retrieval_priority` (smallint 0-100, default 50), `source_of_truth` (bool, default true for is_canonical=true), `frontmatter_json` (jsonb, full parsed YAML).

**Rationale:** the repo already has one idempotency contract `(project_key, source_path, version_hash)`. Duplicating it into a parallel `kb_documents` table means double-write hell. Nullable columns are free in PG (TOAST).

### D2. Graph is its own layer — 3 new tables

`kb_nodes`, `kb_edges`, `kb_canonical_audit`. No embedding on nodes (the vector search stays on chunks). Nodes carry labels, project_code, and a JSON payload. Edges carry provenance (`wikilink` / `explicit_frontmatter` / `inferred`). Audit log records every canonical promote/update/delete for compliance.

### D3. Chunker v2, not v3

The current `MarkdownChunker` is a placeholder (splits on `\n{2,}`, `heading_path` always null). We rewrite it to real section-aware chunking using `league/commonmark` (to be added as dep), extracting:
- YAML frontmatter (if present) into a side-channel
- Markdown AST → section boundaries driven by H1/H2/H3 heading_path
- Wikilinks `[[slug]]` per chunk (persisted into chunk `metadata.wikilinks`)
- Target 512 tokens, hard cap 1024 (already in config, never honored).

This is a hidden win: **existing docs also get better chunking** once Phase 2 ships.

### D4. Graph expansion is additive to reranker, not a replacement

Retrieval stays: vector+FTS → Reranker. **After** the reranker returns top-K chunks, `GraphExpander` loads the parent documents of those chunks, walks `kb_edges` 1 hop (configurable `KB_GRAPH_EXPANSION_HOPS`), collects neighbor documents of types we care about (`decision`, `standard`, `runbook`, `rejected-approach`), pulls *their* best-matching chunk by vector, and merges into the final context block.

Fusion score update:
```
final_score = rerank_score
            + 0.003 * retrieval_priority         # 0..0.30 boost
            - 0.40 * (status == 'superseded')
            - 0.40 * (status == 'deprecated')
            - 0.60 * (status == 'archived')
```
Weights configurable: `KB_CANONICAL_PRIORITY_WEIGHT`, `KB_CANONICAL_SUPERSEDED_PENALTY`, etc.

### D5. Rejected-approach injection is a separate prompt slot

`RejectedApproachInjector` fetches the top-3 rejected-approaches whose best chunk has cosine ≥ `KB_REJECTED_MIN_SIMILARITY` (default 0.45) with the query. They're injected into `kb_rag.blade.php` under a new clearly-labeled block:

```
⚠ REJECTED APPROACHES (do not repeat — these were deliberately dismissed):
- [dec-X] rationale: ...
```

### D6. Promotion is always human-gated

`POST /api/kb/promotion/promote` writes the Markdown file to the KB disk and dispatches ingestion. The Claude skills **never** call `promote` directly — they only produce drafts via `POST /api/kb/promotion/candidates` (validates) and return the proposed path+content for the human to commit. This is non-negotiable (R4 + enterprise trust).

### D7. Multi-tenant scoping by `project_key` everywhere

`kb_nodes.project_code`, `kb_edges` inherit via node FKs, all queries filter by `project_key`. Cross-tenant leak is tested explicitly (Phase 3 has a dedicated feature test).

### D8. Skills are **templates**, not runtime components

The 5 Claude skills live under `.claude/skills/kb-canonical/` in the AskMyDocs repo as the **canonical template**. Consumers copy them into their own `.claude/skills/`. AskMyDocs doesn't auto-activate them (no circular "AskMyDocs documents itself via AskMyDocs").

---

## 4. File Structure

### Files to CREATE (46 files)

**Database (3):**
- `database/migrations/2026_04_22_000001_add_canonical_columns_to_knowledge_documents.php`
- `database/migrations/2026_04_22_000002_create_kb_nodes_and_edges_tables.php`
- `database/migrations/2026_04_22_000003_create_kb_canonical_audit_table.php`

**Test mirror migrations (3):**
- `tests/database/migrations/0001_01_01_000009_add_canonical_columns_to_knowledge_documents.php`
- `tests/database/migrations/0001_01_01_000010_create_kb_nodes_and_edges_tables.php`
- `tests/database/migrations/0001_01_01_000011_create_kb_canonical_audit_table.php`

**Models (3):**
- `app/Models/KbNode.php`
- `app/Models/KbEdge.php`
- `app/Models/KbCanonicalAudit.php`

**Enums / Value Objects (3):**
- `app/Support/Canonical/CanonicalType.php`
- `app/Support/Canonical/CanonicalStatus.php`
- `app/Support/Canonical/EdgeType.php`

**Services (6):**
- `app/Services/Kb/Canonical/CanonicalParser.php`
- `app/Services/Kb/Canonical/WikilinkExtractor.php`
- `app/Services/Kb/Canonical/CanonicalWriter.php`
- `app/Services/Kb/Canonical/PromotionSuggestService.php`
- `app/Services/Kb/Retrieval/GraphExpander.php`
- `app/Services/Kb/Retrieval/RejectedApproachInjector.php`

**Jobs (1):**
- `app/Jobs/CanonicalIndexerJob.php`

**Controllers (1):**
- `app/Http/Controllers/Api/KbPromotionController.php`

**Artisan commands (3):**
- `app/Console/Commands/KbPromoteCommand.php`
- `app/Console/Commands/KbValidateCanonicalCommand.php`
- `app/Console/Commands/KbRebuildGraphCommand.php`

**MCP tools (5):**
- `app/Mcp/Tools/KbGraphNeighborsTool.php`
- `app/Mcp/Tools/KbGraphSubgraphTool.php`
- `app/Mcp/Tools/KbDocumentBySlugTool.php`
- `app/Mcp/Tools/KbDocumentsByTypeTool.php`
- `app/Mcp/Tools/KbPromotionSuggestTool.php`

**Claude skill templates (5):**
- `.claude/skills/kb-canonical/promote-decision/SKILL.md`
- `.claude/skills/kb-canonical/promote-module-kb/SKILL.md`
- `.claude/skills/kb-canonical/promote-runbook/SKILL.md`
- `.claude/skills/kb-canonical/link-kb-note/SKILL.md`
- `.claude/skills/kb-canonical/session-close/SKILL.md`

**Repository-level skills for R10 (1):**
- `.claude/skills/canonical-awareness/SKILL.md`

**ADRs (3):**
- `docs/adr/0001-canonical-knowledge-layer.md`
- `docs/adr/0002-knowledge-graph-model.md`
- `docs/adr/0003-promotion-pipeline.md`

**Tests (9 files, see Phase sections for each):**
- `tests/Unit/Kb/Canonical/CanonicalParserTest.php`
- `tests/Unit/Kb/Canonical/WikilinkExtractorTest.php`
- `tests/Unit/Kb/Canonical/CanonicalWriterTest.php`
- `tests/Unit/Kb/Retrieval/GraphExpanderTest.php`
- `tests/Unit/Kb/Retrieval/RejectedApproachInjectorTest.php`
- `tests/Feature/Jobs/CanonicalIndexerJobTest.php`
- `tests/Feature/Api/KbPromotionControllerTest.php`
- `tests/Feature/Commands/KbPromoteCommandTest.php`
- `tests/Feature/Commands/KbRebuildGraphCommandTest.php`

### Files to MODIFY

- `composer.json` — add `league/commonmark ^2.5` + `symfony/yaml ^8.0` (already pulled as transient).
- `config/kb.php` — add `canonical`, `graph`, `rejected` blocks.
- `.env.example` — add 18 new `KB_CANONICAL_*`, `KB_GRAPH_*`, `KB_REJECTED_*` vars.
- `app/Models/KnowledgeDocument.php` — add fillable columns + casts + scopes (`canonical()`, `accepted()`, `byType()`, `bySlug()`).
- `app/Services/Kb/MarkdownChunker.php` — rewrite with AST + frontmatter + wikilinks.
- `app/Services/Kb/DocumentIngestor.php` — dispatch `CanonicalIndexerJob` after successful ingestion when `is_canonical=true`.
- `app/Services/Kb/KbSearchService.php` — wire `GraphExpander` + `RejectedApproachInjector` behind feature flags.
- `app/Services/Kb/Reranker.php` — accept per-document canonical boost/penalty in scoring.
- `app/Http/Controllers/Api/KbChatController.php` — pass `$rejectedChunks` to the prompt view.
- `resources/views/prompts/kb_rag.blade.php` — add `⚠ REJECTED APPROACHES` block + typed section markers.
- `app/Services/Kb/DocumentDeleter.php` — cascade `kb_nodes`/`kb_edges`/`kb_canonical_audit` rows.
- `app/Mcp/Servers/KnowledgeBaseServer.php` — register 5 new tools in `$tools`.
- `routes/api.php` — add `/api/kb/promotion/*` routes.
- `app/Providers/AppServiceProvider.php` — register 3 new commands.
- `bootstrap/app.php` — add `kb:rebuild-graph` to scheduler at 03:40.
- `CLAUDE.md` — add §4 kb_nodes/kb_edges schema, §7 R10 rule.
- `.github/copilot-instructions.md` — mirror.
- `README.md` — add "Canonical Knowledge Compilation" section (see §10 of this plan).
- `.github/actions/ingest-to-askmydocs/action.yml` — recognize typed folder patterns (decisions/, runbooks/, ...).

---

## 5. Phase Breakdown & PR Strategy

```
feature/kb-canonical-compilation  (integration branch, target of all phase PRs)
├── PR #9   phase 0: foundations + ADRs + composer deps + config + env
├── PR #10  phase 1: data model extension (migrations + models + enums)
├── PR #11  phase 2: canonical parsing + section-aware chunker + indexer
├── PR #12  phase 3: graph-aware retrieval + rejected injection + prompt
├── PR #13  phase 4: promotion API + CLI + writer
├── PR #14  phase 5: MCP tools expansion
├── PR #15  phase 6: Claude skill templates + GH Action update
└── PR #16  phase 7: README + CLAUDE.md + ADR polish
```

Each PR must pass: `vendor/bin/phpunit` + `npm test` + CI + local smoke test. Each PR gets its own commit history (bite-sized commits per task below).

---

## Phase 0: Foundations, ADRs & Config Surface (PR #9)

**Goal:** Land the scaffolding (deps, config keys, env docs, ADRs) that all later phases depend on. **Zero runtime impact** — no code paths activated.

**Acceptance:** existing 162 PHPUnit tests still green; new config keys have defaults identical to off; 3 ADRs merged; `composer install` from scratch works.

### Task 0.1: Add composer deps

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Add league/commonmark + symfony/yaml to require block**

Edit `composer.json` require block:

```json
    "require": {
        "php": "^8.3",
        "laravel/framework": "^13.0",
        "laravel/sanctum": "^4.2",
        "league/commonmark": "^2.5",
        "symfony/yaml": "^8.0"
    },
```

- [ ] **Step 2: Run composer update with PHP 8.4 shim**

Run: `composer update league/commonmark symfony/yaml --no-interaction --ansi`
Expected: both resolved, no conflicts with Laravel 13.

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock 2>/dev/null || git add composer.json
git commit -m "chore(kb-canonical): add commonmark + yaml deps"
```

### Task 0.2: Extend config/kb.php with 3 new blocks

**Files:**
- Modify: `config/kb.php`

- [ ] **Step 1: Append canonical/graph/rejected blocks**

Append to `config/kb.php` before the closing `];`:

```php
    'canonical' => [
        'enabled' => env('KB_CANONICAL_ENABLED', true),
        'default_type' => env('KB_CANONICAL_DEFAULT_TYPE', null), // null = non canonical docs stay non canonical
        'priority_weight' => (float) env('KB_CANONICAL_PRIORITY_WEIGHT', 0.003),
        'superseded_penalty' => (float) env('KB_CANONICAL_SUPERSEDED_PENALTY', 0.40),
        'deprecated_penalty' => (float) env('KB_CANONICAL_DEPRECATED_PENALTY', 0.40),
        'archived_penalty' => (float) env('KB_CANONICAL_ARCHIVED_PENALTY', 0.60),
        'audit_enabled' => env('KB_CANONICAL_AUDIT_ENABLED', true),
    ],

    'graph' => [
        'expansion_enabled' => env('KB_GRAPH_EXPANSION_ENABLED', true),
        'expansion_hops' => (int) env('KB_GRAPH_EXPANSION_HOPS', 1),
        'expansion_max_nodes' => (int) env('KB_GRAPH_EXPANSION_MAX_NODES', 20),
        'expansion_edge_types' => explode(',', env('KB_GRAPH_EXPANSION_EDGE_TYPES', 'depends_on,implements,decision_for,related_to,supersedes')),
    ],

    'rejected' => [
        'injection_enabled' => env('KB_REJECTED_INJECTION_ENABLED', true),
        'injection_max_docs' => (int) env('KB_REJECTED_INJECTION_MAX_DOCS', 3),
        'min_similarity' => (float) env('KB_REJECTED_MIN_SIMILARITY', 0.45),
    ],

    'promotion' => [
        'enabled' => env('KB_PROMOTION_ENABLED', true),
        'path_conventions' => [
            'decision' => 'decisions',
            'module-kb' => 'modules',
            'runbook' => 'runbooks',
            'standard' => 'standards',
            'incident' => 'incidents',
            'integration' => 'integrations',
            'domain-concept' => 'domain-concepts',
            'rejected-approach' => 'rejected',
            'project-index' => '.',
        ],
    ],
```

- [ ] **Step 2: Mirror in `.env.example`**

Append to `.env.example`:

```bash
# ─── Canonical KB (new: compilation layer) ───────────────────────────────────
KB_CANONICAL_ENABLED=true
KB_CANONICAL_PRIORITY_WEIGHT=0.003
KB_CANONICAL_SUPERSEDED_PENALTY=0.40
KB_CANONICAL_DEPRECATED_PENALTY=0.40
KB_CANONICAL_ARCHIVED_PENALTY=0.60
KB_CANONICAL_AUDIT_ENABLED=true

# ─── Knowledge graph (new: wikilink-derived) ─────────────────────────────────
KB_GRAPH_EXPANSION_ENABLED=true
KB_GRAPH_EXPANSION_HOPS=1
KB_GRAPH_EXPANSION_MAX_NODES=20
KB_GRAPH_EXPANSION_EDGE_TYPES=depends_on,implements,decision_for,related_to,supersedes

# ─── Anti-repetition memory (new) ────────────────────────────────────────────
KB_REJECTED_INJECTION_ENABLED=true
KB_REJECTED_INJECTION_MAX_DOCS=3
KB_REJECTED_MIN_SIMILARITY=0.45

# ─── Promotion pipeline (new) ────────────────────────────────────────────────
KB_PROMOTION_ENABLED=true
```

- [ ] **Step 3: Run existing tests to confirm no regression**

Run: `vendor/bin/phpunit`
Expected: `OK (162 tests, 470 assertions)`.

- [ ] **Step 4: Commit**

```bash
git add config/kb.php .env.example
git commit -m "feat(kb-canonical): phase 0 - config + env surface for canonical/graph/rejected/promotion"
```

### Task 0.3: Write 3 ADRs

**Files:**
- Create: `docs/adr/0001-canonical-knowledge-layer.md`
- Create: `docs/adr/0002-knowledge-graph-model.md`
- Create: `docs/adr/0003-promotion-pipeline.md`

- [ ] **Step 1: Write ADR 0001**

Create `docs/adr/0001-canonical-knowledge-layer.md`:

```markdown
# ADR 0001 — Canonical knowledge layer inside `knowledge_documents`

Date: 2026-04-22
Status: accepted

## Context
AskMyDocs indexes generic markdown into `knowledge_documents` + `knowledge_chunks`.
Customers want typed documents (decision, runbook, standard, ...) with stable ids,
statuses, and editorial metadata.

## Decision
Extend `knowledge_documents` with 8 nullable columns (`doc_id`, `slug`,
`canonical_type`, `canonical_status`, `is_canonical`, `retrieval_priority`,
`source_of_truth`, `frontmatter_json`). Do NOT add a parallel `kb_documents` table.

## Rationale
Single idempotency contract `(project_key, source_path, version_hash)` avoids double-write.
Nullable columns are free in PG. Every retrieval pipeline already queries this table.

## Consequences
- Migration is backward compatible (all new columns nullable, sensible defaults).
- Eloquent scopes `canonical()`, `accepted()`, `byType()`, `bySlug()` added.
- DocumentIngestor gains a canonical-aware branch when frontmatter is present.
- No breaking changes for existing consumers.

## Alternatives considered
- Parallel `kb_documents` table (rejected: double-write + SoT split).
- Polymorphic `documentable` relation (rejected: over-engineered for this use case).
```

- [ ] **Step 2: Write ADR 0002**

Create `docs/adr/0002-knowledge-graph-model.md`:

```markdown
# ADR 0002 — Lightweight knowledge graph via kb_nodes / kb_edges

Date: 2026-04-22
Status: accepted

## Context
Flat-chunk RAG has no explicit relations. Customers need decision-for, depends-on,
supersedes, related-to navigation, both for retrieval expansion and for UI graph views.

## Decision
Introduce `kb_nodes` (9 types) and `kb_edges` (10 types), populated by parsing
`[[wikilink]]` tokens from canonical markdown. No embeddings on nodes; nodes are
relational metadata only. Project-scoped (`project_code` on every row).

## Rationale
- Neo4j / dedicated graph DB is overkill at current scale (<1M edges expected per tenant).
- Wikilink syntax is human-writable and Obsidian-compatible.
- Postgres adjacency-list is O(1) for 1-hop, sufficient for RAG expansion.

## Consequences
- 3 new tables + 1 new job (`CanonicalIndexerJob`).
- `kb:rebuild-graph` command for full recompile on schema changes.
- Dangling wikilinks tracked as `kb_nodes.payload_json.dangling = true`.

## Alternatives considered
- Neo4j (rejected: infra complexity, unjustified at this scale).
- JSON column with relations inside `knowledge_documents.metadata` (rejected: no indexing, no cross-doc queries).
```

- [ ] **Step 3: Write ADR 0003**

Create `docs/adr/0003-promotion-pipeline.md`:

```markdown
# ADR 0003 — Human-gated promotion pipeline

Date: 2026-04-22
Status: accepted

## Context
LLMs hallucinate. OmegaWiki's "skills read+write the wiki" must be adapted for
enterprise trust: no automatic writes to canonical storage.

## Decision
Three-stage API:
1. `POST /api/kb/promotion/suggest` — LLM extracts candidate artifacts from a transcript; returns structured JSON, writes nothing.
2. `POST /api/kb/promotion/candidates` — validates a draft against CanonicalParser schema; returns errors or a "valid" verdict; writes nothing.
3. `POST /api/kb/promotion/promote` — writes Markdown to the KB disk and dispatches ingest. Requires explicit client action (never called by skills directly).

Claude skills stop at step 1 and 2. A human commits or calls step 3.

## Rationale
- Trust boundary: LLM output never mutates source-of-truth without a human.
- Auditability: every step logs to `kb_canonical_audit`.
- R4 compliance: `Storage::put()` return value checked before emitting 202.

## Consequences
- 5 Claude skills produce *drafts*, not canonical commits.
- `KbPromotionController` exposes 3 endpoints, all Sanctum-protected.
- `kb:promote` CLI is the only server-side way to actually write (for operators, not LLMs).
```

- [ ] **Step 4: Commit**

```bash
git add docs/adr/
git commit -m "docs(kb-canonical): phase 0 - ADR 0001/0002/0003 for canonical layer, graph, promotion"
```

### Task 0.4: Open Phase 0 PR

- [ ] **Step 1: Push branch and open PR**

```bash
git push -u origin feature/kb-canonical-compilation
gh pr create --base main --title "feat(kb): phase 0 - foundations (config, env, ADRs, deps)" \
  --body "See docs/adr/0001..0003. Zero runtime impact. All 162 tests green."
```

Expected: PR opened against `main`, tests CI green.

---

## Phase 1: Data Model Extension (PR #10)

**Goal:** Ship the 3 migrations + 3 models + 3 enums + scopes on `KnowledgeDocument`. Zero behavior change (no code reads the new columns yet).

**Acceptance:** migrations up/down clean on SQLite and PG; `KnowledgeDocument::canonical()` scope returns 0 rows (nothing is canonical yet); new tables empty.

### Task 1.1: Enum — CanonicalType

**Files:**
- Create: `app/Support/Canonical/CanonicalType.php`
- Test: `tests/Unit/Kb/Canonical/CanonicalTypeTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Kb/Canonical/CanonicalTypeTest.php`:

```php
<?php

namespace Tests\Unit\Kb\Canonical;

use App\Support\Canonical\CanonicalType;
use PHPUnit\Framework\TestCase;

class CanonicalTypeTest extends TestCase
{
    public function test_all_9_types_exist(): void
    {
        $cases = CanonicalType::cases();
        $this->assertCount(9, $cases);
    }

    public function test_tryFrom_accepts_decision(): void
    {
        $this->assertSame(CanonicalType::Decision, CanonicalType::tryFrom('decision'));
    }

    public function test_tryFrom_rejects_unknown(): void
    {
        $this->assertNull(CanonicalType::tryFrom('unknown-type'));
    }

    public function test_pathPrefix_returns_canonical_folder(): void
    {
        $this->assertSame('decisions', CanonicalType::Decision->pathPrefix());
        $this->assertSame('rejected', CanonicalType::RejectedApproach->pathPrefix());
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit tests/Unit/Kb/Canonical/CanonicalTypeTest.php`
Expected: FAIL `Class CanonicalType not found`.

- [ ] **Step 3: Implement CanonicalType**

Create `app/Support/Canonical/CanonicalType.php`:

```php
<?php

namespace App\Support\Canonical;

enum CanonicalType: string
{
    case ProjectIndex = 'project-index';
    case Module = 'module-kb';
    case Decision = 'decision';
    case Runbook = 'runbook';
    case Standard = 'standard';
    case Incident = 'incident';
    case Integration = 'integration';
    case DomainConcept = 'domain-concept';
    case RejectedApproach = 'rejected-approach';

    public function pathPrefix(): string
    {
        return match ($this) {
            self::ProjectIndex => '.',
            self::Module => 'modules',
            self::Decision => 'decisions',
            self::Runbook => 'runbooks',
            self::Standard => 'standards',
            self::Incident => 'incidents',
            self::Integration => 'integrations',
            self::DomainConcept => 'domain-concepts',
            self::RejectedApproach => 'rejected',
        };
    }

    public function nodeType(): string
    {
        return match ($this) {
            self::ProjectIndex => 'project',
            self::Module => 'module',
            self::Decision => 'decision',
            self::Runbook => 'runbook',
            self::Standard => 'standard',
            self::Incident => 'incident',
            self::Integration => 'integration',
            self::DomainConcept => 'domain-concept',
            self::RejectedApproach => 'rejected-approach',
        };
    }
}
```

- [ ] **Step 4: Run test, expect pass**

Run: `vendor/bin/phpunit tests/Unit/Kb/Canonical/CanonicalTypeTest.php`
Expected: 4 tests green.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Canonical/CanonicalType.php tests/Unit/Kb/Canonical/CanonicalTypeTest.php
git commit -m "feat(kb-canonical): phase 1 - CanonicalType enum (9 types)"
```

### Task 1.2: Enums — CanonicalStatus + EdgeType

**Files:**
- Create: `app/Support/Canonical/CanonicalStatus.php`
- Create: `app/Support/Canonical/EdgeType.php`
- Test: `tests/Unit/Kb/Canonical/CanonicalStatusTest.php`
- Test: `tests/Unit/Kb/Canonical/EdgeTypeTest.php`

- [ ] **Step 1: Write tests for both enums**

Create `tests/Unit/Kb/Canonical/CanonicalStatusTest.php`:

```php
<?php

namespace Tests\Unit\Kb\Canonical;

use App\Support\Canonical\CanonicalStatus;
use PHPUnit\Framework\TestCase;

class CanonicalStatusTest extends TestCase
{
    public function test_all_6_statuses_exist(): void
    {
        $this->assertCount(6, CanonicalStatus::cases());
    }

    public function test_accepted_is_boosted(): void
    {
        $this->assertTrue(CanonicalStatus::Accepted->isRetrievable());
    }

    public function test_superseded_is_penalized(): void
    {
        $this->assertFalse(CanonicalStatus::Superseded->isRetrievable());
    }
}
```

Create `tests/Unit/Kb/Canonical/EdgeTypeTest.php`:

```php
<?php

namespace Tests\Unit\Kb\Canonical;

use App\Support\Canonical\EdgeType;
use PHPUnit\Framework\TestCase;

class EdgeTypeTest extends TestCase
{
    public function test_all_10_edge_types_exist(): void
    {
        $this->assertCount(10, EdgeType::cases());
    }

    public function test_defaultWeight_varies_by_type(): void
    {
        $this->assertSame(1.0, EdgeType::DecisionFor->defaultWeight());
        $this->assertSame(0.5, EdgeType::RelatedTo->defaultWeight());
    }
}
```

- [ ] **Step 2: Run tests, expect failure**

Run: `vendor/bin/phpunit tests/Unit/Kb/Canonical/`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement CanonicalStatus**

Create `app/Support/Canonical/CanonicalStatus.php`:

```php
<?php

namespace App\Support\Canonical;

enum CanonicalStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Accepted = 'accepted';
    case Superseded = 'superseded';
    case Deprecated = 'deprecated';
    case Archived = 'archived';

    public function isRetrievable(): bool
    {
        return in_array($this, [self::Accepted, self::Review], true);
    }

    public function penaltyWeight(): float
    {
        return match ($this) {
            self::Superseded => (float) config('kb.canonical.superseded_penalty', 0.40),
            self::Deprecated => (float) config('kb.canonical.deprecated_penalty', 0.40),
            self::Archived => (float) config('kb.canonical.archived_penalty', 0.60),
            default => 0.0,
        };
    }
}
```

- [ ] **Step 4: Implement EdgeType**

Create `app/Support/Canonical/EdgeType.php`:

```php
<?php

namespace App\Support\Canonical;

enum EdgeType: string
{
    case DependsOn = 'depends_on';
    case Uses = 'uses';
    case Implements = 'implements';
    case RelatedTo = 'related_to';
    case Supersedes = 'supersedes';
    case InvalidatedBy = 'invalidated_by';
    case DecisionFor = 'decision_for';
    case DocumentedBy = 'documented_by';
    case Affects = 'affects';
    case OwnedBy = 'owned_by';

    public function defaultWeight(): float
    {
        return match ($this) {
            self::DecisionFor, self::Implements, self::Supersedes => 1.0,
            self::DependsOn, self::Uses, self::Affects => 0.8,
            self::DocumentedBy, self::InvalidatedBy, self::OwnedBy => 0.7,
            self::RelatedTo => 0.5,
        };
    }
}
```

- [ ] **Step 5: Run tests, expect pass**

Run: `vendor/bin/phpunit tests/Unit/Kb/Canonical/`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add app/Support/Canonical/ tests/Unit/Kb/Canonical/
git commit -m "feat(kb-canonical): phase 1 - CanonicalStatus + EdgeType enums"
```

### Task 1.3: Migration — add 8 canonical columns to `knowledge_documents`

**Files:**
- Create: `database/migrations/2026_04_22_000001_add_canonical_columns_to_knowledge_documents.php`
- Create: `tests/database/migrations/0001_01_01_000009_add_canonical_columns_to_knowledge_documents.php`
- Test: `tests/Unit/Migrations/AddCanonicalColumnsMigrationTest.php`

- [ ] **Step 1: Write migration test**

Create `tests/Unit/Migrations/AddCanonicalColumnsMigrationTest.php`:

```php
<?php

namespace Tests\Unit\Migrations;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddCanonicalColumnsMigrationTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_canonical_columns_exist(): void
    {
        foreach (['doc_id','slug','canonical_type','canonical_status','is_canonical','retrieval_priority','source_of_truth','frontmatter_json'] as $col) {
            $this->assertTrue(Schema::hasColumn('knowledge_documents', $col), "missing column: $col");
        }
    }

    public function test_canonical_columns_are_nullable(): void
    {
        // Insert a plain (non-canonical) row — should succeed with all canonical cols null/default
        \DB::table('knowledge_documents')->insert([
            'project_key' => 'test',
            'source_type' => 'markdown',
            'title' => 'x',
            'source_path' => 'x.md',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseCount('knowledge_documents', 1);
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/phpunit tests/Unit/Migrations/AddCanonicalColumnsMigrationTest.php`
Expected: FAIL "missing column: doc_id".

- [ ] **Step 3: Implement production migration**

Create `database/migrations/2026_04_22_000001_add_canonical_columns_to_knowledge_documents.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->string('doc_id', 128)->nullable()->after('id')->index();
            $table->string('slug', 255)->nullable()->after('doc_id')->index();
            $table->string('canonical_type', 64)->nullable()->after('source_type')->index();
            $table->string('canonical_status', 64)->nullable()->after('canonical_type')->index();
            $table->boolean('is_canonical')->default(false)->after('canonical_status')->index();
            $table->unsignedSmallInteger('retrieval_priority')->default(50)->after('is_canonical');
            $table->boolean('source_of_truth')->default(true)->after('retrieval_priority');
            $table->json('frontmatter_json')->nullable()->after('metadata');

            $table->unique(['project_key', 'doc_id'], 'uq_kb_doc_doc_id');
            $table->unique(['project_key', 'slug'], 'uq_kb_doc_slug');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropUnique('uq_kb_doc_doc_id');
            $table->dropUnique('uq_kb_doc_slug');
            $table->dropColumn([
                'doc_id','slug','canonical_type','canonical_status',
                'is_canonical','retrieval_priority','source_of_truth','frontmatter_json',
            ]);
        });
    }
};
```

- [ ] **Step 4: Implement test-mirror migration**

Create `tests/database/migrations/0001_01_01_000009_add_canonical_columns_to_knowledge_documents.php` identical to production (SQLite supports all types used).

- [ ] **Step 5: Run test, expect pass**

Run: `vendor/bin/phpunit tests/Unit/Migrations/AddCanonicalColumnsMigrationTest.php`
Expected: 2 tests green.

- [ ] **Step 6: Run full suite**

Run: `vendor/bin/phpunit`
Expected: `OK (164 tests, ~474 assertions)`.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_04_22_000001_add_canonical_columns_to_knowledge_documents.php tests/database/migrations/0001_01_01_000009_add_canonical_columns_to_knowledge_documents.php tests/Unit/Migrations/AddCanonicalColumnsMigrationTest.php
git commit -m "feat(kb-canonical): phase 1 - add canonical columns to knowledge_documents"
```

### Task 1.4: Migration — kb_nodes + kb_edges tables

**Files:**
- Create: `database/migrations/2026_04_22_000002_create_kb_nodes_and_edges_tables.php`
- Create: `tests/database/migrations/0001_01_01_000010_create_kb_nodes_and_edges_tables.php`
- Test: part of `AddCanonicalColumnsMigrationTest` (extend with `test_kb_nodes_and_edges_tables_exist`)

- [ ] **Step 1: Extend migration test**

Add to `tests/Unit/Migrations/AddCanonicalColumnsMigrationTest.php`:

```php
    public function test_kb_nodes_and_edges_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('kb_nodes'));
        $this->assertTrue(Schema::hasTable('kb_edges'));
        foreach (['node_uid','node_type','label','project_code','source_doc_id','payload_json'] as $col) {
            $this->assertTrue(Schema::hasColumn('kb_nodes', $col));
        }
        foreach (['edge_uid','from_node_uid','to_node_uid','edge_type','weight','provenance','project_code'] as $col) {
            $this->assertTrue(Schema::hasColumn('kb_edges', $col));
        }
    }
```

- [ ] **Step 2: Run — expect FAIL**

Run: `vendor/bin/phpunit --filter kb_nodes`
Expected: FAIL.

- [ ] **Step 3: Write production migration**

Create `database/migrations/2026_04_22_000002_create_kb_nodes_and_edges_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kb_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('node_uid', 191)->unique();
            $table->string('node_type', 64)->index();
            $table->string('label', 255);
            $table->string('project_code', 120)->index();
            $table->string('source_doc_id', 128)->nullable()->index();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->index(['node_type', 'project_code']);
            $table->index(['label']);
        });

        Schema::create('kb_edges', function (Blueprint $table) {
            $table->id();
            $table->string('edge_uid', 191)->unique();
            $table->string('from_node_uid', 191)->index();
            $table->string('to_node_uid', 191)->index();
            $table->string('edge_type', 64)->index();
            $table->string('project_code', 120)->index();
            $table->string('source_doc_id', 128)->nullable()->index();
            $table->decimal('weight', 8, 4)->default(1.0);
            $table->string('provenance', 64)->default('wikilink');
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->foreign('from_node_uid')->references('node_uid')->on('kb_nodes')->onDelete('cascade');
            $table->foreign('to_node_uid')->references('node_uid')->on('kb_nodes')->onDelete('cascade');
            $table->index(['project_code', 'edge_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_edges');
        Schema::dropIfExists('kb_nodes');
    }
};
```

- [ ] **Step 4: Mirror in tests/database/migrations/**

Create `tests/database/migrations/0001_01_01_000010_create_kb_nodes_and_edges_tables.php` **identical to production**.

- [ ] **Step 5: Run tests, expect pass**

Run: `vendor/bin/phpunit tests/Unit/Migrations/`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_04_22_000002_create_kb_nodes_and_edges_tables.php tests/database/migrations/0001_01_01_000010_create_kb_nodes_and_edges_tables.php tests/Unit/Migrations/AddCanonicalColumnsMigrationTest.php
git commit -m "feat(kb-canonical): phase 1 - kb_nodes + kb_edges tables"
```

### Task 1.5: Migration — kb_canonical_audit

**Files:**
- Create: `database/migrations/2026_04_22_000003_create_kb_canonical_audit_table.php`
- Create: `tests/database/migrations/0001_01_01_000011_create_kb_canonical_audit_table.php`

- [ ] **Step 1: Write migration**

Columns: `id`, `project_key`, `doc_id` (nullable), `slug` (nullable), `event_type` (enum: `promoted`|`updated`|`deprecated`|`superseded`|`rejected_injection_used`|`graph_rebuild`), `actor` (string: user_id / command / system), `before_json`, `after_json`, `metadata_json`, `created_at`. Indexes on `(project_key, event_type)` and `(doc_id)`.

- [ ] **Step 2: Mirror + test + commit** (pattern identical to 1.3/1.4).

```bash
git commit -m "feat(kb-canonical): phase 1 - kb_canonical_audit table"
```

### Task 1.6: Eloquent models

**Files:**
- Create: `app/Models/KbNode.php`
- Create: `app/Models/KbEdge.php`
- Create: `app/Models/KbCanonicalAudit.php`
- Modify: `app/Models/KnowledgeDocument.php`
- Test: `tests/Unit/Kb/Canonical/KbNodeModelTest.php`

- [ ] **Step 1: Write model test**

Create `tests/Unit/Kb/Canonical/KbNodeModelTest.php`:

```php
<?php

namespace Tests\Unit\Kb\Canonical;

use App\Models\KbNode;
use App\Models\KbEdge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KbNodeModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_kb_node_can_be_created(): void
    {
        $node = KbNode::create([
            'node_uid' => 'dec-x',
            'node_type' => 'decision',
            'label' => 'X',
            'project_code' => 'proj',
        ]);
        $this->assertSame('dec-x', $node->node_uid);
    }

    public function test_outgoing_edges_relation(): void
    {
        KbNode::create(['node_uid' => 'a', 'node_type' => 'module', 'label' => 'A', 'project_code' => 'p']);
        KbNode::create(['node_uid' => 'b', 'node_type' => 'decision', 'label' => 'B', 'project_code' => 'p']);
        KbEdge::create([
            'edge_uid' => 'a->b',
            'from_node_uid' => 'a',
            'to_node_uid' => 'b',
            'edge_type' => 'decision_for',
            'project_code' => 'p',
        ]);

        $a = KbNode::where('node_uid','a')->first();
        $this->assertCount(1, $a->outgoingEdges);
    }
}
```

- [ ] **Step 2: Implement KbNode**

Create `app/Models/KbNode.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KbNode extends Model
{
    protected $fillable = ['node_uid','node_type','label','project_code','source_doc_id','payload_json'];
    protected $casts = ['payload_json' => 'array'];

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(KbEdge::class, 'from_node_uid', 'node_uid');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(KbEdge::class, 'to_node_uid', 'node_uid');
    }

    public function scopeForProject($q, string $projectKey)
    {
        return $q->where('project_code', $projectKey);
    }

    public function scopeOfType($q, string $type)
    {
        return $q->where('node_type', $type);
    }
}
```

- [ ] **Step 3: Implement KbEdge**

Create `app/Models/KbEdge.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KbEdge extends Model
{
    protected $fillable = ['edge_uid','from_node_uid','to_node_uid','edge_type','project_code','source_doc_id','weight','provenance','payload_json'];
    protected $casts = ['weight' => 'float', 'payload_json' => 'array'];

    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(KbNode::class, 'from_node_uid', 'node_uid');
    }

    public function toNode(): BelongsTo
    {
        return $this->belongsTo(KbNode::class, 'to_node_uid', 'node_uid');
    }

    public function scopeForProject($q, string $projectKey)
    {
        return $q->where('project_code', $projectKey);
    }

    public function scopeOfType($q, string $type)
    {
        return $q->where('edge_type', $type);
    }
}
```

- [ ] **Step 4: Implement KbCanonicalAudit**

Create `app/Models/KbCanonicalAudit.php` — fillable for all columns, casts `before_json`/`after_json`/`metadata_json` to array. `public $timestamps = false; created_at` only.

- [ ] **Step 5: Extend KnowledgeDocument model**

Modify `app/Models/KnowledgeDocument.php`:

```php
    protected $fillable = [
        // ... existing columns ...
        'doc_id', 'slug', 'canonical_type', 'canonical_status',
        'is_canonical', 'retrieval_priority', 'source_of_truth', 'frontmatter_json',
    ];

    protected $casts = [
        // ... existing ...
        'is_canonical' => 'bool',
        'source_of_truth' => 'bool',
        'retrieval_priority' => 'int',
        'frontmatter_json' => 'array',
    ];

    public function scopeCanonical($q)
    {
        return $q->where('is_canonical', true);
    }

    public function scopeAccepted($q)
    {
        return $q->where('canonical_status', 'accepted');
    }

    public function scopeByType($q, string $type)
    {
        return $q->where('canonical_type', $type);
    }

    public function scopeBySlug($q, string $projectKey, string $slug)
    {
        return $q->where('project_key', $projectKey)->where('slug', $slug);
    }
```

- [ ] **Step 6: Run all tests**

Run: `vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add app/Models/ tests/Unit/Kb/Canonical/KbNodeModelTest.php
git commit -m "feat(kb-canonical): phase 1 - Eloquent models + KnowledgeDocument scopes"
```

### Task 1.7: Open Phase 1 PR

- [ ] **Step 1: Push + PR**

```bash
git push
gh pr create --base main --title "feat(kb): phase 1 - data model extension (8 cols + 3 tables + models)" \
  --body "Extends knowledge_documents with canonical columns. Adds kb_nodes, kb_edges, kb_canonical_audit. Zero behavior change (no code reads the new columns yet)."
```

---

## Phase 2: Canonical Parsing + Section-Aware Chunker (PR #11)

**Goal:** `DocumentIngestor` recognizes canonical markdown (YAML frontmatter present) and populates canonical columns + chunks with wikilinks metadata + dispatches `CanonicalIndexerJob` which upserts nodes/edges.

**Acceptance:** a markdown file with frontmatter `type: decision, slug: dec-x, doc_id: DEC-2026-0001` → `knowledge_documents` row has `is_canonical=true`, canonical_type='decision'; chunks have `metadata.wikilinks` array; `kb_nodes` has the decision node; `kb_edges` has edges for every wikilink.

### Task 2.1: WikilinkExtractor

**Files:**
- Create: `app/Services/Kb/Canonical/WikilinkExtractor.php`
- Test: `tests/Unit/Kb/Canonical/WikilinkExtractorTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Kb/Canonical/WikilinkExtractorTest.php`:

```php
<?php

namespace Tests\Unit\Kb\Canonical;

use App\Services\Kb\Canonical\WikilinkExtractor;
use PHPUnit\Framework\TestCase;

class WikilinkExtractorTest extends TestCase
{
    public function test_extracts_simple_wikilinks(): void
    {
        $out = (new WikilinkExtractor())->extract("See [[dec-cache-v2]] and [[module-checkout]].");
        $this->assertSame(['dec-cache-v2', 'module-checkout'], $out);
    }

    public function test_deduplicates(): void
    {
        $out = (new WikilinkExtractor())->extract("[[a]] [[a]] [[b]]");
        $this->assertSame(['a', 'b'], $out);
    }

    public function test_ignores_code_blocks(): void
    {
        $md = "Prose [[keep]]. ```\n[[ignore]]\n```\nMore [[keep2]].";
        $this->assertSame(['keep', 'keep2'], (new WikilinkExtractor())->extract($md));
    }

    public function test_rejects_invalid_slugs(): void
    {
        $out = (new WikilinkExtractor())->extract("[[With Spaces]] [[UPPER]] [[valid-slug]]");
        $this->assertSame(['valid-slug'], $out);
    }
}
```

- [ ] **Step 2: Run, expect FAIL**

Run: `vendor/bin/phpunit tests/Unit/Kb/Canonical/WikilinkExtractorTest.php`

- [ ] **Step 3: Implement**

Create `app/Services/Kb/Canonical/WikilinkExtractor.php`:

```php
<?php

namespace App\Services\Kb\Canonical;

class WikilinkExtractor
{
    private const SLUG_REGEX = '/[a-z0-9][a-z0-9\-]*/';
    private const LINK_REGEX = '/\[\[([^\]]+)\]\]/';

    public function extract(string $markdown): array
    {
        // strip fenced code blocks
        $stripped = preg_replace('/```.*?```/s', '', $markdown) ?? '';
        // strip inline code
        $stripped = preg_replace('/`[^`]*`/', '', $stripped) ?? '';

        $out = [];
        if (preg_match_all(self::LINK_REGEX, $stripped, $matches)) {
            foreach ($matches[1] as $target) {
                if (preg_match('/^' . substr(self::SLUG_REGEX, 1, -1) . '$/', $target)) {
                    $out[$target] = true;
                }
            }
        }
        return array_keys($out);
    }
}
```

- [ ] **Step 4: Run test, expect pass**

- [ ] **Step 5: Commit**

```bash
git add app/Services/Kb/Canonical/WikilinkExtractor.php tests/Unit/Kb/Canonical/WikilinkExtractorTest.php
git commit -m "feat(kb-canonical): phase 2 - WikilinkExtractor with code-block stripping"
```

### Task 2.2: CanonicalParser (frontmatter + body split + schema validation)

**Files:**
- Create: `app/Services/Kb/Canonical/CanonicalParser.php`
- Test: `tests/Unit/Kb/Canonical/CanonicalParserTest.php`

- [ ] **Step 1: Write failing test**

Test covers: parse frontmatter + body; returns DTO with all canonical fields; validates required fields (`id`, `slug`, `type`, `status`); returns `ValidationResult` with errors when invalid; ignores non-canonical markdown (no frontmatter) → returns `null`.

Create the test with 8 test cases covering each path (parse OK, missing id, wrong type enum, missing slug, etc.).

- [ ] **Step 2: Implement CanonicalParser**

Create `app/Services/Kb/Canonical/CanonicalParser.php`:

Signature:
```php
public function parse(string $markdown): ?CanonicalParsedDocument // null = non-canonical
public function validate(CanonicalParsedDocument $doc): ValidationResult
```

Uses `Symfony\Component\Yaml\Yaml::parse()` on the `---\n...\n---` block. Returns a DTO with `$frontmatter`, `$body`, `$type: CanonicalType`, `$status: CanonicalStatus`, `$slug`, `$docId`, `$retrievalPriority`, `$relatedSlugs`, `$supersedesSlugs`, `$supersededBySlugs`, `$tags`, `$owners`, `$summary`.

- [ ] **Step 3: Run + Commit**

```bash
git commit -m "feat(kb-canonical): phase 2 - CanonicalParser (frontmatter + validator)"
```

### Task 2.3: Rewrite MarkdownChunker with AST + wikilink metadata

**Files:**
- Modify: `app/Services/Kb/MarkdownChunker.php`
- Test: `tests/Unit/Kb/MarkdownChunkerTest.php` (new) — 12+ cases covering section splitting, heading_path, wikilinks in metadata, token cap respect, frontmatter stripping before chunking, existing placeholder behavior preserved when no headings.

- [ ] **Step 1: Write failing test**

Key test methods:
- `test_splits_on_h2_headings()`
- `test_preserves_heading_path_breadcrumb()` — "# Root / ## Section A" → `heading_path = "Root > Section A"`
- `test_respects_hard_cap_tokens()` — oversized paragraph is further split
- `test_strips_frontmatter_before_chunking()` — YAML block is not in any chunk
- `test_attaches_wikilinks_to_chunk_metadata()` — chunk with `[[foo]]` and `[[bar]]` → `metadata.wikilinks = ['foo','bar']`
- `test_legacy_markdown_without_headings_falls_back_to_paragraph_split()` (preserve backward compat)

- [ ] **Step 2: Run, expect FAIL**

- [ ] **Step 3: Rewrite implementation**

Key logic: parse with `League\CommonMark\MarkdownConverter`, walk AST, accumulate nodes per section (H1/H2/H3 boundary), token-count check (approx `strlen($text)/4`), emit chunks with `heading_path` and `metadata.wikilinks` populated via `WikilinkExtractor`.

- [ ] **Step 4: Run full suite** — all existing tests must stay green (chunker is used by IngestDocumentJob + DocumentIngestor tests).

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(kb-canonical): phase 2 - MarkdownChunker v2 (AST, heading_path, wikilinks)"
```

### Task 2.4: CanonicalIndexerJob — upsert nodes & edges

**Files:**
- Create: `app/Jobs/CanonicalIndexerJob.php`
- Test: `tests/Feature/Jobs/CanonicalIndexerJobTest.php`

- [ ] **Step 1: Write feature test**

Test asserts: given a `KnowledgeDocument` with canonical frontmatter and chunks containing `[[a]], [[b]]`, after running the job: `kb_nodes` has 3 rows (self + a + b), `kb_edges` has 2 rows from self to a/b with `provenance=wikilink`, audit row logged. Dangling links (slug `a` doesn't exist yet) → `kb_nodes.payload_json.dangling = true`.

- [ ] **Step 2: Implement**

Constructor: `public readonly int $documentId`. `handle(CanonicalParser $parser)`: load doc → if `!is_canonical` return → atomic delete edges where `source_doc_id = doc.doc_id` → upsert self node → for each chunk wikilink set → upsert target node (create as dangling if slug not found) → upsert edge with project scope → write audit row.

Memory-safe (R3): use `chunkById(200)` when walking chunks.

- [ ] **Step 3: Commit**

```bash
git commit -m "feat(kb-canonical): phase 2 - CanonicalIndexerJob (wikilinks → kb_edges)"
```

### Task 2.5: Wire DocumentIngestor → dispatch CanonicalIndexerJob

**Files:**
- Modify: `app/Services/Kb/DocumentIngestor.php`
- Modify: `tests/Feature/Kb/DocumentIngestorTest.php`

- [ ] **Step 1: Write test for canonical branch**

New test method: `test_ingests_canonical_markdown_and_dispatches_indexer_job()`. Uses `Queue::fake()` + markdown with full frontmatter. Asserts `is_canonical=true`, `canonical_type='decision'`, `slug='dec-x'`, `doc_id='DEC-2026-0001'` persisted; `CanonicalIndexerJob::class` dispatched with correct `documentId`.

- [ ] **Step 2: Run, expect FAIL**

- [ ] **Step 3: Extend DocumentIngestor::ingestMarkdown()**

After computing hashes, call `CanonicalParser::parse($markdown)`. If result is non-null and valid:
- Set canonical columns on the `updateOrCreate` payload (`is_canonical`, `doc_id`, `slug`, `canonical_type`, `canonical_status`, `retrieval_priority`, `frontmatter_json`).
- After transaction commit, dispatch `CanonicalIndexerJob` with `$document->id`.

If parser returns validation errors: log warning, fall back to non-canonical ingestion (don't fail the job — R4 compliant degradation).

- [ ] **Step 4: Run all existing DocumentIngestorTest cases** — must stay green.

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(kb-canonical): phase 2 - DocumentIngestor canonical branch + indexer dispatch"
```

### Task 2.6: DocumentDeleter cascade to nodes/edges

**Files:**
- Modify: `app/Services/Kb/DocumentDeleter.php`
- Modify: `tests/Feature/Kb/DocumentDeleterTest.php`

- [ ] **Step 1: Add test**: hard-delete a canonical doc → `kb_nodes` row for its slug gone, all edges gone, audit row written with `event_type='deprecated'`.

- [ ] **Step 2: Extend `delete()` hard path**: after document + chunks deleted, within same transaction delete `kb_edges where source_doc_id = doc_id` and `kb_nodes where source_doc_id = doc_id`. Write audit row.

- [ ] **Step 3: Commit**

```bash
git commit -m "feat(kb-canonical): phase 2 - DocumentDeleter cascade to graph + audit"
```

### Task 2.7: Open Phase 2 PR

```bash
git push
gh pr create --base main --title "feat(kb): phase 2 - canonical parsing + section-aware chunker + graph indexer"
```

---

## Phase 3: Graph-Aware Retrieval + Rejected-Approach Injection (PR #12)

**Goal:** `KbSearchService` now performs graph expansion + rejected injection, `Reranker` applies canonical boost/penalty, prompt template includes the new blocks.

**Acceptance:** feature test with a decision + depends-on module + rejected-approach demonstrates: chat query returns answer grounded on the decision, the linked module chunks included as expansion, rejected-approach quoted in prompt with warning marker.

### Task 3.1: GraphExpander

**Files:**
- Create: `app/Services/Kb/Retrieval/GraphExpander.php`
- Test: `tests/Unit/Kb/Retrieval/GraphExpanderTest.php`

- [ ] **Step 1: Write test**

Signature:
```php
public function expand(Collection $seedChunks, string $projectKey): Collection
```

Test: given seed chunks from doc A, and `kb_edges (A → B decision_for)`, returns chunks including best chunk of B. Respects `KB_GRAPH_EXPANSION_HOPS=1`, `KB_GRAPH_EXPANSION_MAX_NODES=20`, edge type filter. Returns empty when disabled.

- [ ] **Step 2: Implement**

1. Collect unique `doc_id`s from `$seedChunks`.
2. Resolve seed nodes: `KbNode::forProject($projectKey)->whereIn('source_doc_id', $docIds)->get()`.
3. Walk edges: `KbEdge::forProject($projectKey)->whereIn('from_node_uid', $nodeUids)->whereIn('edge_type', $allowedEdgeTypes)->orderByDesc('weight')->limit($maxNodes)->get()`.
4. Load target nodes → load their `KnowledgeDocument` rows (scoped canonical + accepted).
5. For each neighbor doc, pick best chunk by **re-running a mini-vector search** against the original query vector OR picking `chunk_order=0` as heuristic. Use heuristic to avoid extra embedding calls.
6. Return merged collection with `source` metadata `['origin' => 'graph_expansion']`.

Memory-safe (R3): `array_chunk` the IN list to 1000.

- [ ] **Step 3: Commit**

```bash
git commit -m "feat(kb-canonical): phase 3 - GraphExpander (1-hop wikilink expansion)"
```

### Task 3.2: RejectedApproachInjector

**Files:**
- Create: `app/Services/Kb/Retrieval/RejectedApproachInjector.php`
- Test: `tests/Unit/Kb/Retrieval/RejectedApproachInjectorTest.php`

- [ ] **Step 1: Write test**

Signature:
```php
public function pick(array $queryEmbedding, string $projectKey, int $maxDocs = 3): Collection
```

Test: given 5 rejected-approach docs with varying similarity, returns top `$maxDocs` above `KB_REJECTED_MIN_SIMILARITY` threshold, empty when none above threshold or feature disabled.

- [ ] **Step 2: Implement**

Vector search against `knowledge_chunks` scoped to `knowledge_documents.canonical_type='rejected-approach' AND is_canonical=true AND canonical_status='accepted'`. Returns best chunk per document. Respects project_key.

- [ ] **Step 3: Commit**

```bash
git commit -m "feat(kb-canonical): phase 3 - RejectedApproachInjector"
```

### Task 3.3: Wire into KbSearchService

**Files:**
- Modify: `app/Services/Kb/KbSearchService.php`
- Modify: `tests/Feature/Kb/KbSearchServiceTest.php` (or create one)

- [ ] **Step 1: Add constructor deps**

```php
public function __construct(
    protected Reranker $reranker,
    protected GraphExpander $graphExpander,
    protected RejectedApproachInjector $rejectedInjector,
    protected EmbeddingCacheService $embeddingCache,
) {}
```

- [ ] **Step 2: Extend `search()` to return a DTO, not just a collection**

New return type: `SearchResult { public Collection $primary; public Collection $expanded; public Collection $rejected; public array $meta; }`. Back-compat: add `search()->primary` accessor path in KbChatController. Or — simpler — keep returning Collection but stash expanded/rejected into a property array readable via `latestResult(): SearchResult`.

Choose the **DTO approach** (cleaner). Update all callers: `KbChatController`, `KbSearchTool`, `KbSearchByProjectTool`.

- [ ] **Step 3: Run graph expansion conditionally**

```php
if (config('kb.graph.expansion_enabled')) {
    $expanded = $this->graphExpander->expand($reranked, $projectKey ?? '');
}
if (config('kb.rejected.injection_enabled')) {
    $rejected = $this->rejectedInjector->pick($queryEmbedding, $projectKey ?? '', config('kb.rejected.injection_max_docs'));
}
```

- [ ] **Step 4: Run full test suite** — fix any breakage in downstream callers.

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(kb-canonical): phase 3 - KbSearchService integrates graph + rejected"
```

### Task 3.4: Reranker canonical boost/penalty

**Files:**
- Modify: `app/Services/Kb/Reranker.php`
- Modify: `tests/Unit/Kb/RerankerTest.php`

- [ ] **Step 1: Test — canonical doc with `retrieval_priority=90, status=accepted` outranks non-canonical with same vector_score**

- [ ] **Step 2: Extend scoring**

```php
// After base rerank_score is computed:
if ($chunk['document']['is_canonical'] ?? false) {
    $priority = $chunk['document']['retrieval_priority'] ?? 50;
    $score += config('kb.canonical.priority_weight') * $priority;
    $status = $chunk['document']['canonical_status'] ?? null;
    if ($status === 'superseded') $score -= config('kb.canonical.superseded_penalty');
    if ($status === 'deprecated') $score -= config('kb.canonical.deprecated_penalty');
    if ($status === 'archived')   $score -= config('kb.canonical.archived_penalty');
}
```

- [ ] **Step 3: Commit**

```bash
git commit -m "feat(kb-canonical): phase 3 - Reranker canonical boost + penalty"
```

### Task 3.5: Update prompt template + KbChatController

**Files:**
- Modify: `resources/views/prompts/kb_rag.blade.php`
- Modify: `app/Http/Controllers/Api/KbChatController.php`

- [ ] **Step 1: Update Blade**

Add blocks (pseudocode):

```blade
@if($rejected->isNotEmpty())
⚠ REJECTED APPROACHES (do NOT repeat — these were deliberately dismissed):
@foreach($rejected as $r)
- [{{ $r['document']['slug'] }}] {{ $r['document']['title'] }}
  reason: {{ $r['document']['frontmatter_json']['summary'] ?? '(no rationale)' }}
@endforeach
@endif

@if($expanded->isNotEmpty())
📎 RELATED CONTEXT (graph-expanded):
@foreach($expanded as $e)
- [{{ $e['document']['slug'] }}] from edge type `{{ $e['meta']['edge_type'] ?? 'related_to' }}`:
  {{ Str::limit($e['chunk_text'], 300) }}
@endforeach
@endif

🔎 CONTEXT:
@foreach($chunks as $c)
  ...existing...
@endforeach
```

- [ ] **Step 2: Update KbChatController**

Pass `$rejected`, `$expanded`, `$chunks` (primary) separately to the view. Update citations to distinguish primary/expanded/rejected.

- [ ] **Step 3: Add feature test**

`tests/Feature/Api/KbChatCanonicalAwareTest.php`: seed canonical decision + module + rejected → post `/api/kb/chat` → assert response contains citations for all three categories.

- [ ] **Step 4: Commit**

```bash
git commit -m "feat(kb-canonical): phase 3 - kb_rag.blade.php + controller expose typed blocks"
```

### Task 3.6: Cross-tenant isolation test

**Files:**
- Create: `tests/Feature/Kb/MultiTenantGraphIsolationTest.php`

- [ ] **Step 1: Test**: create 2 projects A and B, each with a decision linking to a module inside its own project. Query chat endpoint for project A — assert zero chunks/edges from B in the response or in `kb_nodes`/`kb_edges` projection.

- [ ] **Step 2: Commit**

```bash
git commit -m "test(kb-canonical): phase 3 - multi-tenant graph isolation"
```

### Task 3.7: Open Phase 3 PR

```bash
git push
gh pr create --base main --title "feat(kb): phase 3 - graph-aware retrieval + rejected-approach injection"
```

---

## Phase 4: Promotion API + CLI + Writer (PR #13)

**Goal:** Human-gated promotion pipeline. 3 API endpoints + 2 Artisan commands + `CanonicalWriter` service.

### Task 4.1: CanonicalWriter — writes file to KB disk with R4 compliance

- [ ] Service signature: `write(string $projectKey, CanonicalParsedDocument $doc): string` → returns relative path on disk. Uses `config('kb.promotion.path_conventions')` to pick folder. Returns early throwing if `Storage::put()` returns false (R4).

### Task 4.2: PromotionSuggestService

- [ ] Signature: `suggest(string $projectKey, string $transcript, array $context = []): array`. Calls `AiManager::chat()` with a dedicated prompt (`resources/views/prompts/promotion_suggest.blade.php`) that instructs the LLM to return JSON `{ "candidates": [{ "type": "...", "slug_proposal": "...", "title_proposal": "...", "reason": "...", "related": [...] }] }`. Parses, validates, returns.

### Task 4.3: KbPromotionController

- [ ] 3 routes:
  - `POST /api/kb/promotion/candidates` → CanonicalParser::validate() → JSON `{valid, errors}`.
  - `POST /api/kb/promotion/promote` → CanonicalWriter::write() → dispatch IngestDocumentJob → JSON `{status: 'accepted', path, doc_id}` with 202.
  - `POST /api/kb/promotion/suggest` → PromotionSuggestService::suggest() → JSON `{candidates}`.

All Sanctum-protected. Request validation schemas use FormRequest classes.

### Task 4.4: Artisan `kb:promote` + `kb:validate-canonical` + `kb:rebuild-graph`

- [ ] `kb:promote {path} {--project=}` → reads MD file, runs CanonicalParser, promotes via CanonicalWriter (server-side operator tool).
- [ ] `kb:validate-canonical {--project=} {--path=*}` → walks KB disk, validates every canonical doc, prints errors.
- [ ] `kb:rebuild-graph {--project=}` → truncates `kb_nodes`/`kb_edges` for project, dispatches `CanonicalIndexerJob` for every canonical document (memory-safe chunkById).

Scheduler: add `kb:rebuild-graph` at 03:40 daily (no-op if zero canonicals) in `bootstrap/app.php`.

### Task 4.5: Tests + Commit + PR #13

Feature tests for each endpoint (including 422 for invalid frontmatter, 200 for valid, 202 for successful promote, 413 for oversized markdown). 

```bash
git push
gh pr create --base main --title "feat(kb): phase 4 - promotion API + CLI + writer"
```

---

## Phase 5: MCP Tools Expansion (PR #14)

**Goal:** 5 new MCP tools registered on `KnowledgeBaseServer`.

### Task 5.1: KbGraphNeighborsTool

- [ ] Input schema: `node_uid` (required), `project_key` (required), `edge_types` (array, nullable), `limit` (default 20).
- [ ] Output: `{ neighbors: [{ node_uid, node_type, label, edge_type, weight, document: {...} }] }`.
- [ ] Attributes: `#[IsReadOnly] #[IsIdempotent]`.

### Task 5.2: KbGraphSubgraphTool

- [ ] Input: `seed_slug`, `project_key`, `hops` (default 1, max 2), `max_nodes` (default 30).
- [ ] Output: subgraph with nodes[] + edges[]. Uses GraphExpander.

### Task 5.3: KbDocumentBySlugTool

- [ ] Input: `slug`, `project_key`.
- [ ] Output: full `KnowledgeDocument` + frontmatter + all chunks.

### Task 5.4: KbDocumentsByTypeTool

- [ ] Input: `type` (CanonicalType enum), `project_key`, `status_filter` (default: `accepted`), `limit` (default 50).
- [ ] Output: list of docs with slug/doc_id/title/summary/retrieval_priority.

### Task 5.5: KbPromotionSuggestTool

- [ ] Input: `transcript` (required, max 50k chars), `project_key`.
- [ ] Output: candidate artifacts array.
- [ ] Attribute: `#[IsDestructive]` **false** — only produces suggestions, writes nothing.

### Task 5.6: Register on KnowledgeBaseServer

Modify `app/Mcp/Servers/KnowledgeBaseServer.php`:

```php
protected array $tools = [
    KbSearchTool::class,
    KbReadDocumentTool::class,
    KbReadChunkTool::class,
    KbRecentChangesTool::class,
    KbSearchByProjectTool::class,
    // new:
    KbGraphNeighborsTool::class,
    KbGraphSubgraphTool::class,
    KbDocumentBySlugTool::class,
    KbDocumentsByTypeTool::class,
    KbPromotionSuggestTool::class,
];
```

### Task 5.7: MCP integration tests + PR #14

One test per tool. All with `Http::fake()` / mocked services. Commit, push, open PR.

---

## Phase 6: Claude Skill Templates + GH Action Update (PR #15)

**Goal:** Ship the 5 canonical skill templates under `.claude/skills/kb-canonical/` + add the R10 repo rule skill + update the GitHub composite action to recognize canonical folder patterns.

### Task 6.1: Ship 5 canonical skill templates

Copy (and slightly adapt for AskMyDocs repo context) the 5 SKILL.md files from `C:\Users\lopad\Downloads\omega_inspired_kb_templates\.claude\skills\*` into `.claude/skills/kb-canonical/{promote-decision, promote-module-kb, promote-runbook, link-kb-note, session-close}/SKILL.md`. Add a top-level `.claude/skills/kb-canonical/README.md` explaining that these are **consumer-side templates**, not active in this repo.

### Task 6.2: R10 skill — canonical-awareness

Create `.claude/skills/canonical-awareness/SKILL.md` following the repo's SKILL format (rule → why → patterns → counter-example → correct example). Rule: when editing code in KB paths, preserve canonical-awareness (check `is_canonical` flag before running non-canonical code paths; don't break `kb_nodes`/`kb_edges` invariants; never write to `canonical_type` without validating enum). Add "R10" entry to CLAUDE.md § 7.

### Task 6.3: Update GH composite action

Modify `.github/actions/ingest-to-askmydocs/action.yml`:
- Add a pattern-aware upload: when `kb/decisions/**.md`, `kb/runbooks/**.md`, etc. patterns are matched, log them as canonical with folder → type mapping for observability.
- The action itself does NOT interpret frontmatter; AskMyDocs backend does. Just add a log line + bump `action.yml` to v2.
- Keep `R5` hygiene (jq --rawfile, AMR filter, pattern lock-step).

### Task 6.4: PR #15

```bash
git push
gh pr create --base main --title "feat(kb): phase 6 - Claude skill templates + R10 + GH action v2"
```

---

## Phase 7: README + CLAUDE.md + Final Documentation (PR #16)

**Goal:** Comprehensive documentation update. This is where the **full README section** the user requested lands. See §10 below for the complete text.

### Task 7.1: Add "Canonical Knowledge Compilation" section to README.md

Insert new section between `## Smart Reranking` and `## Citations` in the features table, AND add a full dedicated chapter after `## Hybrid Search`. See §10 of this plan for the full text.

### Task 7.2: Update CLAUDE.md

- Add § 4 schemas: `knowledge_documents` canonical columns, `kb_nodes`, `kb_edges`, `kb_canonical_audit`.
- Add § 5 flows: Canonical ingestion flow, Graph expansion flow, Promotion flow.
- Add § 6 non-obvious decisions: "canonical markdown is the SoT, DB is a projection"; "promotion is always human-gated"; "rejected-approach injection is by design, turn off only if prompt token budget is tight".
- Add § 7 R10 rule: canonical-awareness.
- Update stack line to mention `league/commonmark` + `symfony/yaml`.

### Task 7.3: Update copilot-instructions.md

Mirror CLAUDE.md changes.

### Task 7.4: Update badges + main README headings

Add badges: `Canonical-KB`, `Knowledge-Graph`, `Anti-Repetition`.

### Task 7.5: Final PR #16

```bash
git push
gh pr create --base main --title "docs(kb): phase 7 - canonical compilation README + CLAUDE.md + R10"
```

---

## 6. Test Strategy

### Coverage targets
- Unit: every new class has ≥1 unit test class with ≥3 cases; enums test each `from()`/`tryFrom()` path; services mock external deps.
- Feature: full ingest path (raw → chunks → nodes → edges) + full chat path (query → vector + graph + rejected → prompt) + full promotion path (suggest → candidates → promote → ingest) + multi-tenant isolation + back-compat path (non-canonical docs still work as before).
- Integration: GH Action run (manual on fork) before merging Phase 6.
- No coverage of pgvector-specific SQL (SQLite swap handles it in tests).

### Test commit discipline
Every task above follows: write test → see fail → implement → see pass → commit. No "tests added later" anti-pattern.

### Deprecation signal
`phpunit.xml` already has `displayDetailsOnTestsThatTriggerDeprecations="true"` — we monitor for Laravel/PHP 8.4 deprecations introduced by the new dep chain.

---

## 7. Migration & Rollout Strategy

### Per-phase deploy posture
- **Phase 0 + 1**: safe to deploy immediately (zero runtime impact).
- **Phase 2**: deploys the new ingestion pipeline. First ingestion of canonical docs will dispatch extra jobs. Monitor `failed_jobs`.
- **Phase 3**: enables graph + rejected injection. **Config-gated on by default** but no-op until canonical docs exist. Monitor p95 latency of `/api/kb/chat`.
- **Phase 4**: API surface expansion. Sanctum token scope check.
- **Phase 5**: MCP clients need to re-negotiate tool list. Re-publish MCP manifest.
- **Phase 6**: no runtime impact.
- **Phase 7**: docs only.

### Rollback
Each phase PR is revertible on its own. Migrations all have explicit `down()`. Config flags (`KB_CANONICAL_ENABLED=false`) fully disable the feature without rollback.

### Backfill
Existing customers with no canonical markdown: nothing to backfill, graph stays empty, chat behaves identically.

Customers who want to canonicalize existing docs: run `kb:validate-canonical --project=<X>` to discover what's missing, then progressively add frontmatter and re-ingest (idempotent on `version_hash` — only changed docs rebuild).

---

## 8. Risk Register

| Risk | Severity | Mitigation | Phase |
|---|---|---|---|
| Adding columns to `knowledge_documents` causes Postgres lock | Medium | Use `ALTER TABLE ... ADD COLUMN ... NULL` (non-rewriting in PG); deploy in low-traffic window | 1 |
| CommonMark AST parsing is slower than paragraph split | Low | Benchmark on 10MB corpus; cache parsed AST per document version_hash | 2 |
| Dangling wikilinks inflate `kb_nodes` over time | Low | `kb:rebuild-graph` prunes dangling nodes with 0 incoming edges periodically | 2/4 |
| Graph expansion O(K × hops) latency regression | Medium | Cap `KB_GRAPH_EXPANSION_MAX_NODES=20` + benchmark p95 | 3 |
| Cross-tenant graph leak | High | Mandatory `project_key` filter + explicit test `MultiTenantGraphIsolationTest` | 3 |
| Rejected injection bloats prompt tokens | Medium | Default max=3, char-capped summaries, feature-flag off | 3 |
| LLM hallucinates in `promotion/suggest` output | High | Structured JSON schema validation; parser rejects malformed; human-gated always | 4 |
| MCP tool schema change breaks existing clients | Low | Only *add* tools, never mutate existing; version bump `KnowledgeBaseServer::$version` | 5 |
| Test mirror migrations drift from production | Medium | Migration test asserts column existence on SQLite; CI runs both | 1 |
| Skills confuse devs about trust boundary | Medium | Each SKILL.md has explicit "PRODUCES DRAFT ONLY — DOES NOT COMMIT" banner | 6 |
| `DocumentIngestor` exception on malformed frontmatter blocks all ingestion | High | Wrap parser in try/catch, degrade to non-canonical ingestion (R4) | 2 |
| New env vars not documented → wrong defaults in prod | High | R6 compliance: `.env.example` + `config/kb.php` + README in same PR | 0 |

---

## 9. Acceptance Criteria (per phase)

**Phase 0:** 162 existing tests green; 3 ADRs merged; `.env.example` + `config/kb.php` in lock-step.

**Phase 1:** +5 new tests green (enums + migration). Schema on SQLite + pgsql has all 8 + 3 tables. `KnowledgeDocument::canonical()` scope returns 0.

**Phase 2:** Ingesting `examples/decision-example.md` (from omega templates) produces: 1 `knowledge_documents` row (`is_canonical=true`), N chunks with populated `heading_path` + `metadata.wikilinks`, 1 `kb_node` (+ neighbors created as dangling), edges for each wikilink, 1 audit row.

**Phase 3:** p95 latency of `/api/kb/chat` on fixture corpus (50 canonical docs) ≤ baseline + 80ms. Multi-tenant isolation test green. Rejected-approach appears in prompt with `⚠` marker.

**Phase 4:** `POST /api/kb/promotion/promote` with valid body returns 202, writes MD to disk, dispatches ingest. Invalid frontmatter returns 422 with detailed errors. `kb:rebuild-graph` runs idempotently.

**Phase 5:** `enterprise-kb` MCP server now exposes 10 tools (5 existing + 5 new). Each tool integration-tested.

**Phase 6:** 5 skill templates + R10 skill present. GH action v2 released. Consumers can `cp -r .claude/skills/kb-canonical/ ../their-repo/.claude/skills/` and use immediately.

**Phase 7:** README has full Canonical Knowledge Compilation section (see §10). CLAUDE.md has new R10 rule. Copilot instructions mirrored.

---

## 10. README — New Section (full content, drop-in)

> Insert after the current `## Hybrid Search` chapter in `README.md`. This is the **exact** text to paste — no placeholders.

````markdown
---

## Canonical Knowledge Compilation (Knowledge Graph + Anti-Repetition Memory)

> **What a normal RAG can't do for you** — and what AskMyDocs now does.

A plain Retrieval-Augmented Generation system treats your documentation as a
pile of interchangeable chunks. It embeds them, searches by cosine similarity,
stuffs the top-K into a prompt, and calls an LLM. Every query rediscovers the
answer from zero. There is no typed memory, no navigation, no persistence of
what your team has **already decided**. Rejected approaches get re-proposed.
Decisions drift silently. The knowledge base is read-only — nothing is ever
*promoted*.

AskMyDocs `1.3+` adds a **knowledge compilation layer** on top of the RAG
pipeline, inspired by Karpathy's LLM-Wiki idea and adapted for enterprise
Git-based workflows. The result is a system that behaves less like "semantic
search" and more like a **living, typed, navigable corporate brain**.

### Key capabilities

| Capability | Plain RAG | Wiki + CLI-AI (e.g. OmegaWiki / Obsidian + Claude) | **AskMyDocs Canonical** |
|---|:---:|:---:|:---:|
| Semantic search over markdown | ✓ | partial | ✓ |
| Typed documents (decision, runbook, standard, ...) | ✗ | partial | **✓ (9 types)** |
| Stable business IDs (`DEC-2026-0001`) | ✗ | partial | ✓ |
| Canonical statuses (draft/accepted/superseded/...) | ✗ | partial | **✓ (6 statuses)** |
| Retrieval priority per document | ✗ | ✗ | ✓ |
| Lightweight knowledge graph (wikilinks → edges) | ✗ | partial | **✓ (10 relations)** |
| 1-hop graph expansion at retrieval | ✗ | ✗ | **✓** |
| Rejected-approach anti-repetition memory | ✗ | ✗ | **✓ (prompt-level)** |
| Soft-deletion with retention | ✗ | ✗ | ✓ |
| Human-gated promotion pipeline (raw → canonical) | ✗ | ✗ | **✓ (REST + CLI)** |
| Scalable indexed projection (pgvector + FTS) | ✓ | ✗ | ✓ |
| Multi-tenant (project_key scoping) | partial | ✗ | ✓ |
| Multi-provider AI (OpenAI/Anthropic/Gemini/...) | partial | ✗ | ✓ |
| MCP server with typed tools | ✗ | ✗ | **✓ (10 tools)** |
| Auditable editorial events | ✗ | ✗ | **✓ (kb_canonical_audit)** |
| Git-based source-of-truth | ✗ | ✓ | ✓ |
| Self-hosted / on-prem / EU-sovereign | varies | ✓ | ✓ |
| Enterprise auth (Sanctum) | ✗ | ✗ | ✓ |
| Cross-repo GitHub composite action | ✗ | ✗ | ✓ |

### How it works

Your team writes **canonical markdown** with a small YAML frontmatter:

```yaml
---
id: DEC-2026-0001
slug: dec-cache-invalidation-v2
type: decision
project: ecommerce-core
module: cache
status: accepted
owners: [platform-team]
retrieval_priority: 90
tags: [cache, invalidation, redis]
related:
  - [[module-cache-layer]]
  - [[runbook-purge-failure]]
summary: Official cache invalidation strategy using tagged and secondary keys.
---

# Decision: Cache invalidation v2

## Context
...

## Decision
Use tagged invalidation with fallback to secondary keys. See
[[module-cache-layer]] for the implementation and [[rejected-direct-cache-full-purge-on-price-change]]
for what we explicitly dismissed.
```

When AskMyDocs ingests this file:

1. **Frontmatter parsing** — `CanonicalParser` validates against 9 type schemas
   and 6 status enums.
2. **Section-aware chunking** — `MarkdownChunker v2` splits on H1/H2/H3 while
   preserving `heading_path` breadcrumbs.
3. **Wikilink extraction** — every `[[slug]]` becomes an edge in `kb_edges`.
   Dangling links (target not yet canonicalized) are tracked as placeholder
   nodes.
4. **Canonical projection** — `knowledge_documents` row carries the typed
   metadata; `kb_nodes` / `kb_edges` form the graph.
5. **Audit trail** — every promote/update/deprecate is logged in
   `kb_canonical_audit`.

### Graph-aware retrieval

When a user asks a question:

```
                    ┌─────────────────┐
   user query ─────►│ vector + FTS    │──► top-K chunks (primary)
                    │ Reranker fusion │
                    └────────┬────────┘
                             │
                             ├──► GraphExpander (1-hop)
                             │    walks kb_edges `depends_on`,
                             │    `decision_for`, `related_to`,
                             │    `implements`, `supersedes`
                             │    → pulls best chunk of each neighbor
                             │
                             └──► RejectedApproachInjector
                                  cosine-searches rejected-approach docs
                                  → top-3 injected with ⚠ marker

                    ┌─────────────────┐
   prompt   ◄───────│  primary +      │
                    │  expanded +     │
                    │  rejected       │
                    └─────────────────┘
```

The reranker now applies a **canonical boost** (priority × 0.003) and
**status penalties** (superseded −0.4, deprecated −0.4, archived −0.6) on top
of the existing vector/keyword/heading fusion.

### Anti-repetition memory — the one everyone forgets

This is the feature that makes the system **learn from what didn't work**.
When a question correlates (cosine ≥ 0.45) with a `type: rejected-approach`
document, that document is injected into the prompt under a clearly-labeled
block:

```
⚠ REJECTED APPROACHES (do NOT repeat — these were deliberately dismissed):
- [rejected-direct-cache-full-purge-on-price-change]
  reason: Too expensive and noisy CDN-side and backend-side. Flooded the
  origin during flash sales.
```

The LLM sees the rejected options **before** generating its answer. It stops
proposing them. This single change is why your team stops re-hashing the
same tradeoffs every quarter.

### Promotion pipeline — from session to canonical

Conversations, incident post-mortems and code reviews produce knowledge that
usually evaporates. The promotion pipeline captures it:

```
  raw session transcript
         │
         ▼
  POST /api/kb/promotion/suggest
         │ (LLM extracts candidate artifacts as structured JSON;
         │  writes NOTHING)
         ▼
  [ candidate: { type: 'decision', slug_proposal: 'dec-X',
                 title_proposal: '...', reason: '...' } ]
         │
         ▼
  Claude skill `promote-decision` renders a full draft with frontmatter
         │ (writes to developer's filesystem as draft — still NOT canonical)
         ▼
  Human reviews, adjusts, commits to Git
         │
         ▼
  GitHub Action ingest-to-askmydocs (v2) detects canonical folder patterns
         │
         ▼
  POST /api/kb/ingest  ─►  DocumentIngestor  ─►  CanonicalIndexerJob
         │
         ▼
  Knowledge is now persistent, typed, linked, and retrievable.
```

Three rules:

- **No skill writes directly to canonical storage.** Drafts only.
- **Every promotion produces an audit row.**
- **Rejected approaches are first-class citizens** — the `rejected/` folder is
  as important as `decisions/`.

### The 5 Claude skill templates

Shipped under `.claude/skills/kb-canonical/` as **consumer-side templates**.
Copy them into your own repo to activate:

| Skill | Triggers on | Produces |
|---|---|---|
| `promote-decision` | "we decided to X", "let's go with Y approach" | ADR-style canonical decision markdown |
| `promote-module-kb` | "document how module X works", "rewrite this module's KB" | `module-kb` canonical markdown with 9 standard sections |
| `promote-runbook` | "here's how to handle this incident", "turn this procedure into a runbook" | `runbook` canonical markdown with trigger/actions/rollback/escalation |
| `link-kb-note` | "connect these documents", "what else relates to this?" | wikilink additions to existing canonical notes with rationale |
| `session-close` | session wrap-up | structured list of candidate artifacts + types + reasons |

Every skill is **human-gated**: it produces drafts, never commits.

### New MCP tools (5)

AskMyDocs's `enterprise-kb` MCP server now exposes 10 tools (5 existing + 5 new):

| Tool | Use |
|---|---|
| `kb.graph.neighbors` | 1-hop neighbors of a node, filtered by edge type |
| `kb.graph.subgraph` | Full subgraph from a seed (up to 2 hops) |
| `kb.documents.by_slug` | Lookup canonical doc by stable slug |
| `kb.documents.by_type` | List canonical docs of type X, filtered by status |
| `kb.promotion.suggest` | Extract candidate artifacts from a transcript |

### New Artisan commands (3)

- `kb:promote {path} [--project=]` — operator-side promotion (server CLI; not used by skills).
- `kb:validate-canonical [--project=] [--path=*]` — walk KB disk, validate every canonical doc against the schema, print errors.
- `kb:rebuild-graph [--project=]` — rebuild `kb_nodes`/`kb_edges` from scratch. Scheduled at **03:40 daily**.

### New env vars (18)

```bash
# Canonical layer
KB_CANONICAL_ENABLED=true
KB_CANONICAL_PRIORITY_WEIGHT=0.003
KB_CANONICAL_SUPERSEDED_PENALTY=0.40
KB_CANONICAL_DEPRECATED_PENALTY=0.40
KB_CANONICAL_ARCHIVED_PENALTY=0.60
KB_CANONICAL_AUDIT_ENABLED=true

# Knowledge graph
KB_GRAPH_EXPANSION_ENABLED=true
KB_GRAPH_EXPANSION_HOPS=1
KB_GRAPH_EXPANSION_MAX_NODES=20
KB_GRAPH_EXPANSION_EDGE_TYPES=depends_on,implements,decision_for,related_to,supersedes

# Anti-repetition memory
KB_REJECTED_INJECTION_ENABLED=true
KB_REJECTED_INJECTION_MAX_DOCS=3
KB_REJECTED_MIN_SIMILARITY=0.45

# Promotion
KB_PROMOTION_ENABLED=true
```

### Works for any domain, not just software

The 9 canonical types are deliberately generic. **7 out of 9** apply equally to
software teams, HR, legal, finance, operations, and customer-success:

| Type | Software | HR | Legal | Finance | Ops |
|---|:---:|:---:|:---:|:---:|:---:|
| `decision` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `runbook` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `standard` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `incident` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `domain-concept` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `rejected-approach` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `project-index` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `module-kb` | ✓ | partial | — | — | partial |
| `integration` | ✓ | partial | — | partial | partial |

**Adding a domain-specific type** (e.g. `policy`, `process`, `sla`,
`product-spec`) is a 5-line change to the `CanonicalType` enum + a folder
convention. No migration. No service refactor.

### One instance, many projects

`project_key` is the primary tenant isolator — present on every canonical
table (`knowledge_documents.project_key`, `kb_nodes.project_code`,
`kb_edges.project_code`, `kb_canonical_audit.project_key`). A single
AskMyDocs deployment can host the knowledge of **N independent projects** of
any domain:

```
askmydocs.company.internal (single instance)
├── project_key=ecommerce-core         (software — dev team)
├── project_key=billing                (software — platform team)
├── project_key=hr-portal              (HR — people ops)
├── project_key=legal-vault            (Legal — GC office)
├── project_key=finance-close-process  (Finance — controller)
├── project_key=customer-playbooks     (CS — success team)
└── project_key=product-specs          (Product — PM team)
```

- Canonical `slug` / `doc_id` are unique **per project** (not globally).
- `kb_edges` never cross project boundaries (enforced by tests).
- Cross-project chat is supported (leave `project_key` unset) and later
  gated by role-based ACL (see below).

### Roles & permissions — planned, not blocking

The data model already provisions the hook points for a future RBAC layer:

- `knowledge_documents.access_scope` (already present since day one) — future ACL pivot.
- Canonical frontmatter `owners` / `reviewers` — become ACL input.
- `project_key` — today's tenant boundary, tomorrow combined with role-based
  membership.

Adding RBAC later requires:

- 1 new `project_memberships` (or `roles` + `role_permissions`) table.
- 1 Eloquent global scope on `KnowledgeDocument`.
- 1 middleware on `/api/kb/*` routes.

Every retrieval service (`KbSearchService`, `GraphExpander`,
`RejectedApproachInjector`, all MCP tools) queries through Eloquent, so the
global scope automatically propagates. **Zero structural debt** — the
canonical layer is designed to compose with RBAC when you need it.

### When AskMyDocs Canonical is the right fit

Choose AskMyDocs Canonical **over a plain RAG** when:
- You care about **stable answers** for questions your team answers repeatedly.
- You need a **system of record** for architectural decisions, runbooks, and standards.
- You want LLMs to **stop re-proposing** options your team has already rejected.
- You need **per-tenant** isolation and **audit trails**.

Choose AskMyDocs Canonical **over wiki + CLI-AI (OmegaWiki / Obsidian + Claude CLI)** when:
- You need a **scalable backend** with proper indexing, not in-memory file walks.
- You need **multi-tenant** separation.
- You need **multi-provider AI** with swappable transport (OpenAI, Anthropic, Gemini, OpenRouter, Regolo).
- You want **HTTP/MCP APIs** for cross-system integration, not just a local CLI.
- You need **enterprise auth**, logging, retention, and auditability.
- You want the **wiki as source of truth** AND a scalable searchable projection.

Choose AskMyDocs Canonical **over SaaS (Glean, Notion AI, ...)** when:
- You need **on-prem / EU-sovereign** hosting.
- You refuse vendor lock-in and want your KB in **Git**.
- You want **open source** (MIT) with full control of the prompt surface.

### What this does NOT replace
- Your IDE / code editor. AskMyDocs is a knowledge system, not a developer tool.
- A formal ticketing system. Incident documents here are *post-incident* records, not the ticket.
- A human. The promotion gate is deliberate — LLM output is always a draft.
````

---

## 11. Verification (end-to-end)

After all phases merged:

### Smoke test sequence

- [ ] **S1 — Plain ingest still works** (back-compat):
  ```
  curl -X POST /api/kb/ingest -H 'Authorization: Bearer ...' \
       -d '{"documents":[{"project_key":"test","source_path":"a.md","title":"A","content":"# A\n\nplain markdown"}]}'
  ```
  Expect 202. `knowledge_documents` row has `is_canonical=false`, no rows in `kb_nodes`.

- [ ] **S2 — Canonical ingest creates graph**:
  ```
  curl -X POST /api/kb/ingest -d @examples/decision-example.md.json
  ```
  Expect `is_canonical=true`, `kb_nodes` populated, `kb_edges` matches wikilinks.

- [ ] **S3 — Chat uses graph expansion**:
  ```
  curl -X POST /api/kb/chat -d '{"question":"How do we handle cache invalidation?","project_key":"test"}'
  ```
  Response `meta.chunks_used` breakdown includes `primary`, `expanded`, `rejected` counts.

- [ ] **S4 — Rejected injection fires**:
  Query that correlates with a `rejected-approach` doc → prompt includes `⚠ REJECTED`.

- [ ] **S5 — Promotion flow**:
  ```
  curl /api/kb/promotion/suggest -d '{"transcript":"..."}' → candidates
  curl /api/kb/promotion/candidates -d '<draft>' → valid:true
  curl /api/kb/promotion/promote -d '<draft>' → 202 + path
  ```
  Verify audit trail.

- [ ] **S6 — MCP tools**:
  Claude Desktop / MCP client lists 10 tools. `kb.graph.subgraph` returns valid subgraph.

- [ ] **S7 — All 162 + ~60 new tests green**:
  ```
  vendor/bin/phpunit && npm test
  ```

### Performance SLOs
- `/api/kb/chat` p95 latency stays within +80ms of baseline (measured on the 50-doc canonical fixture).
- `kb:rebuild-graph` on 10k canonical docs completes in <5 minutes.
- Ingestion throughput unchanged for non-canonical docs.

### Rollback procedure
Each PR has an explicit rollback:
- Migrations: `php artisan migrate:rollback --step=N`.
- Config: `KB_CANONICAL_ENABLED=false` → full disable without rollback.
- Code: revert the specific PR; subsequent PRs rebase cleanly.

---

## 12. Multi-project & non-software domains — it's designed for this

> Addressing the question: "how does this handle many projects, and non-software content like HR, processes, legal, customer material?"

### 12.1 Multi-project is baked in from day one

`project_key` is **on every table** — this was a founding design choice before this plan. The canonical extension inherits it:

| Table | Scoping column |
|---|---|
| `knowledge_documents` | `project_key` (unique `(project_key, source_path, version_hash)`) |
| `knowledge_chunks` | `project_key` |
| `kb_nodes` (new) | `project_code` |
| `kb_edges` (new) | `project_code` |
| `kb_canonical_audit` (new) | `project_key` |
| `chat_logs` | `project_key` |
| `conversations` | `project_key` |
| `embedding_cache` | **shared** (by design — embeddings of identical text are reusable across projects, cache hit is a pure win) |

**Invariants ensured by Phase 3:**
- Canonical `slug` / `doc_id` are unique **scoped by project** (indexes `uq_kb_doc_doc_id`, `uq_kb_doc_slug` both composite with `project_key`).
- `kb_edges` NEVER cross project boundaries — every edge has `project_code`, GraphExpander filters by it.
- `MultiTenantGraphIsolationTest` enforces this at the test level.

**Deployment patterns:**

| Pattern | Use when | Cost |
|---|---|---|
| **Single instance, N projects** | Many related projects, common auth boundary | 1 DB, 1 app, N KB disks or 1 disk with N subfolders |
| **One instance per tenant** | Hard data-isolation requirement (e.g. regulated industries) | N DBs, N apps, operational overhead |
| **One GitHub repo per project** | Typical — each project repo ingests with its own `project_key` | GitHub Action composite does it today |

**Example: company with 20 projects** (ecommerce-core, billing, hr-portal, legal-vault, product-specs, customer-success, ...) — all run on ONE AskMyDocs instance, each with its own `project_key`. Each consumer repo has its own `.claude/skills/kb-canonical/` copy and its own `kb/` folder. Chats are scoped: a user asking about `ecommerce-core` never leaks `hr-portal` data.

**Example Markdown layout for a generic business project (e.g. HR):**

```
hr-portal-kb/
├── kb/
│   ├── decisions/
│   │   ├── dec-remote-work-policy-2026.md
│   │   └── dec-bonus-structure-q2.md
│   ├── runbooks/
│   │   ├── runbook-onboarding-new-hire.md
│   │   └── runbook-offboarding-gdpr-compliant.md
│   ├── standards/
│   │   ├── standard-code-of-conduct.md
│   │   └── standard-expense-reimbursement.md
│   ├── domain-concepts/
│   │   ├── dc-vesting-cliff.md
│   │   └── dc-probation-period.md
│   └── rejected/
│       └── rejected-unlimited-pto-2025.md
└── .claude/skills/kb-canonical/ (the 5 templates)
```

This works **today**, with the plan as-is. Zero extra code.

### 12.2 The 9 canonical types are domain-agnostic (and extensible)

Out of the 9 starter types, **7 are already universal** — they apply equally to software, HR, legal, ops, finance, customer-facing:

| Type | Software example | HR example | Legal example | Customer example |
|---|---|---|---|---|
| `decision` | Cache strategy | Bonus structure | GDPR DPA review | Pricing tier choice |
| `runbook` | Deploy rollback | New hire onboarding | Contract countersign | Escalation to L2 |
| `standard` | Coding standard | Code of conduct | NDA template | SLA definition |
| `incident` | Prod outage | Harassment report | Data breach | Customer escalation |
| `domain-concept` | "Idempotency" | "Vesting cliff" | "Force majeure" | "Churn rate" |
| `rejected-approach` | Full-purge cache | Unlimited PTO trial | Jurisdiction clause X | Tiered support rejected |
| `project-index` | App README index | Team handbook index | Matter index | Account playbook |
| `module-kb` ⚠ | Checkout module | — | — | — |
| `integration` ⚠ | Stripe integration | HRIS integration | DocuSign integration | CRM integration |

Only `module-kb` and `integration` lean toward software/systems, and nothing forbids reusing them for "an HRIS module" or "a payroll integration".

### 12.3 Extending the type system for your domain (5-line change)

If you want domain-specific types (e.g. `policy`, `process`, `role-definition`, `product-spec`, `sla`, `customer-commitment`), **this is a 1-file change**:

```php
// app/Support/Canonical/CanonicalType.php
enum CanonicalType: string
{
    // ... existing 9 ...
    case Policy = 'policy';
    case Process = 'process';
    case Sla = 'sla';
    case ProductSpec = 'product-spec';

    public function pathPrefix(): string
    {
        return match ($this) {
            // ... existing ...
            self::Policy => 'policies',
            self::Process => 'processes',
            self::Sla => 'slas',
            self::ProductSpec => 'product-specs',
        };
    }
}
```

No migration needed — `canonical_type` is `varchar(64)` storing the enum string value. Add the type, add a `pathPrefix`, add a Claude skill (if you want automated promotion), update README. Done.

**Recommendation for the initial release:** ship the starter 9 (the OmegaWiki-inspired set). Document clearly that it's an **extension point** and show how to add `policy` / `process` in the README as a "extending the type system" example.

### 12.4 Non-software material in practice

For a generic company knowledge base, here's what the flow looks like:

1. **HR team writes** `dec-remote-work-policy-2026.md` with frontmatter `type: decision, project: hr-portal, module: remote-work, status: accepted`.
2. **Finance team writes** `standard-expense-reimbursement.md` with frontmatter `type: standard, project: finance, status: accepted`.
3. **Legal team writes** `dc-force-majeure.md` with frontmatter `type: domain-concept, project: legal-vault, status: accepted`.
4. **Each team runs** `kb:ingest-folder kb/ --project=hr-portal` (or uses the GitHub Action when pushing).
5. **Users chat** with `project_key=hr-portal` via the chat UI → they get HR-scoped answers with citations to the canonical HR markdown.
6. **Cross-project chat** (e.g. "how do we handle a customer escalation that involves both legal and HR") uses the search endpoint without `project_key` filter — scoped later by permissions (§13).

The system therefore becomes, at company scale, a **typed corporate memory**:
- "Why did we pick this bonus structure?" → retrieves the `decision` doc.
- "What's the onboarding runbook?" → retrieves the `runbook` doc.
- "What did we explicitly reject about unlimited PTO?" → retrieves the `rejected-approach` doc automatically in the prompt.
- "What other policies depend on this one?" → graph-expansion via `depends_on` / `supersedes` edges.

---

## 13. Future-proofing: roles & permissions (planned for later, hooks already present)

> Addressing the question: "tomorrow I want to restrict access to documents per user role — is this plan future-proof for that?"

### 13.1 TL;DR

**Yes, and the hooks are already in place.** No refactor is needed when you later add roles/permissions. The hook points are:

1. **`knowledge_documents.access_scope`** — already exists (`varchar 64`, indexed, default `'internal'`). Today not enforced; tomorrow it becomes the ACL pivot.
2. **`project_key`** — already the tenant isolator; user-to-project mapping is the first permission layer.
3. **Sanctum** — already authenticates every read/write route.
4. **Frontmatter `owners` / `reviewers`** — already parsed into `frontmatter_json`; become ACL inputs.
5. **Laravel Gates & Policies** — the standard enterprise pattern; apply to models without touching services.

### 13.2 What's missing (deliberately, to add later)

A small layer — no core refactor:

| Future addition | Table/code | Effort |
|---|---|---|
| User ↔ project membership | `project_memberships (user_id, project_key, scope[])` table | 1 migration + 1 model |
| Role definitions | `roles`, `role_permissions` tables | 2 migrations + 2 models |
| Per-document ACL (for sensitive docs) | `knowledge_document_acl (document_id, subject_type, subject_id, permission)` | 1 migration + 1 model + policy |
| Middleware enforcement | `EnsureProjectAccess` middleware on `/api/kb/*` routes | 1 middleware class |
| Eloquent global scope | `KnowledgeDocument::addGlobalScope(new AccessScopeScope($user))` | ~15 lines on the model |
| Frontmatter extension | `access_scope: restricted`, `allowed_roles: [finance, leadership]` | Update `CanonicalParser` |

### 13.3 Concrete future migration (illustrative — NOT part of this plan)

```php
// Phase X (future, not now):
Schema::create('project_memberships', function (Blueprint $t) {
    $t->id();
    $t->foreignId('user_id')->constrained()->cascadeOnDelete();
    $t->string('project_key', 120)->index();
    $t->json('scope_allowlist')->nullable(); // e.g. ['public','internal']
    $t->string('role', 64)->default('member'); // member, admin, owner
    $t->timestamps();
    $t->unique(['user_id', 'project_key']);
});
```

And one global scope:

```php
// KnowledgeDocument — future addition, NOT part of this plan:
protected static function booted(): void
{
    static::addGlobalScope('access', function (Builder $q) {
        if ($user = auth()->user()) {
            $q->where(function ($q) use ($user) {
                $q->whereIn('access_scope', $user->allowedScopes())
                  ->orWhereIn('project_key', $user->allowedProjects());
            });
        }
    });
}
```

All existing retrieval paths (`KbSearchService`, `GraphExpander`, `RejectedApproachInjector`, MCP tools) **automatically honor the global scope** because they all query through Eloquent. Zero service-level changes required.

### 13.4 Why this works with canonical markdown

The canonical frontmatter is **the perfect place** to declare ACL intent at write time:

```yaml
---
id: DEC-2026-HR-0012
slug: dec-executive-compensation-q4
type: decision
project: hr-portal
status: accepted
owners: [chro, ceo]
access_scope: restricted      # ← future: enforces ACL at read time
allowed_roles: [leadership, board]  # ← future: extended ACL
---
```

The file lives in Git (visible to ops/admins with repo access), but the AskMyDocs API enforces the runtime ACL at read time. This is the standard enterprise pattern: **source-of-truth in Git**, **runtime enforcement in the app**.

### 13.5 Recommendation

**Don't implement this in the current plan.** Document it as a future Phase 8+ in the README roadmap. Ship the canonical layer first, validate the type system with real content, then layer permissions when there's actual demand — by that time you'll know whether you need role-based, membership-based, or document-level ACLs (likely a mix).

The plan as designed imposes **zero structural debt** on that future phase.

---

## 14. Post-merge follow-ups (NOT in this plan scope)

Explicitly deferred to avoid scope creep:
- Visual graph explorer (React component reading `/api/kb/graph/subgraph`).
- Obsidian vault publish format (one-way bridge: export canonical MD to Obsidian-compatible vault).
- Elasticsearch backend alternative to pgvector (current: pg only).
- Versioned frontmatter schemas (current: one global schema, validated at parse time).
- Cross-project graph queries (current: scoped per project_key only).

These are explicitly out-of-scope. If needed, open a new plan.
