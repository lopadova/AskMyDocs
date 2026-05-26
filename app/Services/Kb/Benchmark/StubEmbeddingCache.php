<?php

declare(strict_types=1);

namespace App\Services\Kb\Benchmark;

use App\Ai\EmbeddingsResponse;
use App\Services\Kb\EmbeddingCacheService;
use App\Support\Benchmark\DeterministicEmbedder;

/**
 * Drop-in EmbeddingCacheService that returns deterministic, content-
 * discriminative embeddings with NO API call and NO cache table — used by
 * `kb:benchmark --stub` and the benchmark feature tests so the full pipeline
 * runs without a key (the LIVE benchmark uses the real cache + provider).
 *
 * Subclasses the real service (not `final`, generate() not `final`) and
 * no-ops the constructor so it needs none of the real collaborators.
 */
final class StubEmbeddingCache extends EmbeddingCacheService
{
    public function __construct()
    {
        // Intentionally empty: the stub never touches the DB cache or a
        // provider, so the parent's dependencies are not required.
    }

    /**
     * @param  list<string>  $texts
     */
    public function generate(array $texts): EmbeddingsResponse
    {
        return new EmbeddingsResponse(
            embeddings: DeterministicEmbedder::embedBatch($texts),
            provider: 'stub',
            model: 'deterministic-'.DeterministicEmbedder::DEFAULT_DIM,
        );
    }
}
