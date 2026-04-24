<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
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
        if (Role::query()->count() === 0) {
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

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
