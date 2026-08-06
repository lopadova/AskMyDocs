<?php

declare(strict_types=1);

namespace App\Invitations;

use App\Models\AdminCommandAudit;
use App\Support\PlatformAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Padosoft\Invitations\Contracts\InvitedAccount;
use Padosoft\Invitations\Contracts\Provisioner;
use Padosoft\Invitations\Support\TenantGrant;
use Spatie\Permission\Models\Role;

/**
 * Host replacement for the package role provisioner.
 *
 * The package version predates `system-admin` and only protects
 * `super-admin`. This adapter preserves its grant-never-revoke behaviour while
 * also making the platform role impossible to acquire through a stored legacy
 * invite grant.
 */
final class ProtectedRoleProvisioner implements Provisioner
{
    public function provision(Model $account, TenantGrant $grant): void
    {
        $role = $grant->role;

        if ($role === null || $role === PlatformAccess::TENANT_SUPER_ADMIN_ROLE) {
            return;
        }

        if ($role === PlatformAccess::SYSTEM_ADMIN_ROLE) {
            AdminCommandAudit::create([
                'user_id' => $account->getKey(),
                'command' => 'system-admin:invite-grant',
                'args_json' => [
                    'surface' => 'invitation',
                    'grant_tenant' => $grant->tenantId,
                ],
                'status' => AdminCommandAudit::STATUS_REJECTED,
                'exit_code' => 1,
                'error_message' => 'Protected platform role rejected from invitation grant.',
                'started_at' => now(),
                'completed_at' => now(),
                'user_agent' => 'invitation-provisioner',
            ]);

            return;
        }

        if (! method_exists($account, 'assignRole')) {
            return;
        }

        $guard = $account instanceof InvitedAccount
            ? $account->getInviteGuardName()
            : (string) config('auth.defaults.guard', 'web');

        if (! Role::query()->where('name', $role)->where('guard_name', $guard)->exists()) {
            Log::warning('invitations.provision.role_missing', [
                'account_id' => $account->getKey(),
                'role' => $role,
                'guard' => $guard,
            ]);

            return;
        }

        $account->assignRole($role);
    }
}
