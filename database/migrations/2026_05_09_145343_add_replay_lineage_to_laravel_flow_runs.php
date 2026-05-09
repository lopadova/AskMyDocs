<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_runs') || Schema::hasColumn('flow_runs', 'replayed_from_run_id')) {
            return;
        }

        Schema::table('flow_runs', function (Blueprint $table): void {
            $table->string('replayed_from_run_id', 36)->nullable()->after('idempotency_key')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('flow_runs') || ! Schema::hasColumn('flow_runs', 'replayed_from_run_id')) {
            return;
        }

        Schema::table('flow_runs', function (Blueprint $table): void {
            $table->dropIndex(['replayed_from_run_id']);
            $table->dropColumn('replayed_from_run_id');
        });
    }
};
