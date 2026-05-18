<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->json('payload');
            $table->json('channel_dispatch_log')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['tenant_id', 'user_id', 'dismissed_at', 'read_at', 'created_at'],
                'idx_notif_events_bell_hot_path',
            );
            $table->index(
                ['tenant_id', 'event_type'],
                'idx_notif_events_tenant_event',
            );
            $table->index(
                ['tenant_id', 'created_at'],
                'idx_notif_events_tenant_created',
            );
            $table->index(
                ['user_id', 'tenant_id'],
                'idx_notif_events_user_cascade',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};
