<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_runs') || Schema::hasColumn('flow_runs', 'graph')) {
            return;
        }

        Schema::table('flow_runs', function (Blueprint $table): void {
            // Canonical graph a queued run executes, stored so the queued
            // fan-out/sub-flow join can reload a suspended parent run's graph by
            // run id and re-advance it. Structure only (node types, wiring,
            // static config) — NOT per-execution runtime data — so, like
            // flow_definitions.graph, it is stored UNREDACTED.
            $column = $table->json('graph')->nullable();

            if (Schema::hasColumn('flow_runs', 'input')) {
                $column->after('input');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('flow_runs') || ! Schema::hasColumn('flow_runs', 'graph')) {
            return;
        }

        Schema::table('flow_runs', function (Blueprint $table): void {
            $table->dropColumn('graph');
        });
    }
};
