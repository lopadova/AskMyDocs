<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v4.7/W2 — Workflow share pivot.
 *
 * Binds a workflow to a recipient email + an `allow_edit` flag. NOT
 * tenant-aware on its own: the share inherits the owning workflow's
 * tenant via FK cascade, so deleting the workflow wipes every share.
 * Composite unique `(workflow_id, shared_with_email)` makes share
 * upsert idempotent.
 */
class WorkflowShare extends Model
{
    protected $table = 'workflow_shares';

    protected $fillable = [
        'workflow_id',
        'shared_by_user_id',
        'shared_with_email',
        'allow_edit',
    ];

    protected $casts = [
        'allow_edit' => 'bool',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function sharedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by_user_id');
    }
}
