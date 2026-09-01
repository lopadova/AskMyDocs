<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R32 for the cockpit, closing the last step of the chain.
 *
 * Three tests already cover most of it, and each proves something different:
 *
 *  - FlowAdminMountingTest asserts every package route CARRIES
 *    `can:viewFlowAdmin`, so a route that forgets the gate fails there. That
 *    is stronger than a single representative entry in an authorization
 *    matrix, because it covers the whole group rather than one endpoint.
 *  - FlowAdminGatesTest asserts the gate ADMITS the right roles.
 *  - FlowAdminDisabledTest covers the off state.
 *
 * What none of them does is drive a real request per role and check what
 * comes back. Middleware attached plus gate defined is a strong inference,
 * not an observation — and R32 exists because inferences of exactly that
 * shape have shipped green while the surface was open.
 *
 * The flag is flipped in getEnvironmentSetUp rather than in a test body
 * because the routes are registered at boot from that config. Setting it
 * afterwards leaves the cockpit unmounted, and every request then falls
 * through to the SPA catch-all — which answers plausibly enough that the
 * assertions still pass while proving nothing about the cockpit.
 */
final class FlowAdminRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private const COCKPIT_URL = '/admin/flows';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('flow-admin.enabled', true);
        $app['config']->set('flow-admin.prefix', 'admin/flows');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedRoles(): iterable
    {
        yield 'super-admin' => ['super-admin'];
        yield 'admin' => ['admin'];
        yield 'dpo' => ['dpo'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deniedRoles(): iterable
    {
        yield 'editor' => ['editor'];
        yield 'viewer' => ['viewer'];
    }

    #[DataProvider('allowedRoles')]
    public function test_an_allowed_role_reaches_the_cockpit(string $role): void
    {
        $response = $this->actingAs($this->makeUser($role))->get(self::COCKPIT_URL);

        $this->assertSame(200, $response->getStatusCode(), sprintf(
            'Role [%s] should reach the cockpit, got %d.',
            $role,
            $response->getStatusCode(),
        ));
    }

    #[DataProvider('deniedRoles')]
    public function test_every_other_role_is_refused_by_the_route(string $role): void
    {
        $this->actingAs($this->makeUser($role))
            ->get(self::COCKPIT_URL)
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login_rather_than_served(): void
    {
        // A web route redirects; it does not answer 401 the way the /api
        // surface does. Asserting merely "not 200" would also pass on a 500.
        $this->get(self::COCKPIT_URL)->assertRedirect();
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name' => "Test {$role}",
            'email' => $role.'-'.uniqid().'@example.test',
            'password' => Hash::make('secret-password'),
        ]);

        $user->assignRole($role);

        return $user->fresh();
    }
}
