<?php

declare(strict_types=1);

namespace App\Invitations;

use App\Models\Project;
use App\Models\User;
use App\Support\PlatformAccess;
use App\Support\SystemTenantRegistry;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Padosoft\Invitations\Models\InviteCode;
use Padosoft\Invitations\Services\CodeGenerator;
use Spatie\Permission\Models\Role;

/**
 * Trusted host issuer for codes accepted by the public registration form.
 *
 * Codes are stored in the reserved system namespace. Tenant-linked codes carry
 * exactly one explicit `grant.tenants` slice, so vendor provisioning never
 * creates access inside the system namespace.
 */
final class RegistrationInvitationIssuer
{
    public function __construct(private readonly CodeGenerator $codes) {}

    public function issueCompanyBootstrap(
        int $maxUses = 1,
        ?CarbonInterface $expiresAt = null,
        ?User $issuer = null,
    ): InviteCode {
        $this->assertCommon($maxUses, $expiresAt);

        return $this->codes->generateRandom([
            'tenant_id' => SystemTenantRegistry::REGISTRATION,
            'issuer_id' => $issuer?->id,
            'max_uses' => $maxUses,
            'expires_at' => $expiresAt,
            'metadata' => [
                'registration_intent' => RegistrationCodeResolution::COMPANY_BOOTSTRAP,
            ],
            'grant' => [],
        ]);
    }

    /**
     * @param list<string> $projectKeys
     */
    public function issueTenantJoin(
        string $tenantId,
        array $projectKeys,
        string $role = 'viewer',
        string $membershipRole = 'member',
        int $maxUses = 1,
        ?CarbonInterface $expiresAt = null,
        ?User $issuer = null,
    ): InviteCode {
        $tenantId = trim($tenantId);
        $projectKeys = array_values(array_unique(array_filter(
            array_map(static fn (mixed $key): string => trim((string) $key), $projectKeys),
            static fn (string $key): bool => $key !== '',
        )));

        $this->assertCommon($maxUses, $expiresAt);
        $this->assertTarget($tenantId, $projectKeys, $role, $membershipRole);

        return $this->codes->generateRandom([
            'tenant_id' => SystemTenantRegistry::REGISTRATION,
            'issuer_id' => $issuer?->id,
            'max_uses' => $maxUses,
            'expires_at' => $expiresAt,
            'metadata' => [
                'registration_intent' => RegistrationCodeResolution::TENANT_JOIN,
                'target_tenant' => $tenantId,
            ],
            'grant' => [
                'tenants' => [[
                    'tenant_id' => $tenantId,
                    'role' => $role,
                    'projects' => $projectKeys,
                    'project_role' => $membershipRole,
                    'scope_allowlist' => null,
                ]],
            ],
        ]);
    }

    private function assertCommon(int $maxUses, ?CarbonInterface $expiresAt): void
    {
        if ($maxUses < 1 || $maxUses > 10_000) {
            throw ValidationException::withMessages([
                'uses' => ['Uses must be between 1 and 10000.'],
            ]);
        }
        if ($expiresAt !== null && ! $expiresAt->isFuture()) {
            throw ValidationException::withMessages([
                'expires' => ['The expiration must be in the future.'],
            ]);
        }
    }

    /**
     * @param list<string> $projectKeys
     */
    private function assertTarget(
        string $tenantId,
        array $projectKeys,
        string $role,
        string $membershipRole,
    ): void {
        if ($tenantId === '' || SystemTenantRegistry::isReserved($tenantId)) {
            throw ValidationException::withMessages([
                'tenant' => ['Choose an operational tenant.'],
            ]);
        }
        if (! Schema::hasTable('tenants')) {
            throw ValidationException::withMessages([
                'tenant' => ['The tenant registry is unavailable.'],
            ]);
        }

        $tenant = Tenant::query()->where('slug', $tenantId)->first();
        if (
            $tenant === null
            || $tenant->status !== 'active'
            || (bool) $tenant->getAttribute('is_system')
        ) {
            throw ValidationException::withMessages([
                'tenant' => ['Choose an active operational tenant.'],
            ]);
        }
        if ($projectKeys === []) {
            throw ValidationException::withMessages([
                'project' => ['At least one project is required for a tenant invitation.'],
            ]);
        }

        $foundProjects = Project::query()
            ->forTenant($tenantId)
            ->whereIn('project_key', $projectKeys)
            ->pluck('project_key')
            ->all();
        $missing = array_values(array_diff($projectKeys, $foundProjects));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'project' => ['Unknown project(s) for this tenant: '.implode(', ', $missing).'.'],
            ]);
        }

        if (
            in_array($role, [PlatformAccess::SYSTEM_ADMIN_ROLE, PlatformAccess::TENANT_SUPER_ADMIN_ROLE], true)
            || ! Role::query()->where('name', $role)->where('guard_name', 'web')->exists()
        ) {
            throw ValidationException::withMessages([
                'role' => ['The invitation role must be an existing non-privileged web role.'],
            ]);
        }
        if (! in_array($membershipRole, ['member', 'admin', 'owner'], true)) {
            throw ValidationException::withMessages([
                'membership_role' => ['Membership role must be member, admin, or owner.'],
            ]);
        }
    }
}
