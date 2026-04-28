<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * BelongsToTenant — trait applied to every tenant-aware Eloquent model.
 *
 * Auto-fills `tenant_id` from `TenantContext` on `creating` if the
 * model does not already have one set. This means callers that already
 * pass a tenant_id explicitly keep their value; callers that do not are
 * silently scoped to the active tenant.
 *
 * Provides `scopeForTenant(string $tenantId)` for explicit query scope.
 *
 * R31: every tenant-aware Model uses this trait.
 * R30: cross-tenant query leakage is prevented at the architecture-test
 * level, which inspects services/controllers/scopes for `where('tenant_id'`
 * usage. This trait makes the WRITE side automatic; the READ side stays
 * the responsibility of each query author.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::creating(function ($model): void {
            if (empty($model->tenant_id)) {
                $model->tenant_id = app(TenantContext::class)->current();
            }
        });
    }

    /**
     * Scope a query to a specific tenant.
     *
     * Usage: `KnowledgeDocument::query()->forTenant('lvr-store')->...`
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where("{$query->getModel()->getTable()}.tenant_id", $tenantId);
    }
}
