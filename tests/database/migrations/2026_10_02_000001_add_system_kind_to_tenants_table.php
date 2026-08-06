<?php

declare(strict_types=1);

use Database\Seeders\SystemTenantSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite mirror of the production system-tenant registry migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants')) {
            return;
        }

        if (! Schema::hasColumn('tenants', 'is_system')) {
            Schema::table('tenants', function (Blueprint $table): void {
                $table->boolean('is_system')
                    ->default(false)
                    ->index('ix_tenants_is_system');
            });
        }

        (new SystemTenantSeeder)->run();
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasColumn('tenants', 'is_system')) {
            return;
        }

        DB::table('tenants')
            ->where('slug', \App\Support\SystemTenantRegistry::REGISTRATION)
            ->where('is_system', true)
            ->delete();

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropIndex('ix_tenants_is_system');
            $table->dropColumn('is_system');
        });
    }
};
