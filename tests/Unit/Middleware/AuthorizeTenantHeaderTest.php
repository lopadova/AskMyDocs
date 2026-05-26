<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\AuthorizeTenantHeader;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * C1 (R30) — AuthorizeTenantHeader regression guard.
 *
 * The hole: ResolveTenant runs pre-auth and trusts X-Tenant-Id blindly,
 * so any authenticated client could operate inside another tenant. This
 * middleware (mounted post-auth) must reject a foreign X-Tenant-Id unless
 * the caller holds the `tenant.cross-access` permission.
 *
 * Pure middleware test — drives a synthetic Request + stub user so the
 * authorization branches are isolated from routing / DB.
 */
final class AuthorizeTenantHeaderTest extends TestCase
{
    public function test_passes_through_when_no_header_present(): void
    {
        $this->assertPassesThrough($this->request(header: null, user: null));
    }

    public function test_passes_through_when_header_present_but_unauthenticated(): void
    {
        // No protected data is reachable on an unauthenticated request —
        // the route's own auth gate rejects it. The middleware does not
        // need to 403 here.
        $this->assertPassesThrough($this->request(header: 'acme', user: null));
    }

    public function test_passes_through_when_header_matches_own_tenant(): void
    {
        $user = $this->user(ownTenant: 'acme', crossAccess: false);
        $this->assertPassesThrough($this->request(header: 'acme', user: $user));
    }

    public function test_rejects_foreign_header_without_cross_access(): void
    {
        // The core C1 attack: an authenticated user (own tenant resolves
        // to 'default' here) forging a foreign tenant header.
        $user = $this->user(ownTenant: 'default', crossAccess: false);
        $response = $this->dispatch($this->request(header: 'victim-tenant', user: $user));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertStringContainsString('tenant_forbidden', (string) $response->getContent());
    }

    public function test_allows_foreign_header_with_cross_access_permission(): void
    {
        $user = $this->user(ownTenant: 'default', crossAccess: true);
        $this->assertPassesThrough($this->request(header: 'other-tenant', user: $user));
    }

    // -----------------------------------------------------------------

    private function assertPassesThrough(Request $request): void
    {
        $response = $this->dispatch($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('downstream', (string) $response->getContent());
    }

    private function dispatch(Request $request): Response
    {
        return (new AuthorizeTenantHeader)->handle(
            $request,
            static fn (): Response => new Response('downstream', 200),
        );
    }

    private function request(?string $header, ?object $user): Request
    {
        $request = Request::create('/api/admin/kb/tags', 'GET');
        if ($header !== null) {
            $request->headers->set('X-Tenant-Id', $header);
        }
        $request->setUserResolver(static fn () => $user);

        return $request;
    }

    private function user(string $ownTenant, bool $crossAccess): object
    {
        return new class($ownTenant, $crossAccess)
        {
            public function __construct(
                private string $ownTenant,
                private bool $crossAccess,
            ) {}

            public function getAttribute(string $key): ?string
            {
                return $key === 'tenant_id' ? $this->ownTenant : null;
            }

            public function can(string $permission): bool
            {
                return $permission === AuthorizeTenantHeader::CROSS_ACCESS_PERMISSION
                    && $this->crossAccess;
            }

            public function getAuthIdentifier(): int
            {
                return 1;
            }
        };
    }
}
