<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of database/migrations/2026_04_24_000002_create_activity_log_table.php
 * for the SQLite test DB. Keeps the activity_log table available so the
 * LogViewerController happy-path test can seed + assert on real rows.
 *
 * One feature test (`activity_returns_empty_note_when_table_missing`)
 * intentionally drops the table at runtime to exercise the
 * Schema::hasTable() soft-dependency branch.
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
