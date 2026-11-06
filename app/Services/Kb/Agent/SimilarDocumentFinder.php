<?php

declare(strict_types=1);

namespace App\Services\Kb\Agent;

use App\Models\KnowledgeDocument;
use App\Support\LikeEscaper;
use Illuminate\Database\Eloquent\Builder;

/**
 * v8.44/W4 (ADR 0035 §D6.3) — "did you mean": when an agent asks for a slug or
 * a path that does not exist, return up to N near misses instead of a bare
 * not-found, so it can recover in one turn.
 *
 * Safe by construction:
 *  - tenant-scoped explicitly (R30) and read through the document model's
 *    global {@see \App\Scopes\AccessScopeScope}, so a suggestion can only name
 *    a document the caller could retrieve (R33) — a "did you mean" must never
 *    reveal that a hidden document exists;
 *  - archived and soft-deleted rows are excluded;
 *  - the candidate set is BOUNDED in SQL (a LIKE on the first characters, with
 *    `%`, `_` and `\` escaped — R19) and capped, then ranked in PHP with
 *    Levenshtein on the BASENAME / slug, never the whole corpus.
 *
 * Distance ≤ `kb.did_you_mean.max_distance` (default 3) on lower-cased
 * strings; ties break alphabetically so the answer is deterministic.
 */
final class SimilarDocumentFinder
{
    private const CANDIDATE_CAP = 200;

    /**
     * @return list<array{slug: string|null, source_path: string, title: string, project_key: string, distance: int}>
     */
    public function suggest(string $tenantId, string $needle, ?string $projectKey = null, int $limit = 5): array
    {
        $needle = mb_strtolower(trim($needle));
        $base = mb_strtolower(pathinfo($needle, PATHINFO_FILENAME) ?: $needle);
        if (mb_strlen($base) < 3) {
            return [];
        }

        $maxDistance = max(1, (int) config('kb.did_you_mean.max_distance', 3));
        $stem = LikeEscaper::contains(mb_substr($base, 0, 3));

        $candidates = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('status', '!=', 'archived')
            ->when($projectKey !== null && $projectKey !== '', fn (Builder $q) => $q->where('project_key', $projectKey))
            ->where(function (Builder $q) use ($stem): void {
                $q->whereRaw('LOWER(source_path) LIKE ? '.LikeEscaper::ESCAPE_SQL, [$stem])
                    ->orWhereRaw("LOWER(COALESCE(slug, '')) LIKE ? ".LikeEscaper::ESCAPE_SQL, [$stem]);
            })
            ->orderBy('id')
            ->limit(self::CANDIDATE_CAP)
            ->get(['id', 'project_key', 'slug', 'source_path', 'title']);

        $scored = [];
        foreach ($candidates as $doc) {
            $slugDistance = $doc->slug !== null && $doc->slug !== '' ? levenshtein($base, mb_strtolower((string) $doc->slug)) : PHP_INT_MAX;
            $pathBase = mb_strtolower(pathinfo((string) $doc->source_path, PATHINFO_FILENAME));
            $pathDistance = levenshtein($base, $pathBase);
            $distance = min($slugDistance, $pathDistance);

            if ($distance > $maxDistance) {
                continue;
            }
            $scored[] = [
                'slug' => $doc->slug,
                'source_path' => (string) $doc->source_path,
                'title' => (string) $doc->title,
                'project_key' => (string) $doc->project_key,
                'distance' => $distance,
            ];
        }

        usort($scored, static fn (array $a, array $b): int => [$a['distance'], $a['source_path']] <=> [$b['distance'], $b['source_path']]);

        return array_slice($scored, 0, max(1, $limit));
    }
}
