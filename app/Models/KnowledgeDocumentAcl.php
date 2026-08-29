<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeDocumentAcl extends Model
{
    use BelongsToTenant;

    protected $table = 'knowledge_document_acl';

    protected $fillable = [
        'tenant_id',
        'knowledge_document_id',
        'subject_type',
        'subject_id',
        'permission',
        'effect',
        'origin',
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

    /*
     * Who owns this row (ADR 0028 phase 2).
     *
     * Reconciliation must be able to DELETE mirrored rows — a mirror that
     * only ever adds permissions is a slow leak, because a share revoked
     * upstream would stay granted here. It must equally never touch what a
     * person set by hand.
     *
     * `origin` is the whole basis for that distinction, which is why the
     * column defaults to ORIGIN_MANUAL: an unlabelled row is treated as
     * somebody's deliberate decision, never as sweepable. The safe direction
     * for a wrong guess is leaving a grant in place for a human to remove,
     * not deleting one nobody meant to lose.
     */
    public const ORIGIN_MANUAL = 'manual';

    public const ORIGIN_SOURCE_MIRROR = 'source-mirror';

    public const ORIGINS = [
        self::ORIGIN_MANUAL,
        self::ORIGIN_SOURCE_MIRROR,
    ];

    /**
     * Rows a sync owns, and may therefore remove.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeMirrored($query)
    {
        return $query->where('origin', self::ORIGIN_SOURCE_MIRROR);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }
}
