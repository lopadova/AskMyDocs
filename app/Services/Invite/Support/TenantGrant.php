<?php

declare(strict_types=1);

namespace App\Services\Invite\Support;

/**
 * One tenant's slice of an invite's provisioning grant: the role + project
 * memberships the redeemer gains *in a specific tenant*. An invite can carry
 * several of these so a single code provisions a user across one OR MORE
 * tenants at once (each with its own projects + roles) — the redeemer then
 * sees those tenants as "teams" in /api/auth/me.
 *
 * Pure value object (no DB, no role-table validation — that lives in the
 * FormRequest, R18). Mirrors the legacy single-tenant grant shape with an
 * explicit `tenantId` added.
 *
 * @see InviteGrant for the campaign→code resolution + legacy single-tenant form.
 */
final class TenantGrant
{
    /**
     * @param  list<string>  $projects
     * @param  array<string, mixed>|null  $scopeAllowlist
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $role = null,
        public readonly array $projects = [],
        public readonly string $projectRole = 'member',
        public readonly ?array $scopeAllowlist = null,
    ) {
    }

    /**
     * Build one tenant grant from a stored map. Tolerant of partial data;
     * `tenant_id` falls back to the supplied default when absent/blank so a
     * malformed entry still lands somewhere deterministic.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $fallbackTenantId = 'default'): self
    {
        $tenantId = isset($data['tenant_id']) && is_string($data['tenant_id']) && trim($data['tenant_id']) !== ''
            ? trim($data['tenant_id'])
            : $fallbackTenantId;

        $role = isset($data['role']) && is_string($data['role']) && $data['role'] !== ''
            ? $data['role']
            : null;

        $projects = [];
        if (isset($data['projects']) && is_array($data['projects'])) {
            foreach ($data['projects'] as $project) {
                if (is_string($project) && trim($project) !== '') {
                    $projects[] = trim($project);
                }
            }
        }
        /** @var list<string> $projects */
        $projects = array_values(array_unique($projects));

        $projectRole = isset($data['project_role']) && is_string($data['project_role']) && $data['project_role'] !== ''
            ? $data['project_role']
            : 'member';

        $scopeAllowlist = isset($data['scope_allowlist']) && is_array($data['scope_allowlist'])
            ? $data['scope_allowlist']
            : null;

        return new self($tenantId, $role, $projects, $projectRole, $scopeAllowlist);
    }

    public function isEmpty(): bool
    {
        return $this->role === null && $this->projects === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'role' => $this->role,
            'projects' => $this->projects,
            'project_role' => $this->projectRole,
            'scope_allowlist' => $this->scopeAllowlist,
        ];
    }
}
