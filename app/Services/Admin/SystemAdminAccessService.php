<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\AdminCommandAudit;
use App\Models\User;
use App\Support\PlatformAccess;
use DomainException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Audited, operator-only lifecycle for the global platform role.
 */
final class SystemAdminAccessService
{
    public function grant(string $email): User
    {
        return $this->change($email, true);
    }

    public function revoke(string $email): User
    {
        return $this->change($email, false);
    }

    private function change(string $email, bool $grant): User
    {
        $email = User::normalizeEmail($email);
        $user = User::withTrashed()
            ->where('email_normalized', $email)
            ->orWhereRaw('LOWER(email) = ?', [$email])
            ->first();
        $command = $grant ? 'system-admin:grant' : 'system-admin:revoke';

        $audit = AdminCommandAudit::create([
            'user_id' => $user?->id,
            'command' => $command,
            'args_json' => [
                'target_email_sha256' => hash('sha256', $email),
                'surface' => 'cli',
            ],
            'status' => AdminCommandAudit::STATUS_STARTED,
            'started_at' => now(),
            'client_ip' => null,
            'user_agent' => 'artisan',
        ]);

        try {
            if ($user === null) {
                throw new DomainException('No account exists for that email address.');
            }
            if ($user->trashed()) {
                throw new DomainException('The account is deleted and cannot receive platform access.');
            }
            if (! $user->is_active) {
                throw new DomainException('The account is inactive and cannot receive platform access.');
            }

            DB::transaction(function () use ($user, $grant, $audit): void {
                $systemRole = Role::query()
                    ->where('name', PlatformAccess::SYSTEM_ADMIN_ROLE)
                    ->where('guard_name', 'web')
                    ->lockForUpdate()
                    ->first();
                $superRole = Role::query()
                    ->where('name', PlatformAccess::TENANT_SUPER_ADMIN_ROLE)
                    ->where('guard_name', 'web')
                    ->first();

                if ($systemRole === null || $superRole === null) {
                    throw new DomainException('System roles are missing. Run RbacSeeder first.');
                }

                if ($grant) {
                    // Companion tenant role keeps existing role-gated admin
                    // surfaces available after the operator selects a tenant.
                    $user->assignRole([$systemRole, $superRole]);
                } elseif ($user->hasRole(PlatformAccess::SYSTEM_ADMIN_ROLE, 'web')) {
                    if ($systemRole->users()->where('users.is_active', true)->count() <= 1) {
                        throw new DomainException('Cannot revoke the last system administrator.');
                    }

                    // Keep super-admin: the account may still administer its
                    // own membership tenants after platform access is removed.
                    $user->removeRole($systemRole);
                }

                // The privilege mutation and its successful audit transition
                // are one atomic invariant. If the audit update fails, the
                // role change is rolled back.
                $audit->forceFill([
                    'status' => AdminCommandAudit::STATUS_COMPLETED,
                    'exit_code' => 0,
                    'stdout_head' => $grant
                        ? 'System administrator access granted.'
                        : 'System administrator access revoked.',
                    'completed_at' => now(),
                ])->save();
            });

            return $user->fresh(['roles']);
        } catch (DomainException $e) {
            $audit->forceFill([
                'status' => AdminCommandAudit::STATUS_REJECTED,
                'exit_code' => 1,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ])->save();

            throw $e;
        } catch (\Throwable $e) {
            $audit->forceFill([
                'status' => AdminCommandAudit::STATUS_FAILED,
                'exit_code' => 1,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'completed_at' => now(),
            ])->save();

            throw $e;
        }
    }
}
