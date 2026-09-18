<?php

declare(strict_types=1);

return [
    'queue' => env('AGENT_QUEUE', 'agent'),
    'planner' => [
        'mode' => env('AGENT_PLANNER_MODE', 'classic'),
        'max_actions_per_plan' => (int) env('AGENT_MAX_ACTIONS_PER_PLAN', 8),
        'router_catalog_limit' => (int) env('AGENT_ROUTER_CATALOG_LIMIT', 40),
        'candidate_limit' => (int) env('AGENT_PLANNER_CANDIDATE_LIMIT', 8),
    ],
    'grounding' => [
        // Agent answers are assembled from evidence-bound claims. This remains
        // configurable only as an emergency rollback; production defaults to
        // fail closed for every agent surface.
        'enabled' => (bool) env('AGENT_CLAIM_GROUNDING_ENABLED', true),
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
    /*
    |--------------------------------------------------------------------------
    | Investigation depth (1-5, user-facing "livello di approfondimento")
    |--------------------------------------------------------------------------
    |
    | A per-run multiplier applied by AgentBudgetTracker::limit() to the
    | depth-eligible entries in `limits` above (iterations, logical_soft/
    | hard, physical_hard, both time budgets, evidence_bytes) — NOT to
    | consecutive_errors/duplicate_calls, which stay fixed loop-safety
    | guards regardless of how deep the caller asked to go. A higher depth
    | gives the planner more plan->act->observe cycles to decide it needs
    | another cascading KB search (or another tool call), and more evidence
    | headroom + wall-clock time to actually finish that investigation — the
    | run is durable/async (AgentRun + polling), so a longer time budget
    | never blocks the initiating HTTP request.
    |
    | Level 3 = 1.0x = today's unscaled defaults, so a caller that never
    | sends `depth` (or any pre-existing client) behaves byte-identically
    | to before this knob existed.
    |
    */
    'depth' => [
        'multipliers' => [
            1 => (float) env('AGENT_DEPTH_MULTIPLIER_1', 0.5),
            2 => (float) env('AGENT_DEPTH_MULTIPLIER_2', 0.75),
            3 => (float) env('AGENT_DEPTH_MULTIPLIER_3', 1.0),
            4 => (float) env('AGENT_DEPTH_MULTIPLIER_4', 2.0),
            5 => (float) env('AGENT_DEPTH_MULTIPLIER_5', 3.0),
        ],
        'default' => (int) env('AGENT_DEPTH_DEFAULT', 3),
    ],
    'locales' => [
        'supported' => array_values(array_filter(array_map(
            static fn (string $locale): string => trim($locale),
            explode(',', (string) env('AGENT_SUPPORTED_LOCALES', 'en,it')),
        ))),
        'fallback' => env('APP_LOCALE', 'en'),
    ],
];
