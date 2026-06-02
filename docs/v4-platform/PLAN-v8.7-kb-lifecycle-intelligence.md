# PLAN — AskMyDocs v8.7 — KB Lifecycle Intelligence + Synonyms + Cloud Time Machine

> Cycle opened 2026-06-02. Integration branch `feature/v8.7` (R37); one sub-branch per Wn
> (R37/R39). Origin: gap analysis against the Affine KB buyer's-playbook + three Lorenzo asks
> (synonym expansion, settings-tunable KB-lifecycle notifications + AI deep-analysis-on-change,
> Cloud Time Machine). Full gap analysis: `~/.claude/plans/wiggly-cooking-biscuit.md`.

## Scope (confirmed with Lorenzo)
All four areas, sequenced foundation-first:

| Wn | Feature | Effort | Status |
|---|---|---|---|
| **W1** | **Synonym Expansion** — `kb_synonyms` table + admin CRUD UI + bidirectional query expansion in `KbSearchService` (embedding-text + FTS tsquery). Default ON, no-op without groups. | S | ✅ shipped |
| **W2** | **Weekly digest + stale-review** — finish the dead `notification_digests` scaffold (`notifications:digest-weekly`), add tunable "X months untouched → needs review" event. | M | ⏳ |
| **W3–W4** | **AI deep-analysis on change (flagship)** — `kb_doc_analyses` + `KbChangeAnalyzer` + `AnalyzeDocumentChangeJob` (async, cost-gated, debounced) → cross-refs + enhancement advice + obsolescence detection of other docs. Suggest-only (ADR 0003). **Default ON for canonical docs only**, non-canonical opt-in per tenant. | L | ⏳ |
| **W5** | **Cloud Time Machine** — version timeline + `MarkdownDiff` + restore-via-reingest + `kb:prune-archived-versions` retention + admin "Time Machine" tab. Substrate (archived versions) already retained. | M | ⏳ |
| **W6** | **RC + GA** — R39 rc-tag, R37 merge, README roadmap-row flip. | S | ⏳ |

## Cross-cutting gates per Wn
R10 canonical-awareness · R30/R31 tenant isolation · R32 RBAC matrix · R13 real-data E2E ·
R14 surface-failures-loudly · R11/R29 testids · R12 Playwright happy+failure · R36 cloud Copilot loop ·
R40 local-critic loop before push · R39 rc-tag per Wn.

## Parked (Affine map surfaced, not in this cycle)
Public read-only KB portal · chat-side graph panel (R10) · content-gap/unanswered-question analytics ·
SAML/SCIM (Pro-tier).
