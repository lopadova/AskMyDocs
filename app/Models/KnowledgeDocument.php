<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeDocument extends Model
{
    protected $fillable = [
        'project_key',
        'source_type',
        'title',
        'source_path',
        'mime_type',
        'language',
        'access_scope',
        'status',
        'document_hash',
        'version_hash',
        'metadata',
        'source_updated_at',
        'indexed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'source_updated_at' => 'datetime',
        'indexed_at' => 'datetime',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }
}
