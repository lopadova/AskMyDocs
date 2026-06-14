<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Analysis;

use App\Models\KbDocAnalysis;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Kb\Analysis\SuggestionApplier;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * v8.11/P8 — apply engine across the R44 surfaces: the `kb:apply-suggestion`
 * command (PHP) and the admin HTTP endpoint, over the shared
 * {@see SuggestionApplier} (mocked so the thin adapters are tested in isolation;
 * the service logic is covered by SuggestionApplierTest, the MCP tool by
 * KnowledgeBaseServerRegistrationTest).
 */
final class SuggestionApplyTriSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');
    }

    private function analysis(): KbDocAnalysis
    {
        $doc = KnowledgeDocument::create([
            'tenant_id' => 'default', 'project_key' => 'docs-v3', 'source_type' => 'markdown',
            'title' => 'Src', 'source_path' => 'docs/src.md', 'mime_type' => 'text/markdown',
            'status' => 'active', 'document_hash' => str_repeat('a', 64), 'version_hash' => 'v1',
            'is_canonical' => true, 'slug' => 'src', 'generation_source' => 'auto',
        ]);

        return KbDocAnalysis::create([
            'tenant_id' => 'default', 'project_key' => 'docs-v3', 'knowledge_document_id' => $doc->id,
            'doc_slug' => 'src', 'trigger' => 'modified',
            'analysis_json' => ['cross_references' => [['slug' => 'n']], 'impacted_docs' => []],
            'suggestion_count' => 0, 'impacted_count' => 0, 'status' => 'completed',
        ]);
    }

    private function bindApplier(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(SuggestionApplier::class);
        $this->app->instance(SuggestionApplier::class, $mock);

        return $mock;
    }

    private function admin(): User
    {
        $u = User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => Hash::make('x')]);
        $u->assignRole('admin');

        return $u;
    }

    private function viewer(): User
    {
        $u = User::create(['name' => 'V', 'email' => 'v-'.uniqid().'@t.local', 'password' => Hash::make('x')]);
        $u->assignRole('viewer');

        return $u;
    }

    public function test_command_applies(): void
    {
        $analysis = $this->analysis();
        $mock = $this->bindApplier();
        $mock->shouldReceive('apply')->once()
            ->with(Mockery::type(KbDocAnalysis::class), 'cross_reference', 'n', 'cli')
            ->andReturn(['applied' => true, 'action' => 'add_cross_reference', 'target' => 'n']);

        $this->artisan('kb:apply-suggestion', ['analysis' => $analysis->id, 'type' => 'cross_reference', 'target' => 'n'])
            ->expectsOutputToContain('Applied add_cross_reference on n')
            ->assertSuccessful();
    }

    public function test_command_fails_for_missing_analysis(): void
    {
        $this->bindApplier();
        $this->artisan('kb:apply-suggestion', ['analysis' => 999999, 'type' => 'cross_reference', 'target' => 'n'])
            ->assertFailed();
    }

    public function test_api_apply(): void
    {
        $analysis = $this->analysis();
        $mock = $this->bindApplier();
        $mock->shouldReceive('apply')->once()->andReturn(['applied' => true, 'action' => 'add_cross_reference', 'target' => 'n']);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/kb/analyses/{$analysis->id}/apply", ['type' => 'cross_reference', 'target' => 'n'])
            ->assertOk()
            ->assertJsonPath('data.applied', true);
    }

    public function test_api_validates_type(): void
    {
        $analysis = $this->analysis();
        $this->bindApplier();
        $this->actingAs($this->admin())
            ->postJson("/api/admin/kb/analyses/{$analysis->id}/apply", ['type' => 'bogus', 'target' => 'n'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_api_404_for_missing_analysis(): void
    {
        $this->bindApplier();
        $this->actingAs($this->admin())
            ->postJson('/api/admin/kb/analyses/999999/apply', ['type' => 'cross_reference', 'target' => 'n'])
            ->assertNotFound();
    }

    public function test_api_viewer_is_forbidden(): void
    {
        $analysis = $this->analysis();
        $this->bindApplier();
        $this->actingAs($this->viewer())
            ->postJson("/api/admin/kb/analyses/{$analysis->id}/apply", ['type' => 'cross_reference', 'target' => 'n'])
            ->assertForbidden();
    }
}
