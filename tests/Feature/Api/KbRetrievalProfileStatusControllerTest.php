<?php

namespace Tests\Feature\Api;

use App\Models\KbRetrievalProfile;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KbRetrievalProfileStatusControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kb.investigation.enabled' => true]);
    }

    private function reader(): User
    {
        app(TenantContext::class)->set('readiness');
        $user = User::create(['name' => 'Reader', 'email' => 'readiness@example.test', 'password' => 'password']);
        foreach (['configured', 'missing'] as $project) {
            ProjectMembership::create(['tenant_id' => 'readiness', 'project_key' => $project, 'user_id' => $user->id, 'role' => 'member']);
        }

        return $user;
    }

    public function test_readers_can_check_status_without_reading_profile_content_and_other_tenants_do_not_count(): void
    {
        $user = $this->reader();
        foreach (['readiness' => 'configured', 'other-tenant' => 'missing'] as $tenant => $project) {
            KbRetrievalProfile::create(['tenant_id' => $tenant, 'project_key' => $project, 'company_context' => 'Private business vocabulary.']);
        }

        $this->actingAs($user)->getJson('/api/kb/chat/retrieval-profile-status?project_keys[]=configured&project_keys[]=missing')
            ->assertOk()->assertExactJson([
                'required' => true,
                'profiles' => [['project_key' => 'configured', 'configured' => true], ['project_key' => 'missing', 'configured' => false]],
            ]);
    }

    public function test_status_rejects_inaccessible_projects_and_invalid_inputs(): void
    {
        $this->actingAs($this->reader())->getJson('/api/kb/chat/retrieval-profile-status?project_keys[]=inaccessible')->assertForbidden();
        $this->getJson('/api/kb/chat/retrieval-profile-status?project_keys[]=*')->assertUnprocessable();
        $this->getJson('/api/kb/chat/retrieval-profile-status')->assertUnprocessable();
    }

    public function test_explicit_retrieval_rollback_does_not_claim_profiles_are_required(): void
    {
        config(['kb.investigation.enabled' => false]);
        $this->actingAs($this->reader())->getJson('/api/kb/chat/retrieval-profile-status?project_keys[]=missing')
            ->assertOk()->assertJsonPath('required', false);
    }
}
