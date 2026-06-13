<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Models\User;
use App\Services\Kb\AutoWiki\ConceptSynthesizer;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * v8.11/P3 — concept synthesis across the R44 surfaces: the
 * `kb:synthesize-concepts` Artisan command (PHP) and the admin HTTP endpoint,
 * both delegating to the shared {@see ConceptSynthesizer} (mocked here so the
 * thin adapters are tested in isolation; the service logic is covered by
 * ConceptSynthesizerTest, the MCP tool by KnowledgeBaseServerRegistrationTest).
 */
final class ConceptSynthesisTriSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');
    }

    private function bindSynthesizer(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(ConceptSynthesizer::class);
        $this->app->instance(ConceptSynthesizer::class, $mock);

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

    // ── PHP — Artisan command ──────────────────────────────────────────

    public function test_command_invokes_the_synthesizer_and_reports(): void
    {
        $mock = $this->bindSynthesizer();
        $mock->shouldReceive('synthesize')->once()
            ->with('default', 'docs-v3', 2)
            ->andReturn(['ran' => true, 'candidates' => 3, 'created' => ['auto-cache'], 'skipped' => []]);

        $this->artisan('kb:synthesize-concepts', ['project' => 'docs-v3', '--limit' => '2'])
            ->expectsOutputToContain('created 1 page(s)')
            ->assertSuccessful();
    }

    public function test_command_reports_disabled(): void
    {
        $mock = $this->bindSynthesizer();
        $mock->shouldReceive('synthesize')->once()->andReturn(['ran' => false, 'reason' => 'disabled']);

        $this->artisan('kb:synthesize-concepts', ['project' => 'docs-v3'])
            ->expectsOutputToContain('Did not run: disabled')
            ->assertSuccessful();
    }

    // ── HTTP — admin API ───────────────────────────────────────────────

    public function test_api_synthesize_returns_the_result(): void
    {
        $mock = $this->bindSynthesizer();
        $mock->shouldReceive('synthesize')->once()
            ->with('default', 'docs-v3', null)
            ->andReturn(['ran' => true, 'candidates' => 2, 'created' => ['auto-cache'], 'skipped' => []]);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/kb/concepts/synthesize', ['project_key' => 'docs-v3'])
            ->assertOk()
            ->assertJsonPath('data.ran', true)
            ->assertJsonPath('data.created.0', 'auto-cache');
    }

    public function test_api_validates_project_key(): void
    {
        $this->bindSynthesizer();

        $this->actingAs($this->admin())
            ->postJson('/api/admin/kb/concepts/synthesize', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('project_key');
    }

    public function test_api_viewer_is_forbidden(): void
    {
        $this->bindSynthesizer();

        $this->actingAs($this->viewer())
            ->postJson('/api/admin/kb/concepts/synthesize', ['project_key' => 'docs-v3'])
            ->assertForbidden();
    }
}
