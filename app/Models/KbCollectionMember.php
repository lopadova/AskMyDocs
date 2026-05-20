<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class KbCollectionMember extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_collection_members';

    protected $fillable = [
        'tenant_id',
        'collection_id',
        'knowledge_document_id',
        'reason',
        'semantic_score',
        'manually_excluded',
    ];

    protected $casts = [
        'semantic_score' => 'float',
        'manually_excluded' => 'bool',
    ];
}

