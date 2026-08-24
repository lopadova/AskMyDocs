<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Host SQLite MIRROR of the package migration of the same name (R31 lockstep).
 * Keep byte-structurally identical to
 * packages/askmydocs-connector-api/database/migrations/2026_07_11_000001_*.
 *
 * Endpoint taxonomy (Lista vs Dettaglio) on api_routes. `endpoint_type` is an
 * axis ORTHOGONAL to `mode`: it records the response SHAPE (list|detail|unknown),
 * auto-detected from the first successful test call. `endpoint_type_locked` marks
 * an operator override so the detector never clobbers a manual choice.
 * `items_path` (lists only) is the dot-path to the array of items inside the
 * response ('' = top-level array, 'data'/'results' = envelope key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_routes', function (Blueprint $table) {
            // ->after() is a MySQL readability nicety and a no-op on SQLite.
            $table->string('endpoint_type', 12)->default('unknown')->after('mode');
            $table->boolean('endpoint_type_locked')->default(false)->after('endpoint_type');
            $table->string('items_path', 255)->nullable()->after('endpoint_type_locked');

            $table->index(['tenant_id', 'endpoint_type'], 'idx_api_routes_tenant_endpoint_type');
        });
    }

    public function down(): void
    {
        Schema::table('api_routes', function (Blueprint $table) {
            $table->dropIndex('idx_api_routes_tenant_endpoint_type');
            $table->dropColumn(['endpoint_type', 'endpoint_type_locked', 'items_path']);
        });
    }
};
