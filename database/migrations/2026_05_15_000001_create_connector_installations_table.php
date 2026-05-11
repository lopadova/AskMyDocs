<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.5/W1 — Connector framework: per-tenant installation record.
 *
 * One row per (tenant_id, connector_name) tracks a workspace's
 * relationship with an external source (Google Drive, Notion, ...).
 *
 * Lifecycle:
 *   - `pending`  : OAuth flow initiated, callback not yet completed
 *   - `active`   : credentials stored, sync running on cadence
 *   - `disabled` : operator paused the connector (credentials kept)
 *   - `errored`  : last sync failed; `error_json` carries the cause
 *
 * Tenant isolation: composite unique on `(tenant_id, connector_name)`
 * lets two tenants legitimately install the same connector without
 * cross-talk. R30 query-side scoping is enforced by the
 * `BelongsToTenant` trait on `App\Models\ConnectorInstallation`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_installations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('connector_name', 64);
            $table->json('config_json')->nullable();
            // Stored as varchar — the `enum` Blueprint type is fragile across
            // drivers (Postgres + SQLite test bench in particular). The
            // application validates the allowed values via the model.
            $table->string('status', 16)->default('pending');
            $table->timestamp('last_sync_at')->nullable();
            $table->json('error_json')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'connector_name'],
                'uq_connector_installations_tenant_name'
            );
            $table->index(['tenant_id', 'status'], 'idx_connector_installations_tenant_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_installations');
    }
};
