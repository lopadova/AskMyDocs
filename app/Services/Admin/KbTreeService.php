<?php

namespace App\Services\Admin;

use App\Models\KnowledgeDocument;
use Illuminate\Database\Eloquent\Builder;

/**
 * KbTreeService — builds a folder/doc tree over `knowledge_documents`
 * suitable for the admin KB explorer (Phase G1).
 *
 * Design:
 *  - Pure logic so the controller stays thin and tests can target a
 *    single public surface.
 *  - Walks the table via `chunkById(100)` (R3) so a project with
 *    thousands of docs doesn't balloon memory when the admin opens
 *    the panel.
 *  - Uses the canonical-aware scopes on {@see KnowledgeDocument}
 *    (R10) — never hand-rolled WHERE clauses on `is_canonical`.
 *  - Soft-delete aware (R2): default read hides trashed rows; the
 *    admin opts in via `$withTrashed`.
 *
 * Returned tree shape (JSON-serialisable):
 *
 *     [
 *       {type: 'folder', name: 'policies', path: 'policies',
 *        children: [
 *          {type: 'doc', name: 'remote-work-policy.md',
 *           path: 'policies/remote-work-policy.md',
 *           meta: { id, slug, canonical_type, canonical_status,
 *                   is_canonical, indexed_at, deleted_at }}
 *        ]
 *       },
 *       ...
 *     ]
 *
 * Folders are synthesised purely from the `source_path` segments
 * (split on `/`); filesystem reads are out of scope. DB is the source
 * of truth for the tree — matches the project invariant that the
 * canonical markdown directory can always be rebuilt from DB +
 * Git (see CLAUDE.md §6).
 *
 * Detail payloads (chunk preview, versions, rendered body) live in
 * G2. This service returns only what the tree UI needs to render
 * a node: enough metadata for icons + chips, nothing more.
 */
class KbTreeService
{
    public const MODE_ALL = 'all';
    public const MODE_CANONICAL = 'canonical';
    public const MODE_RAW = 'raw';

    /**
     * Build the tree + aggregate counts.
     *
     * @param  string|null  $projectKey  optional scope; null returns every project
     * @param  string  $mode  one of MODE_ALL / MODE_CANONICAL / MODE_RAW
     * @param  bool  $withTrashed  when true, includes soft-deleted rows
     * @return array{tree: array<int, array<string, mixed>>, counts: array{docs:int, canonical:int, trashed:int}}
     */
    public function build(?string $projectKey = null, string $mode = self::MODE_ALL, bool $withTrashed = false): array
    {
        $root = [];
        $counts = ['docs' => 0, 'canonical' => 0, 'trashed' => 0];

        // Copilot #7 fix: chunkById tracks its cursor via the `id` column
        // (or whatever column you pass to it). Adding `orderBy('project_key')`
        // / `orderBy('source_path')` before the chunk walk breaks that
        // invariant because the "last ID" Laravel reads for the next
        // chunk is the last row in the CURRENT result order — not the
        // max id. Rows get skipped or processed twice. Sorting happens
        // later inside `finaliseTree()`, which orders folders-first
        // alphabetically regardless of DB order, so dropping these
        // clauses has no user-visible effect.
        $this->baseQuery($projectKey, $mode, $withTrashed)
            ->chunkById(100, function ($docs) use (&$root, &$counts) {
                foreach ($docs as $doc) {
                    $this->insertDoc($root, $doc);
                    $counts['docs']++;
                    if ($doc->is_canonical) {
                        $counts['canonical']++;
                    }
                    if ($doc->deleted_at !== null) {
                        $counts['trashed']++;
                    }
                }
            });

        return [
            'tree' => $this->finaliseTree($root),
            'counts' => $counts,
        ];
    }

    /**
     * Seed the Eloquent query with the mode + trashed + project filters.
     * Uses the dedicated scopes on KnowledgeDocument (R10) — `canonical()`
     * for the positive branch, `raw()` for the inverse. No inline
     * `where('is_canonical', ...)` calls, so the scope vocabulary stays
     * the single source of truth (Copilot #8).
     */
    private function baseQuery(?string $projectKey, string $mode, bool $withTrashed): Builder
    {
        $query = KnowledgeDocument::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        if ($projectKey !== null && $projectKey !== '') {
            $query->where('project_key', $projectKey);
        }

        if ($mode === self::MODE_CANONICAL) {
            $query->canonical();
        }

        if ($mode === self::MODE_RAW) {
            $query->raw();
        }

        return $query;
    }

    /**
     * Walk `source_path` segments, create intermediate folder nodes
     * lazily, append the doc leaf at the terminal segment.
     *
     * @param  array<string, array<string, mixed>>  $root  nested assoc tree keyed by segment name
     */
    private function insertDoc(array &$root, KnowledgeDocument $doc): void
    {
        $path = (string) $doc->source_path;
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s) => $s !== ''));
        if ($segments === []) {
            return;
        }

        $cursor = &$root;
        $accumulated = [];
        $last = count($segments) - 1;

        foreach ($segments as $idx => $segment) {
            $accumulated[] = $segment;

            if ($idx < $last) {
                if (! isset($cursor[$segment])) {
                    $cursor[$segment] = [
                        'type' => 'folder',
                        'name' => $segment,
                        'path' => implode('/', $accumulated),
                        'children' => [],
                    ];
                }
                $cursor = &$cursor[$segment]['children'];
                continue;
            }

            // Terminal — the doc leaf itself.
            $cursor[$segment] = [
                'type' => 'doc',
                'name' => $segment,
                'path' => implode('/', $accumulated),
                'meta' => $this->docMeta($doc),
            ];
        }
    }

    /**
     * The lean `meta` payload for a doc node. Keep this deliberately
     * minimal — the detail endpoint lives in G2 and will return the
     * richer view (chunks, frontmatter, history).
     *
     * @return array<string, mixed>
     */
    private function docMeta(KnowledgeDocument $doc): array
    {
        return [
            'id' => $doc->id,
            'project_key' => $doc->project_key,
            'slug' => $doc->slug,
            'canonical_type' => $doc->canonical_type,
            'canonical_status' => $doc->canonical_status,
            'is_canonical' => (bool) $doc->is_canonical,
            'indexed_at' => optional($doc->indexed_at)->toIso8601String(),
            'deleted_at' => optional($doc->deleted_at)->toIso8601String(),
        ];
    }

    /**
     * Convert the string-keyed associative tree (built for O(1)
     * segment lookup) into a deterministic positional array: folders
     * first, then docs, both alphabetical. Recurses through every
     * folder's `children`.
     *
     * @param  array<string, array<string, mixed>>  $assoc
     * @return array<int, array<string, mixed>>
     */
    private function finaliseTree(array $assoc): array
    {
        ksort($assoc);
        $folders = [];
        $docs = [];

        foreach ($assoc as $node) {
            if (($node['type'] ?? null) === 'folder') {
                $node['children'] = $this->finaliseTree($node['children'] ?? []);
                $folders[] = $node;
                continue;
            }
            $docs[] = $node;
        }

        return array_merge($folders, $docs);
    }
}
