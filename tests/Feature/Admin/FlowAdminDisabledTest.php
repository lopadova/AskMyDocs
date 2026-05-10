<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v4.2/W4 sub-PR 6 — disabled-by-default contract for the Flow Admin
 * cockpit.
 *
 * Sibling to FlowAdminMountingTest (which flips the SPA on via
 * getEnvironmentSetUp()). This class deliberately leaves the default
 * config (`flow-admin.enabled=false`) so we get evidence that the
 * fail-closed posture is wired correctly: the route exists in
 * Route::getRoutes() (the package SP always registers it) BUT the
 * `flow-admin.enabled` middleware aborts every request with 404.
 *
 * R14 — a disabled subsystem returns the correct semantic (404) and
 * never silently 200s with empty content. From the outside the
 * cockpit is indistinguishable from a route that never existed.
 */
class FlowAdminDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_master_switch_default_is_off(): void
    {
        $this->assertFalse(
            (bool) config('flow-admin.enabled'),
            'Default config must keep flow-admin.enabled=false; '.
            'flipping it on for the test config invalidates the disabled-fence assertions.',
        );
    }

    public function test_disabled_cockpit_returns_404_for_super_admin(): void
    {
        // Even a super-admin gets 404 — the master switch fires
        // before any role check. This proves the env flag is a
        // real fence, not a UX hint.
        $this->actingAs($this->makeSuperAdmin());

        $response = $this->get('/'.config('flow-admin.prefix', 'admin/flows'));

        $response->assertStatus(404);
    }

    public function test_disabled_cockpit_redirects_anonymous_to_login(): void
    {
        // Middleware order is `web,auth,flow-admin.enabled,
        // can:viewFlowAdmin`. The `auth` middleware fires before the
        // master switch and short-circuits anonymous requests with the
        // standard 302 -> /login redirect — same as every other
        // authenticated route in the app. From the operator's
        // perspective the cockpit is still invisible because the
        // login screen carries no breadcrumb that hints at what's
        // behind it.
        $response = $this->get('/'.config('flow-admin.prefix', 'admin/flows'));

        $response->assertStatus(302);
    }

    private function makeSuperAdmin(): User
    {
        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'super-'.uniqid().'@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        $user->assignRole('super-admin');

        return $user->fresh();
    }
}
