<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\KbResolveWikilinkController;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class KbResolveWikilinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Register the route without auth middleware to isolate the
        // controller. Real wiring adds auth:sanctum (see routes/api.php).
        Route::get('/api/kb/resolve-wikilink', KbResolveWikilinkController::class)
            ->name('api.kb.resolve-wikilink');

        // Relax RBAC so tests that don't opt into memberships see all rows.
        config()->set('rbac.enforced', false);
    }

    public function test_returns_document_payload_when_slug_matches(): void
    {
        $doc = KnowledgeDocument::create([
            'project_key' => 'hr-portal',
            'source_type' => 'md',
            'title' => 'Remote Work Policy',
            'source_path' => 'policies/remote-work-policy.md',
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'project',
            'status' => 'indexed',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('a', 64),
            'metadata' => [],
            'slug' => 'remote-work-policy',
            'canonical_type' => 'policy',
            'canonical_status' => 'accepted',
            'is_canonical' => true,
        ]);

        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => 'hr-portal',
            'chunk_order' => 0,
            'chunk_hash' => str_repeat('b', 64),
            'heading_path' => 'Intro',
            'chunk_text' => 'ACME employees may work remotely up to 3 days per week with manager approval.',
            'metadata' => [],
        ]);

        $this->getJson('/api/kb/resolve-wikilink?project=hr-portal&slug=remote-work-policy')
            ->assertOk()
            ->assertJson([
                'document_id' => $doc->id,
                'title' => 'Remote Work Policy',
                'source_path' => 'policies/remote-work-policy.md',
                'canonical_type' => 'policy',
                'canonical_status' => 'accepted',
                'is_canonical' => true,
            ])
            ->assertJsonPath('preview', 'ACME employees may work remotely up to 3 days per week with manager approval.');
    }

    public function test_returns_404_when_slug_not_found(): void
    {
        $this->getJson('/api/kb/resolve-wikilink?project=hr-portal&slug=missing-policy')
            ->assertStatus(404)
            ->assertJson(['message' => 'Wikilink target not found.']);
    }

    public function test_validates_required_query_parameters(): void
    {
        $this->getJson('/api/kb/resolve-wikilink')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project', 'slug']);
    }

    public function test_slug_lookup_is_project_scoped(): void
    {
        // Same slug lives in two projects — lookup must honour `project`.
        $hr = KnowledgeDocument::create([
            'project_key' => 'hr-portal',
            'source_type' => 'md',
            'title' => 'HR Cache',
            'source_path' => 'hr/cache.md',
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'project',
            'status' => 'indexed',
            'document_hash' => str_repeat('c', 64),
            'version_hash' => str_repeat('c', 64),
            'metadata' => [],
            'slug' => 'cache-v2',
        ]);
        $eng = KnowledgeDocument::create([
            'project_key' => 'engineering',
            'source_type' => 'md',
            'title' => 'Eng Cache',
            'source_path' => 'eng/cache.md',
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'project',
            'status' => 'indexed',
            'document_hash' => str_repeat('d', 64),
            'version_hash' => str_repeat('d', 64),
            'metadata' => [],
            'slug' => 'cache-v2',
        ]);

        $this->getJson('/api/kb/resolve-wikilink?project=hr-portal&slug=cache-v2')
            ->assertOk()
            ->assertJson(['document_id' => $hr->id, 'title' => 'HR Cache']);

        $this->getJson('/api/kb/resolve-wikilink?project=engineering&slug=cache-v2')
            ->assertOk()
            ->assertJson(['document_id' => $eng->id, 'title' => 'Eng Cache']);
    }

    public function test_soft_deleted_documents_are_invisible(): void
    {
        $doc = KnowledgeDocument::create([
            'project_key' => 'hr-portal',
            'source_type' => 'md',
            'title' => 'Archived Policy',
            'source_path' => 'policies/archived.md',
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'project',
            'status' => 'indexed',
            'document_hash' => str_repeat('e', 64),
            'version_hash' => str_repeat('e', 64),
            'metadata' => [],
            'slug' => 'archived-policy',
        ]);
        $doc->delete();

        $this->getJson('/api/kb/resolve-wikilink?project=hr-portal&slug=archived-policy')
            ->assertStatus(404);
    }

    public function test_rbac_scope_hides_documents_outside_allowed_projects(): void
    {
        config()->set('rbac.enforced', true);

        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@test.local',
            'password' => bcrypt('secret'),
        ]);

        KnowledgeDocument::create([
            'project_key' => 'finance-ops',
            'source_type' => 'md',
            'title' => 'Finance policy',
            'source_path' => 'finance/only.md',
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'project',
            'status' => 'indexed',
            'document_hash' => str_repeat('f', 64),
            'version_hash' => str_repeat('f', 64),
            'metadata' => [],
            'slug' => 'only-finance',
        ]);

        // Viewer has no project memberships — AccessScopeScope blocks everything.
        $this->actingAs($viewer)
            ->getJson('/api/kb/resolve-wikilink?project=finance-ops&slug=only-finance')
            ->assertStatus(404);
    }

    public function test_preview_is_truncated_to_200_chars(): void
    {
        $doc = KnowledgeDocument::create([
            'project_key' => 'hr-portal',
            'source_type' => 'md',
            'title' => 'Long doc',
            'source_path' => 'policies/long.md',
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'project',
            'status' => 'indexed',
            'document_hash' => str_repeat('0', 64),
            'version_hash' => str_repeat('0', 64),
            'metadata' => [],
            'slug' => 'long-policy',
        ]);

        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => 'hr-portal',
            'chunk_order' => 0,
            'chunk_hash' => str_repeat('1', 64),
            'heading_path' => null,
            'chunk_text' => str_repeat('A', 300),
            'metadata' => [],
        ]);

        $response = $this->getJson('/api/kb/resolve-wikilink?project=hr-portal&slug=long-policy')
            ->assertOk();

        $preview = $response->json('preview');
        $this->assertSame(201, mb_strlen($preview), 'Preview should be 200 chars + the ellipsis');
        $this->assertStringEndsWith('…', $preview);
    }
}
