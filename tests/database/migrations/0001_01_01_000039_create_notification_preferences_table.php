<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->string('channel', 32);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'user_id', 'event_type', 'channel'],
                'uq_notif_prefs_tenant_user_event_channel',
            );
            $table->index(
                ['tenant_id', 'event_type', 'channel', 'enabled', 'user_id'],
                'idx_notif_prefs_dispatcher_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
