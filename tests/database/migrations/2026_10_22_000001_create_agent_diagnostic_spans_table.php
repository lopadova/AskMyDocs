<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_diagnostic_spans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id', 50);
            $table->foreignId('agent_run_id')->constrained('agent_runs')->cascadeOnDelete();
            $table->uuid('parent_span_id')->nullable();
            $table->string('phase', 80);
            $table->string('status', 20);
            $table->json('metadata_json')->nullable();
            $table->timestamp('started_at', 6);
            $table->timestamp('ended_at', 6)->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamps(6);
            $table->index(['tenant_id', 'agent_run_id'], 'idx_diagnostic_spans_tenant_run');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_diagnostic_spans');
    }
};
