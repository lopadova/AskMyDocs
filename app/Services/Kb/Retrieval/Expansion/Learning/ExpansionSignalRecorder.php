<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\Expansion\Learning;

use App\Models\KbExpansionEvent;
use App\Services\Kb\Pii\ChunkRedactor;
use App\Services\Kb\Retrieval\Expansion\QueryExpansion;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * W7 — records ONE expansion event right after the ranking it influenced, so
 * the learning loop knows which variant types actually earned a place in the
 * final top-k. Called from `KbSearchService::search()` only when an expansion
 * produced lists AND `kb.expansion_learning.enabled` is on (default OFF, R43).
 *
 * Reward signal, per variant type (`lex` / `vec` / `hyde`):
 *   lists         ranked lists the type produced
 *   in_top        final top-k chunks that any list of that type also returned
 *   unique_in_top final top-k chunks that ONLY that type returned (the
 *                 original query's lists and the other types missed them) —
 *                 the clean measure of "this rewrite found something new"
 *
 * Privacy (SEC-LOG / GDPR): only the PII-MASKED query (one-way `mask`, the same
 * pass the expansion model already sees) and its SHA-256 are stored; chunk ids,
 * never chunk text. Retention: `kb:expansion-learn prune` (scheduled).
 *
 * Never blocks the user path: every failure is caught and logged by class only.
 */
final class ExpansionSignalRecorder
{
    private const TYPES = ['lex', 'vec', 'hyde'];

    public function __construct(
        private readonly ChunkRedactor $redactor,
        private readonly TenantContext $tenants,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('kb.expansion_learning.enabled', false);
    }

    /**
     * @param  list<array{source: string, query: string, chunks: Collection<int, mixed>, weight: float, score_field: string}>  $lists  ALL fused lists (original + expansion)
     * @param  Collection<int, array<string,mixed>>  $ranked  the final ranked results
     */
    public function record(string $query, ?string $projectKey, string $source, QueryExpansion $expansion, array $lists, Collection $ranked, bool $refused): ?KbExpansionEvent
    {
        if (! self::enabled()) {
            return null;
        }

        try {
            $topIds = $ranked->pluck('chunk_id')->filter(static fn ($id): bool => is_numeric($id))->map(static fn ($id): int => (int) $id)->values()->all();
            $masked = mb_substr((string) $this->redactor->redactTexts((string) $projectKey, [trim($query)], previewSafe: true)[0], 0, 500);

            return KbExpansionEvent::query()->create([
                'tenant_id' => $this->tenants->current(),
                'project_key' => $projectKey,
                'query_hash' => hash('sha256', mb_strtolower($masked)),
                'masked_query' => $masked,
                'source' => mb_substr($source, 0, 20),
                'expansion' => $expansion->toArray(),
                'list_stats' => self::stats($lists, $topIds),
                'top_chunk_ids' => $topIds,
                'refused' => $refused,
            ]);
        } catch (Throwable $e) {
            Log::warning('kb.expansion_learning.record_failed', ['error_class' => $e::class]);

            return null;
        }
    }

    /**
     * @param  list<array{query: string, chunks: Collection<int, mixed>}>  $lists
     * @param  list<int>  $topIds
     * @return array<string, array{lists: int, in_top: int, unique_in_top: int}>
     */
    public static function stats(array $lists, array $topIds): array
    {
        $top = array_flip($topIds);
        $byType = [];
        foreach ($lists as $list) {
            $ids = $list['chunks']->map(static fn ($c): int => (int) (is_array($c) ? ($c['id'] ?? $c['chunk_id'] ?? 0) : $c->id))->all();
            $byType[$list['query']][] = array_flip($ids);
        }

        $stats = [];
        foreach (self::TYPES as $type) {
            $own = [];
            foreach ($byType[$type] ?? [] as $set) {
                $own += $set;
            }
            $others = [];
            foreach ($byType as $otherType => $sets) {
                if ($otherType === $type) {
                    continue;
                }
                foreach ($sets as $set) {
                    $others += $set;
                }
            }
            $inTop = array_intersect_key($own, $top);
            $stats[$type] = [
                'lists' => count($byType[$type] ?? []),
                'in_top' => count($inTop),
                'unique_in_top' => count(array_diff_key($inTop, $others)),
            ];
        }

        return $stats;
    }
}
