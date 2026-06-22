<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite test-bench mirror of
 * `padosoft/askmydocs-connector-base` migration
 * `2026_06_22_000001_add_label_and_project_key_to_connector_installations`
 * (v1.3.0). Laravel dedups migrations by filename, so this identical-named
 * copy keeps the test schema in lockstep with what the package SP loads in
 * production (v4.6 mirror convention, `tests/TestCase.php::defineDatabaseMigrations`).
 *
 * One deliberate divergence from the package source: the label-disambiguation
 * helper trims with multibyte-safe `mb_substr`/`mb_strlen` (the package uses
 * byte-based `substr`/`strlen`, which can split a multibyte label at the 64-char
 * boundary). To be upstreamed to connector-base in a follow-up release.
 *
 * Multi-account + project-scoped connector installations.
 *
 * Before: exactly one installation per (tenant_id, connector_name).
 * After: MORE THAN ONE installation per (tenant_id, connector_name),
 * disambiguated by a human-chosen `label`. The composite unique relaxes
 * to (tenant_id, connector_name, label) — still tenant-first (R30/R31),
 * now label-disambiguated (R28-style). Each installation may optionally
 * bind to a real `project_key`; an empty binding falls back to the
 * host's `kb.ingest.default_project` (resolved once in
 * `BaseConnector::resolveProjectKey()`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connector_installations', function (Blueprint $table) {
            if (! Schema::hasColumn('connector_installations', 'label')) {
                $table->string('label', 64)->default('default');
            }
            if (! Schema::hasColumn('connector_installations', 'project_key')) {
                $table->string('project_key', 120)->nullable();
            }
        });

        if (Schema::hasIndex('connector_installations', 'uq_connector_installations_tenant_name')) {
            Schema::table('connector_installations', function (Blueprint $table) {
                $table->dropUnique('uq_connector_installations_tenant_name');
            });
        }

        $this->disambiguateDuplicateLabels();

        if (! Schema::hasIndex('connector_installations', 'uq_connector_installations_tenant_name_label')) {
            Schema::table('connector_installations', function (Blueprint $table) {
                $table->unique(
                    ['tenant_id', 'connector_name', 'label'],
                    'uq_connector_installations_tenant_name_label'
                );
            });
        }

        if (! Schema::hasIndex('connector_installations', 'idx_connector_installations_tenant_project')) {
            Schema::table('connector_installations', function (Blueprint $table) {
                $table->index(
                    ['tenant_id', 'project_key'],
                    'idx_connector_installations_tenant_project'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::table('connector_installations', function (Blueprint $table) {
            $table->dropUnique('uq_connector_installations_tenant_name_label');
            $table->dropIndex('idx_connector_installations_tenant_project');
        });

        Schema::table('connector_installations', function (Blueprint $table) {
            $table->dropColumn(['label', 'project_key']);
        });
    }

    private function disambiguateDuplicateLabels(): void
    {
        $collidingGroups = DB::table('connector_installations')
            ->select('tenant_id', 'connector_name', 'label')
            ->groupBy('tenant_id', 'connector_name', 'label')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($collidingGroups as $group) {
            $ids = DB::table('connector_installations')
                ->where('tenant_id', $group->tenant_id)
                ->where('connector_name', $group->connector_name)
                ->where('label', $group->label)
                ->orderBy('id')
                ->pluck('id');

            foreach ($ids->slice(1) as $id) {
                $newLabel = $this->uniqueLabelFor(
                    $group->tenant_id,
                    $group->connector_name,
                    $group->label,
                    $id,
                );

                DB::table('connector_installations')
                    ->where('id', $id)
                    ->update(['label' => $newLabel]);
            }
        }
    }

    private function uniqueLabelFor(string $tenant, string $connector, string $base, int $id): string
    {
        $taken = DB::table('connector_installations')
            ->where('tenant_id', $tenant)
            ->where('connector_name', $connector)
            ->where('id', '!=', $id)
            ->pluck('label')
            ->flip();

        // Multibyte-safe: labels allow Unicode (the HTTP/API regex is \pL\pN),
        // so trim with mb_substr/mb_strlen — a byte-based substr could split a
        // multibyte char and store invalid UTF-8.
        $build = static function (string $suffix) use ($base): string {
            return mb_substr($base, 0, max(0, 64 - mb_strlen($suffix))).$suffix;
        };

        $candidate = $build('-'.$id);
        $bump = 1;
        while ($taken->has($candidate)) {
            $candidate = $build('-'.$id.'-'.$bump);
            $bump++;
        }

        return $candidate;
    }
};
