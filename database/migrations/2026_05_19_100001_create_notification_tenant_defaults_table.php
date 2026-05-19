<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.0/W2.3 — `notification_tenant_defaults` table.
 *
 * Per-tenant baseline for new users. Super-admins edit it under
 * `/app/admin/notifications/defaults`; the `NotificationPreferencesInitializer`
 * service reads from this table when a new user is created (admin
 * add-user flow today; sign-up + invite-acceptance land in Phase B2).
 *
 * Falls back to `config('askmydocs.notifications.default_channel_preferences')`
 * when no per-tenant override exists for a given (event_type, channel)
 * — so tenants that never touch the admin grid still get sane defaults.
 *
 * Composite unique `(tenant_id, event_type, channel)` keeps upserts
 * idempotent. Starting with `tenant_id` keeps queries tenant-scoped
 * per R30.
 */
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
