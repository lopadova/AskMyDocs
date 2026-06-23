<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.22 (Ciclo 3) — runtime configuration governance.
 *
 * A generic per-(tenant, project) key/value override store, layered over the
 * env/config defaults (pattern: `kb_analysis_settings` + `ChangeAnalysisGate`):
 *   config default  ←  tenant row (project_key='*')  ←  exact-project row.
 *
 * Lets operators change governable knobs (AI provider per tenant, connector
 * sync cadence, runtime-flippable package switches) WITHOUT a deploy. Only keys
 * in `AppSettingRegistry` are writable; security-sensitive knobs are marked
 * deploy-only and rejected. Secrets are NEVER stored here — they stay in the
 * encrypted vault.
 *
 * R31: tenant_id mandatory, indexed; the unique starts with tenant_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index('idx_app_settings_tenant');
            // '*' = the tenant-wide default; any other value = exact-project override.
            $table->string('project_key', 120)->default('*');
            $table->string('setting_key', 120);
            // JSON-encoded value (bool / int / string / enum), nullable = "unset".
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
