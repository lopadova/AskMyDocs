<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\KbDeleteController;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KbDeleteControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum isn't loaded in the test harness; mirror KbIngestControllerTest
        // by registering the route directly so we exercise controller logic.
        Route::delete('/api/kb/documents', KbDeleteController::class)->name('api.kb.delete');

        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('kb.ingest.default_project', 'default');
        Storage::fake('kb');
    }

    private function seedDoc(string $projectKey, string $sourcePath): KnowledgeDocument
    {
        $document = KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => 'X',
            'source_path' => $sourcePath,
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', $projectKey.$sourcePath),
            'version_hash' => hash('sha256', $projectKey.$sourcePath),
            'metadata' => ['disk' => 'kb', 'prefix' => ''],
            'indexed_at' => now(),
        ]);

        KnowledgeChunk::create([
            'knowledge_document_id' => $document->id,
            'project_key' => $document->project_key,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'c'.$document->id),
            'chunk_text' => 'x',
            'metadata' => [],
            'embedding' => [0.1],
        ]);

        return $document;
    }

    public function test_rejects_empty_payload(): void
    {
        $this->deleteJson('/api/kb/documents', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents']);
    }

    public function test_soft_deletes_by_default(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        Storage::disk('kb')->put('docs/a.md', 'hi');
        $doc = $this->seedDoc('demo', 'docs/a.md');

        $response = $this->deleteJson('/api/kb/documents', [
            'documents' => [
                ['project_key' => 'demo', 'source_path' => 'docs/a.md'],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'deleted' => 1,
                'missing' => 0,
                'documents' => [
                    [
                        'project_key' => 'demo',
                        'source_path' => 'docs/a.md',
                        'mode' => 'soft',
                        'status' => 'deleted',
                    ],
                ],
            ]);

        $this->assertNull(KnowledgeDocument::find($doc->id));
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($doc->id));
        Storage::disk('kb')->assertExists('docs/a.md');
    }

    public function test_force_flag_hard_deletes_regardless_of_config(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        Storage::disk('kb')->put('docs/a.md', 'hi');
        $doc = $this->seedDoc('demo', 'docs/a.md');

        $this->deleteJson('/api/kb/documents', [
            'force' => true,
            'documents' => [
                ['project_key' => 'demo', 'source_path' => 'docs/a.md'],
            ],
        ])
            ->assertStatus(200)
            ->assertJson([
                'deleted' => 1,
                'documents' => [
                    ['mode' => 'hard', 'file_deleted' => true],
                ],
            ]);

        $this->assertNull(KnowledgeDocument::withTrashed()->find($doc->id));
        Storage::disk('kb')->assertMissing('docs/a.md');
    }

    public function test_reports_missing_documents_without_failing(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        $present = $this->seedDoc('demo', 'docs/present.md');

        $this->deleteJson('/api/kb/documents', [
            'documents' => [
                ['project_key' => 'demo', 'source_path' => 'docs/present.md'],
                ['project_key' => 'demo', 'source_path' => 'docs/ghost.md'],
            ],
        ])
            ->assertStatus(200)
            ->assertJson([
                'deleted' => 1,
                'missing' => 1,
            ])
            ->assertJsonFragment(['status' => 'not_found', 'source_path' => 'docs/ghost.md']);

        $this->assertNull(KnowledgeDocument::find($present->id));
    }

    public function test_rejects_traversal_segments(): void
    {
        $this->deleteJson('/api/kb/documents', [
            'documents' => [
                ['project_key' => 'demo', 'source_path' => '../etc/passwd'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents']);
    }

    public function test_uses_default_project_when_omitted(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        config()->set('kb.ingest.default_project', 'fallback');

        $doc = $this->seedDoc('fallback', 'docs/a.md');

        $this->deleteJson('/api/kb/documents', [
            'documents' => [
                ['source_path' => 'docs/a.md'],
            ],
        ])
            ->assertStatus(200)
            ->assertJson(['deleted' => 1]);

        $this->assertNull(KnowledgeDocument::find($doc->id));
    }

    public function test_enforces_max_batch_of_100(): void
    {
        $docs = [];
        for ($i = 0; $i < 101; $i++) {
            $docs[] = ['project_key' => 'demo', 'source_path' => "f{$i}.md"];
        }

        $this->deleteJson('/api/kb/documents', ['documents' => $docs])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents']);
    }
}
