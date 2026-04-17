<?php

namespace Tests\Feature\Kb;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentDeleterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        Storage::fake('kb');
    }

    private function makeDocument(array $overrides = []): KnowledgeDocument
    {
        $document = KnowledgeDocument::create(array_merge([
            'project_key' => 'demo',
            'source_type' => 'markdown',
            'title' => 'Sample',
            'source_path' => 'docs/sample.md',
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', 'sample'),
            'version_hash' => hash('sha256', 'sample'),
            'metadata' => ['disk' => 'kb', 'prefix' => ''],
            'indexed_at' => now(),
        ], $overrides));

        KnowledgeChunk::create([
            'knowledge_document_id' => $document->id,
            'project_key' => $document->project_key,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'chunk-'.$document->id),
            'heading_path' => null,
            'chunk_text' => 'body',
            'metadata' => [],
            'embedding' => [0.1, 0.2, 0.3],
        ]);

        return $document;
    }

    public function test_soft_delete_marks_deleted_at_and_keeps_file_and_chunks(): void
    {
        config()->set('kb.deletion.soft_delete', true);

        Storage::disk('kb')->put('docs/sample.md', '# hi');
        $document = $this->makeDocument();

        $deleter = new DocumentDeleter;
        $result = $deleter->delete($document);

        $this->assertSame('soft', $result['mode']);
        $this->assertFalse($result['file_deleted']);
        Storage::disk('kb')->assertExists('docs/sample.md');

        $this->assertNull(KnowledgeDocument::find($document->id));
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($document->id));
        $this->assertSame(1, KnowledgeChunk::where('knowledge_document_id', $document->id)->count());
    }

    public function test_force_option_overrides_soft_delete_config(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        Storage::disk('kb')->put('docs/sample.md', '# hi');
        $document = $this->makeDocument();

        $result = (new DocumentDeleter)->delete($document, force: true);

        $this->assertSame('hard', $result['mode']);
        $this->assertTrue($result['file_deleted']);
        Storage::disk('kb')->assertMissing('docs/sample.md');
        $this->assertNull(KnowledgeDocument::withTrashed()->find($document->id));
        $this->assertSame(0, KnowledgeChunk::where('knowledge_document_id', $document->id)->count());
    }

    public function test_hard_delete_via_config_default(): void
    {
        config()->set('kb.deletion.soft_delete', false);
        Storage::disk('kb')->put('docs/sample.md', '# hi');
        $document = $this->makeDocument();

        $result = (new DocumentDeleter)->delete($document);

        $this->assertSame('hard', $result['mode']);
        Storage::disk('kb')->assertMissing('docs/sample.md');
        $this->assertNull(KnowledgeDocument::withTrashed()->find($document->id));
    }

    public function test_hard_delete_returns_file_deleted_false_when_file_missing(): void
    {
        config()->set('kb.deletion.soft_delete', false);
        $document = $this->makeDocument(); // no file on disk

        $result = (new DocumentDeleter)->delete($document);

        $this->assertSame('hard', $result['mode']);
        $this->assertFalse($result['file_deleted']);
        $this->assertNull(KnowledgeDocument::withTrashed()->find($document->id));
    }

    public function test_hard_delete_applies_path_prefix_from_metadata(): void
    {
        config()->set('kb.deletion.soft_delete', false);
        config()->set('kb.sources.path_prefix', 'tenant-a');
        Storage::disk('kb')->put('tenant-a/docs/sample.md', '# hi');

        $document = $this->makeDocument([
            'metadata' => ['disk' => 'kb', 'prefix' => 'tenant-a'],
        ]);

        $result = (new DocumentDeleter)->delete($document);

        $this->assertTrue($result['file_deleted']);
        Storage::disk('kb')->assertMissing('tenant-a/docs/sample.md');
    }

    public function test_delete_by_path_returns_null_when_not_found(): void
    {
        $result = (new DocumentDeleter)->deleteByPath('missing', 'nope.md');

        $this->assertNull($result);
    }

    public function test_delete_by_path_soft_deletes_matching_row(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        $document = $this->makeDocument();

        $result = (new DocumentDeleter)->deleteByPath($document->project_key, $document->source_path);

        $this->assertNotNull($result);
        $this->assertSame('soft', $result['mode']);
        $this->assertNull(KnowledgeDocument::find($document->id));
    }

    public function test_delete_orphans_removes_only_rows_whose_file_is_missing(): void
    {
        config()->set('kb.deletion.soft_delete', true);

        $kept = $this->makeDocument(['source_path' => 'docs/a.md', 'version_hash' => 'keep']);
        $orphan = $this->makeDocument(['source_path' => 'docs/b.md', 'version_hash' => 'orphan']);
        // Different folder — must NOT be pruned.
        $sibling = $this->makeDocument(['source_path' => 'other/c.md', 'version_hash' => 'sibling']);

        $result = (new DocumentDeleter)->deleteOrphans(
            projectKey: 'demo',
            basePath: 'docs',
            existingRelativePaths: ['docs/a.md'],
        );

        $this->assertCount(1, $result);
        $this->assertSame('docs/b.md', $result[0]['source_path']);

        $this->assertNotNull(KnowledgeDocument::find($kept->id));
        $this->assertNotNull(KnowledgeDocument::find($sibling->id));
        $this->assertNull(KnowledgeDocument::find($orphan->id));
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($orphan->id));
    }

    public function test_delete_orphans_respects_project_boundary(): void
    {
        $docA = $this->makeDocument(['project_key' => 'proj-a', 'source_path' => 'docs/a.md', 'version_hash' => 'a']);
        $docB = $this->makeDocument(['project_key' => 'proj-b', 'source_path' => 'docs/a.md', 'version_hash' => 'b']);

        (new DocumentDeleter)->deleteOrphans(
            projectKey: 'proj-a',
            basePath: 'docs',
            existingRelativePaths: [],
        );

        $this->assertNull(KnowledgeDocument::find($docA->id));
        $this->assertNotNull(KnowledgeDocument::find($docB->id));
    }

    public function test_prune_soft_deleted_hard_deletes_old_rows_and_removes_files(): void
    {
        config()->set('kb.deletion.soft_delete', true);

        Storage::disk('kb')->put('docs/old.md', '# old');
        Storage::disk('kb')->put('docs/recent.md', '# recent');

        $old = $this->makeDocument(['source_path' => 'docs/old.md', 'version_hash' => 'old']);
        $recent = $this->makeDocument(['source_path' => 'docs/recent.md', 'version_hash' => 'recent']);

        $deleter = new DocumentDeleter;
        $deleter->delete($old);    // soft
        $deleter->delete($recent); // soft

        // Backdate $old's deletion past the retention window.
        KnowledgeDocument::withTrashed()
            ->where('id', $old->id)
            ->update(['deleted_at' => now()->subDays(45)]);

        $purged = $deleter->pruneSoftDeleted(now()->subDays(30));

        $this->assertSame(1, $purged);
        $this->assertNull(KnowledgeDocument::withTrashed()->find($old->id));
        Storage::disk('kb')->assertMissing('docs/old.md');

        // The recent soft-delete must still be recoverable on disk and in DB.
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($recent->id));
        Storage::disk('kb')->assertExists('docs/recent.md');
    }
}
