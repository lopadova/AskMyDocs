# ADR 0014 — Auto-Wiki `auto` tier (v8.11)

- **Status:** Accepted
- **Date:** 2026-06-13
- **Cycle:** v8.11 (Auto-Wiki / Agentic Knowledge Compilation)
- **Extends:** [ADR 0003 — Promotion pipeline](0003-promotion-pipeline.md)

## Context

The canonical knowledge layer (typed markdown + `kb_nodes`/`kb_edges` graph)
is the substrate of a Karpathy-style "LLM Wiki", but today it is **human-gated
and never auto-compiled** (ADR 0003): the LLM may *suggest* (`/promotion/suggest`)
but only a human commits via `CanonicalWriter`. The v8.11 cycle adds an
**auto-build** capability — on ingest, an `AutoWikiCompiler` enriches frontmatter
(tags/summary/cross-references) and synthesizes concept pages — and an
**agentic graph-navigation** retrieval. Auto-generating canonical content
directly into the human-vouched tier would reverse ADR 0003 and remove the
anti-hallucination guarantee that is the platform's moat (a hallucinated
cross-reference or tag would sit in the authoritative tier until a human noticed).

## Decision

Introduce a **second-class `auto` tier**, discriminated by a new column
`knowledge_documents.generation_source ∈ {human, auto}` (default `human`):

1. **Auto-generated content is real and retrievable** — it has chunks,
   embeddings and graph nodes like any canonical doc, so it is fully searchable
   and graph-navigable.
2. **Anti-hallucination firewall** — the reranker applies a small extra penalty
   (`kb.canonical.auto_tier_penalty`, default `0.02`) to `auto` docs, so a
   human-`accepted` doc on the same topic **always outranks** the auto-compiled
   one, while an `auto` doc still outranks raw/non-canonical. `0` disables it.
3. **Human gate preserved for the authoritative tier** — ADR 0003 is *extended,
   not revoked*: the `human` tier keeps its human-gated promotion. An admin can
   **promote `auto` → `human`** once reviewed; auto content is reversible,
   audited (`kb_canonical_audit`, actor `system:autowiki`), and re-generable.
4. **Default-ON, layered, R43** — auto-build defaults ON
   (`config('kb.autowiki.*')`), overridable per-(tenant,project) via
   `kb_analysis_settings` (the `AutoWikiGate`, mirroring `ChangeAnalysisGate`).
   Both the OFF path (no auto tier; byte-identical to pre-v8.11) and the ON path
   are tested.
5. **Dedicated model override** — the auto-compile and agentic-retrieval LLM
   calls may target a model distinct from interactive chat
   (`KB_AUTOWIKI_AI_PROVIDER`/`_MODEL`, `KB_AGENTIC_AI_PROVIDER`/`_MODEL`; empty
   falls back to the default chat provider).
6. **Tenant scope** — wikis and the per-tenant index hub are per-(tenant,project)
   / per-tenant; never cross-tenant (R30). No global cross-tenant content.

## Consequences

- The `accepted` retrievable set now contains both `human` and `auto` docs;
  every retrieval surface that must stay human-only uses the `humanCurated()`
  scope, while general retrieval includes `auto` (it is genuinely useful
  content, just ranked below human on ties).
- A new ADR/firewall test gate prevents `auto` from silently becoming the
  human-vouched tier; the firewall ranking + the explicit promote action are
  load-bearing and covered by tests.
- Source-retention policy (`full_copy`/`markdown_only`/`reference_only`) +
  `markdown_path` give a faithful markdown artifact for diff/restore/compile
  inputs instead of the lossy `reconstructContent()` re-derivation.

This ADR is delivered incrementally across the v8.11 cycle: v8.11.0 ships the
tier foundation (column, firewall, gate, config, source-retention); subsequent
releases ship the compiler, lint+index, agentic retrieval, and the apply engine.
