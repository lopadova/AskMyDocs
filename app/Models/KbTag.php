<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class KbTag extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_tags';

    protected $fillable = [
        'tenant_id',
        'project_key',
        'slug',
        'label',
        'color',
    ];

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(
            KnowledgeDocument::class,
            'knowledge_document_tags',
            'kb_tag_id',
            'knowledge_document_id',
        )->withTimestamps();
    }
}
