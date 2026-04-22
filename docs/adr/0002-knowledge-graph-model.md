# ADR 0002 — Lightweight knowledge graph via `kb_nodes` / `kb_edges`

- **Date:** 2026-04-22
- **Status:** accepted
- **Deciders:** platform team
- **Related:** [ADR 0001](./0001-canonical-knowledge-layer.md), [ADR 0003](./0003-promotion-pipeline.md)

## Context

Flat-chunk RAG has no explicit relations between documents. Customers need
`decision_for`, `depends_on`, `supersedes`, `related_to` navigation — both for
retrieval expansion (graph-aware recall) and for eventual UI graph views.

Open source OmegaWiki (Karpathy-inspired LLM-Wiki) models a typed graph with 9
entity kinds × 9 relation kinds. We adapt the idea, tuned for enterprise
domains (software + HR + legal + finance + ops all covered).

## Decision

Introduce **two new tables**:

- `kb_nodes` — one row per canonical concept (`node_type` ∈ 9 values, `label`,
  `project_code`, `source_doc_id`, JSON `payload_json`).
- `kb_edges` — one row per typed relation (`edge_type` ∈ 10 values,
  `from_node_uid` / `to_node_uid` FKs with ON DELETE CASCADE, `weight`,
  `provenance`, `project_code`).

**9 node types:** project, module, decision, runbook, standard, incident,
integration, domain-concept, rejected-approach.

**10 edge types:** depends_on, uses, implements, related_to, supersedes,
invalidated_by, decision_for, documented_by, affects, owned_by.

Edges are populated by parsing `[[wikilink]]` tokens from canonical markdown
body. Optional explicit edges via frontmatter `related` / `supersedes` arrays.

No embeddings on nodes — vector search stays on chunks. Project-scoped
everywhere (`project_code` on both tables).

## Rationale

- **Why not Neo4j / dedicated graph DB?** Scale: expected <1M edges per tenant,
  adjacency-list queries are O(1) for 1-hop retrieval expansion. Postgres
  handles this fine. Infra cost of Neo4j unjustified at this scale.
- **Why wikilinks as the input?** Human-writable, Obsidian-compatible,
  grep-friendly, no separate edge-definition UI needed.
- **Why separate tables rather than a JSON column?** Cross-document queries
  (`which decisions depend_on this module?`) need indexes. JSON columns
  cannot index that efficiently.
- **Why no embeddings on nodes?** They would double the storage without
  adding retrieval quality — chunk-level embeddings already carry semantic
  similarity. Nodes carry structural metadata.

## Consequences

**Positive**
- Retrieval can do 1-hop graph expansion on top of existing vector+FTS.
- Typed relations enable "what decisions apply to this module?" queries.
- `rejected-approach` + `invalidated_by` enable anti-repetition memory.
- Dangling wikilinks (target not yet canonicalized) are tracked as
  `kb_nodes.payload_json.dangling = true` — no data loss.

**Negative / watch-out**
- Graph maintenance job (`kb:rebuild-graph`) required for schema-level changes.
- Wikilink syntax is a new convention devs must learn — offset by Obsidian
  familiarity.
- 3 new tables add migration surface and test cost.

## Alternatives considered

- **Neo4j or Amazon Neptune** — rejected (infra cost, scale overkill).
- **JSON column `relations[]` inside `knowledge_documents.metadata`** — rejected (no cross-doc queries, no indexing).
- **Adjacency list with free-form `target_slug` string** — rejected (no referential integrity, no cascade).

## Implementation pointers

- Migrations: `database/migrations/2026_04_22_000002_create_kb_nodes_and_edges_tables.php`.
- Test mirror: `tests/database/migrations/0001_01_01_000010_create_kb_nodes_and_edges_tables.php`.
- Models: `app/Models/KbNode.php`, `app/Models/KbEdge.php`.
- Indexer job: `app/Jobs/CanonicalIndexerJob.php`.
- Rebuild command: `app/Console/Commands/KbRebuildGraphCommand.php`, scheduled daily at 03:40.
- Graph expansion service: `app/Services/Kb/Retrieval/GraphExpander.php`.
