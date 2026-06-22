<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Back-fill the `grant` JSON column on invite_campaigns + invite_codes.
 *
 * The column was added to the original create-table migrations after they had
 * already run on existing databases (commit 5a770fb4) — so those DBs never got
 * it, and writing a campaign grant fails with
 * `column "grant" of relation "invite_campaigns" does not exist`.
 *
 * Guarded with hasColumn so it's a no-op on fresh databases (where the create
 * migration already defines `grant`) and purely additive on drifted ones.
 * `grant` is a reserved word in PostgreSQL; the schema builder quotes it.
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
