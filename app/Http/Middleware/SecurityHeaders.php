<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emits the security response-header baseline (SEC-CSP-001, SEC-TLS-001,
 * response-headers, csp-nonce-cache, request-correlation).
 *
 * Design notes:
 * - The whole middleware is a no-op when `config('security-headers.enabled')`
 *   is false (R43 — the OFF path must degrade cleanly, never 500).
 * - A fresh CSP nonce is minted per request BEFORE the view renders and handed
 *   to Laravel's Vite integration so `@vite`-injected `<script>` tags carry it;
 *   the same nonce goes into the CSP header. The nonce is generated here (not
 *   read back from Vite) so it is always present even when Vite is disabled in
 *   tests (`withoutVite()`). Per-request generation satisfies the
 *   csp-nonce-cache freshness requirement — the host renders the SPA shell
 *   dynamically, there is no full-page cache to poison.
 * - CSP is attached only to HTML responses; the static baseline (nosniff,
 *   frame, referrer, permissions-policy), HSTS and the correlation id apply to
 *   every response.
 */
class SecurityHeaders
{
    /**
     * Correlation ids must look like a uuid or a bounded hex/opaque token; an
     * inbound value that does not match is discarded and a fresh one minted, so
     * a caller cannot inject control characters into logs via the header.
     */
    private const REQUEST_ID_PATTERN = '/^[A-Za-z0-9._-]{8,128}$/';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security-headers.enabled', true)) {
            return $next($request);
        }

        // Only mint a nonce + prime Vite when CSP is actually enabled — pure-API
        // traffic with CSP off pays nothing.
        $cspEnabled = (bool) config('security-headers.csp.enabled', true);
        $nonce = $cspEnabled ? $this->prepareNonce() : null;
        $requestId = $this->resolveRequestId($request);

        $response = $next($request);

        // The MCP Apps sandbox proxy is the only frameable HTML response in
        // the application. Its package controller emits a stricter dedicated
        // CSP and marks the response so this global baseline does not replace
        // `frame-ancestors` with DENY. User input cannot set response headers.
        if ($response->headers->get('X-AskMyDocs-MCP-App-Sandbox') === '1') {
            $response->headers->remove('X-AskMyDocs-MCP-App-Sandbox');
            $this->applyHsts($request, $response);
            $this->applyRequestId($response, $requestId);

            return $response;
        }

        $this->applyStaticHeaders($response);
        $this->applyHsts($request, $response);
        if ($nonce !== null) {
            $this->applyCsp($response, $nonce);
        }
        $this->applyRequestId($response, $requestId);

        return $response;
    }

    private function prepareNonce(): string
    {
        $nonce = base64_encode(random_bytes(16));

        // Hand the nonce to Vite so its injected tags carry it in production.
        // Guarded: in tests with withoutVite() the facade is a no-op, and we do
        // not want a Vite edge case to break the security headers.
        try {
            Vite::useCspNonce($nonce);
        } catch (\Throwable) {
            // Vite unavailable (e.g. withoutVite in tests) — the header nonce
            // below is authoritative regardless.
        }

        return $nonce;
    }

    private function applyStaticHeaders(Response $response): void
    {
        /** @var array<string, string|null> $headers */
        $headers = (array) config('security-headers.headers', []);
        foreach ($headers as $name => $value) {
            if ($value !== null && $value !== '') {
                $response->headers->set($name, $value);
            }
        }
    }

    private function applyHsts(Request $request, Response $response): void
    {
        if (! config('security-headers.hsts.enabled', true)) {
            return;
        }

        // Only over HTTPS and only in the configured environments. HSTS over
        // plain HTTP is ignored by browsers and must never be advertised in
        // local/testing.
        if (! $request->isSecure()) {
            return;
        }

        $environments = (array) config('security-headers.hsts.environments', ['production']);
        if (! app()->environment($environments)) {
            return;
        }

        $value = 'max-age='.(int) config('security-headers.hsts.max_age', 31536000);
        if (config('security-headers.hsts.include_subdomains', true)) {
            $value .= '; includeSubDomains';
        }
        if (config('security-headers.hsts.preload', false)) {
            $value .= '; preload';
        }

        $response->headers->set('Strict-Transport-Security', $value);
    }

    private function applyCsp(Response $response, string $nonce): void
    {
        // CSP is only meaningful on HTML documents; skip JSON/SSE/binary AND
        // typeless responses (204/empty), which must not carry a CSP header.
        $contentType = (string) $response->headers->get('Content-Type', '');
        if (! Str::contains($contentType, 'text/html')) {
            return;
        }

        /** @var array<int, string> $directives */
        $directives = (array) config('security-headers.csp.directives', []);
        $policy = implode('; ', array_map(
            static fn (string $directive): string => str_replace('{nonce}', $nonce, $directive),
            $directives,
        ));

        if ($policy === '') {
            return;
        }

        $reportUri = config('security-headers.csp.report_uri');
        if (is_string($reportUri) && $reportUri !== '') {
            $policy .= '; report-uri '.$reportUri;
        }

        $header = config('security-headers.csp.report_only', true)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($header, $policy);
    }

    private function resolveRequestId(Request $request): ?string
    {
        if (! config('security-headers.request_id.enabled', true)) {
            return null;
        }

        $header = (string) config('security-headers.request_id.header', 'X-Request-Id');
        $inbound = (string) $request->headers->get($header, '');
        if ($inbound !== '' && preg_match(self::REQUEST_ID_PATTERN, $inbound) === 1) {
            return $inbound;
        }

        return (string) Str::uuid();
    }

    private function applyRequestId(Response $response, ?string $requestId): void
    {
        if ($requestId === null) {
            return;
        }

        $header = (string) config('security-headers.request_id.header', 'X-Request-Id');
        $response->headers->set($header, $requestId);
    }
}
