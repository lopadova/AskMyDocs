<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * SSRF allow-list for server-side outbound requests (SEC-SSRF-001).
 *
 * assertAllowed() rejects a URL whose scheme is not permitted, whose host
 * cannot be resolved, or whose host resolves to a private / loopback /
 * link-local / cloud-metadata / reserved address. Every A/AAAA record is
 * checked — a host that resolves to a public AND a private address is rejected
 * (reject-if-any). Redirects are disabled by callers so a 3xx cannot smuggle an
 * internal target past this check.
 *
 * Residual (documented): this is a resolve-then-check, so a DNS-rebind between
 * validation and the actual connect (TOCTOU) is not fully closed — pinning the
 * validated IP into the connection would require a custom cURL resolver. The
 * primary vectors (literal internal IPs, metadata IP, private hostnames,
 * redirect-to-internal, decimal/hex IP obfuscation) are closed.
 */
class OutboundUrlValidator
{
    /**
     * @throws UnsafeOutboundUrlException
     */
    public static function assertAllowed(string $url): void
    {
        if (! (bool) config('outbound-http.enabled', true)) {
            return;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeOutboundUrlException('Outbound URL is malformed or missing scheme/host.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        /** @var array<int, string> $allowedSchemes */
        $allowedSchemes = (array) config('outbound-http.allowed_schemes', ['https']);
        if (! in_array($scheme, array_map('strtolower', $allowedSchemes), true)) {
            throw new UnsafeOutboundUrlException("Outbound URL scheme '{$scheme}' is not allowed.");
        }

        // Credentials in the authority are a common obfuscation vector.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeOutboundUrlException('Outbound URL must not embed credentials.');
        }

        $host = self::normalizeHost((string) $parts['host']);

        foreach (self::resolveIps($host) as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new UnsafeOutboundUrlException(
                    "Outbound URL host resolves to a non-public address ({$ip}).",
                );
            }
        }
    }

    private static function normalizeHost(string $host): string
    {
        // Strip IPv6 brackets: parse_url keeps them for literals like [::1].
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    /**
     * @return array<int, string> every IP the host resolves to (or the literal)
     *
     * @throws UnsafeOutboundUrlException
     */
    private static function resolveIps(string $host): array
    {
        // IP literal (v4 or v6) — validate directly.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [self::unmapIpv4($host)];
        }

        // A bare-numeric / hex host (e.g. http://2130706433 = 127.0.0.1, or
        // http://0x7f000001) is not a valid IP literal but cURL would interpret
        // it as one. Reject rather than try to normalise every obfuscation.
        if (preg_match('/^(0x[0-9a-f]+|\d+)$/i', $host) === 1) {
            throw new UnsafeOutboundUrlException('Outbound URL host is a numeric/obfuscated address.');
        }

        // A failed lookup (NXDOMAIN) is expected control flow, not an error to
        // surface — but suppress its warning with a scoped handler rather than
        // the `@` operator (R7: no @-silenced errors).
        $ips = [];
        $v4 = self::withoutWarnings(static fn () => gethostbynamel($host));
        if (is_array($v4)) {
            $ips = $v4;
        }
        $v6 = self::withoutWarnings(static fn () => dns_get_record($host, DNS_AAAA));
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        $ips = array_values(array_unique(array_map([self::class, 'unmapIpv4'], $ips)));
        if ($ips === []) {
            throw new UnsafeOutboundUrlException("Outbound URL host '{$host}' does not resolve.");
        }

        return $ips;
    }

    /**
     * Collapse an IPv4-mapped IPv6 address (::ffff:127.0.0.1) to its IPv4 form
     * so the range checks below cannot be bypassed through the mapping.
     */
    private static function unmapIpv4(string $ip): string
    {
        if (stripos($ip, '::ffff:') === 0) {
            $tail = substr($ip, 7);
            if (filter_var($tail, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $tail;
            }
        }

        return $ip;
    }

    /**
     * Run a DNS lookup with warnings suppressed via a scoped error handler
     * (never the `@` operator — R7). Returns the callback's value.
     */
    private static function withoutWarnings(callable $callback): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    private static function isPublicIp(string $ip): bool
    {
        // FILTER_FLAG_NO_PRIV_RANGE rejects RFC1918 + fc00::/7 + fe80::/10;
        // FILTER_FLAG_NO_RES_RANGE rejects loopback, 169.254/16 (incl. the
        // 169.254.169.254 metadata IP), 0.0.0.0/8, 240.0.0.0/4 and friends.
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
