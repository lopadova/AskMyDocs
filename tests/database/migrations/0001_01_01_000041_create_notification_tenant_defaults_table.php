<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_tenant_defaults', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 50)->default('default')->index();
            $table->string('event_type', 64);
            $table->string('channel', 32);
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'event_type', 'channel'],
                'uq_notif_tenant_defaults_event_channel',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_tenant_defaults');
    }
};
