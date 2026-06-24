<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.23 (Ciclo 4) — SQLite test mirror of
 * `database/migrations/2026_06_24_000001_create_kb_pii_settings_table.php`.
 * `tests/database/migrations/` is the only path the SQLite test runner loads
 * (TestCase::loadMigrationsFrom). Schema-equivalent (no vector columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_pii_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('project_key', 120);
            $table->boolean('redact_enabled')->nullable();
            $table->string('strategy', 20)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_key'], 'uq_kb_pii_settings_tenant_project');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_pii_settings');
    }
};
