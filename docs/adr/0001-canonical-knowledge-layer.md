# ADR 0001 — Canonical knowledge layer inside `knowledge_documents`

- **Date:** 2026-04-22
- **Status:** accepted
- **Deciders:** platform team
- **Related:** [ADR 0002](./0002-knowledge-graph-model.md), [ADR 0003](./0003-promotion-pipeline.md)

## Context

AskMyDocs indexes generic markdown into `knowledge_documents` + `knowledge_chunks`
and performs vector + FTS retrieval. Customers now want **typed canonical
documents** (decision, runbook, standard, incident, integration, module-kb,
domain-concept, rejected-approach, project-index) with:

- stable business ids (e.g. `DEC-2026-0001`)
- canonical statuses (draft / review / accepted / superseded / deprecated / archived)
- retrieval priority
- editorial metadata (owners, reviewers, related, supersedes)

Two schema strategies were evaluated.

## Decision

**Extend `knowledge_documents` with 8 nullable columns** — do NOT add a parallel
`kb_documents` table.

New columns (all nullable except booleans with sensible defaults):

| Column | Type | Default | Purpose |
|---|---|---|---|
| `doc_id` | varchar(128) | NULL | Stable business id, unique per project |
| `slug` | varchar(255) | NULL | Stable URL-safe identifier, unique per project |
| `canonical_type` | varchar(64) | NULL | One of the 9 CanonicalType enum values |
| `canonical_status` | varchar(64) | NULL | One of the 6 CanonicalStatus enum values |
| `is_canonical` | bool | false | Fast filter for canonical-aware code paths |
| `retrieval_priority` | smallint | 50 | 0–100 boost weight for reranker |
| `source_of_truth` | bool | true | Reserved for future multi-copy scenarios |
| `frontmatter_json` | jsonb | NULL | Full parsed YAML frontmatter |

New composite unique indexes: `(project_key, doc_id)` and `(project_key, slug)`.

## Rationale

- The idempotency contract `(project_key, source_path, version_hash)` already exists
  and is correct. Duplicating documents into a parallel table means double-write
  and an SoT split.
- Nullable columns are essentially free in PostgreSQL (TOAST storage).
- All retrieval services already query through `KnowledgeDocument`. Adding
  Eloquent scopes (`canonical()`, `accepted()`, `byType()`, `bySlug()`) keeps
  the API surface coherent.
- Multi-tenancy is preserved: `doc_id` and `slug` uniqueness is scoped by
  `project_key`, not global.

## Consequences

**Positive**
- No breaking changes for existing consumers — all new columns nullable, defaults safe.
- Zero double-write risk.
- One query surface for retrieval.
- Migration is fully reversible.

**Negative / watch-out**
- `knowledge_documents` row is slightly wider. Measured: +~90 bytes per non-canonical row (negligible).
- Engineers must remember the canonical scopes when querying for "accepted decisions only".

## Alternatives considered

- **Parallel `kb_documents` table** — rejected: double-write hell, SoT split, service-layer refactor cost.
- **Polymorphic `documentable` relation** — rejected: over-engineered for this use case, breaks simple joins.
- **Pure JSON column with all canonical fields inside `metadata`** — rejected: no indexes, no easy `WHERE canonical_type = 'decision'`.

## Implementation pointers

- Migration: `database/migrations/2026_04_22_000001_add_canonical_columns_to_knowledge_documents.php`.
- Test mirror (SQLite): `tests/database/migrations/0001_01_01_000009_add_canonical_columns_to_knowledge_documents.php`.
- Enum definitions: `app/Support/Canonical/{CanonicalType,CanonicalStatus,EdgeType}.php`.
- Scopes: `app/Models/KnowledgeDocument.php` — `canonical()`, `accepted()`, `byType()`, `bySlug()`.
