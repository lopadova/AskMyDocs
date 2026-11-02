<?php

return [
    'campaigns_enabled' => (bool) env('ASKMYDOCS_QA_CAMPAIGNS_ENABLED', false),
    // Explicit deployment fence: Cloud develop can itself use APP_ENV=production.
    'deployment_tier' => env('ASKMYDOCS_DEPLOYMENT_TIER', 'production'),
    'max_budget_usd' => 10,
];
