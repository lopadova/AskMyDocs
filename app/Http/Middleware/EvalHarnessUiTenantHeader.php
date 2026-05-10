<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v4.2/W4 sub-PR 7 — R30 tenant injection for eval-harness-ui.
 *
 * The package supports multi-tenant operation through an HTTP header
 * (default name `X-Eval-Harness-Tenant`, configured at
 * `config('eval-harness-ui.tenant_header')`). The package itself never
 * reads AskMyDocs's `TenantContext` — it just forwards the header to
 * the backing eval-harness API.
 *
 * This middleware bridges the two: on every request to the SPA mount
 * URL we set the configured header from the request-scoped
 * `TenantContext::current()` BEFORE the package controller (and its
 * downstream API forwarding) sees the request.
 *
 * Why on the request and not on the response: the package's API
 * forwarding reads it from `$request->header(...)`. Setting it on the
 * response would be too late.
 *
 * Why we don't override an existing inbound header: an operator
 * proxy / SDK MAY have already set the header to a value the operator
 * intends. In that narrow case the inbound value wins — the
 * TenantContext fallback only fires when no value is present.
 *
 * Registered as a route middleware alias
 * `eval-harness-ui.tenant-header` by
 * {@see \App\Providers\EvalHarnessUiIntegrationServiceProvider}.
 */
final class EvalHarnessUiTenantHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $headerName = (string) config(
            'eval-harness-ui.tenant_header',
            'X-Eval-Harness-Tenant',
        );

        if ($headerName === '') {
            return $next($request);
        }

        $existing = $request->headers->get($headerName);
        if (is_string($existing) && $existing !== '') {
            return $next($request);
        }

        $tenant = app(TenantContext::class)->current();
        if (is_string($tenant) && $tenant !== '') {
            $request->headers->set($headerName, $tenant);
        }

        return $next($request);
    }
}
