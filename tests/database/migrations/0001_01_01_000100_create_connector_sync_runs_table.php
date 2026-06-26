<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite test-bench mirror of the production
 * `2026_06_23_000001_create_connector_sync_runs_table` migration (v8.21).
 * Driver-portable — copied verbatim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_sync_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index('idx_connector_sync_runs_tenant');
            $table->unsignedBigInteger('connector_installation_id');
            $table->string('connector_name', 64);
            $table->string('label', 64)->default('default');
            // 255 mirrors the production widen (migration
            // 2026_06_26_000001): on SQS the recorded value is the full SQS
            // queue URL (~100+ chars), not a short queue name.
            $table->string('queue', 255)->nullable();
            $table->string('status', 16)->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('items_discovered')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->json('error_json')->nullable();
            $table->timestamps();

            $table->index(
                ['tenant_id', 'connector_installation_id', 'started_at'],
                'idx_connector_sync_runs_install_started',
            );
            $table->index(['tenant_id', 'status'], 'idx_connector_sync_runs_tenant_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_sync_runs');
    }
};
