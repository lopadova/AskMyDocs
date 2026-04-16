<?php

namespace App\Console\Commands;

use App\Services\Kb\EmbeddingCacheService;
use Illuminate\Console\Command;

class PruneEmbeddingCacheCommand extends Command
{
    protected $signature = 'kb:prune-embedding-cache {--days= : Override KB_EMBEDDING_CACHE_RETENTION_DAYS}';

    protected $description = 'Remove embedding_cache rows that have not been used in the last N days.';

    public function handle(EmbeddingCacheService $cache): int
    {
        $days = (int) ($this->option('days') ?? config('kb.embedding_cache.retention_days', 30));

        if ($days <= 0) {
            $this->warn('Retention is 0 or negative — skipping prune.');
            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $deleted = $cache->prune($cutoff);

        $this->info("Pruned {$deleted} embedding_cache rows older than {$days} days (cutoff: {$cutoff->toIso8601String()}).");

        return self::SUCCESS;
    }
}
