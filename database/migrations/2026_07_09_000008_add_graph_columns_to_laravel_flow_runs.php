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

        $columns = [
            'engine' => static fn (Blueprint $table) => $table->string('engine', 16)->nullable(),
            'nodes_total' => static fn (Blueprint $table) => $table->unsignedInteger('nodes_total')->nullable(),
            'nodes_completed' => static fn (Blueprint $table) => $table->unsignedInteger('nodes_completed')->nullable(),
            'nodes_failed' => static fn (Blueprint $table) => $table->unsignedInteger('nodes_failed')->nullable(),
        ];

        // Anchor each new column after the previous one only when that
        // anchor already exists: on MySQL/MariaDB ->after() on a missing
        // column fails the ALTER outright, whereas SQLite/Postgres ignore
        // placement. Guard every column independently so a partially
        // applied migration still gets exactly the missing column(s).
        $previous = 'definition_checksum';

        Schema::table('flow_runs', function (Blueprint $table) use ($columns, &$previous): void {
            foreach ($columns as $name => $define) {
                if (Schema::hasColumn('flow_runs', $name)) {
                    $previous = $name;

                    continue;
                }

                $column = $define($table);

                if (Schema::hasColumn('flow_runs', $previous)) {
                    $column->after($previous);
                }

                $previous = $name;
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('flow_runs')) {
            return;
        }

        $columns = array_values(array_filter(
            ['engine', 'nodes_total', 'nodes_completed', 'nodes_failed'],
            static fn (string $column): bool => Schema::hasColumn('flow_runs', $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('flow_runs', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }
};
