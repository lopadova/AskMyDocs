<?php

return [
    'queue' => 'email-triage',
    'connection' => env('EMAIL_TRIAGE_QUEUE_CONNECTION', 'email-triage'),
    'snippet_characters' => 1500,
    'attachment_budget_bytes' => 8192,
    'extraction_max_bytes' => 5 * 1024 * 1024,
    'campaign_batch_size' => 50,
    // An ops estimate, never a claimed provider price. Actual usage is recorded.
    'estimated_decision_usd_per_email' => env('EMAIL_TRIAGE_ESTIMATED_DECISION_USD'),
    'estimated_reindex_usd_per_email' => env('EMAIL_TRIAGE_ESTIMATED_REINDEX_USD'),
];
