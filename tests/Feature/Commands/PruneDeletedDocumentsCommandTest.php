<?php

namespace Tests\Feature\Commands;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneDeletedDocumentsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        Storage::fake('kb');
    }

    private function softDeletedDoc(string $sourcePath, \DateTimeInterface $deletedAt, string $versionHash): KnowledgeDocument
    {
        $document = KnowledgeDocument::create([
            'project_key' => 'demo',
            'source_type' => 'markdown',
            'title' => 'Sample',
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
            'chunk_text' => 'body',
            'metadata' => [],
            'embedding' => [0.1],
        ]);

        $document->delete();
        KnowledgeDocument::withTrashed()
            ->where('id', $document->id)
            ->update(['deleted_at' => $deletedAt]);

        return $document;
    }

    public function test_uses_config_retention_when_days_option_missing(): void
    {
        config()->set('kb.deletion.retention_days', 14);

        Storage::disk('kb')->put('docs/old.md', 'hi');
        Storage::disk('kb')->put('docs/recent.md', 'hi');

        $old = $this->softDeletedDoc('docs/old.md', now()->subDays(30), 'old');
        $recent = $this->softDeletedDoc('docs/recent.md', now()->subDays(5), 'recent');

        $this->artisan('kb:prune-deleted')
            ->expectsOutputToContain('Pruned 1 soft-deleted document(s) older than 14 days')
            ->assertSuccessful();

        $this->assertNull(KnowledgeDocument::withTrashed()->find($old->id));
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($recent->id));
        Storage::disk('kb')->assertMissing('docs/old.md');
        Storage::disk('kb')->assertExists('docs/recent.md');
    }

    public function test_days_option_overrides_config(): void
    {
        config()->set('kb.deletion.retention_days', 90);

        $old = $this->softDeletedDoc('docs/old.md', now()->subDays(45), 'old');

        $this->artisan('kb:prune-deleted', ['--days' => 30])
            ->expectsOutputToContain('Pruned 1')
            ->assertSuccessful();

        $this->assertNull(KnowledgeDocument::withTrashed()->find($old->id));
    }

    public function test_retention_zero_is_a_noop(): void
    {
        $old = $this->softDeletedDoc('docs/x.md', now()->subYears(2), 'x');

        $this->artisan('kb:prune-deleted', ['--days' => 0])
            ->expectsOutputToContain('skipping prune')
            ->assertSuccessful();

        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($old->id));
    }
}
