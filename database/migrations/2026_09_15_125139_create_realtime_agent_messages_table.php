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
            $table->unsignedBigInteger('message_sequence')->default(0)->after('event_sequence');
        });

        Schema::create('realtime_agent_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('session_id')->constrained('realtime_agent_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('seq');
            $table->string('provider');
            $table->string('provider_event_id')->nullable();
            $table->string('role');
            $table->string('direction');
            $table->string('modality');
            $table->string('status')->default('completed');
            $table->longText('content');
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['session_id', 'seq']);
            $table->unique(['session_id', 'idempotency_key']);
            $table->index(['session_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_agent_messages');
        Schema::table('realtime_agent_sessions', function (Blueprint $table): void {
            $table->dropColumn('message_sequence');
        });
    }
};
