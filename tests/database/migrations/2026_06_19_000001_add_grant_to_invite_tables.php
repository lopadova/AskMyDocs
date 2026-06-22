<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite test mirror of database/migrations/2026_06_19_000001_add_grant_to_invite_tables.php.
 * No-op on the fresh test schema (the create-table mirror already defines
 * `grant`); kept in lockstep with production per the test-migration convention.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['invite_campaigns', 'invite_codes'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'grant')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->json('grant')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'grant')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropColumn('grant');
                });
            }
        }
    }
};
