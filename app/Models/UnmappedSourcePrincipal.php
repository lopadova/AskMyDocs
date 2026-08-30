<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A principal a source named on a document, which this application could not
 * place (ADR 0028 phase 2).
 *
 * This is a question, never a permission. Nothing here grants or withholds
 * anything: the row exists so that a person who legitimately has upstream
 * access, but no account this application recognises, is VISIBLE rather than
 * silently dropped. Answering the question means creating an ordinary manual
 * ACL row — which is deliberately a separate, deliberate act.
 *
 * @property string $principal_type   As the SOURCE described it: user, group,
 *                                    domain, anyone. Not this application's
 *                                    subject types — the point of the row is
 *                                    that no mapping onto those was found.
 */
class UnmappedSourcePrincipal extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_unmapped_source_principals';

    protected $fillable = [
        'tenant_id',
        'knowledge_document_id',
        'project_key',
        'principal_type',
        'principal_external_id',
        'effect',
        'status',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /** Outstanding: nobody has decided about this principal yet. */
    public const STATUS_PENDING = 'pending';

    /**
     * Someone looked and decided no internal subject should be granted.
     *
     * The row stays — so the decision is auditable and the principal does not
     * reappear as new work on the next sync — but it stops counting as
     * outstanding.
     */
    public const STATUS_IGNORED = 'ignored';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IGNORED,
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
