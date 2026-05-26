<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.0.3 security hotfix (H2 / M3 / R31) — rebuild the kb_tags and
 * project_memberships composite uniques to start with tenant_id.
 *
 * 2026_04_28_000001_add_tenant_id_to_v3_tables deliberately DEFERRED the
 * composite-unique rebuild because the kb_nodes / kb_edges uniques carry
 * FK dependents (kb_edges' composite FK references kb_nodes' unique), and
 * dropping those requires raw DROP CONSTRAINT ... CASCADE + FK rebuild.
 *
 * kb_tags and project_memberships are NOT FK-entangled — no other table's
 * FK references their composite uniques (knowledge_document_tags references
 * kb_tags.id, the PK, not the unique). So they can be rebuilt with the
 * portable Blueprint API safely, closing the cross-tenant collision gap
 * the deep review flagged:
 *
 *   - kb_tags:            (project_key, slug) → (tenant_id, project_key, slug)
 *   - project_memberships:(user_id, project_key) → (tenant_id, user_id, project_key)
 *
 * The kb_nodes / kb_edges rebuild remains deferred until a multi-tenant
 * customer onboards, per the original migration's note.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kb_tags') && Schema::hasColumn('kb_tags', 'tenant_id')) {
            Schema::table('kb_tags', function (Blueprint $table): void {
                $table->dropUnique('uq_kb_tags_project_slug');
                $table->unique(['tenant_id', 'project_key', 'slug'], 'uq_kb_tags_tenant_project_slug');
            });
        }

        if (Schema::hasTable('project_memberships') && Schema::hasColumn('project_memberships', 'tenant_id')) {
            Schema::table('project_memberships', function (Blueprint $table): void {
                $table->dropUnique('uq_project_memberships_user_project');
                $table->unique(['tenant_id', 'user_id', 'project_key'], 'uq_project_memberships_tenant_user_project');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('kb_tags') && Schema::hasColumn('kb_tags', 'tenant_id')) {
            Schema::table('kb_tags', function (Blueprint $table): void {
                $table->dropUnique('uq_kb_tags_tenant_project_slug');
                $table->unique(['project_key', 'slug'], 'uq_kb_tags_project_slug');
            });
        }

        if (Schema::hasTable('project_memberships') && Schema::hasColumn('project_memberships', 'tenant_id')) {
            Schema::table('project_memberships', function (Blueprint $table): void {
                $table->dropUnique('uq_project_memberships_tenant_user_project');
                $table->unique(['user_id', 'project_key'], 'uq_project_memberships_user_project');
            });
        }
    }
};
