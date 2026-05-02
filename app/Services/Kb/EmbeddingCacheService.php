<?php

namespace App\Services\Kb;

use App\Ai\AiManager;
use App\Ai\EmbeddingsResponse;
use App\Models\EmbeddingCache;

/**
 * Embedding cache layer that sits in front of AiManager::generateEmbeddings().
 *
 * Cache key: `text_hash` (SHA-256 of the input text) — the only UNIQUE
 * constraint on the table. `provider` + `model` are informational columns
 * used as retrieval-time filters so callers only reuse vectors produced by
 * the same model; identical text under a different provider/model causes a
 * deliberate cache miss. When the embedding model changes, flush stale
 * entries via `flush($provider)` BEFORE the first ingest/search; otherwise
 * the first miss-and-insert on an already-cached text_hash will throw a
 * duplicate-key exception (intentional — it surfaces the missed flush).
 *
 * Only texts with a cache miss are sent to the AI API. Results are stored
 * for future cross-tenant reuse. This eliminates redundant API calls when
 * re-ingesting unchanged documents or when the same query is searched
 * multiple times.
 */
class EmbeddingCacheService
{
    public function __construct(
        private readonly AiManager $ai,
    ) {}

    /**
     * Generate embeddings with caching.
     *
     * @param  list<string>  $texts
     * @return EmbeddingsResponse  Order-matched with input $texts.
     */
    public function generate(array $texts): EmbeddingsResponse
    {
        if (! config('kb.embedding_cache.enabled', true)) {
            return $this->ai->generateEmbeddings($texts);
        }

        $provider = $this->ai->embeddingsProvider();
        $providerName = $provider->name();

        // Determine model name from config
        $modelName = $this->resolveModelName($providerName);

        // Build hash → index map
        $hashes = [];
        foreach ($texts as $i => $text) {
            $hashes[$i] = hash('sha256', $text);
        }

        // Batch lookup cached embeddings
        $cached = EmbeddingCache::query()
            ->whereIn('text_hash', array_values($hashes))
            ->where('provider', $providerName)
            ->where('model', $modelName)
            ->get()
            ->keyBy('text_hash');

        // Separate hits and misses
        $results = [];
        $missIndices = [];
        $missTexts = [];

        foreach ($hashes as $i => $hash) {
            if ($cached->has($hash)) {
                $results[$i] = $cached->get($hash)->embedding;

                // Touch last_used_at (batch later)
                $cached->get($hash)->update(['last_used_at' => now()]);
            } else {
                $missIndices[] = $i;
                $missTexts[] = $texts[$i];
            }
        }

        // Generate embeddings only for cache misses
        if (! empty($missTexts)) {
            $apiResponse = $this->ai->generateEmbeddings($missTexts);

            foreach ($missIndices as $j => $originalIndex) {
                $embedding = $apiResponse->embeddings[$j];
                $results[$originalIndex] = $embedding;

                // Store in cache
                EmbeddingCache::create([
                    'text_hash' => $hashes[$originalIndex],
                    'provider' => $providerName,
                    'model' => $apiResponse->model,
                    'embedding' => $embedding,
                    'last_used_at' => now(),
                ]);
            }

            $modelName = $apiResponse->model;
        }

        // Sort by original index
        ksort($results);

        return new EmbeddingsResponse(
            embeddings: array_values($results),
            provider: $providerName,
            model: $modelName,
            totalTokens: null,
        );
    }

    /**
     * Clear the entire cache or cache for a specific provider.
     */
    public function flush(?string $provider = null): int
    {
        $query = EmbeddingCache::query();

        if ($provider) {
            $query->where('provider', $provider);
        }

        return $query->delete();
    }

    /**
     * Remove cache entries not used since $before.
     */
    public function prune(\DateTimeInterface $before): int
    {
        return EmbeddingCache::query()
            ->where('last_used_at', '<', $before)
            ->delete();
    }

    /**
     * Cache stats.
     */
    public function stats(): array
    {
        return [
            'total_entries' => EmbeddingCache::count(),
            'providers' => EmbeddingCache::query()
                ->selectRaw('provider, model, COUNT(*) as entries')
                ->groupBy('provider', 'model')
                ->get()
                ->toArray(),
        ];
    }

    private function resolveModelName(string $providerName): string
    {
        return match ($providerName) {
            'openai' => config('ai.providers.openai.embeddings_model', 'text-embedding-3-small'),
            'gemini' => config('ai.providers.gemini.embeddings_model', 'text-embedding-004'),
            default => 'unknown',
        };
    }
}
