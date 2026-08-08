<?php

declare(strict_types=1);

return [
    'locales' => [
        'supported' => array_values(array_filter(array_map(
            static fn (string $locale): string => trim($locale),
            explode(',', (string) env('AGENT_SUPPORTED_LOCALES', 'en,it')),
        ))),
        'fallback' => env('APP_LOCALE', 'en'),
    ],
];
