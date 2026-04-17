<?php

namespace Tests\Feature\Commands;

use App\Jobs\IngestDocumentJob;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the --prune-orphans pathway of kb:ingest-folder. A document that
 * was ingested before is removed from the KB store when its source file is
 * no longer present on disk.
 */
class KbIngestFolderPruneOrphansTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('kb.ingest.queue', 'kb-ingest');
    }

    private function seedKnownDocument(string $sourcePath, string $versionHash): KnowledgeDocument
    {
        $document = KnowledgeDocument::create([
            'project_key' => 'demo',
            'source_type' => 'markdown',
            'title' => pathinfo($sourcePath, PATHINFO_FILENAME),
            'source_path' => $sourcePath,
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => $versionHash,
            'version_hash' => $versionHash,
            'metadata' => ['disk' => 'kb', 'prefix' => ''],
            'indexed_at' => now(),
        ]);

        KnowledgeChunk::create([
            'knowledge_document_id' => $document->id,
            'project_key' => $document->project_key,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'chunk-'.$document->id),
            'chunk_text' => 'x',
            'metadata' => [],
            'embedding' => [0.1],
        ]);

        return $document;
    }

    public function test_prune_orphans_soft_deletes_documents_whose_file_was_removed(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        Queue::fake();
        Storage::fake('kb');

        // Only a.md still exists on disk; b.md is an orphan.
        Storage::disk('kb')->put('docs/a.md', 'hello');

        $this->seedKnownDocument('docs/a.md', 'va');
        $orphan = $this->seedKnownDocument('docs/b.md', 'vb');

        $this->artisan('kb:ingest-folder', [
            'path' => 'docs',
            '--project' => 'demo',
            '--prune-orphans' => true,
        ])
            ->expectsOutputToContain('Pruned 1 orphan document(s)')
            ->assertSuccessful();

        Queue::assertPushed(IngestDocumentJob::class, 1);

        $this->assertNull(KnowledgeDocument::find($orphan->id));
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($orphan->id));
    }

    public function test_prune_orphans_with_force_delete_hard_removes(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        Queue::fake();
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/a.md', 'hi');

        $this->seedKnownDocument('docs/a.md', 'va');
        $orphan = $this->seedKnownDocument('docs/b.md', 'vb');

        $this->artisan('kb:ingest-folder', [
            'path' => 'docs',
            '--project' => 'demo',
            '--prune-orphans' => true,
            '--force-delete' => true,
        ])->assertSuccessful();

        $this->assertNull(KnowledgeDocument::withTrashed()->find($orphan->id));
    }

    public function test_prune_orphans_runs_even_when_folder_is_empty(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        Queue::fake();
        Storage::fake('kb');

        $orphan = $this->seedKnownDocument('docs/gone.md', 'gone');

        $this->artisan('kb:ingest-folder', [
            'path' => 'docs',
            '--project' => 'demo',
            '--prune-orphans' => true,
        ])
            ->expectsOutputToContain('No markdown files matched')
            ->expectsOutputToContain('Pruned 1 orphan document(s)')
            ->assertSuccessful();

        $this->assertNull(KnowledgeDocument::find($orphan->id));
    }

    public function test_prune_orphans_respects_folder_scope(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        Queue::fake();
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/a.md', 'hi');

        $this->seedKnownDocument('docs/a.md', 'va');
        $outside = $this->seedKnownDocument('other/b.md', 'vb');

        $this->artisan('kb:ingest-folder', [
            'path' => 'docs',
            '--project' => 'demo',
            '--prune-orphans' => true,
        ])->assertSuccessful();

        // "other/b.md" lives outside the folder scope — must NOT be pruned.
        $this->assertNotNull(KnowledgeDocument::find($outside->id));
    }
}
