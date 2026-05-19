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

        /*
        |--------------------------------------------------------------------------
        | Default channel-preference matrix (W2.2)
        |--------------------------------------------------------------------------
        |
        | When a user has no `notification_preferences` row for a given
        | (event_type, channel) cell, the W2.2 grid renders the toggle
        | using the default below. The dispatcher only ever consults
        | rows that are explicitly enabled, so an absent row is
        | functionally equivalent to `enabled=false` regardless of
        | this map — these defaults only drive the FE rendering of
        | the preferences grid for first-time visitors.
        |
        | Tenant-level overrides land in W2.3 via the
        | `AdminNotificationDefaultsController`.
        */
        'default_channel_preferences' => [
            'in_app' => true,
            'email' => false,
            'discord' => false,
            'slack' => false,
            'teams' => false,
            'webhook' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | External channel webhook URLs (W2.1)
        |--------------------------------------------------------------------------
        |
        | Each entry below maps to one optional outbound webhook
        | channel registered by `NotificationServiceProvider`:
        |
        |   - `discord`  — Discord incoming webhook (rich embed)
        |   - `slack`    — Slack incoming webhook (Block Kit blocks)
        |   - `teams`    — Microsoft Teams Incoming Webhook
        |                  connector (Adaptive Card 1.4)
        |   - `webhook`  — generic outbound POST with optional
        |                  HMAC-SHA256 `X-AskMyDocs-Signature`
        |                  header for receiver verification
        |
        | A channel is registered with `ChannelRegistry` ONLY when
        | its `.url` is non-empty (see
        | `NotificationServiceProvider::registerExternalChannels()`).
        | Per-user opt-in still gates dispatch via
        | `notification_preferences` — a configured URL alone does
        | NOT cause traffic to fire until at least one user toggles
        | the relevant cell on in `/app/account/notifications` (the
        | W2.2 grid).
        |
        | The `secret` value is only consumed by the generic
        | `webhook` channel — Discord / Slack / Teams authenticate
        | via the webhook id baked into the URL and ignore it.
        */
        'channels' => [
            'discord' => [
                'url' => (string) env('NOTIFICATIONS_DISCORD_URL', ''),
            ],
            'slack' => [
                'url' => (string) env('NOTIFICATIONS_SLACK_URL', ''),
            ],
            'teams' => [
                'url' => (string) env('NOTIFICATIONS_TEAMS_URL', ''),
            ],
            'webhook' => [
                'url' => (string) env('NOTIFICATIONS_WEBHOOK_URL', ''),
                'secret' => (string) env('NOTIFICATIONS_WEBHOOK_SECRET', ''),
            ],
        ],
    ],

];
