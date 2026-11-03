<?php

declare(strict_types=1);

return [
    'default' => env('DECISION_MODEL', 'jev'),
    'e2e_fixture_enabled' => (bool) env('ASKMYDOCS_E2E_DECISIONS_ENABLED', false),
    // Application guardrail for sanitized UTF-8 JSON, NOT the model token window.
    // Questions retain their independent 12,000-byte limit in Decisions::decide().
    // Real mixed email/ERP cases exceed 80 KiB even after shared-source dedup.
    // Keep complete originals and contradictions; never truncate them to fit.
    'max_state_bytes' => (int) env('DECISION_MAX_STATE_BYTES', 131072),
    'models' => [
        'e2e_fixture' => ['driver' => \App\Decisions\Models\E2eFixtureDecisionModel::class],
        'jev' => [
            'driver' => \App\Decisions\Models\JevDecisionModel::class,
            'model' => env('JEV_MODEL', 'typesafe/jev-1.13'),
            'timeout' => (int) env('JEV_TIMEOUT', 15),
        ],
    ],
];
