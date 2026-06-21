<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * `projects` — first-class registry of the project_key namespace.
 *
 * Until now a "project" was an implicit string: a `project_key` that
 * happened to appear on a tenant-aware row (knowledge_documents,
 * project_memberships, chat_logs, …). There was nowhere to NAME a
 * project, describe it, or create one before its first document. This
 * table makes the project a real, manageable entity (admin Projects
 * page) while staying a SOFT registry: there is intentionally NO hard
 * FK from documents/memberships onto it, so the "DB is rebuildable from
 * the canonical markdown" invariant (CLAUDE.md §6) is preserved and the
 * ingest pipeline is never blocked by a missing registry row.
 *
 * Tenant-aware per R30/R31: `tenant_id` defaults to 'default' (v3
 * back-compat) and the UNIQUE is `(tenant_id, project_key)` — two
 * tenants can legitimately share the same key (R28).
 *
 * The up() backfills one row per distinct (tenant_id, project_key) that
 * already exists in knowledge_documents or project_memberships, so every
 * project currently in use surfaces in the new management page on day 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tenant_id', 50)->default('default')->index();
            // The stable join key documents/memberships reference. Immutable
            // after creation (the controller rejects a change with 422).
            $table->string('project_key', 120);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'project_key'], 'uq_projects_tenant_key');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }

    /**
     * Seed the registry from every (tenant_id, project_key) already in use,
     * so no in-use project disappears from the new picker / admin page.
     */
    private function backfill(): void
    {
        $now = now();
        $seen = [];
        $rows = [];

        foreach (['knowledge_documents', 'project_memberships'] as $source) {
            if (! Schema::hasTable($source)) {
                continue;
            }

            DB::table($source)
                ->select('tenant_id', 'project_key')
                ->whereNotNull('project_key')
                ->where('project_key', '!=', '')
                ->distinct()
                ->orderBy('tenant_id')
                ->orderBy('project_key')
                ->each(function ($row) use (&$seen, &$rows, $now): void {
                    $tenant = (string) ($row->tenant_id ?? 'default');
                    $key = (string) $row->project_key;
                    $dedupe = $tenant.'|'.$key;
                    if (isset($seen[$dedupe])) {
                        return;
                    }
                    $seen[$dedupe] = true;
                    $rows[] = [
                        'tenant_id' => $tenant,
                        'project_key' => $key,
                        'name' => Str::headline($key),
                        'description' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                });
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            // insertOrIgnore: the composite UNIQUE makes re-runs / overlap
            // between the two sources a no-op.
            DB::table('projects')->insertOrIgnore($chunk);
        }
    }
};
