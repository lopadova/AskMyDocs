<?php

// Security response-header policy (SEC-CSP-001, SEC-TLS-001, response-headers,
// csp-nonce-cache, request-correlation). Every knob is env-overridable and the
// whole middleware is a no-op when `enabled` is false (R43: the OFF path must
// degrade cleanly — no headers, no error). Defaults are chosen so a fresh
// deploy is safe: CSP ships in REPORT-ONLY mode (rule-security-runtime-browser:
// "Enforcement follows report-only inventory"), so it observes violations
// without breaking the SPA until an operator flips `csp.report_only` to false.

return [
    // Master switch. false → the middleware adds nothing at all.
    'enabled' => env('SECURITY_HEADERS_ENABLED', true),

    'csp' => [
        'enabled' => env('SECURITY_HEADERS_CSP_ENABLED', true),
        // true  → Content-Security-Policy-Report-Only (observe, never block).
        // false → Content-Security-Policy (enforce). Flip only after the
        //         report-only inventory is clean in staging.
        'report_only' => env('SECURITY_HEADERS_CSP_REPORT_ONLY', true),
        // Where the browser POSTs violation reports. Null → no report-uri /
        // report-to directive and the collector route is inert (404).
        'report_uri' => env('SECURITY_HEADERS_CSP_REPORT_URI', '/csp-report'),
        // Directives. `{nonce}` is replaced per-request with a fresh nonce.
        // script-src is strict (nonce + self); style-src permits inline styles
        // because React/Tailwind runtime styling is not script execution and a
        // strict style-src would break the SPA without adding XSS protection.
        'directives' => [
            "default-src 'self'",
            "script-src 'self' 'nonce-{nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ],
    ],

    'hsts' => [
        // HSTS is only emitted over HTTPS AND only in the environments listed
        // here — never on plain HTTP (browsers ignore it) and never in local.
        'enabled' => env('SECURITY_HEADERS_HSTS_ENABLED', true),
        'environments' => ['production'],
        'max_age' => (int) env('SECURITY_HEADERS_HSTS_MAX_AGE', 31536000),
        'include_subdomains' => env('SECURITY_HEADERS_HSTS_INCLUDE_SUBDOMAINS', true),
        'preload' => env('SECURITY_HEADERS_HSTS_PRELOAD', false),
    ],

    // Static headers applied to every response when `enabled`. A null value
    // omits that header.
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), browsing-topics=()',
    ],

    // Correlation id echoed on every response (request-correlation control).
    // Reused from the inbound header if it matches the allowed format, else a
    // fresh UUID is minted. Never trusted for authorization.
    'request_id' => [
        'enabled' => env('SECURITY_HEADERS_REQUEST_ID_ENABLED', true),
        'header' => 'X-Request-Id',
    ],
];
