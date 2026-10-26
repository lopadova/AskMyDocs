<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('API_TOOLS_ENABLED', false),
    'measurements_enabled' => (bool) env('API_TOOLS_MEASUREMENTS_ENABLED', false),
    'queue' => env('API_TOOLS_QUEUE', 'api-tools'),
    'connection' => env('API_TOOLS_QUEUE_CONNECTION'),
    'node_lock_seconds' => 60,
    'max_steps' => 40,
    'max_items' => 100,
    'max_pages' => 20,
    'max_physical_requests' => 100,
    'timeout_seconds' => 120,
    'failure_threshold' => 3,
];
