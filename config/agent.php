<?php

declare(strict_types=1);

return [
    'queue' => env('AGENT_QUEUE', 'agent'),
    'planner' => [
        'max_actions_per_plan' => (int) env('AGENT_MAX_ACTIONS_PER_PLAN', 8),
    ],
    'events' => [
        'poll_ms' => (int) env('AGENT_EVENT_POLL_MS', 100),
        'stream_seconds' => (float) env('AGENT_EVENT_STREAM_SECONDS', 25),
    ],
    'tools' => [
        'pagination_max_pages' => (int) env('AGENT_PAGINATION_MAX_PAGES', 100),
        'fanout_max_items' => (int) env('AGENT_FANOUT_MAX_ITEMS', 100),
        'fanout_concurrency' => (int) env('AGENT_FANOUT_CONCURRENCY', 5),
        'fanout_driver' => env('AGENT_FANOUT_DRIVER', 'process'),
        'fanout_timeout_seconds' => (int) env('AGENT_FANOUT_TIMEOUT_SECONDS', 90),
    ],
    'limits' => [
        'iterations' => (int) env('AGENT_ITERATION_LIMIT', 8),
        'logical_soft' => (int) env('AGENT_LOGICAL_SOFT_LIMIT', 12),
        'logical_hard' => (int) env('AGENT_LOGICAL_HARD_LIMIT', 25),
        'physical_hard' => (int) env('AGENT_PHYSICAL_HARD_LIMIT', 100),
        'consecutive_errors' => (int) env('AGENT_CONSECUTIVE_ERROR_LIMIT', 3),
        'duplicate_calls' => (int) env('AGENT_DUPLICATE_CALL_LIMIT', 2),
        'interactive_time_seconds' => (int) env('AGENT_INTERACTIVE_TIME_LIMIT', 60),
        'bulk_time_seconds' => (int) env('AGENT_BULK_TIME_LIMIT', 90),
        'evidence_bytes' => (int) env('AGENT_EVIDENCE_BYTE_LIMIT', 524288),
        'confirmation_logical_extension_max' => (int) env('AGENT_CONFIRMATION_LOGICAL_EXTENSION_MAX', 25),
        'confirmation_physical_extension_max' => (int) env('AGENT_CONFIRMATION_PHYSICAL_EXTENSION_MAX', 100),
    ],
    'locales' => [
        'supported' => array_values(array_filter(array_map(
            static fn (string $locale): string => trim($locale),
            explode(',', (string) env('AGENT_SUPPORTED_LOCALES', 'en,it')),
        ))),
        'fallback' => env('APP_LOCALE', 'en'),
    ],
];
