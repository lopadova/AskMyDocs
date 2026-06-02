<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.8/W3 — per-(tenant, project) override for the AI deep-analysis gate.
 *
 * The deep-analysis posture (on/off, canonical vs non-canonical, on-delete)
 * defaults from `config('kb.change_analysis.*')`. This table lets an operator
 * override it for a specific project — or, with `project_key='*'`, for the
 * whole tenant. Every column is NULLABLE: a null field INHERITS the next
 * level up (exact project → tenant-wide `*` → config default), so an override
 * can flip a single knob without restating the others.
 *
 * Tenant-aware per R30/R31; composite unique on (tenant_id, project_key) per
 * R28 — two tenants legitimately share `project_key='eng'`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_analysis_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            // The project this override applies to. '*' = tenant-wide default.
            $table->string('project_key', 120);
            // NULL on any flag = inherit (project → tenant '*' → config).
            $table->boolean('enabled')->nullable();
            $table->boolean('canonical')->nullable();
            $table->boolean('non_canonical')->nullable();
            $table->boolean('delete_enabled')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_key'], 'uq_kb_analysis_settings_tenant_project');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_analysis_settings');
    }
};
