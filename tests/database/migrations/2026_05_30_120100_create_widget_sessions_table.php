<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror SQLite di database/migrations/..._create_widget_sessions_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_sessions', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('widget_key_id')->constrained('widget_keys')->cascadeOnDelete();
            $table->string('project_key', 120);

            $table->uuid('public_session_id')->unique();
            $table->string('status', 20)->default('active')->index();

            $table->string('skill', 100)->nullable();
            $table->string('mission', 120)->nullable();
            $table->string('page_url', 1024)->nullable();
            $table->string('origin', 255)->nullable();

            $table->text('summary')->nullable();
            $table->text('blocked_reason')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['widget_key_id', 'status'], 'idx_widget_sessions_key_status');
            $table->index(['tenant_id', 'created_at'], 'idx_widget_sessions_tenant_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_sessions');
    }
};
