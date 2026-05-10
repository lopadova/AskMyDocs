<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v4.3/W1 sub-PR 4.5 — B4 — GET /api/admin/pii/strategy endpoint test.
 *
 * Mirrors `DashboardMetricsControllerTest`'s route-mounting pattern so
 * the api.php routes are reachable under Testbench.
 */
final class PiiStrategyEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.strategy', 'mask');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_admin_can_read_pii_strategy(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson('/api/admin/pii/strategy')
            ->assertOk()
            ->assertJsonStructure([
                'active' => ['name', 'class', 'requires_tokenise_store'],
                'available',
                'config' => ['mask_token', 'hash_hex_length', 'token_hex_length', 'has_salt'],
            ])
            ->assertJsonPath('active.name', 'mask')
            ->assertJsonPath('active.requires_tokenise_store', false);
    }

    public function test_viewer_gets_403(): void
    {
        $viewer = User::create([
            'name' => 'v',
            'email' => 'v@example.com',
            'password' => Hash::make('x'),
        ]);
        $viewer->assignRole('viewer');

        $this->actingAs($viewer)
            ->getJson('/api/admin/pii/strategy')
            ->assertForbidden();
    }

    /**
     * Finding #6 + #7 — the route MUST be reachable by the `dpo` role.
     * The `viewPiiRedactorAdmin` Gate (AppServiceProvider) admits dpo;
     * the route must be mounted accordingly (i.e. NOT trapped behind
     * the role:admin|super-admin admin group, which would 403 dpo).
     */
    public function test_dpo_can_read_pii_strategy(): void
    {
        $dpo = User::create([
            'name' => 'd',
            'email' => 'd@example.com',
            'password' => Hash::make('x'),
        ]);
        $dpo->assignRole('dpo');

        $this->actingAs($dpo)
            ->getJson('/api/admin/pii/strategy')
            ->assertOk()
            ->assertJsonPath('active.name', 'mask');
    }

    public function test_route_is_explicitly_gated_by_can_view_pii_redactor_admin(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()
            ->getByName('api.admin.pii.strategy');

        $this->assertNotNull($route, 'Expected api.admin.pii.strategy route to be registered.');
        $this->assertContains(
            'can:viewPiiRedactorAdmin',
            $route->gatherMiddleware(),
            'Expected /api/admin/pii/strategy to carry can:viewPiiRedactorAdmin middleware.',
        );
    }

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'a',
            'email' => 'a@example.com',
            'password' => Hash::make('x'),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }
}
