<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.0/W1.1 — `notification_preferences` table.
 *
 * Per-user-per-event-per-channel toggle matrix (ADR 0012).
 *
 * The dispatcher consults this table for every (user, event, channel)
 * combination before delivering. Rows are populated:
 *   - on user creation, from tenant defaults
 *     (`config('askmydocs.notifications.defaults')` or
 *     tenant-overridden in `tenant_settings`);
 *   - on every user-edit save from `/account/notifications`;
 *   - via tenant-admin bulk override from
 *     `/admin/notifications/defaults`.
 *
 * Composite unique `(tenant_id, user_id, event_type, channel)` makes
 * upserts idempotent. Starting with `tenant_id` keeps queries
 * tenant-scoped and aligns with R30.
 *
 * Channels (W1 ships `in_app` + `email`; W2 extends with `discord`,
 * `slack`, `teams`, `webhook`).
 */
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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
