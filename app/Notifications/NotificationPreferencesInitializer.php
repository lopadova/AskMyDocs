<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\NotificationTenantDefault;

/**
 * v8.0/W2.3 — Populate `notification_preferences` for a brand-new
 * user from the active tenant's baseline.
 *
 * Resolution order:
 *  1. Per-tenant rows in `notification_tenant_defaults` (edited by
 *     super-admin via `/api/admin/notifications/defaults`).
 *  2. Platform fallback `config('askmydocs.notifications.default_channel_preferences')`
 *     — a `{channel: bool}` map applied uniformly across every
 *     event_type the model exposes.
 *
 * Idempotent in resulting state: calling twice for the same
 * `(user, tenant)` pair lands the same final `enabled` map (composite
 * unique `(tenant_id, user_id, event_type, channel)` keeps row count
 * fixed). The second call still bumps `updated_at` on every row via
 * the `upsert`'s update branch, but the business value is unchanged
 * — safe to retry on partial failure.
 *
 * **R30 — do NOT invoke from `User::created`.** `User` is a
 * cross-tenant identity in this codebase; a global model-event would
 * fire under whatever `TenantContext` happened to be active and seed
 * the wrong tenant. Callers invoke this explicitly with the EXPLICIT
 * tenant_id of the surface that created the user (admin add-user,
 * sign-up form, invite acceptance).
 */
final class NotificationPreferencesInitializer
{
    /**
     * @param  int|string  $userId
     */
    public function seedFromTenantDefaults(int|string $userId, string $tenantId): void
    {
        $eventTypes = NotificationEvent::eventTypes();
        $channels = NotificationPreference::availableChannels();

        $overrides = $this->loadTenantOverrides($tenantId);
        $platformDefaults = (array) config('askmydocs.notifications.default_channel_preferences', []);

        $now = now();
        $rows = [];
        foreach ($eventTypes as $event) {
            foreach ($channels as $channel) {
                $key = $event.'|'.$channel;
                $enabled = $overrides[$key] ?? (bool) ($platformDefaults[$channel] ?? false);
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'event_type' => $event,
                    'channel' => $channel,
                    'enabled' => $enabled,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        // Single-statement `upsert()` is atomic at the DB level — no
        // inner transaction needed (Copilot iter-4: this initializer
        // is invoked from within `UserController@store`'s existing
        // DB::transaction, so a nested savepoint would add overhead
        // without changing semantics). Callers that need cross-step
        // atomicity own their own transaction boundary.
        NotificationPreference::query()->upsert(
            $rows,
            ['tenant_id', 'user_id', 'event_type', 'channel'],
            ['enabled', 'updated_at'],
        );
    }

    /**
     * @return array<string, bool>  keyed by "event_type|channel"
     */
    private function loadTenantOverrides(string $tenantId): array
    {
        $overrides = [];
        NotificationTenantDefault::query()
            ->where('tenant_id', $tenantId)
            ->get(['event_type', 'channel', 'enabled'])
            ->each(function ($row) use (&$overrides): void {
                $key = $row->event_type.'|'.$row->channel;
                $overrides[$key] = (bool) $row->enabled;
            });

        return $overrides;
    }
}
