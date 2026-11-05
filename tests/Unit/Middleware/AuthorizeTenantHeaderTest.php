<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\AuthorizeTenantHeader;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Hash;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * C1 (R30) — AuthorizeTenantHeader regression guard.
 *
 * The hole: ResolveTenant runs pre-auth and trusts X-Tenant-Id blindly,
 * so any authenticated client could operate inside another tenant. This
 * middleware (mounted post-auth) must reject every tenant context for which
 * the caller has no explicit membership.
 *
 * Pure middleware test — drives a synthetic Request + stub user so the
 * authorization branches are isolated from routing / DB.
 */
final class AuthorizeTenantHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_through_when_no_header_present_and_unauthenticated(): void
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

    public function test_rejects_reserved_default_even_when_a_stale_membership_exists(): void
    {
        $user = $this->realUser('default-member@example.com');
        ProjectMembership::create([
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'project_key' => 'default',
            'role' => 'member',
        ]);

        $response = $this->dispatch($this->request(header: null, user: $user));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertStringContainsString('tenant_forbidden', (string) $response->getContent());
    }

    public function test_rejects_without_header_when_no_operational_membership_exists(): void
    {
        $user = $this->realUser('no-default@example.com');
        $response = $this->dispatch($this->request(header: null, user: $user));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertStringContainsString('tenant_forbidden', (string) $response->getContent());
    }

    public function test_system_admin_without_membership_cannot_bypass_tenant_boundary(): void
    {
        $user = $this->realUser('system-without-membership@example.com');
        $response = $this->dispatch($this->request(header: 'other-tenant', user: $user));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertStringContainsString('tenant_forbidden', (string) $response->getContent());
    }

    public function test_allows_foreign_header_with_membership_in_requested_tenant(): void
    {
        // Team-switcher path: a regular user holding a membership in the
        // requested tenant may operate inside it.
        $user = $this->realUser('member@example.com');
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'acme-kb',
            'role' => 'editor',
        ]);

        $this->assertPassesThrough($this->request(header: 'acme', user: $user));
    }

    public function test_remembers_a_successfully_authorised_explicit_tenant_in_the_session(): void
    {
        $user = $this->realUser('remembered-member@example.com');
        $this->membership($user, 'acme');
        $request = $this->request(header: 'acme', user: $user);

        $this->assertPassesThrough($request);

        $this->assertSame('acme', $request->session()->get('askmydocs.active_tenant'));
    }

    public function test_reuses_the_remembered_tenant_for_a_same_origin_package_spa(): void
    {
        $user = $this->realUser('package-spa-member@example.com');
        $this->membership($user, 'acme');
        $request = $this->request(header: null, user: $user, rememberedTenant: 'acme');

        $this->assertPassesThrough($request);

        $this->assertSame('acme', app(TenantContext::class)->current());
        $this->assertSame('acme', app(ConnectorTenantContext::class)->current());
    }

    public function test_rejects_and_forgets_a_remembered_tenant_after_membership_is_revoked(): void
    {
        $user = $this->realUser('revoked-package-spa-member@example.com');
        $request = $this->request(header: null, user: $user, rememberedTenant: 'acme');

        $response = $this->dispatch($request);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertNull($request->session()->get('askmydocs.active_tenant'));
    }

    public function test_explicit_tenant_never_falls_back_to_a_remembered_tenant(): void
    {
        $user = $this->realUser('explicit-tenant-member@example.com');
        $this->membership($user, 'acme');
        $request = $this->request(header: 'victim', user: $user, rememberedTenant: 'acme');

        $response = $this->dispatch($request);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertSame('acme', $request->session()->get('askmydocs.active_tenant'));
    }

    public function test_rejects_foreign_header_when_membership_is_in_another_tenant(): void
    {
        // A membership in tenant B must NOT open tenant A: the EXISTS is
        // scoped forTenant(header), not "any membership at all".
        $user = $this->realUser('wrong-tenant@example.com');
        ProjectMembership::create([
            'tenant_id' => 'globex',
            'user_id' => $user->id,
            'project_key' => 'globex-kb',
            'role' => 'admin',
        ]);

        $response = $this->dispatch($this->request(header: 'acme', user: $user));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertStringContainsString('tenant_forbidden', (string) $response->getContent());
    }

    public function test_rejects_foreign_header_when_membership_belongs_to_another_user(): void
    {
        $member = $this->realUser('the-member@example.com');
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $member->id,
            'project_key' => 'acme-kb',
            'role' => 'admin',
        ]);

        $outsider = $this->realUser('outsider@example.com');
        $response = $this->dispatch($this->request(header: 'acme', user: $outsider));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_suspended_tenant_is_blocked_even_with_a_membership(): void
    {
        Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'status' => 'suspended']);
        $user = $this->realUser('suspended-member@example.com');
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'acme-kb',
            'role' => 'admin',
        ]);

        $response = $this->dispatch($this->request(header: 'acme', user: $user));

        $this->assertSame(Response::HTTP_LOCKED, $response->getStatusCode());
        $this->assertStringContainsString('tenant_suspended', (string) $response->getContent());
    }

    public function test_suspended_tenant_lifecycle_is_not_disclosed_without_membership(): void
    {
        Tenant::create(['slug' => 'secret', 'name' => 'Secret', 'status' => 'suspended']);
        $outsider = $this->realUser('lifecycle-outsider@example.com');

        $response = $this->dispatch($this->request(header: 'secret', user: $outsider));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertStringContainsString('tenant_forbidden', (string) $response->getContent());
        $this->assertStringNotContainsString('tenant_suspended', (string) $response->getContent());
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
        app(TenantContext::class)->set(
            is_string($request->header('X-Tenant-Id'))
                ? (string) $request->header('X-Tenant-Id')
                : 'default',
        );
        app(ConnectorTenantContext::class)->set(
            is_string($request->header('X-Tenant-Id'))
                ? (string) $request->header('X-Tenant-Id')
                : 'default',
        );

        return app(AuthorizeTenantHeader::class)->handle(
            $request,
            static fn (): Response => new Response('downstream', 200),
        );
    }

    private function request(?string $header, ?object $user, ?string $rememberedTenant = null): Request
    {
        $request = Request::create('/api/admin/kb/tags', 'GET');
        $session = new Store('authorize-tenant-test', new ArraySessionHandler(120));
        $session->start();
        if ($rememberedTenant !== null) {
            $session->put('askmydocs.active_tenant', $rememberedTenant);
        }
        $request->setLaravelSession($session);
        if ($header !== null) {
            $request->headers->set('X-Tenant-Id', $header);
        }
        $request->setUserResolver(static fn () => $user);

        return $request;
    }

    private function membership(User $user, string $tenantId): void
    {
        ProjectMembership::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'project_key' => $tenantId.'-kb',
            'role' => 'member',
        ]);
    }

    private function realUser(string $email): User
    {
        return User::create([
            'name' => 'Membership Tester',
            'email' => $email,
            'password' => Hash::make('secret123'),
        ]);
    }
}
