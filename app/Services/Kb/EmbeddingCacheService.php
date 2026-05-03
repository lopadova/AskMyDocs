<?php

namespace App\Services\Kb;

use App\Ai\AiManager;
use App\Ai\EmbeddingsResponse;
use App\Models\EmbeddingCache;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;

/**
 * Embedding cache layer that sits in front of AiManager::generateEmbeddings().
 *
 * Cache key: the composite `(text_hash, provider, model)` (UNIQUE
 * constraint — see migration
 * `2026_05_03_000001_change_embedding_cache_unique_to_composite.php`,
 * which supersedes the original single-column `text_hash` UNIQUE
 * shipped by v4.0). The composite UNIQUE matches what this service
 * queries on read AND insert, so identical text under a different
 * provider/model produces a deliberate cache miss without raising a
 * duplicate-key error. Multiple embedding models can coexist for the
 * same text — useful when one database backs deployments running
 * different models concurrently.
 *
 * `flush($provider)` remains available for housekeeping (LRU eviction,
 * removing an obsolete model's vectors when retiring it) but is no
 * longer a required pre-condition for switching embedding models.
 *
 * Only texts with a cache miss are sent to the AI API. Results are
 * stored for future cross-tenant reuse. This eliminates redundant API
 * calls when re-ingesting unchanged documents or when the same query
 * is searched multiple times.
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
        // v4.1/W4.1.C — when both knobs are on, mask PII out of every
        // input BEFORE we hash it for the cache key (otherwise PII
        // would leak into `text_hash`) and BEFORE we send the text to
        // the embedding provider. Mask strategy (not Tokenise) because
        // embeddings are one-way: we never need to detokenise back.
        // The substitution is also stable (same input → same masked
        // output), so cache hit-rate is preserved across re-ingestion.
        $texts = $this->maskPiiIfEnabled($texts);

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

    /**
     * Mask any PII out of every input text using the package's
     * `MaskStrategy` BEFORE hashing or sending to the provider, when
     * `kb.pii_redactor.enabled` AND `kb.pii_redactor.redact_before_embeddings`
     * are both true. Default-off — v3 hosts upgrading to v4.1 see zero
     * behaviour change until they explicitly opt in.
     *
     * @param  list<string>  $texts
     * @return list<string>
     */
    private function maskPiiIfEnabled(array $texts): array
    {
        if (! (bool) config('kb.pii_redactor.enabled', false)) {
            return $texts;
        }

        if (! (bool) config('kb.pii_redactor.redact_before_embeddings', false)) {
            return $texts;
        }

        /** @var RedactorEngine $engine */
        $engine = app(RedactorEngine::class);
        $maskStrategy = app(MaskStrategy::class);

        return array_map(
            static fn (string $text): string => $engine->redact($text, $maskStrategy),
            $texts,
        );
    }

    private function resolveModelName(string $providerName): string
    {
        // Each provider's config key shape differs because the
        // upstream SDK contracts differ — openai + gemini expose a
        // single embeddings model (`embeddings_model` scalar), regolo
        // sits on top of `laravel/ai` which models multi-purpose
        // model registries per provider (`models.embeddings.default`
        // nested key, see `config/ai.php` lines 117-119).
        //
        // Returning the literal real model name is required for the
        // (text_hash, provider, model) composite UNIQUE: the lookup
        // key on read MUST match what the insert path actually stores
        // (the `$apiResponse->model` returned by the provider). A
        // mismatch (e.g. lookup with 'unknown', insert with the real
        // model) would make every lookup miss while inserts still
        // succeed, polluting the cache with unreachable rows.
        return match ($providerName) {
            'openai' => config('ai.providers.openai.embeddings_model', 'text-embedding-3-small'),
            'gemini' => config('ai.providers.gemini.embeddings_model', 'text-embedding-004'),
            'regolo' => config('ai.providers.regolo.models.embeddings.default', 'Qwen3-Embedding-8B'),
            default => 'unknown',
        };
    }
}
