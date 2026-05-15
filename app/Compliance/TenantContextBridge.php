<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Support\TenantContext as HostTenantContext;
use Illuminate\Contracts\Container\Container;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Padosoft\AiActCompliance\MultiTenancy\Services\TenantContext as PackageTenantContext;

/**
 * Bridge AskMyDocs's host TenantContext to the sister-package's
 * v1.5 TenantContext.
 *
 * AskMyDocs has had its own `App\Support\TenantContext` since v4.0
 * (string-based, always-set, defaults to 'default'). The sister
 * package introduced its own `Padosoft\...\MultiTenancy\Services\TenantContext`
 * in v1.5 (model-based, nullable when not in a multi-tenant request).
 * Both contexts coexist:
 *
 *   - Host context owns AskMyDocs query scoping (R30 / R31).
 *   - Package context owns config-override resolution for the AI Act
 *     services (`TenantConfigResolver::resolve()`).
 *
 * This bridge keeps them in sync at the boundaries we actually care
 * about: when the host's `ResolveTenant` runs (and sets a non-default
 * tenant id), the bridge looks up the matching `tenants` row and
 * publishes it into the package context so per-tenant
 * `config_overrides_json` applies under the same tenant id the rest
 * of AskMyDocs is using.
 *
 * Bridge does NOT auto-create `tenants` rows — that remains an
 * operator concern (POST /api/admin/ai-act-compliance/tenants). When
 * the host id has no matching package row, the package context stays
 * null and the package services fall back to the host config block
 * exactly as if multi-tenancy were not configured.
 */
final class TenantContextBridge
{
    public function __construct(private readonly Container $container) {}

    /**
     * Best-effort propagation: host tenant id → package Tenant model.
     * Idempotent — safe to call multiple times in the same request.
     *
     * Returns the resolved package tenant (or null when no matching
     * `tenants` row exists, including for the default 'default'
     * host tenant id, which we deliberately do NOT auto-promote into
     * the package context).
     */
    public function syncFromHost(): ?Tenant
    {
        if (! $this->container->bound(HostTenantContext::class)) {
            return null;
        }
        $hostContext = $this->container->make(HostTenantContext::class);
        $hostId = $hostContext->current();
        if ($hostId === '' || $hostContext->isDefault()) {
            return null;
        }
        if (! $this->container->bound(PackageTenantContext::class)) {
            return null;
        }
        $packageContext = $this->container->make(PackageTenantContext::class);

        // Already in sync — skip the DB lookup.
        if ($packageContext->currentSlug() === $hostId) {
            return $packageContext->current();
        }

        return $packageContext->activate($hostId);
    }
}
