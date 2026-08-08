<?php

declare(strict_types=1);

return [
    'queue' => env('AGENT_QUEUE', 'agent'),
    'events' => [
        'poll_ms' => (int) env('AGENT_EVENT_POLL_MS', 100),
        'stream_seconds' => (float) env('AGENT_EVENT_STREAM_SECONDS', 25),
    ],
    'locales' => [
        'supported' => array_values(array_filter(array_map(
            static fn (string $locale): string => trim($locale),
            explode(',', (string) env('AGENT_SUPPORTED_LOCALES', 'en,it')),
        ))),
        'fallback' => env('APP_LOCALE', 'en'),
    ],
];
