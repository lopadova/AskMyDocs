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
   and apply multiple `whereNotIn()` clauses so each individual `IN` list stays
   ≤ 1000 values. This keeps the generated SQL parser-friendly across drivers
   (PostgreSQL is generous, MySQL/SQL-Server far less so) and the EXPLAIN plans
   readable. The total bindings for the query can still exceed 1000 — the goal
   is bounded per-clause lists, not bounded total parameters.

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

---

## Extension: chunkById + custom orderBy is a cursor bug

Distilled from PR16 re-harvest. PR #24 `KbTreeService` added
`orderBy('project_key')` / `orderBy('source_path')` BEFORE
`chunkById()`. That silently breaks the cursor — `chunkById` remembers
the last `id` it processed and re-queries with `WHERE id > :last`.
Mixing that with a custom `ORDER BY` means rows can be skipped (seen
out of id-order once, then skipped the second pass) or processed
twice (when the ordering churns on updates mid-sweep).

### Symptoms in a review diff

```php
SomeModel::query()
    ->where(...)
    ->orderBy('project_key')       // ⚠ custom order
    ->orderBy('source_path')       // ⚠ custom order
    ->chunkById(100, function ($rows) { ... });   // expects id-order
```

### Fix template

Option A — use `cursor()` when id-order doesn't matter:

```php
foreach (SomeModel::query()
    ->where(...)
    ->orderBy('project_key')
    ->orderBy('source_path')
    ->cursor() as $row) {
    // stream; no cursor semantics to preserve
}
```

Option B — sort in PHP after `chunkById`:

```php
$results = [];
SomeModel::query()->where(...)->orderBy('id')->chunkById(100, function ($rows) use (&$results) {
    foreach ($rows as $row) $results[] = $row;
});
usort($results, fn ($a, $b) => [$a->project_key, $a->source_path] <=> [$b->project_key, $b->source_path]);
```

Option C — batch-safe custom cursor: chunk on the composite order's
unique key (only works when the `ORDER BY` is unique).

---

## Extension: N+1 inside the chunk walker

Distilled from PR16 re-harvest. PR #30 `AiInsightsService::detectOrphans`
ran `chunks()->count()` + `KbEdge::exists()` inside the `chunkById`
closure — classic N+1 turned into chunk-walker-amplified N+1. On 10k
docs that's 20k DB round-trips wrapped in a scheduler run.

### Symptoms in a review diff

```php
KnowledgeDocument::query()->orderBy('id')->chunkById(100, function ($docs) {
    foreach ($docs as $doc) {
        $chunkCount = $doc->chunks()->count();                   // N+1
        $hasEdges = KbEdge::where('from_node_uid', $doc->doc_id)->exists();  // N+1
        // ...
    }
});
```

### Fix template — `withCount` + pre-fetched set-based check

```php
KnowledgeDocument::query()
    ->withCount('chunks')  // set-based: one subquery, not N
    ->orderBy('id')
    ->chunkById(100, function ($docs) {
        // Pre-fetch the set of doc_ids that have any edges — one query per chunk
        $docIds = $docs->pluck('doc_id')->filter();
        $hasEdgeSet = KbEdge::query()
            ->whereIn('from_node_uid', $docIds)
            ->pluck('from_node_uid')
            ->unique()
            ->flip();

        foreach ($docs as $doc) {
            $chunkCount = $doc->chunks_count;  // already hydrated
            $hasEdges = isset($hasEdgeSet[$doc->doc_id]);
            // ...
        }
    });
```

---

## Extension: SQL-side histogram buckets, not PHP-side iteration

Distilled from PR16 re-harvest. PR #30 `AiInsightsService::qualityReport`
computed 5 histogram buckets by `GROUP BY LENGTH(chunk_text)` (up to
#chunks distinct groups) and iterated in PHP. For a 100k-row
knowledge_chunks table, that's a hundred-thousand-row hydrate.

### Fix template — CASE in SQL so the DB produces 5 rows, not 100k

```php
$buckets = DB::table('knowledge_chunks')
    ->selectRaw(
        "CASE
           WHEN LENGTH(chunk_text) <  200 THEN '0-200'
           WHEN LENGTH(chunk_text) <  500 THEN '200-500'
           WHEN LENGTH(chunk_text) < 1000 THEN '500-1000'
           WHEN LENGTH(chunk_text) < 2000 THEN '1000-2000'
           ELSE '2000+'
         END AS bucket,
         COUNT(*) AS count"
    )
    ->where('project_key', $project)
    ->groupBy('bucket')
    ->get()
    ->keyBy('bucket')
    ->toArray();
```
