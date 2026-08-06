<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Stable registry for non-operational tenant namespaces.
 *
 * Tenant-aware tables use the tenant slug as their `tenant_id`; the numeric
 * primary key on the optional `tenants` registry is deliberately irrelevant
 * to authorization. Keep every reserved slug in this one place so creation,
 * navigation and middleware cannot drift.
 */
final class SystemTenantRegistry
{
    public const REGISTRATION = 'system-registration';

    public const LEGACY_DEFAULT = 'default';

    /**
     * @return list<string>
     */
    public static function systemSlugs(): array
    {
        return [self::REGISTRATION];
    }

    /**
     * @return list<string>
     */
    public static function reservedSlugs(): array
    {
        return [self::LEGACY_DEFAULT, ...self::systemSlugs()];
    }

    public static function isSystem(string $slug): bool
    {
        return in_array($slug, self::systemSlugs(), true);
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::reservedSlugs(), true);
    }
}
