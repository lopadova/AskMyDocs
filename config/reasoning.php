<?php

return [
    'enabled' => (bool) env('CHAT_REASONING_ENABLED', true),
    'selective_validation' => (bool) env('AGENT_SELECTIVE_VALIDATION_ENABLED', true),
    'threshold' => (float) env('AGENT_SELECTIVE_VALIDATION_THRESHOLD', 0.8),
    'max_evidence' => max(1, (int) env('CHAT_REASONING_MAX_EVIDENCE', 100)),
    'max_state_bytes' => max(4096, (int) env('CHAT_REASONING_MAX_STATE_BYTES', 65536)),
    'parallel_research' => (bool) env('CHAT_PARALLEL_RESEARCH_ENABLED', true),
    'research_concurrency' => max(1, min(4, (int) env('CHAT_RESEARCH_CONCURRENCY', 4))),
    'research_timeout_seconds' => max(1, (int) env('CHAT_RESEARCH_TIMEOUT_SECONDS', 90)),
];
