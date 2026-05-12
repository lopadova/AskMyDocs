<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v4.7/W2 — Per-user hide-from-my-list marker for shared / system
 * workflows.
 *
 * Hiding does NOT delete the workflow; it merely makes
 * {@see \App\Services\Workflow\WorkflowService::list()} omit the row
 * for THIS user. Other users sharing the same workflow are unaffected.
 *
 * R30/R31: tenant-scoped via {@see BelongsToTenant}.
 */
class HiddenWorkflow extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'hidden_workflows';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'workflow_id',
        'hidden_at',
    ];

    protected $casts = [
        'hidden_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
