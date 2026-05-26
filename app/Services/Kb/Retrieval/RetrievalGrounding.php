<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval;

use Illuminate\Support\Collection;

/**
 * Single source of truth for the "is this chunk strong enough to ground an
 * answer on?" decision, shared by every chat surface (KbChatController,
 * MessageController, MessageStreamController) so the three channels refuse
 * (or answer) identically for the same query.
 *
 * Two correctness properties this class exists to guarantee:
 *
 *  1. **Shape-agnostic reads.** `KbSearchService::search()` returns a
 *     Collection of ARRAYS (the Reranker uses `$chunk['vector_score']`),
 *     but historically the controllers read `$c->vector_score` with OBJECT
 *     syntax — which on a PHP array yields `null`, collapses to `0`, and
 *     silently made the refusal gate non-functional in production (always
 *     refuse with `min_chunks >= 1`, never refuse with `min_chunks = 0`).
 *     The feature tests masked it by mocking the search service with
 *     `(object)` chunks — a shape production never produces (R13/R16).
 *     `data_get()` reads correctly from BOTH arrays and objects, so the
 *     gate works against real retrieval output AND legacy stdClass fixtures.
 *
 *  2. **Gate on the FINAL ranking signal.** The real ranking is
 *     `rerank_score` (vector + keyword + heading + canonical/source deltas),
 *     not the raw `vector_score`. Gating on `vector_score` alone wrongly
 *     refuses a chunk with weak vector similarity but a strong lexical /
 *     heading match — exactly the kind of relevant hit the reranker
 *     promotes. A chunk is therefore "grounded" when EITHER its reranked
 *     score clears the rerank floor OR its raw semantic similarity clears
 *     the vector floor. The OR keeps a known-good semantic floor while no
 *     longer discarding lexically-strong matches.
 */
final class RetrievalGrounding
{
    /**
     * @param  mixed  $chunk  array (production) or stdClass (test fixture)
     */
    public static function isGrounded(mixed $chunk, float $minRerankScore, float $minVectorScore): bool
    {
        // Ground on the INTRINSIC relevance, not the @mention-boosted score.
        // The reranker adds `mention_boost_weight` (0.50) directly into
        // rerank_score for @mentioned docs; if the refusal gate compared the
        // boosted score to min_rerank_score (0.25) it would ground EVERY
        // chunk of a mentioned doc purely from the boost — even an
        // intrinsically irrelevant one — silently disabling the
        // anti-hallucination gate for mentioned docs (Copilot caught this
        // P0.3 × P0.1 interaction). Subtracting the recorded mention boost
        // keeps the boost's effect on RANKING while requiring real relevance
        // to clear the refusal floor.
        $rerank = (float) (data_get($chunk, 'rerank_score') ?? 0.0);
        $mentionBoost = (float) (data_get($chunk, 'rerank_detail.mention_boost') ?? 0.0);
        $intrinsicRerank = $rerank - $mentionBoost;
        if ($intrinsicRerank >= $minRerankScore) {
            return true;
        }

        $vector = (float) (data_get($chunk, 'vector_score') ?? 0.0);

        return $vector >= $minVectorScore;
    }

    /**
     * The subset of chunks that pass {@see isGrounded()}. Reads thresholds
     * from config so every caller stays in lockstep with the tunables.
     *
     * @param  Collection<int, mixed>  $chunks
     * @return Collection<int, mixed>
     */
    public static function grounded(Collection $chunks): Collection
    {
        $minRerank = (float) config('kb.refusal.min_rerank_score', 0.25);
        $minVector = (float) config('kb.refusal.min_chunk_similarity', 0.45);

        return $chunks->filter(
            static fn ($chunk): bool => self::isGrounded($chunk, $minRerank, $minVector),
        )->values();
    }

    /**
     * True when the retrieved set is too weak to ground an answer — i.e.
     * fewer than `kb.refusal.min_chunks_required` chunks pass the gate.
     *
     * @param  Collection<int, mixed>  $chunks
     */
    public static function shouldRefuse(Collection $chunks): bool
    {
        $minChunks = (int) config('kb.refusal.min_chunks_required', 1);

        return self::grounded($chunks)->count() < $minChunks;
    }
}
