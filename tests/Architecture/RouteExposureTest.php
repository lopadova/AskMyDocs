<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Routing\Route;
use ReflectionMethod;
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
    /** Exact middleware names that authenticate. @var array<int, string> */
    private const AUTH_EXACT = ['auth', 'tenant.authorize', 'widget.key', 'mcp.scope'];

    /**
     * Prefixes that authenticate/authorize. `auth:` / `auth.` are precise so a
     * hypothetical `authorize-*` alias is NOT mistaken for authentication.
     *
     * @var array<int, string>
     */
    private const AUTH_PREFIXES = [
        'auth:',                // auth:sanctum
        'auth.',                // auth.sse, auth.sse:sanctum
        'role:',
        'can:',
        'permission:',
        'role_or_permission:',
    ];

    /**
     * Deliberately public state-changing routes: authentication bootstrap,
     * invite-gated registration (throttled), the widget's anonymous session
     * token (widget-key gated + throttled), and the testing-only reset/seed
     * endpoints (mounted only under APP_ENV=testing). Each is a reasoned
     * exception, not an oversight.
     *
     * Keyed by URI; the value is `'*'` when every mutating method on that
     * URI is exempt (the common case — one mutating method per URI), or an
     * explicit method list when only SOME of the URI's mutating methods are
     * exempt. `mcp/kb` needs the latter: it carries both a real
     * authenticated POST transport (mcp.scope) AND a spec-mandated,
     * middleware-less DELETE 405-stub sharing the same URI. A bare
     * URI-only match (the previous shape) would silently exempt POST too
     * if `mcp.scope` were ever accidentally dropped from that route —
     * exactly the gap this test exists to catch (Copilot review PR #497,
     * pullrequestreview-5256772155).
     *
     * @var array<string, string|array<int, string>>
     */
    private const PUBLIC_MUTATING_ROUTES = [
        'login' => '*',
        'testing/reset' => '*',
        'testing/seed' => '*',
        'api/auth/login' => '*',
        'api/auth/register' => '*',
        'api/auth/forgot-password' => '*',
        'api/auth/reset-password' => '*',
        'api/auth/token' => '*',
        'api/auth/register-token' => '*',
        'api/widget/user-token' => '*',
        'csp-report' => '*',
        // v8.37/W3b round 7 — Laravel\Mcp\Server\Registrar::web() registers
        // a stub DELETE /mcp/kb (spec-mandated 405 "Allow: POST" responder
        // for the MCP HTTP transport's session-close semantics) with NO
        // middleware — it touches no data and mutates nothing, it only
        // ever returns a static 405. ONLY DELETE is exempt here: the real
        // POST /mcp/kb must always carry mcp.scope (AUTH_EXACT below) and
        // is never covered by this entry.
        'mcp/kb' => ['DELETE'],
        // v8.39/W5 — `padosoft/laravel-routines`' own webhook ingress
        // (`RoutinesServiceProvider::packageBooted()`, `hooks/routines/{id}`).
        // The package mounts it unconditionally by DEFAULT, but this app's
        // `config/routines.php` now sets `webhooks.enabled` to `false`
        // (subagent review, PR #512 — should-fix: no reason to expose it
        // when nothing here ever creates a `trigger_kind=webhook` routine),
        // so under the test suite's config it currently does NOT appear in
        // the resolved routing table at all. This entry stays regardless:
        // an operator can still set `ROUTINES_WEBHOOKS_ENABLED=true`, and
        // when they do the route is deliberately session-less by design
        // (ADR 0033 §5 quotes the package's own docblock: "la chiama una
        // macchina, che non ha cookie ne' CSRF e non deve averne") — auth
        // is an HMAC-SHA256 signature over the raw body with a PER-ROUTINE
        // secret (`WebhookController`), not Laravel's `auth` middleware,
        // plus a `throttle:60,1` rate limit.
        'hooks/routines/{id}' => '*',
    ];

    protected function defineRoutes($router): void
    {
        require __DIR__.'/../../routes/web.php';
        $router->prefix('api')->middleware('api')->group(__DIR__.'/../../routes/api.php');
        // v8.37/W3b round 7 — POST /mcp/kb (routes/ai.php) is a real
        // mutating route and belongs in this inventory. `laravel/mcp`'s
        // own McpServiceProvider (registered in the parent TestCase's
        // getEnvironmentSetUp) would normally load it, but under
        // Testbench its base_path() points at Testbench's own skeleton
        // app, not this project, so that auto-load silently no-ops here
        // — same reason Tests\TestCase::defineRoutes() requires it
        // explicitly. `mcp.scope` is already in AUTH_EXACT below.
        require __DIR__.'/../../routes/ai.php';
    }

    private function routeHasAuth(Route $route): bool
    {
        foreach (array_filter($route->gatherMiddleware(), 'is_string') as $m) {
            if (in_array($m, self::AUTH_EXACT, true)) {
                return true;
            }
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

    /**
     * Method-aware allow-list lookup: `'*'` exempts every mutating method on
     * the URI; an explicit method list exempts ONLY those methods, so a
     * route sharing a URI with an exempt stub (e.g. POST /mcp/kb alongside
     * the exempt DELETE stub) is never accidentally waved through too.
     */
    private function routeIsPubliclyAllowed(Route $route): bool
    {
        $allowed = self::PUBLIC_MUTATING_ROUTES[$route->uri()] ?? null;
        if ($allowed === null) {
            return false;
        }
        if ($allowed === '*') {
            return true;
        }

        $mutatingMethods = array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods());

        return array_diff($mutatingMethods, $allowed) === [];
    }

    public function test_every_mutating_route_is_authenticated_or_declared_public(): void
    {
        $offenders = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            if (! $this->routeIsMutating($route) || $this->routeHasAuth($route)) {
                continue;
            }
            if ($this->routeIsPubliclyAllowed($route)) {
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

    /**
     * Copilot review PR #497 (pullrequestreview-5256772155) regression: the
     * previous bare URI-only allow-list (`in_array($route->uri(), [...])`)
     * would have exempted BOTH methods sharing the `mcp/kb` URI — the real
     * POST transport included — if `mcp.scope` were ever accidentally
     * dropped from the POST route. Proves the method-aware lookup tells
     * the exempt DELETE stub apart from the always-must-be-authenticated
     * POST route, using real Route objects (not just the current routing
     * table, which would trivially pass either way as long as POST /mcp/kb
     * keeps its middleware today).
     */
    public function test_public_mutating_routes_allow_list_is_method_aware_not_uri_only(): void
    {
        $method = new ReflectionMethod($this, 'routeIsPubliclyAllowed');
        $method->setAccessible(true);

        $deleteRoute = new Route(['DELETE'], 'mcp/kb', fn () => null);
        $postRoute = new Route(['POST'], 'mcp/kb', fn () => null);

        $this->assertTrue(
            $method->invoke($this, $deleteRoute),
            'the spec-mandated DELETE /mcp/kb 405 stub must stay exempt',
        );
        $this->assertFalse(
            $method->invoke($this, $postRoute),
            'POST /mcp/kb must NEVER be exempted by URI alone — it must carry mcp.scope',
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
