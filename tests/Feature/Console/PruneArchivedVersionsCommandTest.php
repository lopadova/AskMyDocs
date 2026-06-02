<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v8.7/W5 — `kb:prune-archived-versions` retention cap.
 */
final class PruneArchivedVersionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
    }

    private function makeVersion(int $n, string $status): KnowledgeDocument
    {
        $doc = KnowledgeDocument::create([
            'project_key' => 'eng',
            'source_path' => 'docs/dec.md',
            'source_type' => 'markdown',
            'title' => "Decision v{$n}",
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => $status,
            'document_hash' => hash('sha256', "v{$n}"),
            'version_hash' => hash('sha256', "v{$n}"),
            'metadata' => [],
            // Older n = older indexed_at, so higher n are "newer".
            'indexed_at' => now()->subDays(100 - $n),
        ]);
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => 'eng',
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', "v{$n}chunk"),
            'heading_path' => 'D',
            'chunk_text' => "body {$n}",
            'metadata' => [],
        ]);

        return $doc;
    }

    public function test_keeps_the_newest_n_archived_versions_and_deletes_the_rest(): void
    {
        // 1 live + 5 archived. keep=2 → 3 oldest archived pruned.
        $live = $this->makeVersion(6, 'active');
        $archived = [];
        for ($n = 1; $n <= 5; $n++) {
            $archived[$n] = $this->makeVersion($n, 'archived');
        }

        $this->artisan('kb:prune-archived-versions', ['--keep' => 2])->assertExitCode(0);

        // Live version untouched.
        $this->assertDatabaseHas('knowledge_documents', ['id' => $live->id]);
        // Newest 2 archived (n=5, n=4) survive; n=1..3 hard-deleted.
        $this->assertDatabaseHas('knowledge_documents', ['id' => $archived[5]->id]);
        $this->assertDatabaseHas('knowledge_documents', ['id' => $archived[4]->id]);
        foreach ([1, 2, 3] as $n) {
            $this->assertDatabaseMissing('knowledge_documents', ['id' => $archived[$n]->id]);
            // Chunks cascade-deleted with their document.
            $this->assertDatabaseMissing('knowledge_chunks', ['knowledge_document_id' => $archived[$n]->id]);
        }
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->makeVersion(3, 'active');
        $this->makeVersion(2, 'archived');
        $this->makeVersion(1, 'archived');

        $this->artisan('kb:prune-archived-versions', ['--keep' => 0, '--dry-run' => true])->assertExitCode(0);

        // keep=0 would delete both archived, but dry-run keeps everything.
        $this->assertSame(3, KnowledgeDocument::count());
    }

    public function test_live_version_is_never_pruned_even_with_keep_zero(): void
    {
        $live = $this->makeVersion(2, 'active');
        $this->makeVersion(1, 'archived');

        $this->artisan('kb:prune-archived-versions', ['--keep' => 0])->assertExitCode(0);

        $this->assertDatabaseHas('knowledge_documents', ['id' => $live->id, 'status' => 'active']);
        // The single archived version is pruned (keep=0).
        $this->assertSame(1, KnowledgeDocument::where('status', 'archived')->count() + KnowledgeDocument::where('status', 'active')->count());
    }
}
