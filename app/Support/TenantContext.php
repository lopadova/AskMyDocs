<?php

declare(strict_types=1);

namespace App\Support;

/**
 * TenantContext — request-scoped singleton holding the active tenant id.
 *
 * Set by `ResolveTenant` middleware (HTTP) or by `--tenant=X` option on
 * the CLI commands that touch tenant-aware data. Defaults to `'default'`
 * so v3 code that never sets a tenant keeps working (backward compat).
 *
 * R31: every tenant-aware query MUST funnel through this context.
 * Pattern parallels the v3 ProjectContext singleton — same lifecycle,
 * same DI binding (singleton in the container, reset between tests).
 */
final class TenantContext
{
    private const DEFAULT_TENANT = 'default';

    private string $tenantId = self::DEFAULT_TENANT;

    public function set(string $tenantId): void
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            $tenantId = self::DEFAULT_TENANT;
        }

        // Validate format: lowercase alphanumeric + dash + underscore, max 50 chars.
        // Mirrors the column constraint and what middleware accepts as input.
        if (! preg_match('/^[a-z0-9_-]{1,50}$/', $tenantId)) {
            throw new \InvalidArgumentException(
                "Invalid tenant_id format: must match /^[a-z0-9_-]{1,50}$/, got '{$tenantId}'"
            );
        }

        $this->tenantId = $tenantId;
    }

    public function current(): string
    {
        return $this->tenantId;
    }

    public function isDefault(): bool
    {
        return $this->tenantId === self::DEFAULT_TENANT;
    }

    public function reset(): void
    {
        $this->tenantId = self::DEFAULT_TENANT;
    }
}
