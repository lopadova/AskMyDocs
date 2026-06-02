<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Jobs\AnalyzeDocumentDeletionJob;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v8.8/W2 — `DocumentDeleter` dispatches the obsolescence-impact deep-analysis
 * ONLY for a user-initiated single delete (`analyzeImpact: true`), with a
 * pre-delete snapshot captured BEFORE the chunks are gone. Bulk sweeps
 * (orphans/prune) never trigger the LLM.
 */
final class DocumentDeleterDeletionAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
        app(TenantContext::class)->set('default');
        config()->set('kb.change_analysis.enabled', true);
        config()->set('kb.change_analysis.delete_enabled', true);
    }

    private function seedDocWithChunks(bool $canonical = true): KnowledgeDocument
    {
        $doc = KnowledgeDocument::create([
            'project_key' => 'proj-del',
            'source_type' => 'markdown',
            'title' => 'Cache decision',
            'source_path' => 'decisions/dec-cache.md',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_pad('1', 64, 'a'),
            'version_hash' => str_pad('1', 64, 'b'),
            'doc_id' => $canonical ? 'DEC-1' : null,
            'slug' => $canonical ? 'dec-cache' : null,
            'canonical_type' => $canonical ? 'decision' : null,
            'canonical_status' => $canonical ? 'accepted' : null,
            'is_canonical' => $canonical,
        ]);

        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => 'proj-del',
            'chunk_order' => 0,
            'chunk_hash' => str_pad('c', 64, 'c'),
            'heading_path' => 'Decision',
            'chunk_text' => 'We chose Redis for the cache layer.',
        ]);

        return $doc;
    }

    public function test_user_delete_dispatches_deletion_analysis_with_snapshot(): void
    {
        Bus::fake([AnalyzeDocumentDeletionJob::class]);
        $doc = $this->seedDocWithChunks();

        (new DocumentDeleter())->delete($doc, force: false, analyzeImpact: true);

        Bus::assertDispatched(AnalyzeDocumentDeletionJob::class, function (AnalyzeDocumentDeletionJob $job) use ($doc): bool {
            return $job->snapshot['knowledge_document_id'] === $doc->id
                && $job->snapshot['project_key'] === 'proj-del'
                && $job->snapshot['doc_slug'] === 'dec-cache'
                && $job->snapshot['is_canonical'] === true
                && str_contains($job->snapshot['doc_text'], 'Redis');
        });
    }

    public function test_snapshot_text_survives_a_hard_delete_that_cascades_chunks(): void
    {
        // The snapshot must be built BEFORE forceDelete cascades the chunks,
        // otherwise doc_text would be empty.
        Bus::fake([AnalyzeDocumentDeletionJob::class]);
        $doc = $this->seedDocWithChunks();

        (new DocumentDeleter())->delete($doc, force: true, analyzeImpact: true);

        $this->assertSame(0, KnowledgeChunk::query()->forTenant('default')->where('knowledge_document_id', $doc->id)->count());
        Bus::assertDispatched(AnalyzeDocumentDeletionJob::class, function (AnalyzeDocumentDeletionJob $job): bool {
            return str_contains($job->snapshot['doc_text'], 'Redis');
        });
    }

    public function test_bulk_delete_without_opt_in_does_not_dispatch(): void
    {
        Bus::fake([AnalyzeDocumentDeletionJob::class]);
        $doc = $this->seedDocWithChunks();

        // Default analyzeImpact:false — the path bulk sweeps use.
        (new DocumentDeleter())->delete($doc, force: false);

        Bus::assertNotDispatched(AnalyzeDocumentDeletionJob::class);
    }

    public function test_no_dispatch_when_delete_analysis_disabled(): void
    {
        Bus::fake([AnalyzeDocumentDeletionJob::class]);
        config()->set('kb.change_analysis.delete_enabled', false);
        $doc = $this->seedDocWithChunks();

        (new DocumentDeleter())->delete($doc, force: false, analyzeImpact: true);

        Bus::assertNotDispatched(AnalyzeDocumentDeletionJob::class);
    }
}
