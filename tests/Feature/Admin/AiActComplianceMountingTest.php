<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AiActComplianceMountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_route_is_registered_with_auth_and_gate_middleware(): void
    {
        $route = Route::getRoutes()->getByName('ai-act-compliance.spa');

        $this->assertNotNull($route);
        $this->assertSame('admin/ai-act-compliance/{any?}', $route->uri());

        $middleware = $route->gatherMiddleware();

        $this->assertContains(
            'can:viewAiActCompliance',
            $middleware,
            'Expected compliance mount route to be gated by can:viewAiActCompliance.',
        );
        $this->assertTrue(
            in_array('auth', $middleware, true)
                || in_array(\Illuminate\Auth\Middleware\Authenticate::class, $middleware, true),
            'Expected compliance mount route to be protected by auth middleware.',
        );
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/ai-act-compliance')->assertRedirect('/login');
    }

    public function test_admin_is_redirected_into_host_spa_placeholder(): void
    {
        $this->actingAs($this->makeUser('admin'));

        $this->get('/admin/ai-act-compliance')->assertRedirect('/app/admin/ai-act-compliance');
    }

    public function test_nested_path_redirect_preserves_suffix(): void
    {
        $this->actingAs($this->makeUser('super-admin'));

        $this->get('/admin/ai-act-compliance/reports/pending')
            ->assertRedirect('/app/admin/ai-act-compliance/reports/pending');
    }

    public function test_viewer_is_denied(): void
    {
        $this->actingAs($this->makeUser('viewer'));

        $this->get('/admin/ai-act-compliance')->assertForbidden();
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
