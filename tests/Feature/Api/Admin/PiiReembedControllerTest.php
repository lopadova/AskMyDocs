<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Jobs\ReembedDocumentJob;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4, PR5) — HTTP re-embed endpoint (manageKbPiiPolicy gated).
 */
final class PiiReembedControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        $this->seed(RbacSeeder::class);
        Cache::flush();
    }

    private function user(string $role): User
    {
        $u = User::create(['name' => $role, 'email' => $role.'-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
        $u->assignRole($role);

        return $u;
    }

    private function doc(string $project = 'support'): void
    {
        KnowledgeDocument::create([
            'project_key' => $project, 'source_type' => 'markdown', 'title' => 'Doc',
            'source_path' => 'docs/'.uniqid().'.md', 'language' => 'en', 'access_scope' => 'internal',
            'status' => 'active', 'document_hash' => hash('sha256', uniqid()), 'version_hash' => hash('sha256', uniqid()),
        ]);
    }

    public function test_dpo_can_trigger_a_reembed_and_jobs_are_queued(): void
    {
        Queue::fake();
        $this->doc('support');
        $this->doc('support');

        $this->actingAs($this->user('dpo'))
            ->postJson('/api/admin/pii/reembed', ['project_key' => 'support'])
            ->assertOk()
            ->assertJsonPath('queued', 2)
            ->assertJsonPath('project_key', 'support');

        Queue::assertPushed(ReembedDocumentJob::class, 2);
    }

    public function test_admin_without_manage_gate_is_forbidden(): void
    {
        Queue::fake();
        $this->actingAs($this->user('admin'))
            ->postJson('/api/admin/pii/reembed', ['project_key' => 'support'])
            ->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_missing_project_key_is_rejected_with_422(): void
    {
        $this->actingAs($this->user('dpo'))
            ->postJson('/api/admin/pii/reembed', [])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('project_key');
    }

    public function test_whitespace_project_key_is_rejected_with_422(): void
    {
        $this->actingAs($this->user('dpo'))
            ->postJson('/api/admin/pii/reembed', ['project_key' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('project_key');
    }

    public function test_guest_is_rejected_with_401(): void
    {
        $this->postJson('/api/admin/pii/reembed', ['project_key' => 'support'])->assertUnauthorized();
    }
}
