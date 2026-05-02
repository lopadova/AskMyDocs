<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * EmbeddingCache — cross-tenant reuse layer for embedding vectors.
 *
 * Intentionally NOT tenant-scoped. The cache is keyed by
 * (text_hash, provider, model) and globally unique on text_hash. Identical
 * input text produces identical embeddings regardless of which tenant
 * triggered the API call, so reusing the vector across tenants is a pure
 * cost win with no isolation risk (the cached value is the model's public
 * embedding output, not tenant data). See `EmbeddingCacheService` and the
 * R31 architecture-test exclusion for the matching design note.
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
