<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class EmbeddingCache extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'embedding_cache';

    protected $fillable = [
        'tenant_id',
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
