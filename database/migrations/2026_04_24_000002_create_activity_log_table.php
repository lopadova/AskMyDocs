<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spatie laravel-activitylog table (Phase H1 — Log Viewer).
 *
 * Mirrors vendor/spatie/laravel-activitylog/database/migrations/create_activity_log_table.php.stub.
 * We publish the migration into our own `database/migrations` folder (rather
 * than relying on the package auto-load) so the same column set ships into
 * the SQLite mirror under `tests/database/migrations/` and CI stays
 * deterministic.
 *
 * The LogViewerController treats this table as an OPTIONAL dependency —
 * if the migration has not been run yet, the activity endpoint returns an
 * empty page with a `{note: 'activitylog not installed'}` hint instead of
 * crashing. So we do NOT pivot any load-bearing feature on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
