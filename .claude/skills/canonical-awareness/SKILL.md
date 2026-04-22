---
name: canonical-awareness
description: Canonical columns (doc_id, slug, canonical_type, canonical_status, is_canonical, retrieval_priority, source_of_truth, frontmatter_json) and the kb_nodes / kb_edges / kb_canonical_audit tables are load-bearing — every query, scope, retrieval step, and delete path that touches knowledge_documents must treat canonical and non-canonical docs correctly. Trigger when editing anything under app/Services/Kb/, app/Jobs/*Canonical*, app/Http/Controllers/Api/Kb*, app/Console/Commands/Kb*, app/Mcp/Tools/Kb*, or any migration / model that touches knowledge_documents, kb_nodes, kb_edges, kb_canonical_audit.
---

# Canonical awareness (R10)

## Rule

The canonical layer is NOT optional metadata. Every query, scope,
retrieval step, promotion path, and delete path that touches
`knowledge_documents` or the graph tables MUST handle BOTH states
(canonical / non-canonical) deliberately.

Concrete checklist — apply on every PR that touches the KB subsystem:

1. **Queries against `knowledge_documents`** — decide whether the
   query should surface canonical docs only, non-canonical only, or
   both. Use the dedicated scopes (`canonical()`, `accepted()`,
   `byType()`, `bySlug()`) instead of building raw WHERE clauses.
   A bare `KnowledgeDocument::where('project_key', $x)` returns a
   mix of both; that's fine for ingestion / deletion but wrong for
   retrieval grounding (which should favour canonical + accepted).

2. **`scopeAccepted()` implies `canonical()`** — don't re-implement
   status filtering by hand. If you need "accepted AND any flavour"
   you're probably doing something wrong; revisit the intent.

3. **Tenant-scoped composite FKs on `kb_edges`** — never add an edge
   without `project_key`. The schema enforces
   `(project_key, from_node_uid) → kb_nodes.(project_key, node_uid)`
   and the same for `to_node_uid`. Any insert that tries to cross
   tenants will raise a FK violation — the error message must be
   treated as a bug, not silenced.

4. **Slug + doc_id uniqueness is scoped per project** — the composite
   uniques are `(project_key, slug)` and `(project_key, doc_id)`.
   Two different projects can (and SHOULD be able to) share
   `dec-cache-v2`. Never assume a slug is globally unique.

5. **Hard delete cascades the graph** — `DocumentDeleter::forceDelete()`
   removes `kb_nodes` owned by the doc (matched via `source_doc_id`
   or falling back to `node_uid = slug`). The FK on `kb_edges`
   cascades both directions. Any new hard-delete code path must call
   through DocumentDeleter or replicate the cascade; never leave
   orphaned graph rows.

6. **Soft delete leaves the graph intact** — retention windows must
   be reversible. Cascade fires only on final hard delete from
   `kb:prune-deleted` or `DELETE /api/kb/documents?force=1`.

7. **Canonical re-ingest vacates identifiers first** — changed content
   on a canonical doc violates `uq_kb_doc_slug` / `uq_kb_doc_doc_id`
   unless the archived prior version has its canonical identifiers
   nulled BEFORE the new row is inserted. `DocumentIngestor` already
   does this in `vacateCanonicalIdentifiersOnPreviousVersions()`;
   any new ingestion path must do the same or delegate to
   DocumentIngestor.

8. **Retrieval pipeline canonical boost / penalty** — Reranker applies
   `priority_weight × retrieval_priority` as a boost and status
   penalties for superseded / deprecated / archived. New retrieval
   services (e.g. a future re-ranker alternative) must honour these
   knobs or explicitly justify deviating in an ADR.

9. **Graph expansion + rejected injection are config-gated** — the
   feature flags are `kb.graph.expansion_enabled` and
   `kb.rejected.injection_enabled`, both default `true`. When `false`,
   the pipeline must degrade to pre-canonical behaviour (legacy
   retrieval). Never hard-code "always on".

10. **Audit on mutation** — every canonical create / promote / update
    / deprecate / hard-delete writes to `kb_canonical_audit` via the
    dedicated service / job path. The audit table is the compliance
    record; bypassing it is a defect even when the functional change
    "works".

## Why this exists

The canonical layer was added across PRs #9–#13 (phases 0–5 of the
canonical compilation plan). Every PR discovered at least one canonical
invariant that a naive change would break — from forgotten tenant
scoping on FKs (#9) to silent identifier collision on canonical
re-ingest (#10) to N+1 query fanouts on graph expansion (#11) to
unbounded LLM prompt amplification (#13). The rule exists to keep
future changes from re-opening any of those failure modes.

## Correct example

```php
// Chat retrieval: canonical + accepted (or review), tenant-scoped,
// statuses lower than accepted are demoted by the reranker, not hidden.
$chunks = KnowledgeDocument::query()
    ->where('project_key', $projectKey)
    ->canonical()
    ->accepted()
    ->with('chunks')
    ->get();

// Hard-delete path: delegate to DocumentDeleter, which cascades graph
// and writes the audit row.
app(DocumentDeleter::class)->delete($document, force: true);
```

## Counter-example (do NOT do)

```php
// Raw WHERE without scope — returns a mix of canonical + non-canonical
// rows; retrieval surfaces stale or irrelevant docs.
$chunks = KnowledgeDocument::where('project_key', $x)
    ->where('canonical_status', 'accepted')
    ->get();   // is_canonical may be false — leak!

// Manual DELETE that skips DocumentDeleter — orphans kb_nodes and
// skips the kb_canonical_audit row.
DB::table('knowledge_documents')->where('id', $id)->delete();
```
