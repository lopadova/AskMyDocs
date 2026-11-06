<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * The exact-host allow-list check shared by the operator-configured model
 * endpoints (cross-encoder profiles, the self-hosted query-expansion profile).
 * SEC-SSRF-001: no userinfo / query / fragment, https only (plain http only
 * when the caller explicitly tolerates it for an on-prem profile), and the
 * host must be listed EXACTLY — `host` (any port) or `host:port` (that port).
 * No suffix or wildcard matching; an empty list allows nothing.
 *
 * Returns null when the endpoint is acceptable, else a stable machine reason
 * (`endpoint_invalid` | `endpoint_scheme` | `endpoint_not_allowed`) the caller
 * maps to its own exception — never a boolean that hides WHY.
 */
final class AllowListedEndpoint
{
    /** @param  list<string>|array<int,mixed>  $allowedHosts */
    public static function violation(string $endpoint, array $allowedHosts, bool $allowHttp): ?string
    {
        $parts = parse_url($endpoint);
        if ($endpoint === '' || $parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return 'endpoint_invalid';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme === '' || $host === '') {
            return 'endpoint_invalid';
        }
        if ($scheme !== 'https' && ! ($scheme === 'http' && $allowHttp)) {
            return 'endpoint_scheme';
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $allowed = array_values(array_filter(array_map(
            static fn ($h): string => strtolower(trim((string) $h)),
            $allowedHosts,
        )));

        return in_array($host, $allowed, true) || in_array($host.':'.$port, $allowed, true) ? null : 'endpoint_not_allowed';
    }
}
