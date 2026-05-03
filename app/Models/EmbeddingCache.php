<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * EmbeddingCache — cross-tenant reuse layer for embedding vectors.
 *
 * Intentionally NOT tenant-scoped. The cache key is the composite
 * `(text_hash, provider, model)` (UNIQUE constraint declared in
 * database/migrations/2026_05_03_000001_change_embedding_cache_unique_to_composite.php
 * — supersedes the original single-column `text_hash` UNIQUE shipped
 * by the v4.0 baseline create migration). The composite key matches
 * what `EmbeddingCacheService` queries: identical text under a
 * different provider/model produces a deliberate cache miss without
 * raising a duplicate-key error, so multiple models can coexist for
 * the same text — useful when one database backs both a development
 * and a production deployment running different embedding models.
 * `EmbeddingCacheService::flush($provider)` remains available for
 * housekeeping (LRU eviction or cleaning up an obsolete model's
 * vectors) but is no longer a required pre-condition for switching
 * embedding models.
 *
 * Identical input text produces identical embeddings regardless of
 * which tenant triggered the API call, so reusing the vector across
 * tenants is a pure cost win with no isolation risk (the cached value
 * is the model's public embedding output, not tenant data). See
 * `EmbeddingCacheService` and the R31 architecture-test exclusion for
 * the matching design note.
 */
class EmbeddingCache extends Model
{
    public $timestamps = false;

    protected $table = 'embedding_cache';

    protected $fillable = [
        'text_hash',
        'provider',
        'model',
        'embedding',
        'created_at',
        'last_used_at',
    ];

    protected $casts = [
        'embedding' => 'array',
        'created_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];
}
