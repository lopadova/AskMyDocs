<?php

return [
    'enabled' => (bool) env('PACT_AUTH_ENABLED', false),
    // Exact client origins, without a trailing slash. No wildcard hosts.
    'allowed_client_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('PACT_AUTH_ALLOWED_CLIENT_ORIGINS', ''))))),
    'allow_local_http' => (bool) env('PACT_AUTH_ALLOW_LOCAL_HTTP', false),
    'dynamic_registration' => (bool) env('PACT_AUTH_DYNAMIC_REGISTRATION', true),
    'token_ttl_seconds' => (int) env('PACT_AUTH_TOKEN_TTL_SECONDS', 86400),
    'producer_id' => 'urn:askmydocs:pact',
];
