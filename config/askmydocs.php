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

    /*
    |--------------------------------------------------------------------------
    | Composite-gated scheduler upstreams (v8.0/W2.4)
    |--------------------------------------------------------------------------
    |
    | Mirror of the upstream env flags `EVAL_NIGHTLY_ENABLED` and
    | `AI_ACT_REGULATORY_FEED_ENABLED` that `bootstrap/app.php` wraps
    | around the corresponding `registerSlot()` calls. Reading these
    | values through `config(...)` (instead of `env(...)`) at request
    | time keeps the ops-widget endpoint safe under
    | `php artisan config:cache` (env() lookups bypass the cache and
    | can return null in production after `config:cache`).
    |
    | Each entry is bound at config-load time to the same env source
    | bootstrap reads, so the dual-gate semantics stay consistent
    | between scheduler registration (bootstrap) and ops-widget
    | rendering (request handler).
    */
    'composite_gates' => [
        'eval_nightly' => (bool) env('EVAL_NIGHTLY_ENABLED', false),
        'ai_act_regulatory_poll' => (bool) env('AI_ACT_REGULATORY_FEED_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tier-1 scheduler slots (v8.0/W2.4)
    |--------------------------------------------------------------------------
    |
    | Each host-side scheduler slot reads its cron expression and
    | enabled flag from this map. Both knobs are env-driven so
    | operators can shift retention windows, disable individual jobs
    | (e.g. on staging where a noisy cron would skew telemetry), or
    | adopt a non-overnight maintenance window without editing
    | `bootstrap/app.php`.
    |
    | Conventions:
    |   - `enabled` defaults to true (matches the pre-W2.4 behavior).
    |   - `cron` defaults preserve the original `dailyAt('HH:MM')`
    |     literals so opting out of every env var keeps the same
    |     overnight rotation.
    |   - Slot names mirror the kebab-style command name with `:` and
    |     `-` collapsed to `_`, so the env var is grep-able from the
    |     command itself.
    |
    | Tier-2 (W4 — per-tenant scheduler overrides) layers on top of
    | this Tier-1 map. The connector sync scheduler is owned by the
    | `padosoft/askmydocs-connector-base` package and not configurable
    | here — that registration stays inside `bootstrap/app.php`.
    */
    'schedule' => [
        'kb_prune_embedding_cache' => [
            'enabled' => (bool) env('SCHEDULE_KB_PRUNE_EMBEDDING_CACHE_ENABLED', true),
            'cron' => (string) env('SCHEDULE_KB_PRUNE_EMBEDDING_CACHE_CRON', '10 3 * * *'),
        ],
        'chat_log_prune' => [
            'enabled' => (bool) env('SCHEDULE_CHAT_LOG_PRUNE_ENABLED', true),
            'cron' => (string) env('SCHEDULE_CHAT_LOG_PRUNE_CRON', '20 3 * * *'),
        ],
        'kb_prune_deleted' => [
            'enabled' => (bool) env('SCHEDULE_KB_PRUNE_DELETED_ENABLED', true),
            'cron' => (string) env('SCHEDULE_KB_PRUNE_DELETED_CRON', '30 3 * * *'),
        ],
        'kb_rebuild_graph' => [
            'enabled' => (bool) env('SCHEDULE_KB_REBUILD_GRAPH_ENABLED', true),
            'cron' => (string) env('SCHEDULE_KB_REBUILD_GRAPH_CRON', '40 3 * * *'),
        ],
        'kb_health_recompute' => [
            'enabled' => (bool) env('SCHEDULE_KB_HEALTH_RECOMPUTE_ENABLED', true),
            'cron' => (string) env('SCHEDULE_KB_HEALTH_RECOMPUTE_CRON', '50 3 * * *'),
        ],
        'queue_prune_failed' => [
            'enabled' => (bool) env('SCHEDULE_QUEUE_PRUNE_FAILED_ENABLED', true),
            'cron' => (string) env('SCHEDULE_QUEUE_PRUNE_FAILED_CRON', '0 4 * * *'),
        ],
        'notifications_prune' => [
            'enabled' => (bool) env('SCHEDULE_NOTIFICATIONS_PRUNE_ENABLED', true),
            'cron' => (string) env('SCHEDULE_NOTIFICATIONS_PRUNE_CRON', '10 4 * * *'),
        ],
        'admin_audit_prune' => [
            'enabled' => (bool) env('SCHEDULE_ADMIN_AUDIT_PRUNE_ENABLED', true),
            'cron' => (string) env('SCHEDULE_ADMIN_AUDIT_PRUNE_CRON', '30 4 * * *'),
        ],
        'kb_prune_orphan_files' => [
            'enabled' => (bool) env('SCHEDULE_KB_PRUNE_ORPHAN_FILES_ENABLED', true),
            'cron' => (string) env('SCHEDULE_KB_PRUNE_ORPHAN_FILES_CRON', '40 4 * * *'),
        ],
        'admin_nonces_prune' => [
            'enabled' => (bool) env('SCHEDULE_ADMIN_NONCES_PRUNE_ENABLED', true),
            'cron' => (string) env('SCHEDULE_ADMIN_NONCES_PRUNE_CRON', '50 4 * * *'),
        ],
        'insights_compute' => [
            'enabled' => (bool) env('SCHEDULE_INSIGHTS_COMPUTE_ENABLED', true),
            'cron' => (string) env('SCHEDULE_INSIGHTS_COMPUTE_CRON', '0 5 * * *'),
        ],
        // v8.15/W1 — daily engagement snapshot (after insights, 05:15).
        'engagement_compute' => [
            'enabled' => (bool) env('SCHEDULE_ENGAGEMENT_COMPUTE_ENABLED', true),
            'cron' => (string) env('SCHEDULE_ENGAGEMENT_COMPUTE_CRON', '15 5 * * *'),
        ],
        'compliance_digest_quarterly' => [
            'enabled' => (bool) env('SCHEDULE_COMPLIANCE_DIGEST_QUARTERLY_ENABLED', true),
            'cron' => (string) env('SCHEDULE_COMPLIANCE_DIGEST_QUARTERLY_CRON', '0 6 1 1,4,7,10 *'),
        ],
        // M5.10 — Prune expired widget sessions (cascade-deletes steps).
        'widget_prune_sessions' => [
            'enabled' => (bool) env('SCHEDULE_WIDGET_PRUNE_SESSIONS_ENABLED', true),
            'cron' => (string) env('SCHEDULE_WIDGET_PRUNE_SESSIONS_CRON', '0 4 * * *'),
        ],
        // v8.7/W2 — stale-document review sweep (daily 03:55) + weekly
        // notification digest (Monday 07:00).
        'kb_stale_review_sweep' => [
            'enabled' => (bool) env('SCHEDULE_KB_STALE_REVIEW_SWEEP_ENABLED', true),
            'cron' => (string) env('SCHEDULE_KB_STALE_REVIEW_SWEEP_CRON', '55 3 * * *'),
        ],
        'notifications_digest_weekly' => [
            'enabled' => (bool) env('SCHEDULE_NOTIFICATIONS_DIGEST_WEEKLY_ENABLED', true),
            'cron' => (string) env('SCHEDULE_NOTIFICATIONS_DIGEST_WEEKLY_CRON', '0 7 * * 1'),
        ],
        // v8.15/W2 — rich engagement digest (metrics + AI narrative) to email +
        // Discord/Slack/Teams. Weekly Mon 07:15; monthly on the 1st at 07:30.
        'digest_weekly' => [
            'enabled' => (bool) env('SCHEDULE_DIGEST_WEEKLY_ENABLED', true),
            'cron' => (string) env('SCHEDULE_DIGEST_WEEKLY_CRON', '15 7 * * 1'),
        ],
        'digest_monthly' => [
            'enabled' => (bool) env('SCHEDULE_DIGEST_MONTHLY_ENABLED', true),
            'cron' => (string) env('SCHEDULE_DIGEST_MONTHLY_CRON', '30 7 1 * *'),
        ],
        // v8.15/W3 — in-app digest feed retention (daily 04:25).
        'digest_prune_feed' => [
            'enabled' => (bool) env('SCHEDULE_DIGEST_PRUNE_FEED_ENABLED', true),
            'cron' => (string) env('SCHEDULE_DIGEST_PRUNE_FEED_CRON', '25 4 * * *'),
        ],
        // v8.15/W5 — gamification badge awarding (daily 05:20; no-op when disabled).
        'gamification_recompute' => [
            'enabled' => (bool) env('SCHEDULE_GAMIFICATION_RECOMPUTE_ENABLED', true),
            'cron' => (string) env('SCHEDULE_GAMIFICATION_RECOMPUTE_CRON', '20 5 * * *'),
        ],
        // v8.18/W4 — weekly AI gamification insights (Mondays 05:40); no-op when
        // gamification is disabled.
        'gamification_narrate' => [
            'enabled' => (bool) env('SCHEDULE_GAMIFICATION_NARRATE_ENABLED', true),
            'cron' => (string) env('SCHEDULE_GAMIFICATION_NARRATE_CRON', '40 5 * * 1'),
        ],
        // v8.7/W5 — Cloud Time Machine archived-version retention (daily 04:20).
        'kb_prune_archived_versions' => [
            'enabled' => (bool) env('SCHEDULE_KB_PRUNE_ARCHIVED_VERSIONS_ENABLED', true),
            'cron' => (string) env('SCHEDULE_KB_PRUNE_ARCHIVED_VERSIONS_CRON', '20 4 * * *'),
        ],
        // v8.11/P9 — scheduled Auto-Wiki maintenance (daily 04:40): rebuild
        // indices + lint + backfill un-enriched docs.
        'kb_wiki_maintain' => [
            'enabled' => (bool) env('SCHEDULE_KB_WIKI_MAINTAIN_ENABLED', true),
            'cron' => (string) env('SCHEDULE_KB_WIKI_MAINTAIN_CRON', '40 4 * * *'),
        ],
        // v8.16 — FinOps maintenance window (staggered inside the 04:xx slot so
        // it never collides with the other overnight pruners).
        'finops_capture_prices' => [
            'enabled' => (bool) env('SCHEDULE_FINOPS_CAPTURE_PRICES_ENABLED', true),
            'cron' => (string) env('SCHEDULE_FINOPS_CAPTURE_PRICES_CRON', '5 4 * * *'),
        ],
        'finops_check_alerts' => [
            'enabled' => (bool) env('SCHEDULE_FINOPS_CHECK_ALERTS_ENABLED', true),
            'cron' => (string) env('SCHEDULE_FINOPS_CHECK_ALERTS_CRON', '15 4 * * *'),
        ],
        'finops_prune_ledger' => [
            'enabled' => (bool) env('SCHEDULE_FINOPS_PRUNE_LEDGER_ENABLED', true),
            'cron' => (string) env('SCHEDULE_FINOPS_PRUNE_LEDGER_CRON', '45 4 * * *'),
        ],
        // `eval:nightly` is double-gated: an upstream
        // `EVAL_NIGHTLY_ENABLED` env var (legacy v4.3 knob) gates
        // scheduler REGISTRATION in `bootstrap/app.php` — when false,
        // the slot is never registered and `enabled` / `cron` here
        // have no effect. When the legacy knob is true, this Tier-1
        // slot controls the cron expression and offers a per-host
        // kill-switch without removing the upstream knob. Production
        // live-runs require BOTH knobs on.
        'eval_nightly' => [
            'enabled' => (bool) env('SCHEDULE_EVAL_NIGHTLY_ENABLED', true),
            'cron' => (string) env('SCHEDULE_EVAL_NIGHTLY_CRON', '30 5 * * *'),
        ],
        // `ai-act:regulatory-poll` ALSO early-returns when
        // `ai-act-compliance.regulatory_feed.enabled` is false. This
        // slot controls only the scheduler registration window.
        'ai_act_regulatory_poll' => [
            'enabled' => (bool) env('SCHEDULE_AI_ACT_REGULATORY_POLL_ENABLED', true),
            'cron' => (string) env('SCHEDULE_AI_ACT_REGULATORY_POLL_CRON', '10 4 * * *'),
        ],
    ],

    'kb_health' => [
        'threshold_event_score' => (int) env('KB_HEALTH_THRESHOLD_EVENT_SCORE', 70),
        // v8.7/W2 — a document untouched (no re-ingest) for this many
        // months is flagged for review by `kb:stale-review-sweep`. 0
        // disables the sweep entirely. Settings-tunable per deployment.
        'stale_review_months' => (int) env('KB_HEALTH_STALE_REVIEW_MONTHS', 6),
        'weights' => [
            'age_decay' => (float) env('KB_HEALTH_WEIGHT_AGE_DECAY', 0.25),
            'repeat_questions' => (float) env('KB_HEALTH_WEIGHT_REPEAT_QUESTIONS', 0.20),
            'supersedes_chain' => (float) env('KB_HEALTH_WEIGHT_SUPERSEDES_CHAIN', 0.20),
            'orphan_outbound' => (float) env('KB_HEALTH_WEIGHT_ORPHAN_OUTBOUND', 0.15),
            'status_decay' => (float) env('KB_HEALTH_WEIGHT_STATUS_DECAY', 0.20),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Engagement & Intelligence Suite (v8.15)
    |--------------------------------------------------------------------------
    | The KB engagement layer: a contribution-event log feeds contributor
    | analytics, "your impact" metrics, the digest, and the opt-in gamification
    | layer. `enabled` gates the ContributionRecorder write side (best-effort
    | telemetry; never breaks a hot path). `window_days` is the default rolling
    | activity window for the daily snapshot.
    */
    'engagement' => [
        'enabled' => (bool) env('KB_ENGAGEMENT_ENABLED', true),
        'window_days' => (int) env('KB_ENGAGEMENT_WINDOW_DAYS', 7),
        'snapshot_retention_days' => (int) env('KB_ENGAGEMENT_SNAPSHOT_RETENTION_DAYS', 400),
    ],

    'compliance' => [
        'hmac_secret' => (string) env(
            'COMPLIANCE_HMAC_SECRET',
            env('APP_ENV') === 'production' ? '' : (string) env('APP_KEY', ''),
        ),
    ],

];
