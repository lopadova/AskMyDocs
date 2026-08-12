<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeder for the dashboard empty-state Playwright scenario.
 *
 * Produces the minimum stateful surface the admin UI needs — two
 * users (admin + viewer, for auth reuse) with roles assigned — and
 * absolutely nothing else: no KnowledgeDocument rows, no chunks, no
 * ChatLog rows, no conversations, no canonical audit. The dashboard
 * is expected to render every chart in its `empty` state.
 */
class EmptyAdminSeeder extends Seeder
{
    public function run(): void
    {
        $canonicalRoles = ['system-admin', 'super-admin', 'admin', 'dpo', 'editor', 'viewer'];
        if (Role::query()->whereIn('name', $canonicalRoles)->count() !== count($canonicalRoles)) {
            $this->call(RbacSeeder::class);
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@demo.local'],
            [
                'name' => 'Demo Admin',
                'password' => Hash::make('password'),
            ],
        );
        if (! $admin->hasRole('super-admin')) {
            $admin->assignRole('super-admin');
        }

        $viewer = User::firstOrCreate(
            ['email' => 'viewer@demo.local'],
            [
                'name' => 'Demo Viewer',
                'password' => Hash::make('password'),
            ],
        );
        if (! $viewer->hasRole('viewer')) {
            $viewer->assignRole('viewer');
        }

        // The admin shell now requires an operational tenant membership.
        // Keep the dashboard dataset empty while still modelling a valid
        // company/project boundary, otherwise the SPA correctly renders the
        // company-onboarding gate instead of the dashboard empty states.
        if (Schema::hasTable('tenants')) {
            Tenant::query()->updateOrCreate(
                ['slug' => DemoSeeder::PRIMARY_TENANT],
                ['name' => 'Empty Dashboard Company', 'status' => 'active', 'is_system' => false],
            );
        }

        Project::query()->updateOrCreate(
            [
                'tenant_id' => DemoSeeder::PRIMARY_TENANT,
                'project_key' => 'empty-dashboard',
            ],
            ['name' => 'Empty Dashboard', 'description' => 'E2E empty-state project.'],
        );

        foreach ([$admin, $viewer] as $user) {
            ProjectMembership::query()->firstOrCreate(
                [
                    'tenant_id' => DemoSeeder::PRIMARY_TENANT,
                    'user_id' => $user->id,
                    'project_key' => 'empty-dashboard',
                ],
                ['role' => 'member', 'scope_allowlist' => null],
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
