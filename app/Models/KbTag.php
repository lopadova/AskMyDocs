<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class KbTag extends Model
{
    protected $table = 'kb_tags';

    protected $fillable = [
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
