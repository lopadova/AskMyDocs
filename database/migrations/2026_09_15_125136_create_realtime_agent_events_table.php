<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_agent_events', function (Blueprint $table): void {
            $table->string('id', 30)->primary();
            $table->foreignUlid('session_id')
                ->constrained('realtime_agent_sessions')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('seq');
            $table->string('type')->index();
            $table->string('source');
            $table->json('payload');
            $table->unsignedBigInteger('state_revision');
            $table->string('provider_event_id')->nullable();
            $table->timestamp('created_at');

            $table->unique(['session_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_agent_events');
    }
};
