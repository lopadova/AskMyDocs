<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ProjectMembership;
use App\Support\SystemTenantRegistry;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * AuthorizeTenantHeader — closes the C1 cross-tenant escalation hole.
 *
 * `ResolveTenant` runs FIRST in the stack (prepended in bootstrap/app.php),
 * before the session + Sanctum guard resolve the authenticated user. That
 * is by design — every downstream service needs the tenant on
 * `TenantContext` — but it means ResolveTenant CANNOT validate an inbound
 * `X-Tenant-Id` header against the authenticated user (there is no user
 * yet). Without this guard, any authenticated client could send
 * `X-Tenant-Id: victim` and operate inside another tenant.
 *
 * This middleware is mounted AFTER `auth:sanctum` on every operational
 * route group, so the user is resolved by the time it runs. It validates
 * the tenant already resolved into TenantContext whether it came from an
 * explicit header or from the legacy `default` fallback.
 *
 * Policy (R30, decision 2026-05-26; membership extension 2026-06-10 for
 * the SPA team switcher):
 *   - Membership in the resolved tenant → pass through.
 *   - No membership                     → 403 tenant_forbidden.
 *   - Unauthenticated           → pass through (no protected data is
 *     reachable on an unauthenticated request; the route's own auth gate
 *     handles rejection).
 *
 * `/api/auth/*` and `/api/system-admin/*` deliberately do not mount this
 * middleware, so authentication and the global control plane remain usable
 * by a system administrator with zero operational memberships.
 */
final class AuthorizeTenantHeader
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $tenantId = $this->tenants->current();

        // System namespaces are storage/control-plane implementation details,
        // never operational destinations — even an accidentally-created
        // membership must not turn one into a selectable tenant.
        if (SystemTenantRegistry::isSystem($tenantId)) {
            return response()->json([
                'error' => 'tenant_forbidden',
                'message' => 'You are not authorised to act on behalf of the requested tenant.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Check membership before lifecycle so a forged tenant slug never
        // reveals whether that tenant is active, suspended, or archived.
        if (! $this->hasMembershipInTenant($user, $tenantId)) {
            return response()->json([
                'error' => 'tenant_forbidden',
                'message' => 'You are not authorised to act on behalf of the requested tenant.',
            ], Response::HTTP_FORBIDDEN);
        }

        // A registry lifecycle state is authoritative even when the caller
        // still has a stale membership. The global
        // super-admin control plane carries no tenant header, so operators can
        // always reactivate a tenant from there.
        if (Schema::hasTable('tenants')) {
            $status = Tenant::query()->where('slug', $tenantId)->value('status');
            if ($status === 'suspended') {
                return response()->json([
                    'error' => 'tenant_suspended',
                    'message' => 'This tenant is suspended.',
                ], Response::HTTP_LOCKED);
            }
            if ($status === 'archived') {
                return response()->json([
                    'error' => 'tenant_archived',
                    'message' => 'This tenant is archived.',
                ], Response::HTTP_GONE);
            }
        }

        return $next($request);
    }

    private function hasMembershipInTenant(mixed $user, string $tenantId): bool
    {
        if (! is_object($user) || ! method_exists($user, 'getAuthIdentifier')) {
            return false;
        }

        return ProjectMembership::query()
            ->forTenant($tenantId)
            ->where('user_id', $user->getAuthIdentifier())
            ->exists();
    }
}
