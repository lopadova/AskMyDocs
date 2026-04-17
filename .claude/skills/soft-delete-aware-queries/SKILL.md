---
name: soft-delete-aware-queries
description: Audit every query against KnowledgeDocument for soft-delete scope. Read paths stay default-scoped (hide trashed rows); write/admin paths that operate on already-deleted rows must use withTrashed()/onlyTrashed(). Trigger when modifying DocumentDeleter, KbDeleteController, KbDeleteCommand, PruneDeletedDocumentsCommand, KbIngestFolderCommand --prune-orphans, or any new code that touches knowledge_documents outside KbSearchService.
---

# Soft-delete aware queries

## Rule

`KnowledgeDocument` uses the `SoftDeletes` trait. Eloquent hides
`deleted_at IS NOT NULL` rows from every default query — which is correct for
the **read path** (search, MCP, chat). Any operation that must act on
already-soft-deleted rows has to explicitly opt in:

| Intent | Scope |
|---|---|
| Hide trashed from readers (default) | default builder |
| Hard-delete even when previously soft-deleted (`--force`) | `withTrashed()` |
| Retention purge (kb:prune-deleted) | `onlyTrashed()` |
| Diagnostics / admin "show everything" | `withTrashed()` |

## Why this exists

Copilot flagged (PR #6) that `DocumentDeleter::deleteByPath()` queried
without `withTrashed()`. The documented behaviour says:

> `force=true` always hard-deletes: DB row + chunks + physical file.

…but once a doc was soft-deleted, `force` silently became "no document found".
Same issue in `KbDeleteCommand`.

## Checklist before opening a PR

- [ ] The default read path stays default-scoped. Do **not** add
      `withTrashed()` to `KbSearchService`, MCP tools, or the chat controllers.
- [ ] Every branch that handles `$force === true` queries with
      `withTrashed()` (or delegates to a service that does).
- [ ] Retention purges use `onlyTrashed()` — never filter on `deleted_at` in
      raw SQL, the trait scope is authoritative.
- [ ] Feature test: soft-delete a document, then call the force path, assert
      the row and chunks disappear and the file is removed from the KB disk.
- [ ] `KnowledgeDocument` FK on `knowledge_chunks` is `ON DELETE CASCADE`;
      do not manually delete chunks before force-deleting — the cascade
      handles it.

## Counter-example

```php
// ❌ Misses already-trashed rows
$doc = KnowledgeDocument::where('project_key', $project)
    ->where('source_path', $path)
    ->first();

if ($doc && $force) {
    $doc->forceDelete();  // never reached for soft-deleted docs
}
```

## Correct example

```php
$query = KnowledgeDocument::query()
    ->where('project_key', $project)
    ->where('source_path', $path);

if ($force) {
    $query->withTrashed();
}

$doc = $query->first();

if ($doc === null) {
    return ['status' => 'not_found'];
}

if ($force) {
    $this->hardDelete($doc);   // file + row + (cascade) chunks
} else {
    $doc->delete();            // soft delete
}
```

```php
// Retention purge
KnowledgeDocument::onlyTrashed()
    ->where('deleted_at', '<', $before)
    ->chunkById(100, fn ($rows) => $rows->each(fn ($r) => $this->forceDelete($r)));
```
