<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.11/P4 — Auto-Wiki indices across the R44 surfaces: the `kb:wiki-index`
 * Artisan command (PHP) and the admin HTTP endpoints (rebuild + hub + operation
 * log), over the shared {@see \App\Services\Kb\AutoWiki\WikiIndexBuilder}. (The
 * MCP tools are registration-tested in KnowledgeBaseServerRegistrationTest.)
 */
final class WikiIndexTriSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');
    }

    private function doc(): KnowledgeDocument
    {
        static $n = 0;
        $n++;

        return KnowledgeDocument::create([
            'tenant_id' => 'default', 'project_key' => 'docs-v3', 'source_type' => 'markdown',
            'title' => "Doc {$n}", 'source_path' => "docs/i-{$n}.md", 'mime_type' => 'text/markdown',
            'status' => 'active', 'document_hash' => str_repeat('a', 64), 'version_hash' => 'v'.$n,
            'is_canonical' => true, 'slug' => "dec-{$n}", 'canonical_type' => 'decision', 'generation_source' => 'human',
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

    public function test_command_rebuilds_indices(): void
    {
        $this->doc();

        $this->artisan('kb:wiki-index')
            ->expectsOutputToContain('Rebuilt 1 project')
            ->assertSuccessful();

        $this->assertDatabaseHas('kb_wiki_indices', ['project_key' => 'docs-v3', 'index_type' => 'project']);
        $this->assertDatabaseHas('kb_wiki_indices', ['project_key' => '*', 'index_type' => 'tenant_hub']);
    }

    // ── HTTP — admin API ───────────────────────────────────────────────

    public function test_api_rebuild_then_show_hub(): void
    {
        $this->doc();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/admin/kb/wiki-index', [])
            ->assertOk()
            ->assertJsonPath('data.hub_project_count', 1);

        $this->actingAs($admin)
            ->getJson('/api/admin/kb/wiki-index')
            ->assertOk()
            ->assertJsonPath('data.hub.index_type', 'tenant_hub')
            ->assertJsonPath('data.projects.0.project_key', 'docs-v3');
    }

    public function test_api_operations_log(): void
    {
        $this->doc();
        $admin = $this->admin();
        // Rebuild writes auto-wiki audit rows that the op-log surfaces.
        $this->actingAs($admin)->postJson('/api/admin/kb/wiki-index', [])->assertOk();

        $this->actingAs($admin)
            ->getJson('/api/admin/kb/wiki-operations')
            ->assertOk()
            ->assertJsonPath('data.0.event_type', 'graph_rebuild');
    }

    public function test_api_viewer_is_forbidden(): void
    {
        $this->actingAs($this->viewer())
            ->getJson('/api/admin/kb/wiki-index')
            ->assertForbidden();
    }
}
