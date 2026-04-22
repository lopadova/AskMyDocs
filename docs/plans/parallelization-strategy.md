# Parallelization Strategy — Canonical Compilation Rollout

This document maps, for each phase of the canonical compilation plan, which tasks can run **in parallel** (dispatched as concurrent subagents) vs which must run **sequentially** (ordering / shared-state constraints).

**Orchestrator model:** the orchestrator never lets subagents touch git. Subagents edit files; the orchestrator stages + commits + pushes, runs the test gate, and opens PRs.

**Test gate:** after each task, run the relevant test(s). If red → fix on the same task, do not advance. If green → commit. Only commit when green.

**Legend:**
- 🟢 fully parallel — safe to dispatch simultaneously
- 🟡 partially parallel — can overlap with caveats
- 🔴 sequential — must finish before next begins

---

## Phase 0 — Foundations

| Task | Mode | Parallel group | Reason |
|---|---|---|---|
| 0.1 composer.json + `composer update` | 🔴 | alone | `composer.lock` mutation — keep deterministic |
| 0.2 config/kb.php + .env.example | 🟢 | A | Independent files, no overlap with 0.1 or 0.3 |
| 0.3 docs/adr/0001..0003.md (3 ADRs) | 🟢 | A | Independent files |
| 0.4 commit + push + PR | 🔴 | alone | Git operations — orchestrator-only |

**Execution:** orchestrator runs 0.1; in parallel dispatches one agent for 0.2 and one for 0.3 (Group A). Wait for all three, run `vendor/bin/phpunit` (expect 162 green), then 0.4.

---

## Phase 1 — Data model extension

| Task | Mode | Parallel group | Reason |
|---|---|---|---|
| 1.1 CanonicalType enum + test | 🟢 | A | Independent file |
| 1.2 CanonicalStatus + EdgeType enums + tests | 🟢 | A | Independent files |
| 1.3 Migration add canonical cols + test mirror + test | 🟢 | B | Independent from enums but all migrations share the test-migration sequence number |
| 1.4 Migration kb_nodes + kb_edges + test mirror | 🟡 | B | Must order its test-mirror timestamp after 1.3's |
| 1.5 Migration kb_canonical_audit + test mirror | 🟡 | B | Must order its test-mirror timestamp after 1.4's |
| 1.6 Eloquent models (KbNode, KbEdge, KbCanonicalAudit) + KnowledgeDocument scopes + tests | 🟢 | C | Independent; depends on 1.3–1.5 migrations to have run |
| 1.7 Commit + push + PR | 🔴 | alone | Orchestrator-only |

**Execution:**
- **Group A** (enums): 2 parallel agents for 1.1 and 1.2.
- **Group B** (migrations): sequential within the group because of timestamp ordering, but the prod + test-mirror for each migration can be one agent per migration.
- **Group C** (models): after migrations run clean, 1 agent for all three models + scope additions.
- Test gate between groups: run `vendor/bin/phpunit` after B, expect migrations green.

---

## Phase 2 — Canonical parsing + chunker v2 + indexer

| Task | Mode | Parallel group | Reason |
|---|---|---|---|
| 2.1 WikilinkExtractor + test | 🟢 | A | Pure utility, no deps |
| 2.2 CanonicalParser + test | 🟢 | A | Depends only on symfony/yaml |
| 2.3 MarkdownChunker v2 rewrite + test | 🟢 | A | Depends only on league/commonmark + WikilinkExtractor (but can be coded with a stub for WikilinkExtractor) |
| 2.4 CanonicalIndexerJob + feature test | 🔴 | B | Depends on 2.1 + 2.2 + KbNode/KbEdge models |
| 2.5 DocumentIngestor canonical branch + test | 🔴 | B | Depends on 2.4 |
| 2.6 DocumentDeleter cascade to graph + test | 🟢 | B | Independent from 2.5 but same PR |
| 2.7 Commit + push + PR | 🔴 | alone | Orchestrator-only |

**Execution:**
- **Group A**: 3 parallel agents for 2.1, 2.2, 2.3. Test gate: each unit test green.
- **Group B**: sequential (2.4 → 2.5) because ingestor wiring depends on the indexer. 2.6 can run concurrently with 2.5 (different file).
- Memory-safe bulk ops discipline (R3) enforced in 2.4.

---

## Phase 3 — Graph-aware retrieval + rejected injection

| Task | Mode | Parallel group | Reason |
|---|---|---|---|
| 3.1 GraphExpander + test | 🟢 | A | Pure service |
| 3.2 RejectedApproachInjector + test | 🟢 | A | Pure service |
| 3.3 KbSearchService integration + DTO change + test | 🔴 | B | Depends on 3.1 + 3.2 |
| 3.4 Reranker canonical boost/penalty + test | 🟢 | A | Independent from 3.1/3.2 |
| 3.5 kb_rag.blade.php + KbChatController + feature test | 🔴 | C | Depends on 3.3 |
| 3.6 MultiTenantGraphIsolationTest | 🟢 | C | Feature test, independent of 3.5 but same PR |
| 3.7 Commit + push + PR | 🔴 | alone | Orchestrator-only |

**Execution:**
- **Group A**: 3 parallel agents for 3.1, 3.2, 3.4.
- **Group B**: 1 agent for 3.3 after A is green.
- **Group C**: 2 parallel agents for 3.5 and 3.6.

---

## Phase 4 — Promotion API + CLI

| Task | Mode | Parallel group | Reason |
|---|---|---|---|
| 4.1 CanonicalWriter + test | 🟢 | A | Pure service, R4 compliance |
| 4.2 PromotionSuggestService + test | 🟢 | A | Pure service |
| 4.3 KbPromotionController (3 routes) + FormRequests + feature test | 🔴 | B | Depends on 4.1 + 4.2 |
| 4.4a kb:promote command + test | 🟢 | C | Uses CanonicalWriter |
| 4.4b kb:validate-canonical command + test | 🟢 | C | Uses CanonicalParser |
| 4.4c kb:rebuild-graph command + test | 🟢 | C | Uses CanonicalIndexerJob |
| 4.4d Scheduler wiring (bootstrap/app.php) | 🟢 | C | Depends on 4.4c existing |
| 4.5 Commit + push + PR | 🔴 | alone | Orchestrator-only |

**Execution:**
- **Group A**: 2 parallel agents.
- **Group B**: 1 agent after A.
- **Group C**: 4 parallel agents (3 commands + scheduler).

---

## Phase 5 — 5 new MCP tools

| Task | Mode | Parallel group | Reason |
|---|---|---|---|
| 5.1 KbGraphNeighborsTool + test | 🟢 | A | Fully independent |
| 5.2 KbGraphSubgraphTool + test | 🟢 | A | Fully independent |
| 5.3 KbDocumentBySlugTool + test | 🟢 | A | Fully independent |
| 5.4 KbDocumentsByTypeTool + test | 🟢 | A | Fully independent |
| 5.5 KbPromotionSuggestTool + test | 🟢 | A | Fully independent |
| 5.6 Register on KnowledgeBaseServer | 🔴 | B | Needs all 5 tool classes |
| 5.7 Commit + push + PR | 🔴 | alone | Orchestrator-only |

**Execution:** **Group A** — 5 parallel agents (one per tool). Group B — 1 agent for registration after all 5 green.

**This is the phase with the highest parallelism payoff.**

---

## Phase 6 — Claude skills + GH action v2

| Task | Mode | Parallel group | Reason |
|---|---|---|---|
| 6.1 5 skill templates under .claude/skills/kb-canonical/ | 🟢 | A | Independent files |
| 6.2 canonical-awareness skill (R10) | 🟢 | A | Independent file |
| 6.3 GitHub composite action v2 update | 🟢 | A | Independent from skills |
| 6.4 Commit + push + PR | 🔴 | alone | Orchestrator-only |

**Execution:** **Group A** — 3 parallel agents (skills bundle / R10 / action). One agent can handle all 5 skill templates because they're similar scaffolds.

---

## Phase 7 — Documentation

| Task | Mode | Parallel group | Reason |
|---|---|---|---|
| 7.1 README new section | 🟢 | A | Independent file |
| 7.2 CLAUDE.md updates | 🟢 | A | Independent file |
| 7.3 copilot-instructions.md mirror | 🟢 | A | Independent file |
| 7.4 README badges | 🟢 | A | Same file as 7.1 — combine in one agent |
| 7.5 Commit + push + PR | 🔴 | alone | Orchestrator-only |

**Execution:** **Group A** — 2 parallel agents (combined README / combined CLAUDE+copilot).

---

## Total agent dispatches

| Phase | Parallel dispatches | Sequential steps | Notes |
|---|---|---|---|
| 0 | 2 | 2 | Smallest phase |
| 1 | 2–3 per group × 3 groups | 1 | Migration group sequential inside |
| 2 | 3 + 2 | 2 | |
| 3 | 3 + 1 + 2 | 1 | |
| 4 | 2 + 1 + 4 | 1 | |
| 5 | 5 + 1 | 1 | Best parallel payoff |
| 6 | 3 | 1 | |
| 7 | 2 | 1 | |

---

## Handoff protocol (if session interrupted)

When dispatching agents, the orchestrator:
1. Gives each agent a self-contained prompt with the exact task snippet from the plan file.
2. Asks the agent to return: **file paths modified** + **commands to run to verify** + **summary of the change**.
3. Does NOT ask the agent to commit. Commits happen from the orchestrator after the verification gate passes.
4. Logs every agent dispatch + outcome to `progress.md`.
5. Appends non-obvious findings to `lessons-learned.md`.
