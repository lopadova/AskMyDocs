<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Http\Middleware\EvalHarnessUiTenantHeader;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * v4.2/W4 sub-PR 7 — R30 tenant injection contract for the
 * EvalHarnessUiTenantHeader middleware.
 *
 * The middleware is invoked on every request to a route under the
 * eval-harness-ui mount. It must:
 *
 *   - inject the configured header (default: X-Eval-Harness-Tenant)
 *     from the request-scoped TenantContext when no inbound value is
 *     present;
 *   - leave an existing inbound header value untouched (operator-set
 *     headers / SDK overrides win);
 *   - no-op when the configured header name is empty (unusual config
 *     but should not crash);
 *   - no-op when TenantContext returns an empty string.
 *
 * Tests drive the middleware directly with a synthetic Request rather
 * than through the package controller — that way the assertions
 * isolate middleware behaviour from package routing nuances.
 */
class EvalHarnessUiTenantHeaderTest extends TestCase
{
    public function test_injects_header_from_tenant_context_when_inbound_is_empty(): void
    {
        $this->setActiveTenant('acme-corp');

        $middleware = new EvalHarnessUiTenantHeader;
        $request = Request::create('/admin/eval-harness', 'GET');

        $captured = null;
        $middleware->handle($request, function (Request $r) use (&$captured) {
            $captured = $r->headers->get('X-Eval-Harness-Tenant');

            return response('ok');
        });

        $this->assertSame('acme-corp', $captured);
    }

    public function test_preserves_existing_inbound_header_value(): void
    {
        // Operator-set header MUST win — proxies / SDKs may legitimately
        // pre-populate the header to scope a debug request to a
        // specific tenant.
        $this->setActiveTenant('acme-corp');

        $middleware = new EvalHarnessUiTenantHeader;
        $request = Request::create('/admin/eval-harness', 'GET');
        $request->headers->set('X-Eval-Harness-Tenant', 'override-tenant');

        $captured = null;
        $middleware->handle($request, function (Request $r) use (&$captured) {
            $captured = $r->headers->get('X-Eval-Harness-Tenant');

            return response('ok');
        });

        $this->assertSame('override-tenant', $captured);
    }

    public function test_no_op_when_header_name_is_empty_in_config(): void
    {
        // Edge case — operator deliberately blanked the header name.
        // The middleware must not crash; it just passes the request
        // through untouched.
        config(['eval-harness-ui.tenant_header' => '']);
        $this->setActiveTenant('acme-corp');

        $middleware = new EvalHarnessUiTenantHeader;
        $request = Request::create('/admin/eval-harness', 'GET');

        $response = $middleware->handle($request, fn () => response('ok'));

        $this->assertSame(200, $response->getStatusCode());
        // Default header NOT auto-set — config explicitly turned it off.
        $this->assertNull($request->headers->get('X-Eval-Harness-Tenant'));
    }

    public function test_falls_back_to_default_tenant_when_set_to_empty_string(): void
    {
        // TenantContext::set('') normalizes to the 'default' sentinel
        // (see app/Support/TenantContext.php). The middleware must
        // therefore inject 'default' — empty string can never reach
        // the header by going through TenantContext.
        $this->setActiveTenant('');

        $middleware = new EvalHarnessUiTenantHeader;
        $request = Request::create('/admin/eval-harness', 'GET');

        $captured = null;
        $middleware->handle($request, function (Request $r) use (&$captured) {
            $captured = $r->headers->get('X-Eval-Harness-Tenant');

            return response('ok');
        });

        $this->assertSame('default', $captured);
    }

    public function test_uses_configured_header_name_when_overridden(): void
    {
        config(['eval-harness-ui.tenant_header' => 'X-Custom-Tenant-Id']);
        $this->setActiveTenant('acme-corp');

        $middleware = new EvalHarnessUiTenantHeader;
        $request = Request::create('/admin/eval-harness', 'GET');

        $captured = null;
        $middleware->handle($request, function (Request $r) use (&$captured) {
            $captured = $r->headers->get('X-Custom-Tenant-Id');

            return response('ok');
        });

        $this->assertSame('acme-corp', $captured);
    }

    private function setActiveTenant(string $tenantId): void
    {
        $ctx = app(TenantContext::class);
        $ctx->set($tenantId);
    }
}
