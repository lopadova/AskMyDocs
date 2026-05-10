<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.2/W4 sub-PR 5 — TEST mirror of the production migrations:
 *   - database/migrations/2026_05_10_021616_create_pii_redactor_admin_audit_events_table.php (vendor)
 *   - database/migrations/2026_05_10_021617_add_tenant_id_to_pii_redactor_admin_audit_events_table.php
 *
 * Combined here so SQLite tests under Orchestra Testbench can boot the
 * pii-redactor-admin audit table + tenant_id column without depending on
 * the package's published migration (which lives outside this repo's
 * tests/database/migrations stack).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pii_redactor_admin_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 64);
            $table->string('actor_id', 255)->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('strategy', 32)->nullable();
            $table->unsignedInteger('total')->nullable();
            $table->json('counts_json')->nullable();
            $table->string('target_hash', 64)->nullable()->index();
            $table->string('target_ref', 255)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('justification', 500)->nullable();
            // v4.2/W4 sub-PR 5 — R30/R31 tenant scoping. Default 'default'
            // mirrors the production migration so v3.x backward compat
            // is preserved.
            $table->string('tenant_id', 50)->default('default')->index();
            $table->timestamps();
            $table->index(['event_type', 'status_code', 'id'], 'pra_audit_event_status_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pii_redactor_admin_audit_events');
    }
};
