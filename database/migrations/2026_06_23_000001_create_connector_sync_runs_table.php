<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.21 (Ciclo 2) — per-run observability for connector sync.
 *
 * One row per `ConnectorSyncJob` execution, recorded host-side by
 * {@see \App\Connectors\ConnectorSyncRunRecorder} off Laravel's queue
 * lifecycle events (the package job emits no events, so the host observes it).
 * Lets the admin "Ingestion & Sync" screen show per-account sync history:
 * when it ran, how long it took, how many documents it discovered/dispatched,
 * and whether it succeeded / partially failed / failed.
 *
 * R31: `tenant_id` mandatory, indexed, composite uniques/indexes start with it.
 * No FK to `connector_installations` — runs are a forensic history that must
 * survive an installation delete (mirrors `kb_canonical_audit`).
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
            $table->string('queue', 64)->nullable();
            // running | success | partial | failed
            $table->string('status', 16)->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            // Documents the connector discovered + handed to ingestion during
            // this run (counted via HostIngestionBridge::dispatchIngestion).
            $table->unsignedInteger('items_discovered')->default(0);
            // Per-document ingestion outcome is derived from flow_runs at read
            // time; items_failed here captures sync-level partial errors.
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
