<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\CrossEncoder;

use App\FinOps\CrossRerankCallMeter;
use App\Services\Kb\Pii\ChunkRedactor;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v8.43/W3 (ADR 0035 §D4) — re-orders the heuristic top-N with a cross-encoder
 * that reads each (query, chunk) pair, blending position-aware (see
 * {@see CrossEncoderBlender}) so the head of the heuristic order is protected.
 *
 * Contract with the rest of the pipeline:
 *  - runs AFTER {@see \App\Services\Kb\Reranker}; with the stage OFF
 *    `KbSearchService` calls the Reranker exactly as before (asserted);
 *  - `rerank_score` and `vector_score` are NEVER modified — the refusal gate
 *    reads them, and refusal is deliberately unchanged in this cycle. The
 *    blended value lives under `cross_encoder.blended` and only decides ORDER;
 *  - any failure (timeout, bad status, malformed answer, host not allowed,
 *    unknown driver) falls back to the heuristic order and says so in the
 *    returned meta (`status: unavailable`, machine `reason`) — R14: the caller
 *    can tell, nothing is thrown into the chat path;
 *  - the query is PII-masked before it leaves the process (SEC-LLM-001 gate 3,
 *    one-way `mask`: never mints reversible vault tokens for a transient call);
 *  - scores are cached by `sha256(tenant | model | sha256(query) | sha256(passage))`
 *    — score only, no query or chunk text is stored.
 */
class CrossEncoderStage
{
    public function __construct(
        private readonly CrossEncoderManager $manager,
        private readonly ChunkRedactor $redactor,
        private readonly CrossRerankCallMeter $meter,
        private readonly TenantContext $tenants,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('kb.cross_rerank.enabled', false);
    }

    /** How many heuristic candidates the cross-encoder reads. */
    public function candidates(): int
    {
        return max(1, (int) config('kb.cross_rerank.candidates', 30));
    }

    /**
     * @param  Collection<int, array<string,mixed>>  $ranked  Reranker output, best first
     * @return array{0: Collection<int, array<string,mixed>>, 1: array<string,mixed>}  [final ordered top-$limit, meta]
     */
    public function apply(string $query, Collection $ranked, int $limit, ?string $projectKey = null, ?string $intent = null): array
    {
        $candidates = $ranked->take($this->candidates())->values();

        if ($candidates->isEmpty()) {
            return [$ranked->take($limit)->values(), ['status' => 'skipped', 'reason' => 'no_candidates']];
        }

        try {
            $driver = $this->manager->driver();
            $scores = $this->scores($driver, $query, $candidates, $projectKey, $intent);
        } catch (CrossEncoderUnavailableException $e) {
            return [$ranked->take($limit)->values(), $this->unavailable($e->reason, $e->getMessage())];
        } catch (Throwable $e) {
            return [$ranked->take($limit)->values(), $this->unavailable('error', $e::class)];
        }

        $tiers = $this->tiers();
        $heuristic = $candidates->map(fn (array $c): float => (float) ($c['rerank_score'] ?? 0.0) - $this->mentionBoost($c))->all();
        $normalised = CrossEncoderBlender::normalise(array_map('floatval', $heuristic));

        $blended = $candidates->map(function (array $chunk, int $i) use ($scores, $normalised, $tiers, $driver): array {
            $b = CrossEncoderBlender::blend(
                $normalised[$i],
                $scores[$i],
                (float) data_get($chunk, 'rerank_detail.canonical_penalty', 0.0),
                $i + 1,
                // An explicit @mention is a PRECEDENCE, not a score: the flat
                // +1.0 exceeds anything the blend can produce (both terms are
                // ≤ 1), so a mentioned document is never overtaken by an
                // unmentioned one, and the blend still orders the mentioned
                // ones among themselves. (The Reranker's raw +0.5 lives on a
                // different scale than the normalised blend.)
                $this->mentionBoost($chunk) > 0.0 ? 1.0 : 0.0,
                $tiers,
            );
            $chunk['cross_encoder'] = [
                'status' => 'ok',
                'driver' => $driver->name(),
                'model' => $driver->model(),
                'score' => round($scores[$i], 6),
                'score_status_adjusted' => round($b['cross_adjusted'], 6),
                'heuristic_rank' => $i + 1,
                'retrieval_weight' => $b['weight'],
                'blended' => round($b['final'], 6),
            ];

            return $chunk;
        });

        // Stable sort: on a tie the heuristic order (the original index) wins.
        $ordered = $blended
            ->map(fn (array $c, int $i): array => ['c' => $c, 'i' => $i])
            ->sort(fn (array $a, array $b): int => [$b['c']['cross_encoder']['blended'], $a['i']] <=> [$a['c']['cross_encoder']['blended'], $b['i']])
            ->pluck('c')
            ->take($limit)
            ->values();

        return [$ordered, ['status' => 'ok', 'driver' => $driver->name(), 'model' => $driver->model(), 'candidates' => $candidates->count()]];
    }

    /**
     * @param  Collection<int, array<string,mixed>>  $candidates
     * @return list<float>  one score per candidate, same order
     */
    private function scores(CrossEncoderReranker $driver, string $query, Collection $candidates, ?string $projectKey, ?string $intent): array
    {
        $tenant = $this->tenants->current();
        $ttl = max(0, (int) config('kb.cross_rerank.cache_ttl', 604800));
        $model = $driver->name().'|'.$driver->model();

        // PII: mask the query (and the intent) BEFORE it can leave the process.
        $safe = $this->redactor->redactTexts((string) $projectKey, [$query, $intent ?? ''], previewSafe: true);
        $safeQuery = trim(($safe[1] !== '' ? $safe[1]."\n\n" : '').$safe[0]);
        $queryHash = hash('sha256', $safeQuery);

        $scores = [];
        $missIndexes = [];
        $missPassages = [];
        foreach ($candidates as $i => $chunk) {
            $passage = (string) ($chunk['chunk_text'] ?? '');
            $key = 'kb:cross:'.hash('sha256', $tenant.'|'.$model.'|'.$queryHash.'|'.hash('sha256', $passage));

            $hit = $ttl > 0 ? Cache::get($key) : null;
            if (is_numeric($hit)) {
                $scores[$i] = (float) $hit;

                continue;
            }
            $missIndexes[$i] = $key;
            $missPassages[$i] = $passage;
        }

        if ($missPassages !== []) {
            $started = microtime(true);
            $fresh = $driver->score($safeQuery, array_values($missPassages));

            if (count($fresh) !== count($missPassages)) {
                throw new CrossEncoderUnavailableException('bad_shape', 'The driver returned a different number of scores than passages.');
            }

            $j = 0;
            foreach ($missIndexes as $i => $key) {
                $scores[$i] = $this->finite($fresh[$j++]);
                if ($ttl > 0) {
                    Cache::put($key, $scores[$i], $ttl);
                }
            }

            $this->meter->meter(
                $driver->name(),
                $driver->model(),
                count($missPassages),
                intdiv(array_sum(array_map('strlen', $missPassages)) + strlen($safeQuery) * count($missPassages), 4),
                (int) round((microtime(true) - $started) * 1000),
            );
        }

        ksort($scores);

        return array_values($scores);
    }

    private function finite(float $score): float
    {
        if (! is_finite($score)) {
            throw new CrossEncoderUnavailableException('bad_shape', 'The driver returned a non-finite score.');
        }

        return min(1.0, max(0.0, $score));
    }

    /** @param  array<string,mixed>  $chunk */
    private function mentionBoost(array $chunk): float
    {
        return (float) data_get($chunk, 'rerank_detail.mention_boost', 0.0);
    }

    /** @return array{head_rank: int, mid_rank: int, head_weight: float, mid_weight: float, tail_weight: float} */
    private function tiers(): array
    {
        return [
            'head_rank' => max(1, (int) config('kb.cross_rerank.tiers.head_rank', 3)),
            'mid_rank' => max(1, (int) config('kb.cross_rerank.tiers.mid_rank', 10)),
            'head_weight' => (float) config('kb.cross_rerank.tiers.head_weight', 0.75),
            'mid_weight' => (float) config('kb.cross_rerank.tiers.mid_weight', 0.60),
            'tail_weight' => (float) config('kb.cross_rerank.tiers.tail_weight', 0.40),
        ];
    }

    /** @return array{status: string, reason: string} */
    private function unavailable(string $reason, string $detail): array
    {
        // Operator-facing log: the machine reason is the stable key; the
        // detail may name a host, so it stays in the log and never reaches the
        // response meta (SEC-ERRLEAK-001).
        Log::warning('kb.cross_rerank.unavailable', ['reason' => $reason, 'detail' => mb_substr($detail, 0, 200)]);

        return ['status' => 'unavailable', 'reason' => $reason];
    }
}
