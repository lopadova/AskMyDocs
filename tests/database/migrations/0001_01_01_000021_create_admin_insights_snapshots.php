<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of database/migrations/2026_04_24_000020_create_admin_insights_snapshots.php
 * for the SQLite test DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_insights_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('snapshot_date')->unique();
            $table->json('suggest_promotions')->nullable();
            $table->json('orphan_docs')->nullable();
            $table->json('suggested_tags')->nullable();
            $table->json('coverage_gaps')->nullable();
            $table->json('stale_docs')->nullable();
            $table->json('quality_report')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->integer('computed_duration_ms')->nullable();

            // Copilot #8 fix: mirror of the production migration —
            // unique() already provides the index we need for the
            // SPA's "latest snapshot" lookup. No explicit duplicate.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_insights_snapshots');
    }
};
