<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * EmbeddingCache — cross-tenant reuse layer for embedding vectors.
 *
 * Intentionally NOT tenant-scoped. The cache key is `text_hash` alone
 * (UNIQUE constraint declared in
 * database/migrations/2026_01_01_000006_create_embedding_cache_table.php).
 * `provider` + `model` are informational columns: EmbeddingCacheService
 * filters by them on retrieval to ensure callers only reuse vectors
 * produced by the same model, so identical text under a different
 * provider/model produces a cache miss. The supported way to evict
 * stale entries when the embedding model changes is
 * `EmbeddingCacheService::flush($provider)` — switching the embedding
 * model without flushing first can therefore trigger a duplicate-key
 * insert on text_hash, which is intentional: it surfaces the missed
 * flush rather than silently overwriting a known-good vector.
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
