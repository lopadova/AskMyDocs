<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * Resolved-router exposure regression gate (SEC-COVERAGE-001,
 * route-exposure-regression-gate, http-surface-inventory, F-07).
 *
 * Enumerates the REAL resolved routing table (web.php + api.php, group
 * middleware included) and asserts:
 *   1. every state-changing route (POST/PUT/PATCH/DELETE) is authenticated OR
 *      is on the explicit, reasoned public allow-list below;
 *   2. every SSE route (`auth.sse`) also carries `tenant.authorize` — the exact
 *      class of gap that was the F-04 cross-tenant IDOR.
 *
 * A new mutating route that forgets its auth gate — or a new SSE route that
 * forgets tenant scoping — fails this test until it is gated or the exposure is
 * consciously declared here.
 */
class RouteExposureTest extends TestCase
{
    /**
     * Middleware names/prefixes that establish an authenticated + authorized
     * request. `role:` / `can:` / `permission:` gate access against the current
     * user (denying anonymous callers); the rest authenticate.
     *
     * @var array<int, string>
     */
    private const AUTH_PREFIXES = [
        'auth',                 // auth, auth:sanctum, auth.sse, auth.sse:sanctum
        'role:',
        'can:',
        'permission:',
        'role_or_permission:',
        'tenant.authorize',
        'widget.key',
        'mcp.scope',
    ];

    /**
     * Deliberately public state-changing routes: authentication bootstrap,
     * invite-gated registration (throttled), the widget's anonymous session
     * token (widget-key gated + throttled), and the testing-only reset/seed
     * endpoints (mounted only under APP_ENV=testing). Each is a reasoned
     * exception, not an oversight.
     *
     * @var array<int, string>
     */
    private const PUBLIC_MUTATING_ROUTES = [
        'login',
        'testing/reset',
        'testing/seed',
        'api/auth/login',
        'api/auth/register',
        'api/auth/forgot-password',
        'api/auth/reset-password',
        'api/auth/token',
        'api/auth/register-token',
        'api/widget/user-token',
        'csp-report',
    ];

    protected function defineRoutes($router): void
    {
        require __DIR__.'/../../routes/web.php';
        $router->prefix('api')->middleware('api')->group(__DIR__.'/../../routes/api.php');
    }

    private function routeHasAuth(Route $route): bool
    {
        foreach (array_filter($route->gatherMiddleware(), 'is_string') as $m) {
            foreach (self::AUTH_PREFIXES as $prefix) {
                if (str_starts_with($m, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function routeIsMutating(Route $route): bool
    {
        return (bool) array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods());
    }

    public function test_every_mutating_route_is_authenticated_or_declared_public(): void
    {
        $offenders = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            if (! $this->routeIsMutating($route) || $this->routeHasAuth($route)) {
                continue;
            }
            if (in_array($route->uri(), self::PUBLIC_MUTATING_ROUTES, true)) {
                continue;
            }
            $offenders[] = implode('|', array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()))
                .' /'.$route->uri();
        }

        $this->assertSame(
            [],
            $offenders,
            "Un-authenticated state-changing route(s) that are not on the public allow-list. "
            ."Gate them with auth/role/can/tenant.authorize, or add a reasoned entry to "
            ."RouteExposureTest::PUBLIC_MUTATING_ROUTES:\n".implode("\n", $offenders),
        );
    }

    public function test_every_sse_route_enforces_tenant_authorization(): void
    {
        $missing = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            $mw = array_filter($route->gatherMiddleware(), 'is_string');
            $isSse = false;
            foreach ($mw as $m) {
                if (str_starts_with($m, 'auth.sse')) {
                    $isSse = true;
                    break;
                }
            }
            if ($isSse && ! in_array('tenant.authorize', $mw, true)) {
                $missing[] = '/'.$route->uri();
            }
        }

        $this->assertSame(
            [],
            $missing,
            "SSE route(s) with auth.sse but no tenant.authorize — the F-04 cross-tenant "
            ."IDOR class. Add tenant.authorize:\n".implode("\n", $missing),
        );
    }
}
