<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.44/W4 (ADR 0035 §D6.4) — admin-authored descriptions bound to a
 * `source_path` prefix ("policies/" → "Policy HR vigenti, approvate dal DPO").
 *
 * Tenant-aware (R31): `tenant_id` leads the composite UNIQUE, so two tenants —
 * and two projects — may use the same prefix. One row per
 * (tenant, project, prefix); an upsert replaces the description.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kb_path_contexts')) {
            return;
        }

        Schema::create('kb_path_contexts', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('project_key', 120);
            $table->string('path_prefix', 300);
            $table->string('description', 500);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_key', 'path_prefix'], 'uq_kb_path_ctx_tenant_project_prefix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_path_contexts');
    }
};
