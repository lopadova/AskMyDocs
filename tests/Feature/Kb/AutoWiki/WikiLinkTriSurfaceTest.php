<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Models\KbEdge;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.11/P2 — the auto-wiki graph-canonicalization capability across the R44
 * surfaces: the `kb:wiki-link` Artisan command (PHP) and the admin HTTP
 * endpoint, both over the shared {@see \App\Services\Kb\AutoWiki\AutoWikiGraphLinker}.
 * (The MCP tool is registration-tested in KnowledgeBaseServerRegistrationTest;
 * its project-scoped doc_id resolution mirrors the evidence-tier tool's.)
 */
final class WikiLinkTriSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');
    }

    /** @param array<string,mixed> $overrides */
    private function doc(array $overrides = []): KnowledgeDocument
    {
        static $n = 0;
        $n++;

        $doc = KnowledgeDocument::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'source_type' => 'markdown',
            'title' => "Doc {$n}",
            'source_path' => "docs/w-{$n}.md",
            'mime_type' => 'text/markdown',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => 'ver'.$n,
            'is_canonical' => false,
            'frontmatter_json' => ['_autowiki' => ['cross_references' => [
                ['slug' => 'neighbour', 'edge_type' => 'related_to'],
            ]]],
        ], $overrides));

        return $doc;
    }

    private function admin(): User
    {
        $u = User::create(['name' => 'Adm', 'email' => 'adm-'.uniqid().'@t.local', 'password' => Hash::make('x')]);
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

    public function test_command_links_a_document(): void
    {
        $doc = $this->doc(['title' => 'Linkable Doc']);

        $this->artisan('kb:wiki-link', ['document' => $doc->id])
            ->expectsOutputToContain('Linked auto-linkable-doc')
            ->assertSuccessful();

        $this->assertDatabaseHas('kb_edges', ['from_node_uid' => 'auto-linkable-doc', 'to_node_uid' => 'neighbour', 'provenance' => 'inferred']);
    }

    public function test_command_fails_for_missing_doc(): void
    {
        $this->artisan('kb:wiki-link', ['document' => 999999])->assertFailed();
    }

    // ── HTTP — admin API ───────────────────────────────────────────────

    public function test_api_rebuild_links_a_document(): void
    {
        $doc = $this->doc(['title' => 'Api Doc']);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/kb/documents/{$doc->id}/wiki-link")
            ->assertOk()
            ->assertJsonPath('data.linked', true)
            ->assertJsonPath('data.slug', 'auto-api-doc');

        $this->assertSame(1, KbEdge::query()->where('from_node_uid', 'auto-api-doc')->where('provenance', 'inferred')->count());
    }

    public function test_api_rebuild_404_for_missing_doc(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/kb/documents/999999/wiki-link')
            ->assertNotFound();
    }

    public function test_api_viewer_is_forbidden(): void
    {
        $doc = $this->doc();

        $this->actingAs($this->viewer())
            ->postJson("/api/admin/kb/documents/{$doc->id}/wiki-link")
            ->assertForbidden();
    }
}
