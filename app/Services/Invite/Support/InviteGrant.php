<?php

declare(strict_types=1);

namespace App\Services\Invite\Support;

/**
 * The provisioning grant an invite key carries: what the redeemer's account
 * becomes on a fresh claim.
 *
 * Pure value object — no DB, no validation against the roles table (that lives
 * in the FormRequest, against the real domain, R18). It only models the shape
 * and the campaign→code resolution rule:
 *
 *   - `role`           the single Spatie role the redeemer is granted (additive
 *                      at apply time — never a downgrade). super-admin is never
 *                      grantable via a code (enforced upstream in validation).
 *   - `projects`       tenant project_keys the redeemer gains membership on.
 *   - `projectRole`    the membership role written per project (member/admin/owner).
 *   - `scopeAllowlist` optional per-project scope restriction (folder_globs/tags).
 *   - `tenants`        OPTIONAL multi-tenant form: a list of per-tenant grants
 *                      (each its own tenant_id + role + projects). When present
 *                      it is authoritative and the redeemer is provisioned across
 *                      ALL listed tenants — so one code can seed memberships in
 *                      several tenants ("teams") at once. When absent, the legacy
 *                      single-tenant fields above apply to the redemption tenant.
 *
 * An empty grant (no role, no projects, no tenant grants) provisions nothing —
 * the redemption still succeeds, it just creates/links no access.
 */
final class InviteGrant
{
    /**
     * @param  list<string>  $projects
     * @param  array<string, mixed>|null  $scopeAllowlist
     * @param  list<TenantGrant>  $tenants
     */
    public function __construct(
        public readonly ?string $role = null,
        public readonly array $projects = [],
        public readonly string $projectRole = 'member',
        public readonly ?array $scopeAllowlist = null,
        public readonly array $tenants = [],
    ) {
    }

    /**
     * Build from a stored grant map (campaign/code `grant` column). Tolerant of
     * a null/partial map; unknown keys are ignored. Project keys are coerced to
     * a clean, de-duplicated, non-empty string list.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null) {
            return new self();
        }

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

        $tenants = [];
        if (isset($data['tenants']) && is_array($data['tenants'])) {
            foreach ($data['tenants'] as $entry) {
                if (is_array($entry)) {
                    $tenants[] = TenantGrant::fromArray($entry);
                }
            }
        }
        /** @var list<TenantGrant> $tenants */
        $tenants = array_values($tenants);

        return new self($role, $projects, $projectRole, $scopeAllowlist, $tenants);
    }

    /**
     * Resolve the effective grant for a code: a non-empty code grant wins over
     * the campaign default; otherwise inherit the campaign grant. Either side
     * may be null.
     *
     * @param  array<string, mixed>|null  $codeGrant
     * @param  array<string, mixed>|null  $campaignGrant
     */
    public static function resolve(?array $codeGrant, ?array $campaignGrant): self
    {
        $code = self::fromArray($codeGrant);

        return $code->isEmpty() ? self::fromArray($campaignGrant) : $code;
    }

    public function isEmpty(): bool
    {
        if ($this->role !== null || $this->projects !== []) {
            return false;
        }

        foreach ($this->tenants as $tenant) {
            if (! $tenant->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The per-tenant grants to actually provision — the UNION of:
     *   1. the legacy single-tenant fields (role/projects), applied to the
     *      redemption tenant (so an invite can always say "grant X wherever you
     *      redeem"); and
     *   2. the explicit `tenants` entries (each with its own fixed tenant_id).
     *
     * Empty entries are dropped. A legacy-only grant yields exactly one entry on
     * the redemption tenant (unchanged behaviour); a tenants-only grant yields
     * just the explicit entries; both together union — so the admin form can
     * send a primary grant plus extra tenants in one payload.
     *
     * @return list<TenantGrant>
     */
    public function effectiveTenantGrants(string $redemptionTenantId): array
    {
        $grants = [];

        if ($this->role !== null || $this->projects !== []) {
            $grants[] = new TenantGrant(
                $redemptionTenantId,
                $this->role,
                $this->projects,
                $this->projectRole,
                $this->scopeAllowlist,
            );
        }

        foreach ($this->tenants as $tenant) {
            if (! $tenant->isEmpty()) {
                $grants[] = $tenant;
            }
        }

        return $grants;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'projects' => $this->projects,
            'project_role' => $this->projectRole,
            'scope_allowlist' => $this->scopeAllowlist,
            'tenants' => array_map(
                static fn (TenantGrant $tenant): array => $tenant->toArray(),
                $this->tenants,
            ),
        ];
    }
}
