<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ProjectMembership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
 * This middleware is mounted AFTER `auth:sanctum` on every authenticated
 * route group, so the user is resolved by the time it runs. It only acts
 * when an explicit `X-Tenant-Id` override is present — normal SPA traffic
 * sends no such header (the tenant is derived from the user / default), so
 * this is a no-op for the common path.
 *
 * Policy (R30, decision 2026-05-26; membership extension 2026-06-10 for
 * the SPA team switcher):
 *   - No header                 → pass through (no override attempted).
 *   - Header == own tenant      → pass through.
 *   - Header != own tenant
 *       + holds `tenant.cross-access` permission → pass through (audited).
 *       + has ≥1 `project_memberships` row in the requested tenant
 *         → pass through (this is what lets a regular user operate in
 *         the teams the switcher offers; users carry no tenant_id
 *         column, so every non-default team flows through here).
 *       + otherwise                              → 403 tenant_forbidden.
 *   - Unauthenticated           → pass through (no protected data is
 *     reachable on an unauthenticated request; the route's own auth gate
 *     handles rejection).
 */
final class AuthorizeTenantHeader
{
    public const CROSS_ACCESS_PERMISSION = 'tenant.cross-access';

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('X-Tenant-Id');
        if (! is_string($header) || $header === '') {
            return $next($request);
        }

        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $ownTenant = $user->getAttribute('tenant_id');
        $ownTenant = is_string($ownTenant) && $ownTenant !== '' ? $ownTenant : 'default';

        if ($header === $ownTenant) {
            return $next($request);
        }

        if ($this->canAccessForeignTenant($user)) {
            Log::info('Cross-tenant access granted via X-Tenant-Id header.', [
                'user_id' => $user->getAuthIdentifier(),
                'own_tenant' => $ownTenant,
                'requested_tenant' => $header,
                'path' => $request->path(),
            ]);

            return $next($request);
        }

        // Team-switcher path: a membership in the requested tenant is the
        // proof the user belongs to that team. Single indexed EXISTS per
        // request; logged at debug — this is the COMMON path for every
        // request the SPA sends while operating in a non-default team.
        if ($this->hasMembershipInTenant($user, $header)) {
            return $next($request);
        }

        return response()->json([
            'error' => 'tenant_forbidden',
            'message' => 'You are not authorised to act on behalf of the requested tenant.',
        ], Response::HTTP_FORBIDDEN);
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

    private function canAccessForeignTenant(mixed $user): bool
    {
        return is_object($user)
            && method_exists($user, 'can')
            && $user->can(self::CROSS_ACCESS_PERMISSION);
    }
}
