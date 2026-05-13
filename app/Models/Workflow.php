<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * v4.7/W2 — Workflow.
 *
 * Reusable prompt template — `type=assistant` for chat workflows or
 * `type=tabular` for column-based extraction. Shared firm-wide via
 * {@see WorkflowShare}; juniors invoke the template in one click. The
 * built-in templates seeded by {@see \Database\Seeders\BuiltInWorkflowSeeder}
 * carry `is_system=true` and cannot be deleted from the API.
 *
 * R30/R31: tenant-scoped via {@see BelongsToTenant}.
 */
class Workflow extends Model
{
    use BelongsToTenant;

    protected $table = 'workflows';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'title',
        'type',
        'prompt_md',
        'columns_config',
        'practice',
        'is_system',
    ];

    protected $casts = [
        'columns_config' => 'array',
        'is_system' => 'bool',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<WorkflowShare>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(WorkflowShare::class);
    }

    /**
     * @return HasMany<HiddenWorkflow>
     */
    public function hiddenBy(): HasMany
    {
        return $this->hasMany(HiddenWorkflow::class);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where("{$query->getModel()->getTable()}.type", $type);
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->where("{$query->getModel()->getTable()}.is_system", true);
    }
}
