<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v4.5/W3 — Gate coverage for /api/admin/connectors.
 *
 * The `manageConnectors` Gate (registered in
 * `AppServiceProvider::registerConnectorGates()`) is the load-bearing
 * defence: every connector route group runs under
 * `can:manageConnectors` so a single Gate body governs all six
 * endpoints.
 *
 * ConnectorAdminControllerTest already covers the negative path
 * (`test_non_super_admin_gets_403_on_every_endpoint` +
 * `test_guest_gets_401`). This file adds positive coverage:
 *   - super-admin role → 200 on the list endpoint.
 *   - admin role       → 200 (admin + super-admin manage connectors).
 *   - viewer role      → 403.
 *   - guest            → 401.
 *
 * R30 — every assertion runs under the `default` tenant; the Gate
 * does not reach into the active tenant, but the controller does, so
 * we keep the test scope to authorisation only (controller tests
 * cover tenant isolation separately).
 */
final class ConnectorAdminGateTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_gets_200_on_index(): void
    {
        $user = $this->makeUserWithRole('super-admin', 'super');

        $this->actingAs($user)
            ->getJson('/api/admin/connectors')
            ->assertStatus(200);
    }

    public function test_admin_role_gets_200_on_index(): void
    {
        $user = $this->makeUserWithRole('admin', 'admin');

        $this->actingAs($user)
            ->getJson('/api/admin/connectors')
            ->assertStatus(200);
    }

    public function test_viewer_role_gets_403_on_index(): void
    {
        $user = $this->makeUserWithRole('viewer', 'viewer');

        $this->actingAs($user)
            ->getJson('/api/admin/connectors')
            ->assertStatus(403);
    }

    public function test_guest_gets_401_on_index(): void
    {
        $this->getJson('/api/admin/connectors')->assertStatus(401);
    }

    public function test_gate_applies_to_install_endpoint(): void
    {
        // A role OUTSIDE the allow-set (viewer) proves the Gate guards the
        // install endpoint, independent of any provider-specific controller path.
        $viewer = $this->makeUserWithRole('viewer', 'viewer-install');

        // The install endpoint sits behind the same Gate as index.
        $this->actingAs($viewer)
            ->getJson('/api/admin/connectors/google-drive/install')
            ->assertStatus(403);
    }

    public function test_gate_applies_to_sync_endpoint(): void
    {
        $viewer = $this->makeUserWithRole('viewer', 'viewer-sync');

        $this->actingAs($viewer)
            ->postJson('/api/admin/connectors/999/sync-now')
            ->assertStatus(403);
    }

    public function test_gate_applies_to_destroy_endpoint(): void
    {
        $viewer = $this->makeUserWithRole('viewer', 'viewer-destroy');

        $this->actingAs($viewer)
            ->deleteJson('/api/admin/connectors/999')
            ->assertStatus(403);
    }

    private function makeUserWithRole(string $role, string $prefix): User
    {
        $user = User::create([
            'name' => ucfirst($prefix),
            'email' => $prefix.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole($role);

        return $user;
    }
}
