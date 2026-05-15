<?php

declare(strict_types=1);

namespace Tests\Feature\AiAct;

use App\Compliance\TenantContextBridge;
use App\Support\TenantContext as HostTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Padosoft\AiActCompliance\MultiTenancy\Models\Tenant;
use Padosoft\AiActCompliance\MultiTenancy\Services\TenantConfigResolver;
use Padosoft\AiActCompliance\MultiTenancy\Services\TenantContext as PackageTenantContext;
use Tests\TestCase;

/**
 * v6.1.1 — host-side end-to-end proof that the v1.5 multi-tenancy
 * surface (TenantContext, TenantConfigResolver, ai-act.tenant-context
 * middleware) is wired correctly inside AskMyDocs.
 *
 * Sister-package repo has 24 PHPUnit tests for these in isolation;
 * this suite proves:
 *
 *   - The `ai-act.tenant-context` middleware alias is registered in
 *     `bootstrap/app.php` and resolves on every route group that
 *     opts in.
 *   - `App\Compliance\TenantContextBridge` propagates the host
 *     `App\Support\TenantContext` into the package context so
 *     `TenantConfigResolver::resolve()` returns per-tenant overrides
 *     under the SAME tenant id AskMyDocs is already scoping queries
 *     to (R30 / R31).
 *   - Unknown / suspended / archived tenants get the correct HTTP
 *     status from the package middleware (404 / 423 / 410).
 */
class TenantContextHostFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mount a probe route behind the package middleware alias so
        // the test asserts on the alias registration in
        // `bootstrap/app.php`, not on any specific controller.
        Route::middleware('ai-act.tenant-context')
            ->get('/test/ai-act-tenant-probe', function () {
                $packageContext = app(PackageTenantContext::class);

                return response()->json([
                    'package_slug' => $packageContext->currentSlug(),
                ]);
            })
            ->name('test.ai-act.tenant-probe');
    }

    public function test_middleware_alias_is_registered_in_bootstrap(): void
    {
        // Smoke: no header → pass-through with null package context.
        $response = $this->getJson('/test/ai-act-tenant-probe');

        $response->assertOk();
        $this->assertNull($response->json('package_slug'));
    }

    public function test_known_tenant_resolves_into_package_context(): void
    {
        Tenant::query()->create(['slug' => 'acme', 'name' => 'Acme Inc.']);

        $response = $this->getJson(
            '/test/ai-act-tenant-probe',
            ['X-Tenant-Id' => 'acme'],
        );

        $response->assertOk();
        $this->assertSame('acme', $response->json('package_slug'));
    }

    public function test_unknown_tenant_returns_404(): void
    {
        $response = $this->getJson(
            '/test/ai-act-tenant-probe',
            ['X-Tenant-Id' => 'never-existed'],
        );
        $response->assertStatus(404);
    }

    public function test_suspended_tenant_returns_423(): void
    {
        Tenant::query()->create([
            'slug' => 'frozen',
            'name' => 'Frozen Co',
            'status' => 'suspended',
        ]);
        $response = $this->getJson(
            '/test/ai-act-tenant-probe',
            ['X-Tenant-Id' => 'frozen'],
        );
        $response->assertStatus(423);
    }

    public function test_archived_tenant_returns_410(): void
    {
        Tenant::query()->create([
            'slug' => 'gone',
            'name' => 'Gone Co',
            'status' => 'archived',
        ]);
        $response = $this->getJson(
            '/test/ai-act-tenant-probe',
            ['X-Tenant-Id' => 'gone'],
        );
        $response->assertStatus(410);
    }

    public function test_bridge_propagates_host_id_into_package_context(): void
    {
        Tenant::query()->create(['slug' => 'acme', 'name' => 'Acme Inc.']);

        // Simulate: host's `ResolveTenant` already set the id before
        // the bridge runs (which is what the production pipeline does
        // — bridge call is the LAST thing in `handle()`).
        app(HostTenantContext::class)->set('acme');
        $resolved = app(TenantContextBridge::class)->syncFromHost();

        $this->assertNotNull($resolved);
        $this->assertSame('acme', $resolved->slug);
        $this->assertSame('acme', app(PackageTenantContext::class)->currentSlug());
    }

    public function test_bridge_skips_default_host_id(): void
    {
        // The host's 'default' tenant is the v3 single-tenant
        // backward-compat sentinel — we deliberately do NOT promote
        // it into the package context (the package would 404 on a
        // 'default' lookup unless the operator explicitly creates
        // that row).
        Tenant::query()->create(['slug' => 'default', 'name' => 'Default']);
        app(HostTenantContext::class)->set('default');

        $resolved = app(TenantContextBridge::class)->syncFromHost();

        $this->assertNull($resolved, 'bridge must skip the host default sentinel');
        $this->assertNull(app(PackageTenantContext::class)->currentSlug());
    }

    public function test_per_tenant_config_override_wins_when_bridge_is_active(): void
    {
        // Host default for the disparity threshold.
        config()->set('ai-act-compliance.bias.disparity_threshold', 0.05);

        Tenant::query()->create([
            'slug' => 'acme',
            'name' => 'Acme Inc.',
            'config_overrides_json' => ['bias.disparity_threshold' => 0.02],
        ]);
        app(HostTenantContext::class)->set('acme');
        app(TenantContextBridge::class)->syncFromHost();

        $resolver = app(TenantConfigResolver::class);

        // Under acme: tenant override wins.
        $this->assertSame(0.02, $resolver->resolve('bias.disparity_threshold'));

        // Other tenant (or no tenant): host config wins.
        app(PackageTenantContext::class)->set(null);
        $this->assertSame(0.05, $resolver->resolve('bias.disparity_threshold'));
    }
}
