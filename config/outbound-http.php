<?php

// Outbound HTTP SSRF policy (SEC-SSRF-001). Applied by
// App\Support\Http\OutboundUrlValidator before any server-side request to a
// URL that is not a code-owned constant (webhooks, digest webhooks, any future
// user/operator-supplied fetch target). AI provider egress is governed
// separately by its own provider/base-URL allow-list (see PR 8).

return [
    // Master switch. When false the validator is a no-op — DO NOT disable in
    // production; present only so a controlled environment can opt out.
    'enabled' => env('OUTBOUND_HTTP_SSRF_GUARD_ENABLED', true),

    // URL schemes permitted for outbound requests. https-only by default; add
    // 'http' only for an isolated internal integration behind its own network
    // controls.
    'allowed_schemes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OUTBOUND_HTTP_ALLOWED_SCHEMES', 'https')),
    ))),

    // Redirects are disabled on guarded requests: a 3xx to an internal host is
    // a classic SSRF bypass, and webhooks have no legitimate need to redirect.
    'follow_redirects' => env('OUTBOUND_HTTP_FOLLOW_REDIRECTS', false),
];
