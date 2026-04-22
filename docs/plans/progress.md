# Canonical Knowledge Compilation — Progress Tracker

**Branch:** `feature/kb-canonical-compilation` (based on `chore/upgrade-laravel-13` until PR #8 merges, then rebase onto `main`).
**Plan:** [`2026-04-22-canonical-knowledge-compilation.md`](./2026-04-22-canonical-knowledge-compilation.md)
**Parallelization:** [`parallelization-strategy.md`](./parallelization-strategy.md)
**Lessons learned:** [`lessons-learned.md`](./lessons-learned.md)

**Baseline state (before canonical work):** 162 PHPUnit tests / 470 assertions + 18 Vitest tests — all green. Laravel 13.6.0, PHPUnit 12.5.23, Orchestra Testbench 11.1.0.

---

## Phase overview

| Phase | Title | PR | Status | Tests (at merge) |
|---|---|---|---|---|
| 0 | Foundations (deps + config + env + ADRs) | #9 | ✅ green (PR open) | 162/470 |
| 1 | Data model extension (8 cols + 3 tables + models) | #10 | ✅ green (PR pending) | 202/621 |
| 2 | Canonical parsing + chunker v2 + indexer | #11 | ⏸ pending | — |
| 3 | Graph-aware retrieval + rejected injection | #12 | ⏸ pending | — |
| 4 | Promotion API + CLI | #13 | ⏸ pending | — |
| 5 | 5 new MCP tools | #14 | ⏸ pending | — |
| 6 | Claude skill templates + GH action v2 | #15 | ⏸ pending | — |
| 7 | README + CLAUDE.md + copilot instructions | #16 | ⏸ pending | — |

**Status legend:** ⏸ pending · ⏳ in progress · ✅ green / merged · ❌ blocked (see notes)

---

## Phase 0 — Foundations

**Goal:** composer deps + config keys + env vars + 3 ADRs. Zero runtime impact.

| Task | Owner | Status | Notes |
|---|---|---|---|
| 0.1 Add `league/commonmark ^2.5` + `symfony/yaml ^8.0` to composer.json, run `composer update` | orchestrator | ✅ | Both already present as transient deps — lock unchanged, no install performed. commonmark 2.8.2 + yaml 8.0.8 confirmed via `composer show` |
| 0.2 Add `canonical`/`graph`/`rejected`/`promotion` blocks to `config/kb.php` + mirror env in `.env.example` | orchestrator | ✅ | Done by orchestrator — first subagent attempt entered plan mode and did no edits, fell back to manual edit |
| 0.3 Write ADR 0001/0002/0003 under `docs/adr/` | subagent | ✅ | All 3 ADR files created; line counts 70/72/63 |
| 0.4 Commit + push + open PR #9 | orchestrator | ⏳ | Next |

**Acceptance:** **162 tests / 470 assertions still green ✅** · 3 ADRs committed · config defaults map to on (graph + rejected both enabled by default, no-op when no canonical docs exist).

---

## How to resume

If the session dies mid-phase:

1. `cd` into the project, `git checkout feature/kb-canonical-compilation`, `git status` to see WIP.
2. Read this file (last-updated-at line below) + lessons-learned.md.
3. Open the plan at [`2026-04-22-canonical-knowledge-compilation.md`](./2026-04-22-canonical-knowledge-compilation.md) and jump to the phase marked ⏳.
4. Read the per-phase parallelization guidance in `parallelization-strategy.md`.
5. Continue with the next pending task.

Update this file **after every task completion** with the new status and test count.

---

**Last updated:** 2026-04-22 (Phase 0 start).
