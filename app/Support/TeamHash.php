<?php

declare(strict_types=1);

namespace App\Support;

/**
 * TeamHash — the unique, URL-safe identifier of a team (= tenant) used
 * as the routing segment of the SPA: `/app/{teamHash}/…`.
 *
 * Deterministic by design: a truncated sha256 of the tenant_id, NOT a
 * stored random token. This keeps it computable everywhere (no DB
 * lookup, no row required for tenants that never touched the package
 * `tenants` table, e.g. `default`), stable across re-deploys, and
 * identical for the same tenant on every environment — bookmarks and
 * shared deep links keep working.
 *
 * 12 hex chars = 48 bits. With the handful of tenants a deployment
 * hosts, the collision probability is negligible (~10^-9 even at ten
 * thousand tenants). The hash is a ROUTING namespace, not a secret:
 * authorization stays on AuthorizeTenantHeader (membership /
 * cross-access), so guessing a hash discloses nothing and grants
 * nothing.
 */
final class TeamHash
{
    public const LENGTH = 12;

    public static function for(string $tenantId): string
    {
        return substr(hash('sha256', $tenantId), 0, self::LENGTH);
    }
}
