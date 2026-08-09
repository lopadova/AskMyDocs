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

    // NOTE: redirects are ALWAYS disabled on guarded requests (hardcoded at the
    // call site, not configurable). Following a 3xx would let a validated public
    // URL redirect to an internal host — the classic SSRF bypass — and would
    // require re-validating every hop. Webhooks have no legitimate need to
    // redirect, so there is deliberately no knob to re-enable it.
];
