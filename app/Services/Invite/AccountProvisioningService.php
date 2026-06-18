<?php

declare(strict_types=1);

namespace App\Services\Invite;

use App\Models\InviteAnalyticsEvent;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Invite\Support\InviteGrant;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

/**
 * Applies an invite key's provisioning grant to the redeemer's account
 * (R44 core — the same path used by the HTTP redeem endpoint, the deferred
 * Login/Registered listener, and any MCP/CLI redemption).
 *
 * Two invariants:
 *   - GRANT, never REVOKE. Roles are assigned additively (Spatie assignRole,
 *     idempotent) and project memberships use firstOrCreate, so redeeming a
 *     code can only raise a user's access, never lower an existing role or
 *     clobber an existing scope allowlist. An invite is an entitlement, not a
 *     reset.
 *   - BEST-EFFORT, never fatal. Provisioning runs after the redemption has
 *     already been recorded atomically; a fault here (missing role, transient
 *     DB error) is logged loudly but must never fail or roll back the claim —
 *     same posture as analytics + referral attribution. The real gate is the
 *     campaign-create validation (role must exist, super-admin excluded).
 *
 * Tenant-scoped (R30/R31): project memberships are written under the supplied
 * tenant id. Roles are intentionally global — Spatie teams are disabled and a
 * role is cross-tenant identity; tenant-scoped access is the project membership.
 */
final class AccountProvisioningService
{
    public function __construct(
        private readonly AnalyticsTracker $analytics,
    ) {
    }

    public function provision(User $user, InviteGrant $grant, string $tenantId): void
    {
        if ($grant->isEmpty()) {
            return;
        }

        try {
            $this->grantRole($user, $grant->role);
            $this->grantProjects($user, $grant, $tenantId);

            $this->analytics->record(
                InviteAnalyticsEvent::TYPE_ACCOUNT_PROVISIONED,
                "provisioned:{$tenantId}:{$user->id}",
                [
                    'account_id' => $user->id,
                    'role' => $grant->role,
                    'project_count' => count($grant->projects),
                ],
            );
        } catch (\Throwable $e) {
            // Never propagate — the redemption is already committed.
            Log::error('invite.provision.failed', [
                'tenant_id' => $tenantId,
                'account_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function grantRole(User $user, ?string $role): void
    {
        // super-admin is never grantable via a code; defence in depth behind the
        // campaign-create validation that already rejects it.
        if ($role === null || $role === 'super-admin') {
            return;
        }

        $exists = Role::query()
            ->where('name', $role)
            ->where('guard_name', $user->guard_name ?? 'web')
            ->exists();

        if (! $exists) {
            Log::warning('invite.provision.role_missing', [
                'account_id' => $user->id,
                'role' => $role,
            ]);

            return;
        }

        // Additive + idempotent: keeps any role the user already holds.
        $user->assignRole($role);
    }

    private function grantProjects(User $user, InviteGrant $grant, string $tenantId): void
    {
        foreach ($grant->projects as $projectKey) {
            // firstOrCreate, keyed on the tenant-scoped uniqueness tuple, so an
            // existing membership is never downgraded or its allowlist clobbered
            // by a later invite. Only a brand-new membership takes the grant's
            // project_role / scope_allowlist.
            ProjectMembership::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'project_key' => $projectKey,
                ],
                [
                    'role' => $grant->projectRole,
                    'scope_allowlist' => $grant->scopeAllowlist,
                ],
            );
        }
    }
}
