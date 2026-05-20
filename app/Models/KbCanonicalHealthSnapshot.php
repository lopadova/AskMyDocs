<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class KbCanonicalHealthSnapshot extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_canonical_health_snapshot';

    protected $fillable = [
        'tenant_id',
        'knowledge_document_id',
        'project_key',
        'doc_slug',
        'health_score',
        'factors',
        'computed_at',
    ];

    protected $casts = [
        'health_score' => 'float',
        'factors' => 'array',
        'computed_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }
}

