<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\KbRetrievalProfile;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class KbRetrievalProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('test-tenant');
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@example.test',
            'password' => Hash::make('secret'),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function document(string $tenant, string $project): void
    {
        KnowledgeDocument::create([
            'tenant_id' => $tenant,
            'project_key' => $project,
            'source_type' => 'markdown',
            'title' => 'Manuale',
            'source_path' => "{$project}/manuale.md",
            'mime_type' => 'text/markdown',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => bin2hex(random_bytes(16)),
        ]);
    }

    public function test_admin_can_create_and_list_a_profile_for_a_real_project(): void
    {
        $this->document('test-tenant', 'vendite');
        $payload = [
            'project_key' => 'vendite',
            'company_context' => 'L’azienda vende arredamento. Un ordine è una pratica commerciale con cliente e stato.',
            'glossary' => [['term' => 'ordine', 'meaning' => 'Pratica commerciale', 'aliases' => ['pratica']]],
            'relevant_entities' => ['cliente', 'numero ordine'],
            'expected_facts' => ['stato ordine', 'data prevista'],
            'preferred_source_types' => ['markdown', 'email'],
        ];

        $this->actingAs($this->user('admin'))
            ->putJson('/api/admin/kb/retrieval-profiles', $payload)
            ->assertOk()
            ->assertJsonPath('profile.configured', true)
            ->assertJsonPath('profile.glossary.0.term', 'ordine')
            ->assertJsonPath('profile.preferred_source_types.1', 'email');

        $this->actingAs($this->user('admin'))
            ->getJson('/api/admin/kb/retrieval-profiles')
            ->assertOk()
            ->assertJsonPath('profiles.0.project_key', 'vendite')
            ->assertJsonPath('profiles.0.configured', true);

        $this->assertDatabaseHas('kb_retrieval_profiles', [
            'tenant_id' => 'test-tenant',
            'project_key' => 'vendite',
        ]);
    }

    public function test_profile_is_isolated_by_tenant_even_when_project_key_matches(): void
    {
        KbRetrievalProfile::create([
            'tenant_id' => 'other-tenant',
            'project_key' => 'vendite',
            'company_context' => 'Profilo dell’altra azienda che non deve essere visibile.',
        ]);

        $this->actingAs($this->user('admin'))
            ->getJson('/api/admin/kb/retrieval-profiles')
            ->assertOk()
            ->assertJsonPath('profiles', []);
    }

    public function test_viewer_cannot_manage_profiles_and_context_is_required(): void
    {
        $this->actingAs($this->user('viewer'))
            ->getJson('/api/admin/kb/retrieval-profiles')
            ->assertForbidden();

        $this->actingAs($this->user('admin'))
            ->putJson('/api/admin/kb/retrieval-profiles', ['project_key' => 'vendite'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_context');
    }
}
