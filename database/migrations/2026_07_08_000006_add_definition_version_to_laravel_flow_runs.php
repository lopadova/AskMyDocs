<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_runs')) {
            return;
        }

        $hasVersion = Schema::hasColumn('flow_runs', 'definition_version');
        $hasChecksum = Schema::hasColumn('flow_runs', 'definition_checksum');

        if ($hasVersion && $hasChecksum) {
            return;
        }

        // Guarded independently (not "both or neither") so a partially
        // applied migration — e.g. one column added by hand, or a prior
        // run that failed mid-ALTER — still gets exactly the missing
        // column(s) instead of being skipped entirely or re-adding one
        // that already exists.
        Schema::table('flow_runs', function (Blueprint $table) use ($hasVersion, $hasChecksum): void {
            if (! $hasVersion) {
                $version = $table->unsignedInteger('definition_version')->nullable();

                // Anchor after the replay-lineage column only when it
                // actually exists: on MySQL/MariaDB, ->after() on a
                // missing column fails the ALTER TABLE outright, whereas
                // SQLite/Postgres ignore column placement. A host table
                // that predates or otherwise lacks 2026_05_04_000002 must
                // still get this column appended.
                if (Schema::hasColumn('flow_runs', 'replayed_from_run_id')) {
                    $version->after('replayed_from_run_id');
                }
            }

            if (! $hasChecksum) {
                $checksum = $table->string('definition_checksum', 64)->nullable();

                if (! $hasVersion) {
                    $checksum->after('definition_version');
                } elseif (Schema::hasColumn('flow_runs', 'replayed_from_run_id')) {
                    $checksum->after('replayed_from_run_id');
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('flow_runs')) {
            return;
        }

        $columns = array_filter(
            ['definition_version', 'definition_checksum'],
            static fn (string $column): bool => Schema::hasColumn('flow_runs', $column),
        );

        if ($columns === []) {
            return;
        }

        Schema::table('flow_runs', function (Blueprint $table) use ($columns): void {
            $table->dropColumn(array_values($columns));
        });
    }
};
