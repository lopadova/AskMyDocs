<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\PlatformAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Split the legacy global `super-admin` into:
 *  - `system-admin`: platform-wide control plane + cross-tenant access;
 *  - `super-admin`: highest tenant role, constrained by tenant membership.
 *
 * Existing super-admins are copied to the new system role so a deployment
 * never loses its current platform operators. The old role is retained as a
 * companion role for tenant route compatibility. Future super-admin grants
 * are tenant-scoped because only this one-time migration performs the copy.
 */
return new class extends Migration
{
    private const GUARD = 'web';

    public function up(): void
    {
        if (! $this->permissionTablesExist()) {
            return;
        }

        DB::transaction(function (): void {
            $now = now();
            $systemRoleId = $this->roleId(PlatformAccess::SYSTEM_ADMIN_ROLE, $now);
            $superRoleId = $this->roleId(PlatformAccess::TENANT_SUPER_ADMIN_ROLE, $now);
            $platformPermissionId = $this->permissionId(PlatformAccess::PLATFORM_ADMIN_PERMISSION, $now);
            $crossTenantPermissionId = $this->permissionId(PlatformAccess::CROSS_TENANT_PERMISSION, $now);

            // The global role carries only global permissions. Existing
            // operators retain the companion super-admin role for all tenant
            // permissions.
            foreach ([$platformPermissionId, $crossTenantPermissionId] as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $systemRoleId,
                ]);
            }

            DB::table('role_has_permissions')
                ->where('role_id', $systemRoleId)
                ->whereNotIn('permission_id', [$platformPermissionId, $crossTenantPermissionId])
                ->delete();

            // The tenant super-admin must never retain either global bypass.
            DB::table('role_has_permissions')
                ->where('role_id', $superRoleId)
                ->whereIn('permission_id', [$platformPermissionId, $crossTenantPermissionId])
                ->delete();

            DB::table('users')
                ->join('model_has_roles', function ($join) use ($superRoleId): void {
                    $join->on('model_has_roles.model_id', '=', 'users.id')
                        ->where('model_has_roles.role_id', $superRoleId)
                        ->where('model_has_roles.model_type', User::class);
                })
                ->select('users.id')
                ->orderBy('users.id')
                ->chunkById(100, function ($assignments) use ($systemRoleId, $now): void {
                    foreach ($assignments as $assignment) {
                        DB::table('model_has_roles')->insertOrIgnore([
                            'role_id' => $systemRoleId,
                            'model_type' => User::class,
                            'model_id' => $assignment->id,
                        ]);

                        $this->auditLegacyPromotion((int) $assignment->id, $now);
                    }
                }, 'users.id', 'id');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! $this->permissionTablesExist()) {
            return;
        }

        DB::transaction(function (): void {
            $systemRoleId = DB::table('roles')
                ->where('name', PlatformAccess::SYSTEM_ADMIN_ROLE)
                ->where('guard_name', self::GUARD)
                ->value('id');
            $superRoleId = DB::table('roles')
                ->where('name', PlatformAccess::TENANT_SUPER_ADMIN_ROLE)
                ->where('guard_name', self::GUARD)
                ->value('id');
            $crossTenantPermissionId = DB::table('permissions')
                ->where('name', PlatformAccess::CROSS_TENANT_PERMISSION)
                ->where('guard_name', self::GUARD)
                ->value('id');

            if ($superRoleId !== null && $crossTenantPermissionId !== null) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $crossTenantPermissionId,
                    'role_id' => $superRoleId,
                ]);
            }

            if ($systemRoleId !== null) {
                DB::table('roles')->where('id', $systemRoleId)->delete();
            }

            DB::table('permissions')
                ->where('name', PlatformAccess::PLATFORM_ADMIN_PERMISSION)
                ->where('guard_name', self::GUARD)
                ->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function permissionTablesExist(): bool
    {
        return Schema::hasTable('roles')
            && Schema::hasTable('permissions')
            && Schema::hasTable('role_has_permissions')
            && Schema::hasTable('model_has_roles');
    }

    private function roleId(string $name, mixed $now): int
    {
        $existing = DB::table('roles')
            ->where('name', $name)
            ->where('guard_name', self::GUARD)
            ->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('roles')->insertGetId([
            'name' => $name,
            'guard_name' => self::GUARD,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function permissionId(string $name, mixed $now): int
    {
        $existing = DB::table('permissions')
            ->where('name', $name)
            ->where('guard_name', self::GUARD)
            ->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('permissions')->insertGetId([
            'name' => $name,
            'guard_name' => self::GUARD,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function auditLegacyPromotion(int $userId, mixed $now): void
    {
        if (! Schema::hasTable('admin_command_audit')) {
            return;
        }

        $columns = [
            'user_id' => $userId,
            'command' => 'system-admin:migrate-legacy',
            'args_json' => json_encode([
                'source_role' => PlatformAccess::TENANT_SUPER_ADMIN_ROLE,
                'granted_role' => PlatformAccess::SYSTEM_ADMIN_ROLE,
            ], JSON_THROW_ON_ERROR),
            'status' => 'completed',
            'exit_code' => 0,
            'stdout_head' => 'Legacy global super-admin preserved as system-admin.',
            'error_message' => null,
            'started_at' => $now,
            'completed_at' => $now,
            'client_ip' => null,
            'user_agent' => 'database-migration',
        ];

        if (Schema::hasColumn('admin_command_audit', 'tenant_id')) {
            $columns['tenant_id'] = 'default';
        }

        DB::table('admin_command_audit')->insert($columns);
    }
};
