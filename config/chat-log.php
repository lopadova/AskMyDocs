<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable Chat Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, every chat interaction (question, answer, token usage,
    | client info, latency) is persisted via the configured driver.
    | Disabled by default — enable in .env with CHAT_LOG_ENABLED=true.
    |
    */

    'enabled' => env('CHAT_LOG_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Log Driver
    |--------------------------------------------------------------------------
    |
    | The storage backend for chat logs.
    |
    | Supported: "database"
    | Planned:   "bigquery", "cloudwatch"
    |
    | To add a custom driver, implement ChatLogDriverInterface and register
    | it in ChatLogManager::resolveDriver().
    |
    */

    'driver' => env('CHAT_LOG_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Retention (days)
    |--------------------------------------------------------------------------
    |
    | The scheduled chat-log:prune job removes rows whose `created_at` is
    | older than this many days. Set to 0 to disable rotation entirely.
    |
    */

    'retention_days' => (int) env('CHAT_LOG_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Anonymous-chat logging level (data minimisation)
    |--------------------------------------------------------------------------
    |
    | An anonymous chat turn (POST /api/kb/chat with `anonymous: true`) is
    | never attributed and never persisted as a conversation. This knob
    | governs how its chat_logs row is written, by GDPR data-minimisation:
    |
    |   'none'    — write NO chat_logs row at all.
    |   'minimal' — write a stripped row: NO question / answer / sources /
    |               user_id / client_ip / user_agent; keep only the
    |               by-norm operational fields (tenant, provider/model,
    |               token counts, latency, chunks_count, timestamp) for
    |               billing + abuse counting. Default.
    |
    | A normal (non-anonymous) turn is unaffected — it logs in full as before.
    |
    */

    'anonymous_level' => env('CHAT_LOG_ANONYMOUS_LEVEL', 'minimal'),

    /*
    |--------------------------------------------------------------------------
    | Driver-Specific Options
    |--------------------------------------------------------------------------
    */

    'drivers' => [

        'database' => [
            'connection' => env('CHAT_LOG_DB_CONNECTION'), // null = default
        ],

        // 'bigquery' => [
        //     'project_id' => env('BIGQUERY_PROJECT_ID'),
        //     'dataset'    => env('BIGQUERY_DATASET', 'chat_analytics'),
        //     'table'      => env('BIGQUERY_TABLE', 'chat_logs'),
        //     'credentials_path' => env('BIGQUERY_CREDENTIALS'),
        // ],

        // 'cloudwatch' => [
        //     'region'    => env('AWS_DEFAULT_REGION', 'eu-west-1'),
        //     'log_group' => env('CLOUDWATCH_LOG_GROUP', '/enterprise-kb/chat'),
        //     'log_stream' => env('CLOUDWATCH_LOG_STREAM', 'chat-logs'),
        // ],

    ],

];
