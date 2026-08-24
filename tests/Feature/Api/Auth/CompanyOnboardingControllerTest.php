<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Auth;

use App\Models\ProjectMembership;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CompanyOnboardingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_guest_cannot_complete_company_onboarding(): void
    {
        $this->postJson('/api/auth/onboarding/company', [
            'company_name' => 'Acme',
        ])->assertUnauthorized();
    }

    public function test_user_without_tenants_atomically_creates_company_and_becomes_super_admin(): void
    {
        $user = $this->user('founder@example.com');

        $response = $this->actingAsWithoutTenant($user)
            ->postJson('/api/auth/onboarding/company', [
                'company_name' => 'Acme S.r.l.',
                'tenant_slug' => 'acme',
                'project_key' => 'acme-kb',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.tenant.slug', 'acme')
            ->assertJsonPath('data.project.project_key', 'acme-kb')
            ->assertJsonPath('data.project.membership_role', 'owner')
            ->assertJsonPath('data.onboarding_required', false);

        $this->assertDatabaseHas('tenants', [
            'slug' => 'acme',
            'name' => 'Acme S.r.l.',
            'is_system' => false,
        ]);
        $this->assertDatabaseHas('projects', [
            'tenant_id' => 'acme',
            'project_key' => 'acme-kb',
        ]);
        $this->assertDatabaseHas('project_memberships', [
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'acme-kb',
            'role' => 'owner',
        ]);
        $this->assertDatabaseHas('admin_command_audit', [
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'command' => 'auth:onboarding-company',
            'status' => 'completed',
        ]);
        $this->assertTrue($user->fresh()->hasRole('super-admin'));

        $this->actingAsWithoutTenant($user->fresh())
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('onboarding.required', false)
            ->assertJsonPath('teams.0.tenant_id', 'acme');
    }

    public function test_second_completion_is_rejected_without_creating_another_tenant(): void
    {
        $user = $this->user('founder@example.com');

        $this->actingAsWithoutTenant($user)
            ->postJson('/api/auth/onboarding/company', [
                'company_name' => 'Acme',
                'tenant_slug' => 'acme',
            ])
            ->assertCreated();

        $this->actingAsWithoutTenant($user->fresh())
            ->postJson('/api/auth/onboarding/company', [
                'company_name' => 'Globex',
                'tenant_slug' => 'globex',
            ])
            ->assertConflict()
            ->assertJsonPath('error', 'onboarding_not_required');

        $this->assertDatabaseMissing('tenants', ['slug' => 'globex']);
    }

    public function test_system_admin_without_memberships_uses_control_plane_not_onboarding(): void
    {
        $user = $this->user('system@example.com');
        $user->assignRole(['system-admin', 'super-admin']);

        $this->actingAsWithoutTenant($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('onboarding.required', false)
            ->assertJsonPath('features.system_admin', true);

        $this->actingAsWithoutTenant($user)
            ->postJson('/api/auth/onboarding/company', [
                'company_name' => 'Must Not Exist',
            ])
            ->assertConflict()
            ->assertJsonPath('error', 'onboarding_not_required');
    }

    public function test_reserved_default_slug_is_rejected(): void
    {
        $user = $this->user('founder@example.com');

        $this->actingAsWithoutTenant($user)
            ->postJson('/api/auth/onboarding/company', [
                'company_name' => 'Legacy',
                'tenant_slug' => 'default',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);

        $this->assertDatabaseMissing('tenants', ['slug' => 'default']);
    }

    public function test_existing_operational_membership_disables_onboarding(): void
    {
        $user = $this->user('member@example.com');
        ProjectMembership::create([
            'tenant_id' => 'acme',
            'user_id' => $user->id,
            'project_key' => 'acme-kb',
            'role' => 'member',
        ]);

        $this->actingAsWithoutTenant($user)
            ->postJson('/api/auth/onboarding/company', [
                'company_name' => 'Another',
            ])
            ->assertConflict();
    }

    public function test_stale_default_membership_does_not_suppress_onboarding(): void
    {
        $user = $this->user('legacy-member@example.com');
        ProjectMembership::create([
            'tenant_id' => 'default',
            'user_id' => $user->id,
            'project_key' => 'legacy-project',
            'role' => 'member',
        ]);

        $this->actingAsWithoutTenant($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('onboarding.required', true)
            ->assertJsonCount(0, 'teams');
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => $email,
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
    }
}
