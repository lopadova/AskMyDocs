<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('realtime_agent_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('usage_sequence')->default(0)->after('message_sequence');
        });

        Schema::create('realtime_agent_usage', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('session_id')->constrained('realtime_agent_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('seq');
            $table->string('provider');
            $table->string('provider_event_id')->nullable();
            $table->string('kind');
            $table->string('model')->nullable();
            $table->json('units');
            $table->json('raw');
            $table->json('pricing')->nullable();
            $table->decimal('amount', 18, 8)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('estimated');
            $table->string('idempotency_key', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['session_id', 'seq']);
            $table->unique(['session_id', 'idempotency_key']);
            $table->index(['session_id', 'occurred_at']);
            $table->index(['provider', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_agent_usage');
        Schema::table('realtime_agent_sessions', function (Blueprint $table): void {
            $table->dropColumn('usage_sequence');
        });
    }
};
