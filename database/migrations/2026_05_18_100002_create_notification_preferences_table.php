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
 * combination before delivering. The W1.1 schema covers persistence
 * only; the population paths land in follow-up sub-PRs of the same
 * cycle:
 *   - **W2.3** — populate from tenant defaults on `User::created`.
 *     Sources `config('askmydocs.notifications.defaults')` (config
 *     file introduced in W2) with per-tenant override in
 *     `tenant_settings` (table introduced in W2).
 *   - **W2.2** — `/app/account/notifications` user-edit save.
 *   - **W2.3** — `/app/admin/notifications/defaults` tenant-admin
 *     bulk override.
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
            // W2 dispatcher hot path: "for this (tenant, event,
            // channel), which users have it enabled?" — drives the
            // recipient set on every event fire. `user_id` as the
            // trailing index column lets the planner return the
            // recipient IDs from the index itself (covering scan)
            // without a heap lookup for every enabled preference
            // row — material on tenants with thousands of users.
            $table->index(
                ['tenant_id', 'event_type', 'channel', 'enabled', 'user_id'],
                'idx_notif_prefs_dispatcher_lookup',
            );
            // FK cascade hot path: `User::forceDelete()` triggers
            // `DELETE FROM notification_preferences WHERE user_id = ?`.
            // PostgreSQL does NOT auto-index child FK columns; the
            // unique + dispatcher indexes above both lead with
            // `tenant_id` so the cascade would fall back to a full
            // table scan + locks on every user removal. Mirror the
            // same `(user_id, tenant_id)` index pattern used by
            // `notification_events`.
            $table->index(
                ['user_id', 'tenant_id'],
                'idx_notif_prefs_user_cascade',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
