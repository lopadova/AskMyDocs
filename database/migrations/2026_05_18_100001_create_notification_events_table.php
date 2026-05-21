<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v8.0/W1.1 — `notification_events` table.
 *
 * Persists every notification fired by the dispatcher (ADR 0012).
 * Two write contracts coexist on this table:
 *
 *  - **Per-user fan-out** (`user_id != null`): the dispatcher emits
 *    ONE row per recipient so the in-app bell renders per-user
 *    state (read / dismissed) without join gymnastics. Main path.
 *  - **System-level / tenant-wide** (`user_id == null`): operator-
 *    facing events whose read state is not bound to a single user.
 *    The weekly aggregated digest is NOT stored here — it lives in
 *    `notification_digests` (which is intentionally per-tenant, no
 *    `user_id` FK).
 *
 * `payload` carries event-specific data (doc title, project key, etc.).
 * `channel_dispatch_log` records which external channels were
 * attempted and their outcome for forensic / debugging purposes —
 * structure `[{channel: 'email', status: 'queued', at: '<iso>'}]`.
 *
 * Retention: pruned by `notifications:prune` after the configured
 * retention window (default 90 days; the config key
 * `askmydocs.notifications.retention_days` is introduced in W1.5
 * alongside the prune command).
 *
 * Indexes:
 *  - `(tenant_id, user_id, dismissed_at, read_at, created_at)` — bell
 *    unread + undismissed hot path, newest first
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

            // Bell hot path: "unread + undismissed for THIS user
            // in THIS tenant, newest first". The composite covers
            // every predicate the W1.4 query will carry, in the
            // order the planner uses, so the unread-badge count and
            // the dropdown's top-N read both hit one index without
            // a sort step.
            $table->index(
                ['tenant_id', 'user_id', 'dismissed_at', 'read_at', 'created_at'],
                'idx_notif_events_bell_hot_path',
            );
            // Admin panel filter "all events of type X in tenant Y".
            $table->index(
                ['tenant_id', 'event_type'],
                'idx_notif_events_tenant_event',
            );
            // Retention sweep: `notifications:prune` walks oldest
            // rows per tenant.
            $table->index(
                ['tenant_id', 'created_at'],
                'idx_notif_events_tenant_created',
            );
            // FK cascade hot path: User deletion triggers
            // `DELETE FROM notification_events WHERE user_id = ?`.
            // The bell + admin indexes above all lead with
            // `tenant_id` (correct for read patterns), so without
            // this user-leading index the cascade falls back to a
            // table scan + row-level locks that on a 90-day
            // retained feed can hold writes back longer than
            // necessary. `tenant_id` as the trailing column keeps
            // the index small (covers the typical (user_id,
            // tenant_id) tuple) without becoming a duplicate of
            // the bell index.
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
