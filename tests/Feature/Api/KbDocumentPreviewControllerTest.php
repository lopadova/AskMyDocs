<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\KbDocumentPreviewController;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Chat "open source" modal endpoint — full source text of a cited document for
 * any authenticated reader, scoped to the caller's tenant + AccessScope.
 *
 * Mirrors KbResolveWikilinkTest: the route is registered without auth here to
 * isolate the controller (real wiring adds auth:sanctum + tenant.authorize).
 */
final class KbDocumentPreviewControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/kb/documents/{document}/preview', KbDocumentPreviewController::class)
            ->whereNumber('document')
            ->name('api.kb.documents.preview');

        // Relax RBAC so tests that don't opt into memberships see all rows;
        // the isolation tests below enable it explicitly.
        config()->set('rbac.enforced', false);
    }

    public function test_returns_full_content_reconstructed_from_chunks_in_order(): void
    {
        $doc = $this->seedDoc('hr-portal', 'Remote Work Policy', 'policies/remote.md');

        // Insert out of order to prove the controller sorts by chunk_order.
        $this->seedChunk($doc, 1, 'Second: approval is required.');
        $this->seedChunk($doc, 0, 'First: up to 3 days per week.');

        $this->getJson("/api/kb/documents/{$doc->id}/preview")
            ->assertOk()
            ->assertJson([
                'document_id' => $doc->id,
                'title' => 'Remote Work Policy',
                'source_path' => 'policies/remote.md',
                'project_key' => 'hr-portal',
                'is_canonical' => true,
            ])
            ->assertJsonPath('content', "First: up to 3 days per week.\n\nSecond: approval is required.");
    }

    public function test_returns_404_for_missing_document(): void
    {
        $this->getJson('/api/kb/documents/999999/preview')
            ->assertStatus(404)
            ->assertJson(['message' => 'Document not found.']);
    }

    public function test_returns_200_with_empty_content_when_document_has_no_chunks(): void
    {
        // A document that legitimately exists but carries no chunks is a 200
        // with an empty body (FE renders an empty state) — NOT a fake 404 (R14).
        $doc = $this->seedDoc('hr-portal', 'Empty doc', 'policies/empty.md');

        $this->getJson("/api/kb/documents/{$doc->id}/preview")
            ->assertOk()
            ->assertJsonPath('content', '');
    }

    public function test_soft_deleted_document_is_invisible(): void
    {
        $doc = $this->seedDoc('hr-portal', 'Archived', 'policies/archived.md');
        $doc->delete();

        $this->getJson("/api/kb/documents/{$doc->id}/preview")
            ->assertStatus(404);
    }

    public function test_rbac_scope_hides_documents_outside_allowed_projects(): void
    {
        config()->set('rbac.enforced', true);

        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer-preview@test.local',
            'password' => bcrypt('secret'),
        ]);

        // Viewer has no project memberships — AccessScopeScope blocks the read.
        $doc = $this->seedDoc('finance-ops', 'Finance only', 'finance/only.md');

        $this->actingAs($viewer)
            ->getJson("/api/kb/documents/{$doc->id}/preview")
            ->assertStatus(404);
    }

    public function test_cross_tenant_document_is_invisible(): void
    {
        // R30 — a document in another tenant must never be reachable, even by
        // its numeric id. The active tenant in tests is 'default'.
        $foreign = KnowledgeDocument::create([
            'tenant_id' => 'other-tenant',
            'project_key' => 'hr-portal',
            'source_type' => 'markdown',
            'title' => 'Foreign tenant doc',
            'source_path' => 'policies/foreign.md',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('9', 64),
            'version_hash' => str_repeat('9', 64),
        ]);

        $this->assertSame('default', app(TenantContext::class)->current());

        $this->getJson("/api/kb/documents/{$foreign->id}/preview")
            ->assertStatus(404);
    }

    private function seedDoc(string $projectKey, string $title, string $sourcePath): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => $title,
            'source_path' => $sourcePath,
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', $sourcePath),
            'version_hash' => hash('sha256', $sourcePath . 'v'),
            'slug' => str_replace(['/', '.md'], ['-', ''], $sourcePath),
            'canonical_type' => 'standard',
            'canonical_status' => 'accepted',
            'is_canonical' => true,
        ]);
    }

    private function seedChunk(KnowledgeDocument $doc, int $order, string $text): void
    {
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => $order,
            'chunk_hash' => hash('sha256', $text . $order),
            'heading_path' => null,
            'chunk_text' => $text,
            'metadata' => [],
        ]);
    }
}
