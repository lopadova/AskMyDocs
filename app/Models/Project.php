<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Project — first-class registry row for a `project_key` within a team
 * (tenant). See the create_projects_table migration for the rationale:
 * this is a SOFT registry (no hard FK from documents/memberships), so a
 * deleted project row never orphans real content and the ingest pipeline
 * is never blocked by a missing row.
 *
 * Tenant-aware (R30/R31): `BelongsToTenant` auto-fills `tenant_id` on
 * create from the active TenantContext, and the `(tenant_id, project_key)`
 * UNIQUE keeps the key per-tenant — two teams may share `surface-kb`.
 */
class Project extends Model
{
    use BelongsToTenant;

    protected $table = 'projects';

    protected $fillable = [
        'tenant_id',
        'project_key',
        'name',
        'description',
    ];

    /**
     * Documents that carry this project's key WITHIN the same tenant.
     * Joined by the string `project_key` (soft relation, no DB FK) and
     * constrained to the project's own tenant so a shared key in another
     * team never bleeds in.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class, 'project_key', 'project_key')
            ->where('knowledge_documents.tenant_id', $this->tenant_id);
    }

    /**
     * Membership grants on this project, scoped to the project's tenant.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class, 'project_key', 'project_key')
            ->where('project_memberships.tenant_id', $this->tenant_id);
    }
}
