<?php

declare(strict_types=1);

namespace App\Invitations;

use App\Models\ProjectMembership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Padosoft\Invitations\Contracts\Provisioner;
use Padosoft\Invitations\Support\TenantGrant;

/**
 * AskMyDocs provisioner for padosoft/laravel-invitations: turns the
 * project-membership slice of an invite's {@see TenantGrant} into
 * `project_memberships` rows for the redeemer.
 *
 * Registered under the `invitations.provisioners` tag alongside the package's
 * default {@see \Padosoft\Invitations\Provisioning\SpatiePermissionProvisioner}
 * (which grants the Spatie role). The two compose: the Spatie provisioner
 * raises the global role, this one raises per-project access.
 *
 * Two invariants from the {@see Provisioner} contract:
 *   - GRANT, never REVOKE — `firstOrCreate` only adds a membership; an existing
 *     row (with whatever role/scope it already carries) is never downgraded or
 *     clobbered. The redeemer can only gain access.
 *   - BEST-EFFORT — a fault is swallowed + logged, never thrown: the redemption
 *     is already committed when provisioning runs (per the contract docblock).
 *
 * R30/R31: the membership is written for the grant's own `tenantId` (one
 * `TenantGrant` = one tenant slice), and ProjectMembership's BelongsToTenant
 * trait also auto-fills tenant_id on create — we set it explicitly here so the
 * row lands in the grant's tenant even when the redemption request's active
 * tenant differs (a single code can provision across several tenants).
 */
final class ProjectMembershipProvisioner implements Provisioner
{
    public function provision(Model $account, TenantGrant $grant): void
    {
        if ($grant->projects === []) {
            return;
        }

        $userId = $account->getKey();
        if ($userId === null) {
            return;
        }

        foreach ($grant->projects as $projectKey) {
            try {
                // withoutGlobalScopes(): a grant tenant can differ from the
                // request's active tenant (one code provisions across several
                // tenants). ProjectMembership carries no tenant global scope
                // today, but asserting it here keeps the cross-tenant lookup
                // correct even if one is ever added — the firstOrCreate must
                // match on the GRANT's tenant, never the active one. The INSERT
                // still lands in $grant->tenantId because we pass it explicitly
                // (BelongsToTenant::creating only auto-fills when tenant_id is
                // empty, so it never overwrites our value).
                ProjectMembership::query()->withoutGlobalScopes()->firstOrCreate(
                    [
                        'tenant_id' => $grant->tenantId,
                        'user_id' => $userId,
                        'project_key' => $projectKey,
                    ],
                    [
                        'role' => $grant->projectRole,
                        'scope_allowlist' => $grant->scopeAllowlist,
                    ],
                );
            } catch (\Throwable $e) {
                // Best-effort: never fail an already-committed redemption. Log
                // the exception CLASS alongside the message so production triage
                // isn't blind to the failure TYPE (a unique-constraint race vs a
                // DB outage are very different signals).
                Log::warning('invitations.provision.project_membership_failed', [
                    'account_id' => $userId,
                    'tenant_id' => $grant->tenantId,
                    'project_key' => $projectKey,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
