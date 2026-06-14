<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Models\User;
use App\Services\Kb\AutoWiki\AutoWikiReviewer;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * v8.11/P7 — cross-model review across the R44 surfaces: the `kb:wiki-review`
 * command (PHP) and the admin HTTP endpoint, over the shared
 * {@see AutoWikiReviewer} (mocked so the thin adapters are tested in isolation;
 * the service logic is covered by AutoWikiReviewerTest, the MCP tool by
 * KnowledgeBaseServerRegistrationTest).
 */
final class WikiReviewTriSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');
    }

    private function doc(): \App\Models\KnowledgeDocument
    {
        static $n = 0;
        $n++;

        return \App\Models\KnowledgeDocument::create([
            'tenant_id' => 'default', 'project_key' => 'docs-v3', 'source_type' => 'markdown',
            'title' => "Doc {$n}", 'source_path' => "docs/rv-{$n}.md", 'mime_type' => 'text/markdown',
            'status' => 'active', 'document_hash' => str_repeat('a', 64), 'version_hash' => 'v'.$n,
            'is_canonical' => false, 'slug' => "doc-{$n}", 'generation_source' => 'auto',
        ]);
    }

    private function bindReviewer(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(AutoWikiReviewer::class);
        $this->app->instance(AutoWikiReviewer::class, $mock);

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

    public function test_command_reviews(): void
    {
        $doc = $this->doc();
        $mock = $this->bindReviewer();
        $mock->shouldReceive('review')->once()->andReturn([
            'reviewed' => true, 'verdict' => 'approved', 'grounded' => true,
            'cross_refs_valid' => true, 'novelty' => 'novel', 'contradictions' => [],
        ]);

        $this->artisan('kb:wiki-review', ['document' => $doc->id])
            ->expectsOutputToContain('Verdict: approved')
            ->assertSuccessful();
    }

    public function test_command_fails_for_missing_doc(): void
    {
        $this->bindReviewer();
        $this->artisan('kb:wiki-review', ['document' => 999999])->assertFailed();
    }

    public function test_api_review(): void
    {
        $doc = $this->doc();
        $mock = $this->bindReviewer();
        $mock->shouldReceive('review')->once()->andReturn(['reviewed' => true, 'verdict' => 'flagged']);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/kb/documents/{$doc->id}/wiki-review")
            ->assertOk()
            ->assertJsonPath('data.verdict', 'flagged');
    }

    public function test_api_404_for_missing_doc(): void
    {
        $this->bindReviewer();
        $this->actingAs($this->admin())
            ->postJson('/api/admin/kb/documents/999999/wiki-review')
            ->assertNotFound();
    }

    public function test_api_viewer_is_forbidden(): void
    {
        $doc = $this->doc();
        $this->bindReviewer();
        $this->actingAs($this->viewer())
            ->postJson("/api/admin/kb/documents/{$doc->id}/wiki-review")
            ->assertForbidden();
    }
}
