<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveTenant — sets the active tenant_id on the request-scoped
 * TenantContext singleton.
 *
 * Resolution order (first match wins):
 *  1. `X-Tenant-Id` header
 *  2. Authenticated user's `tenant_id` attribute (Eloquent attribute,
 *     read via `getAttribute('tenant_id')` so it works regardless of
 *     whether the user model declares it as a real PHP property)
 *  3. Default to `'default'` (backward-compat with v3 single-tenant)
 *
 * Invalid tenant_id format triggers 400 Bad Request rather than silently
 * falling back, so misuse is visible.
 *
 * Register early in the middleware stack so downstream code (controllers,
 * services, scopes) sees the right tenant on `app(TenantContext::class)->current()`.
 */
final class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->resolveTenantId($request);

        try {
            app(TenantContext::class)->set($tenantId);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'invalid_tenant_id',
                'message' => $e->getMessage(),
            ], 400);
        }

        return $next($request);
    }

    private function resolveTenantId(Request $request): string
    {
        // 1) Header has highest priority — explicit per-request override.
        $header = $request->header('X-Tenant-Id');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        // 2) Authenticated user's tenant_id attribute (Eloquent attributes
        //    live in $attributes, NOT as PHP properties — `property_exists`
        //    returns false even when the column is set. Read via
        //    `getAttribute()` so we work uniformly with any user model that
        //    exposes a `tenant_id` column or accessor.
        $user = $request->user();
        if ($user !== null) {
            $userTenantId = $user->getAttribute('tenant_id');
            if (is_string($userTenantId) && $userTenantId !== '') {
                return $userTenantId;
            }
        }

        // 3) Default — preserves v3 backward compatibility.
        return 'default';
    }
}
