<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Kb\AutoWiki\WikiExplorerService;
use App\Support\Canonical\GenerationSource;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * v8.11/P10 — the Wiki Explorer across the R44 surfaces: the `kb:wiki-promote`
 * Artisan command (PHP) and the admin HTTP endpoints (list / promote / discard),
 * all delegating to the shared {@see WikiExplorerService} (mocked here so the
 * thin adapters are tested in isolation; the service logic is covered by
 * WikiExplorerServiceTest, the MCP tool by KnowledgeBaseServerRegistrationTest).
 */
final class WikiExplorerTriSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');
    }

    private function bind(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(WikiExplorerService::class);
        $this->app->instance(WikiExplorerService::class, $mock);

        return $mock;
    }

    private function autoDoc(): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'tenant_id' => 'default', 'project_key' => 'eng', 'source_type' => 'markdown',
            'source_path' => 'decisions/auto-a.md', 'title' => 'Auto A', 'mime_type' => 'text/markdown',
            'status' => 'active', 'document_hash' => str_repeat('a', 64), 'version_hash' => bin2hex(random_bytes(16)),
            'is_canonical' => true, 'doc_id' => 'auto-a', 'slug' => 'auto-a', 'canonical_type' => 'decision',
            'canonical_status' => 'review', 'generation_source' => GenerationSource::Auto->value,
        ]);
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

    public function test_command_promotes(): void
    {
        $doc = $this->autoDoc();
        $mock = $this->bind();
        $mock->shouldReceive('promote')->once()
            ->andReturn(['promoted' => true, 'slug' => 'auto-a']);

        $this->artisan('kb:wiki-promote', ['document' => $doc->id])
            ->expectsOutputToContain('Promoted auto-a')
            ->assertSuccessful();
    }

    public function test_command_discards_with_flag(): void
    {
        $doc = $this->autoDoc();
        $mock = $this->bind();
        $mock->shouldReceive('discard')->once()
            ->andReturn(['discarded' => true, 'slug' => 'auto-a']);

        $this->artisan('kb:wiki-promote', ['document' => $doc->id, '--discard' => true])
            ->expectsOutputToContain('Discarded auto page auto-a')
            ->assertSuccessful();
    }

    public function test_command_reports_missing_document(): void
    {
        $this->bind();

        $this->artisan('kb:wiki-promote', ['document' => 999999])
            ->expectsOutputToContain('Document not found')
            ->assertFailed();
    }

    // ── HTTP — admin API ───────────────────────────────────────────────

    public function test_api_list_returns_pages(): void
    {
        $mock = $this->bind();
        $mock->shouldReceive('list')->once()
            ->with('default', 'eng', 'auto', 100)
            ->andReturn(['tier' => 'auto', 'project_key' => 'eng', 'total' => 1, 'pages' => [['slug' => 'auto-a']]]);

        $this->actingAs($this->admin())
            ->getJson('/api/admin/kb/wiki-pages?project_key=eng&tier=auto')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.pages.0.slug', 'auto-a');
    }

    public function test_api_list_rejects_bad_tier(): void
    {
        $this->bind();

        $this->actingAs($this->admin())
            ->getJson('/api/admin/kb/wiki-pages?tier=bogus')
            ->assertStatus(422)
            ->assertJsonValidationErrors('tier');
    }

    public function test_api_promote_returns_result(): void
    {
        $doc = $this->autoDoc();
        $mock = $this->bind();
        $mock->shouldReceive('promote')->once()
            ->andReturn(['promoted' => true, 'slug' => 'auto-a']);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/kb/documents/{$doc->id}/wiki-promote")
            ->assertOk()
            ->assertJsonPath('data.promoted', true);
    }

    public function test_api_discard_returns_result(): void
    {
        $doc = $this->autoDoc();
        $mock = $this->bind();
        $mock->shouldReceive('discard')->once()
            ->andReturn(['discarded' => true, 'slug' => 'auto-a']);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/kb/documents/{$doc->id}/wiki-discard")
            ->assertOk()
            ->assertJsonPath('data.discarded', true);
    }

    public function test_api_promote_404_for_unknown_doc(): void
    {
        $this->bind();

        $this->actingAs($this->admin())
            ->postJson('/api/admin/kb/documents/999999/wiki-promote')
            ->assertNotFound();
    }

    public function test_api_viewer_is_forbidden(): void
    {
        $doc = $this->autoDoc();
        $this->bind();

        $this->actingAs($this->viewer())
            ->postJson("/api/admin/kb/documents/{$doc->id}/wiki-promote")
            ->assertForbidden();
    }
}
