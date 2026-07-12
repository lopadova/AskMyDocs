<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HOST MIRROR (R31 lockstep) of the package migration
 * database/migrations/2026_07_11_000003_add_pagination_to_api_routes_table.php.
 * The host SQLite test DB loads these mirrors; keep byte-for-byte in step with
 * the package copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_routes', function (Blueprint $table) {
            $table->json('pagination')->nullable()->after('output_transform');
        });
    }

    public function down(): void
    {
        Schema::table('api_routes', function (Blueprint $table) {
            $table->dropColumn('pagination');
        });
    }
};
