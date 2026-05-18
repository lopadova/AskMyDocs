<?php

declare(strict_types=1);

/**
 * v8.0 — AskMyDocs core configuration namespace.
 *
 * Currently used by the notification subsystem (`NotificationDispatcher`,
 * `UnsubscribeTokenSigner`, `EmailChannel`). Future v8.x cycles will add
 * `kb_health.weights`, `compliance.hmac_secret`, etc. under this same
 * namespace per the v8.0 plan (§A1, §A6).
 */
return [

    'notifications' => [
        /*
        |--------------------------------------------------------------------------
        | Tenant-wide system event channels
        |--------------------------------------------------------------------------
        |
        | Channels used when a `BaseNotificationEvent` carries a `null`
        | recipient (the "system event" / "tenant-wide" path — see
        | NotificationDispatcher::resolveEnabledChannels). Override per
        | deployment via `NOTIFICATIONS_SYSTEM_EVENT_CHANNELS` (comma list).
        |
        | Default: in_app only. Pull email in once the tenant-admin
        | digest UI lands (§C.4 W4 — kb_decision_debt_threshold).
        */
        'system_event_channels' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NOTIFICATIONS_SYSTEM_EVENT_CHANNELS', 'in_app')),
        ))),

        /*
        |--------------------------------------------------------------------------
        | HMAC secret for one-click unsubscribe links
        |--------------------------------------------------------------------------
        |
        | Signs the `(tenant_id, user_id, event_type)` triple embedded in
        | every EmailChannel notification's unsubscribe URL. Per
        | UnsubscribeTokenSigner, the signer THROWS when this is unset —
        | a misconfigured deployment must not ship unsigned links.
        |
        | Generate with: `php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"`
        | and set `NOTIFICATIONS_HMAC_SECRET` in `.env`. Falls back to
        | `APP_KEY` in non-production environments so local dev + CI never
        | break on missing config; production MUST override.
        */
        'hmac_secret' => (string) env(
            'NOTIFICATIONS_HMAC_SECRET',
            env('APP_ENV') === 'production' ? '' : (string) env('APP_KEY', ''),
        ),

        /*
        |--------------------------------------------------------------------------
        | Retention window for `notification_events`
        |--------------------------------------------------------------------------
        |
        | Days to keep delivered / dismissed notification rows before the
        | `notifications:prune` cron (W1.5) hard-deletes them. Set to 0
        | to disable pruning entirely.
        */
        'retention_days' => (int) env('NOTIFICATIONS_RETENTION_DAYS', 90),
    ],

];
