<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_agent_session_links', function (Blueprint $table): void {
            $table->ulid('session_id')->primary();
            $table->foreign('session_id')
                ->references('id')
                ->on('realtime_agent_sessions')
                ->cascadeOnDelete();
            $table->string('tenant_id', 50)->index();
            // Users are host-wide and can be hard-deleted while operational
            // audit remains. Keep the immutable numeric attribution instead of
            // cascading the link and orphaning its vendor session.
            $table->unsignedBigInteger('user_id')->index();
            $table->foreignId('conversation_id')
                ->nullable()
                ->constrained('conversations')
                ->nullOnDelete();
            $table->json('filters')->nullable();
            $table->json('live_sources')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(
                ['tenant_id', 'user_id', 'conversation_id'],
                'idx_realtime_links_tenant_user_conversation',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_agent_session_links');
    }
};
