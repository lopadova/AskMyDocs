<?php

declare(strict_types=1);

namespace App\Invitations;

use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\PlatformAccess;
use App\Support\SystemTenantRegistry;
use Illuminate\Support\Facades\DB;
use Padosoft\Invitations\Models\InviteCode;
use Padosoft\Invitations\Models\Redemption;
use Padosoft\Invitations\Support\InviteGrant;
use Padosoft\Invitations\Support\TenantGrant;
use RuntimeException;
use Throwable;

/**
 * Strict, retryable completion layer over the invitation package.
 *
 * The package correctly makes redemption atomic, but its provisioners are
 * best-effort because the claim is already committed. Public registration has
 * a stronger contract: a tenant-linked code must not complete until its role
 * and initial project memberships exist. This service verifies that contract
 * transactionally and records completion on the user. The immutable redemption
 * remains the recovery source when the first attempt fails.
 */
final class RegistrationAccountCompletionService
{
    public function __construct(private readonly RegistrationCodeResolver $codes) {}

    public function complete(User $user, RegistrationCodeResolution $resolution): void
    {
        if (! $resolution->ok || $resolution->code === null) {
            throw new RegistrationCompletionPendingException;
        }

        try {
            DB::transaction(function () use ($user, $resolution): void {
                $lockedUser = User::query()
                    ->whereKey($user->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedUser->registration_completed_at !== null) {
                    return;
                }

                if ($resolution->intent === RegistrationCodeResolution::TENANT_JOIN) {
                    $grant = $this->tenantGrant($resolution);
                    $this->applyTenantGrant($lockedUser, $grant);
                    $this->assertTenantGrant($lockedUser, $grant);
                } elseif ($resolution->intent !== RegistrationCodeResolution::COMPANY_BOOTSTRAP) {
                    throw new RuntimeException('Unsupported registration intent.');
                }

                // Every successfully registered account has a safe baseline
                // role. Tenant grants are additive and may raise it further.
                $lockedUser->assignRole('viewer');
                if (! $lockedUser->hasRole('viewer')) {
                    throw new RuntimeException('The baseline registration role was not assigned.');
                }

                $lockedUser->registration_completed_at = now();
                if (! $lockedUser->save()) {
                    throw new RuntimeException('The registration completion marker was not persisted.');
                }
            }, 3);
        } catch (RegistrationCompletionPendingException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RegistrationCompletionPendingException($e);
        }

        $user->refresh();
    }

    /**
     * Retry a registration that consumed its code before provisioning finished.
     *
     * The first redemption is the registration claim: the account did not
     * exist before that event, so it cannot have an earlier invitation claim.
     * The cross-tenant lookup is deliberate because the redemption can live in
     * either the reserved registration namespace or a legacy tenant namespace.
     */
    public function reconcile(User $user): void
    {
        if ($user->registration_completed_at !== null) {
            return;
        }

        $redemption = Redemption::query()
            ->withoutGlobalScopes()
            ->where('redeemer_id', $user->getKey())
            ->orderBy('redeemed_at')
            ->orderBy('id')
            ->first();

        // Legacy/operator-created identities have no public registration
        // redemption. They keep their existing auth/onboarding behaviour.
        if ($redemption === null) {
            return;
        }

        $code = InviteCode::query()
            ->withoutGlobalScopes()
            ->whereKey($redemption->code_id)
            ->first();
        if ($code === null) {
            throw new RegistrationCompletionPendingException;
        }

        $resolution = $this->codes->resolveClaimedCode($code);
        if (! $resolution->ok) {
            throw new RegistrationCompletionPendingException;
        }

        $this->complete($user, $resolution);
    }

    private function tenantGrant(RegistrationCodeResolution $resolution): TenantGrant
    {
        $code = $resolution->code;
        if ($code === null || $resolution->redemptionTenant === null) {
            throw new RuntimeException('The tenant invitation has no recoverable grant.');
        }

        $grant = InviteGrant::resolve($code->grant, $code->campaign?->grant);
        $tenantGrants = $grant->effectiveTenantGrants($resolution->redemptionTenant);
        if (count($tenantGrants) !== 1) {
            throw new RuntimeException('The tenant invitation grant is ambiguous.');
        }

        $tenantGrant = $tenantGrants[0];
        if (
            $resolution->targetTenant === null
            || ! hash_equals($resolution->targetTenant, $tenantGrant->tenantId)
            || SystemTenantRegistry::isReserved($tenantGrant->tenantId)
        ) {
            throw new RuntimeException('The tenant invitation target does not match its grant.');
        }

        return $tenantGrant;
    }

    private function applyTenantGrant(User $user, TenantGrant $grant): void
    {
        if ($grant->role !== null) {
            if (in_array($grant->role, [
                PlatformAccess::SYSTEM_ADMIN_ROLE,
                PlatformAccess::TENANT_SUPER_ADMIN_ROLE,
            ], true)) {
                throw new RuntimeException('A protected role cannot be assigned by registration.');
            }

            $user->assignRole($grant->role);
        }

        foreach ($grant->projects as $projectKey) {
            ProjectMembership::query()
                ->withoutGlobalScopes()
                ->firstOrCreate(
                    [
                        'tenant_id' => $grant->tenantId,
                        'user_id' => $user->getKey(),
                        'project_key' => $projectKey,
                    ],
                    [
                        'role' => $grant->projectRole,
                        'scope_allowlist' => $grant->scopeAllowlist,
                    ],
                );
        }
    }

    private function assertTenantGrant(User $user, TenantGrant $grant): void
    {
        if ($grant->role !== null && ! $user->hasRole($grant->role)) {
            throw new RuntimeException('The invitation role was not assigned.');
        }

        $membershipCount = ProjectMembership::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $grant->tenantId)
            ->where('user_id', $user->getKey())
            ->whereIn('project_key', array_values(array_unique($grant->projects)))
            ->count();

        if ($membershipCount !== count(array_unique($grant->projects))) {
            throw new RuntimeException('The invitation project memberships were not assigned.');
        }
    }
}
