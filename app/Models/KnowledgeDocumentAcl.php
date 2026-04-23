<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeDocumentAcl extends Model
{
    protected $table = 'knowledge_document_acl';

    protected $fillable = [
        'knowledge_document_id',
        'subject_type',
        'subject_id',
        'permission',
        'effect',
    ];

    // Subject type (polymorphic via subject_id; kept simple to avoid the
    // overhead of Laravel morphs because the three concrete types — user /
    // role / team — are known upfront and stored as varchar for portability).
    public const SUBJECT_USER = 'user';
    public const SUBJECT_ROLE = 'role';
    public const SUBJECT_TEAM = 'team';

    public const PERMISSION_VIEW = 'view';
    public const PERMISSION_EDIT = 'edit';
    public const PERMISSION_DELETE = 'delete';
    public const PERMISSION_PROMOTE = 'promote';

    public const EFFECT_ALLOW = 'allow';
    public const EFFECT_DENY = 'deny';

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }
}
