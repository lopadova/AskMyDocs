<?php

namespace Tests\Feature\Commands;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KbDeleteCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        Storage::fake('kb');
    }

    private function seedDocument(array $overrides = []): KnowledgeDocument
    {
        $document = KnowledgeDocument::create(array_merge([
            'project_key' => 'demo',
            'source_type' => 'markdown',
            'title' => 'Sample',
            'source_path' => 'docs/sample.md',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', 'x'),
            'version_hash' => hash('sha256', 'x'),
            'metadata' => ['disk' => 'kb', 'prefix' => ''],
            'indexed_at' => now(),
        ], $overrides));

        KnowledgeChunk::create([
            'knowledge_document_id' => $document->id,
            'project_key' => $document->project_key,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'chunk-'.$document->id),
            'chunk_text' => 'body',
            'metadata' => [],
            'embedding' => [0.1],
        ]);

        return $document;
    }

    public function test_soft_delete_by_default_keeps_file(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        Storage::disk('kb')->put('docs/sample.md', 'hi');
        $doc = $this->seedDocument();

        $this->artisan('kb:delete', [
            'path' => 'docs/sample.md',
            '--project' => 'demo',
        ])
            ->expectsOutputToContain('soft-deleted')
            ->assertSuccessful();

        $this->assertNull(KnowledgeDocument::find($doc->id));
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($doc->id));
        Storage::disk('kb')->assertExists('docs/sample.md');
    }

    public function test_force_flag_hard_deletes_and_removes_file(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        Storage::disk('kb')->put('docs/sample.md', 'hi');
        $doc = $this->seedDocument();

        $this->artisan('kb:delete', [
            'path' => 'docs/sample.md',
            '--project' => 'demo',
            '--force' => true,
        ])
            ->expectsOutputToContain('hard-deleted')
            ->assertSuccessful();

        $this->assertNull(KnowledgeDocument::withTrashed()->find($doc->id));
        $this->assertSame(0, KnowledgeChunk::where('knowledge_document_id', $doc->id)->count());
        Storage::disk('kb')->assertMissing('docs/sample.md');
    }

    public function test_soft_flag_overrides_hard_config_default(): void
    {
        config()->set('kb.deletion.soft_delete', false);
        Storage::disk('kb')->put('docs/sample.md', 'hi');
        $doc = $this->seedDocument();

        $this->artisan('kb:delete', [
            'path' => 'docs/sample.md',
            '--project' => 'demo',
            '--soft' => true,
        ])
            ->expectsOutputToContain('soft-deleted')
            ->assertSuccessful();

        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($doc->id));
        Storage::disk('kb')->assertExists('docs/sample.md');
    }

    public function test_fails_when_document_not_found(): void
    {
        $this->artisan('kb:delete', [
            'path' => 'docs/missing.md',
            '--project' => 'demo',
        ])
            ->expectsOutputToContain('No document found')
            ->assertFailed();
    }

    public function test_fails_when_both_force_and_soft_given(): void
    {
        $this->seedDocument();

        $this->artisan('kb:delete', [
            'path' => 'docs/sample.md',
            '--project' => 'demo',
            '--force' => true,
            '--soft' => true,
        ])
            ->expectsOutputToContain('Cannot combine --force and --soft')
            ->assertFailed();
    }

    public function test_normalizes_path_before_lookup_so_unexpected_slashes_still_match(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        Storage::disk('kb')->put('docs/sample.md', 'hi');
        $doc = $this->seedDocument();

        // The document was stored with the normalised source_path "docs/sample.md".
        // A user typing "docs//sample.md" or "/docs/sample.md" must still find it.
        $this->artisan('kb:delete', [
            'path' => '/docs//sample.md',
            '--project' => 'demo',
        ])
            ->expectsOutputToContain('soft-deleted')
            ->assertSuccessful();

        $this->assertNull(KnowledgeDocument::find($doc->id));
    }

    public function test_rejects_paths_containing_parent_traversal(): void
    {
        $this->artisan('kb:delete', [
            'path' => '../etc/passwd',
            '--project' => 'demo',
        ])
            ->expectsOutputToContain('must be a relative path without')
            ->assertFailed();
    }

    public function test_force_can_hard_delete_already_soft_deleted_document(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        Storage::disk('kb')->put('docs/sample.md', 'hi');
        $doc = $this->seedDocument();

        // Soft delete first.
        $this->artisan('kb:delete', [
            'path' => 'docs/sample.md',
            '--project' => 'demo',
        ])->assertSuccessful();

        // Then escalate to hard delete. Previously this reported "No document
        // found" because the row was hidden by the SoftDeletes global scope.
        $this->artisan('kb:delete', [
            'path' => 'docs/sample.md',
            '--project' => 'demo',
            '--force' => true,
        ])
            ->expectsOutputToContain('hard-deleted')
            ->assertSuccessful();

        $this->assertNull(KnowledgeDocument::withTrashed()->find($doc->id));
        Storage::disk('kb')->assertMissing('docs/sample.md');
    }
}
