<?php

declare(strict_types=1);

namespace App\Services\Kb\Agent;

use App\Models\KnowledgeDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * v8.44/W4 (ADR 0035 §D6.1) — "what can I filter on?": the filterable
 * dimensions of the corpus with counts, so an agent (or the chat filter UI)
 * builds a sensible `RetrievalFilters` instead of guessing.
 *
 * The ONE core behind `kb:facets`, `GET /api/kb/facets` and `KbFacetsTool`
 * (R44). Every figure comes from SQL aggregates (`GROUP BY`, each dimension
 * bounded by `kb.facets.limit` — R3/R18), over the SAME document population
 * retrieval can read:
 *
 *   - tenant-scoped explicitly (R30);
 *   - the model's global {@see \App\Scopes\AccessScopeScope} applies to the
 *     authenticated principal (R33), so a caller NEVER learns that a value,
 *     tag or folder exists if every document carrying it is hidden from them.
 *     Counts are computed AFTER the scope, not subtracted from a global total;
 *   - archived versions and soft-deleted rows are excluded (the retrieval view).
 *
 * No document text, titles or paths of individual files are returned — only
 * dimension values and counts (folders to depth ≤ 2).
 */
final class KbFacetsService
{
    /**
     * @return array{
     *   document_count: int,
     *   source_types: list<array{value: string, count: int}>,
     *   canonical_types: list<array{value: string, count: int}>,
     *   canonical_statuses: list<array{value: string, count: int}>,
     *   languages: list<array{value: string, count: int}>,
     *   tags: list<array{slug: string, label: string, count: int}>,
     *   folders: list<array{prefix: string, count: int}>,
     *   source_updated_at: array{min: string|null, max: string|null}
     * }
     */
    public function for(string $tenantId, ?string $projectKey = null, int $folderDepth = 2): array
    {
        $limit = max(1, (int) config('kb.facets.limit', 50));

        return [
            'document_count' => (clone $this->documents($tenantId, $projectKey))->count(),
            'source_types' => $this->group($tenantId, $projectKey, 'source_type', $limit),
            'canonical_types' => $this->group($tenantId, $projectKey, 'canonical_type', $limit, canonicalOnly: true),
            'canonical_statuses' => $this->group($tenantId, $projectKey, 'canonical_status', $limit, canonicalOnly: true),
            'languages' => $this->group($tenantId, $projectKey, 'language', $limit),
            'tags' => $this->tags($tenantId, $projectKey, $limit),
            'folders' => $this->folders($tenantId, $projectKey, max(1, min(2, $folderDepth)), $limit),
            'source_updated_at' => $this->dateRange($tenantId, $projectKey),
        ];
    }

    /** @return Builder<KnowledgeDocument> */
    private function documents(string $tenantId, ?string $projectKey): Builder
    {
        return KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('status', '!=', 'archived')
            ->when($projectKey !== null && $projectKey !== '', fn (Builder $q) => $q->where('knowledge_documents.project_key', $projectKey));
    }

    /** @return list<array{value: string, count: int}> */
    private function group(string $tenantId, ?string $projectKey, string $column, int $limit, bool $canonicalOnly = false): array
    {
        $q = $this->documents($tenantId, $projectKey)
            ->whereNotNull($column)
            ->where($column, '!=', '');
        if ($canonicalOnly) {
            $q->where('is_canonical', true);
        }

        return $q->select($column.' as value', DB::raw('COUNT(*) as aggregate'))
            ->groupBy($column)
            ->orderByDesc('aggregate')
            ->orderBy($column)
            ->limit($limit)
            ->get()
            ->map(fn ($r): array => ['value' => (string) $r->value, 'count' => (int) $r->aggregate])
            ->all();
    }

    /** @return list<array{slug: string, label: string, count: int}> */
    private function tags(string $tenantId, ?string $projectKey, int $limit): array
    {
        $docIds = $this->documents($tenantId, $projectKey)->select('knowledge_documents.id');

        return DB::table('knowledge_document_tags as dt')
            ->join('kb_tags as t', 't.id', '=', 'dt.kb_tag_id')
            ->where('t.tenant_id', $tenantId)
            ->whereIn('dt.knowledge_document_id', $docIds)
            ->select('t.slug', 't.label', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('t.slug', 't.label')
            ->orderByDesc('aggregate')
            ->orderBy('t.slug')
            ->limit($limit)
            ->get()
            ->map(fn ($r): array => ['slug' => (string) $r->slug, 'label' => (string) $r->label, 'count' => (int) $r->aggregate])
            ->all();
    }

    /**
     * Folder prefixes: always the top-level directories; with `$depth = 2` also
     * the two-level ones (`policies/hr/`) for paths that have them.
     *
     * @return list<array{prefix: string, count: int}>
     */
    private function folders(string $tenantId, ?string $projectKey, int $depth, int $limit): array
    {
        $rows = $this->foldersAtDepth($tenantId, $projectKey, 1, $limit);
        if ($depth >= 2) {
            $rows = array_merge($rows, $this->foldersAtDepth($tenantId, $projectKey, 2, $limit));
        }

        usort($rows, static fn (array $a, array $b): int => [$b['count'], $a['prefix']] <=> [$a['count'], $b['prefix']]);

        return array_slice($rows, 0, $limit);
    }

    /** @return list<array{prefix: string, count: int}> */
    private function foldersAtDepth(string $tenantId, ?string $projectKey, int $depth, int $limit): array
    {
        $driver = DB::connection()->getDriverName();
        $expr = match (true) {
            $driver === 'pgsql' && $depth === 1 => "split_part(source_path, '/', 1)",
            $driver === 'pgsql' => "split_part(source_path, '/', 1) || '/' || split_part(source_path, '/', 2)",
            $driver === 'sqlite' && $depth === 1 => "substr(source_path, 1, instr(source_path, '/') - 1)",
            $driver === 'sqlite' => "substr(source_path, 1, instr(source_path, '/') + instr(substr(source_path, instr(source_path, '/') + 1), '/') - 1)",
            default => null,
        };

        // Other drivers have no portable segment expression; report no folders
        // rather than loading every path into PHP (R3).
        if ($expr === null) {
            return [];
        }

        // A path needs `depth` slashes to HAVE `depth` leading directory segments.
        $like = $depth === 1 ? '%/%' : '%/%/%';

        return $this->documents($tenantId, $projectKey)
            ->where('source_path', 'like', $like)
            ->select(DB::raw("{$expr} as prefix"), DB::raw('COUNT(*) as aggregate'))
            ->groupBy(DB::raw($expr))
            ->orderByDesc('aggregate')
            ->orderBy('prefix')
            ->limit($limit)
            ->get()
            ->map(fn ($r): array => ['prefix' => (string) $r->prefix.'/', 'count' => (int) $r->aggregate])
            ->all();
    }

    /** @return array{min: string|null, max: string|null} */
    private function dateRange(string $tenantId, ?string $projectKey): array
    {
        $row = $this->documents($tenantId, $projectKey)
            ->whereNotNull('source_updated_at')
            ->selectRaw('MIN(source_updated_at) as lo, MAX(source_updated_at) as hi')
            ->first();

        return [
            'min' => $row?->lo !== null ? \Illuminate\Support\Carbon::parse($row->lo)->toIso8601String() : null,
            'max' => $row?->hi !== null ? \Illuminate\Support\Carbon::parse($row->hi)->toIso8601String() : null,
        ];
    }
}
