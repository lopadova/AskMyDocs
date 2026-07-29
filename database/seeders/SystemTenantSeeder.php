<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\SystemTenantRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Materialise the reserved tenant namespaces used by platform workflows.
 *
 * Re-running this seeder restores the controlled system fields without
 * touching operational tenants or rewriting the row's creation timestamp.
 */
final class SystemTenantSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasColumn('tenants', 'is_system')) {
            return;
        }

        $now = now();
        $existing = DB::table('tenants')
            ->where('slug', SystemTenantRegistry::REGISTRATION)
            ->exists();

        $attributes = [
            'name' => 'System Registration',
            'subscription_tier' => 'team',
            'status' => 'active',
            'is_system' => true,
            'dpo_email' => null,
            'contact_email' => null,
            'config_overrides_json' => null,
            'suspended_at' => null,
            'archived_at' => null,
            'updated_at' => $now,
        ];

        if ($existing) {
            DB::table('tenants')
                ->where('slug', SystemTenantRegistry::REGISTRATION)
                ->update($attributes);

            return;
        }

        DB::table('tenants')->insert([
            'slug' => SystemTenantRegistry::REGISTRATION,
            ...$attributes,
            'created_at' => $now,
        ]);
    }
}
