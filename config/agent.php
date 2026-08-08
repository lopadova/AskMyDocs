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
    'limits' => [
        'physical_hard' => (int) env('AGENT_PHYSICAL_HARD_LIMIT', 100),
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
