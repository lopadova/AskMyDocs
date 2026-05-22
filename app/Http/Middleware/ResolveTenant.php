<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Compliance\TenantContextBridge;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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

        // v6.1.1 — propagate the resolved tenant id into the
        // sister-package's v1.5 TenantContext (if a matching
        // `tenants` row exists). Best-effort: an error here MUST
        // NOT break the request — the host tenant scoping
        // continues to work, the package-side just falls back to
        // the host config block as if multi-tenancy were
        // unconfigured.
        //
        // v8.0.2 / deep-review D — but failure MUST be observable.
        // The previous bare `catch (Throwable) {}` was fail-open
        // compliance: a DB outage / schema drift / package bug
        // silently dropped per-tenant policy enforcement and left
        // no breadcrumb for the operator. report() routes through
        // the configured error pipeline (Sentry, log, etc.); the
        // Log::warning() ensures at minimum a local trace exists.
        try {
            app(TenantContextBridge::class)->syncFromHost();
        } catch (Throwable $e) {
            report($e);
            Log::warning('ResolveTenant: TenantContextBridge::syncFromHost() failed; package-side falling back to host config.', [
                'tenant_id' => $tenantId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
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
