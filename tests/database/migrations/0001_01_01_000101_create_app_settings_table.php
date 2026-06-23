<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite test-bench mirror of the production
 * `2026_06_23_000002_create_app_settings_table` migration (v8.22).
 * Schema-equivalent (same columns, indexes, and composite unique); the
 * production file carries extra explanatory comments not reproduced here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index('idx_app_settings_tenant');
            $table->string('project_key', 120)->default('*');
            $table->string('setting_key', 120);
            $table->json('value_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'project_key', 'setting_key'],
                'uq_app_settings_tenant_project_key',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
