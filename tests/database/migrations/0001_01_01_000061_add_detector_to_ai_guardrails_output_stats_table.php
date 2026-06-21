<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration for existing installs: adds the nullable `detector` column
 * (introduced in v1.1.0) to the already-migrated ai_guardrails_output_stats table.
 * Fresh installs get the column from the create-table stub directly and must NOT run this.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ai_guardrails_output_stats', 'detector')) {
            return;
        }

        Schema::table('ai_guardrails_output_stats', function (Blueprint $table): void {
            // Nullable: populated only for PiiRedaction events when the pii-redactor exposes
            // per-detector counts. Null = detector breakdown unavailable (legacy rows or
            // redactor absent). Indexed so byDetector() GROUP BY queries remain O(log n).
            // Use $table->index() without a hard-coded name so Laravel infers the name from
            // the column list — symmetric with the dropIndex(['detector']) call in down().
            $table->string('detector')->nullable()->after('event_count');
            $table->index('detector');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ai_guardrails_output_stats', 'detector')) {
            return;
        }

        Schema::table('ai_guardrails_output_stats', function (Blueprint $table): void {
            // Drop by column list so Laravel infers the name — portable across DB table prefixes
            // and custom index naming conventions, symmetric with the index() call in up().
            $table->dropIndex(['detector']);
            $table->dropColumn('detector');
        });
    }
};
