<?php

declare(strict_types=1);

namespace App\Mcp\Runtime;

use App\Services\Admin\AppSettingsResolver;
use App\Support\TenantContext;

final readonly class McpRuntimeGate
{
    public const OFF = 'off';

    public const SHADOW = 'shadow';

    public const ACTIVE = 'active';

    public function __construct(
        private AppSettingsResolver $settings,
        private TenantContext $tenants,
    ) {}

    public function mode(?string $tenantId = null): string
    {
        if (! config('connector-mcp.enabled', false)) {
            return self::OFF;
        }

        $mode = $this->settings->effective(
            'connector.mcp.runtime_mode',
            $tenantId ?? $this->tenants->current(),
        );

        return in_array($mode, [self::OFF, self::SHADOW, self::ACTIVE], true)
            ? $mode
            : self::OFF;
    }

    public function usesConnector(?string $tenantId = null): bool
    {
        return $this->mode($tenantId) === self::ACTIVE;
    }

    public function usesLegacy(?string $tenantId = null): bool
    {
        return $this->mode($tenantId) !== self::ACTIVE;
    }

    public function runsShadow(?string $tenantId = null): bool
    {
        return $this->mode($tenantId) === self::SHADOW;
    }
}
