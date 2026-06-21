<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_guardrails_output_stats', function (Blueprint $table): void {
            $table->id();
            $table->string('kind')->index();
            // Named `event_count` (not `count`) to avoid the SQL reserved word in aggregate expressions.
            $table->unsignedInteger('event_count')->default(1);
            // Nullable: populated only for PiiRedaction events when the pii-redactor exposes per-detector
            // counts. Null = detector breakdown unavailable (legacy rows or redactor absent). Indexed so
            // byDetector() GROUP BY queries remain O(log n).
            $table->string('detector')->nullable()->index();
            $table->timestamp('occurred_at')->index();
            // Append-only: intentionally NO updated_at — rows are never modified in place.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_guardrails_output_stats');
    }
};
