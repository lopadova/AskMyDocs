<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test mirror of
 * database/migrations/2026_05_26_000001_tenant_scope_kb_tags_and_membership_uniques.php
 * (R9 — test schema must match production). Runs after the test
 * add_tenant_id mirror (0001_01_01_000024) so the tenant_id column exists.
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
