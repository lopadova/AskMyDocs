<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.0/W1.1 — `notification_events` table.
 *
 * Persists every notification fired by the dispatcher (ADR 0012). One
 * row per (user, event) emission — even tenant-wide events that target
 * multiple users get one row per recipient so the in-app bell panel
 * can render per-user state (unread / read / dismissed) without
 * any join gymnastics.
 *
 * `payload` carries event-specific data (doc title, project key, etc.).
 * `channel_dispatch_log` records which external channels were
 * attempted and their outcome for forensic / debugging purposes —
 * structure `[{channel: 'email', status: 'queued', at: '<iso>'}]`.
 *
 * Retention: pruned by `notifications:prune` after
 * `config('askmydocs.notifications.retention_days', 90)` days.
 *
 * Indexes:
 *  - `(tenant_id, user_id, read_at)` — bell unread counter / list
 *  - `(tenant_id, user_id, dismissed_at)` — dismissed filter
 *  - `(tenant_id, event_type)` — admin panel filter by event
 *  - `(tenant_id, created_at)` — pruning sweep
 */
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
                ['tenant_id', 'user_id', 'read_at'],
                'idx_notif_events_tenant_user_read',
            );
            $table->index(
                ['tenant_id', 'user_id', 'dismissed_at'],
                'idx_notif_events_tenant_user_dismissed',
            );
            $table->index(
                ['tenant_id', 'event_type'],
                'idx_notif_events_tenant_event',
            );
            $table->index(
                ['tenant_id', 'created_at'],
                'idx_notif_events_tenant_created',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};
