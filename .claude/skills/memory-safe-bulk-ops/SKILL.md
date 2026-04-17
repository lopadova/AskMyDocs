---
name: memory-safe-bulk-ops
description: Keep bulk DB operations on knowledge_documents / knowledge_chunks / embedding_cache / chat_logs memory-bounded. Use chunkById/cursor, push filters into SQL with whereNotIn + array_chunk, avoid ->get() followed by PHP-side filtering. Trigger when editing DocumentDeleter, PruneDeletedDocumentsCommand, PruneEmbeddingCacheCommand, PruneChatLogsCommand, KbIngestFolderCommand, or any new sweep/backfill/prune that can see more than a few hundred rows.
---

# Memory-safe bulk operations

## Rule

Any sweep that can iterate more than a few hundred rows **must**:

1. Use `chunkById(100)` (or `cursor()` for read-only streams) instead of
   `->get()` + `foreach`.
2. Push filters into SQL. Do **not** load a set into memory and then filter
   with `in_array()` / `array_filter()`.
3. When the filter set itself is large (e.g. orphan detection with a list of
   existing files), chunk the bound parameters with `array_chunk($list, 1000)`
   and apply multiple `whereNotIn()` clauses so you never pass > 1000 bindings
   to PostgreSQL.

## Why this exists

Copilot flagged (PR #6) two concrete cases:

- `DocumentDeleter::deleteOrphans()` loaded every matching
  `KnowledgeDocument` into memory and used `in_array()` to filter against
  on-disk files — O(N·M) CPU and unbounded memory on large projects.
- `DocumentDeleter::pruneSoftDeleted()` used `->get()` then iterated,
  holding the full trashed set in memory during a long-running transaction.

Both happen to run **in the scheduler**, where silently dying on OOM is the
worst-case scenario.

## Patterns

### Streaming a potentially large table

```php
KnowledgeDocument::onlyTrashed()
    ->where('deleted_at', '<', $before)
    ->orderBy('id')
    ->chunkById(100, function ($rows) use (&$count) {
        foreach ($rows as $row) {
            $this->hardDelete($row);  // row + file + (cascade) chunks
            $count++;
        }
    });
```

### Orphan detection with a large "exists on disk" set

```php
$query = KnowledgeDocument::where('project_key', $project)
    ->where('source_path', 'like', "$folder/%");

if ($existing !== []) {
    foreach (array_chunk($existing, 1000) as $chunk) {
        $query->whereNotIn('source_path', $chunk);
    }
}

$results = [];
$query->orderBy('id')->chunkById(100, function ($orphans) use (&$results, $force) {
    foreach ($orphans as $orphan) {
        $results[] = $this->delete($orphan, $force);
    }
});
```

### Read-only scan (diagnostics, reporting)

```php
foreach (ChatLog::query()->where('created_at', '<', $before)->cursor() as $row) {
    // stream, no hydrate-all
}
```

## Checklist before opening a PR

- [ ] No `->get()` followed by `foreach` on a table that can exceed a few
      thousand rows.
- [ ] No PHP-side filtering (`in_array`, `array_filter`, `array_diff`) of a
      DB set that could be narrowed in SQL.
- [ ] Long sweeps are `chunkById()`-driven so each iteration is a bounded
      transaction.
- [ ] Bound-parameter lists are `array_chunk($list, 1000)`-split before
      `whereIn`/`whereNotIn`.
- [ ] Add a feature test with ≥ 150 rows to prove the chunking path is
      exercised (not just the first page).

## Counter-example

```php
// ❌ Loads every trashed row, then iterates — O(N) memory.
$rows = KnowledgeDocument::onlyTrashed()
    ->where('deleted_at', '<', $before)
    ->get();

foreach ($rows as $row) {
    $this->hardDelete($row);
}
```
