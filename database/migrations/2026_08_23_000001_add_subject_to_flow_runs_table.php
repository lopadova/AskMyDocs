<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_runs') || Schema::hasColumn('flow_runs', 'subject')) {
            return;
        }

        Schema::table('flow_runs', function (Blueprint $table): void {
            // The subject the run acts FOR (e.g. "user:42") — set when the run is
            // started on behalf of someone (agent-initiated / delegated tool calls).
            // Identity lives HERE, never in the unredacted `input` payload.
            $table->string('subject')->nullable()->after('correlation_id')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('flow_runs') || ! Schema::hasColumn('flow_runs', 'subject')) {
            return;
        }

        Schema::table('flow_runs', function (Blueprint $table): void {
            $table->dropIndex(['subject']);
            $table->dropColumn('subject');
        });
    }
};
