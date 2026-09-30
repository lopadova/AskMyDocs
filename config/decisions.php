<?php

declare(strict_types=1);

return [
    'default' => env('DECISION_MODEL', 'jev'),
    // Application guardrail for sanitized UTF-8 JSON, NOT the model token window.
    // Questions retain their independent 12,000-byte limit in Decisions::decide().
    'max_state_bytes' => (int) env('DECISION_MAX_STATE_BYTES', 32768),
    'models' => [
        'jev' => [
            'driver' => \App\Decisions\Models\JevDecisionModel::class,
            'model' => env('JEV_MODEL', 'typesafe/jev-1.13'),
            'timeout' => (int) env('JEV_TIMEOUT', 15),
        ],
    ],
];
