<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Chat;

use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\SearchResult;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * v8.1 P1 — the shared citation builder produces evidence-grade, origin-
 * aware citations. buildCitations() doesn't touch the search dependency,
 * so a bare mock suffices.
 */
final class ChatRetrievalServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function service(): ChatRetrievalService
    {
        return new ChatRetrievalService(Mockery::mock(KbSearchService::class));
    }

    public function test_citation_project_key_reads_from_the_chunk_not_the_document_select(): void
    {
        // v8.8 live-verification regression: the production retrieval select on
        // the document relation OMITS project_key (it loads id/title/slug/…),
        // so reading document.project_key returned null and the W6 Related
        // panel never rendered. project_key MUST come from the chunk (always
        // loaded + tenant-scoped). This fixture mirrors the production array
        // shape: chunk carries project_key; the `document` sub-array does NOT.
        $result = new SearchResult(
            primary: collect([[
                'chunk_id' => 1,
                'project_key' => 'hr-portal',
                'chunk_text' => 'We chose Redis as the cache backend.',
                'heading_path' => 'Cache',
                'rerank_score' => 0.9,
                // NOTE: no project_key here — matches the real retrieval select.
                'document' => ['id' => 7, 'title' => 'Cache decision', 'source_path' => 'd/cache.md', 'slug' => 'dec-cache'],
            ]]),
            expanded: collect(),
            rejected: collect(),
        );

        $c = $this->service()->buildCitations($result)[0];

        $this->assertSame('dec-cache', $c['slug']);
        $this->assertSame('hr-portal', $c['project_key'], 'project_key must come from the chunk, not the (unselected) document.project_key');
    }

    public function test_build_citations_includes_evidence_grade_chunk_provenance(): void
    {
        $text = 'The remote work stipend applies after 90 days.';
        $result = new SearchResult(
            primary: collect([[
                'chunk_id' => 11,
                'chunk_text' => $text,
                'heading_path' => 'Stipend',
                'rerank_score' => 0.77,
                'document' => ['id' => 1, 'title' => 'Policy', 'source_path' => 'hr/p.md', 'source_type' => 'markdown'],
            ]]),
            expanded: collect(),
            rejected: collect(),
        );

        $citations = $this->service()->buildCitations($result);

        $this->assertCount(1, $citations);
        $c = $citations[0];
        $this->assertSame('primary', $c['origin']);
        $this->assertSame(1, $c['document_id']);
        $this->assertArrayHasKey('chunks', $c);

        $ev = $c['chunks'][0];
        $this->assertSame(11, $ev['chunk_id']);
        $this->assertSame('Stipend', $ev['heading']);
        $this->assertEqualsWithDelta(0.77, $ev['score'], 0.0001);
        $this->assertStringContainsString('remote work stipend', $ev['snippet']);
        $this->assertSame(hash('sha256', $text), $ev['evidence_hash']);
    }

    public function test_evidence_hash_prefers_persisted_chunk_hash(): void
    {
        $result = new SearchResult(
            primary: collect([[
                'chunk_id' => 1,
                'chunk_text' => 'body',
                'chunk_hash' => 'deadbeef',
                'heading_path' => 'H',
                'rerank_score' => 0.5,
                'document' => ['id' => 1, 'title' => 'D', 'source_path' => 'a.md'],
            ]]),
            expanded: collect(),
            rejected: collect(),
        );

        $ev = $this->service()->buildCitations($result)[0]['chunks'][0];
        $this->assertSame('deadbeef', $ev['evidence_hash']);
    }

    public function test_build_citations_groups_by_document_id_across_buckets(): void
    {
        // Two primary chunks from the SAME doc → one citation with two
        // evidence chunks. A rejected chunk from another doc → a second,
        // rejected-origin citation.
        $result = new SearchResult(
            primary: collect([
                ['chunk_id' => 1, 'chunk_text' => 'x', 'heading_path' => 'A', 'rerank_score' => 0.8, 'document' => ['id' => 5, 'title' => 'D5', 'source_path' => 'd5.md']],
                ['chunk_id' => 2, 'chunk_text' => 'y', 'heading_path' => 'B', 'rerank_score' => 0.7, 'document' => ['id' => 5, 'title' => 'D5', 'source_path' => 'd5.md']],
            ]),
            expanded: collect(),
            rejected: collect([
                ['chunk_id' => 3, 'chunk_text' => 'z', 'heading_path' => 'C', 'rerank_score' => 0.6, 'document' => ['id' => 9, 'title' => 'D9', 'source_path' => 'd9.md']],
            ]),
        );

        $citations = $this->service()->buildCitations($result);

        $this->assertCount(2, $citations);
        $primary = collect($citations)->firstWhere('origin', 'primary');
        $this->assertSame(5, $primary['document_id']);
        $this->assertSame(2, $primary['chunks_used']);
        $this->assertCount(2, $primary['chunks']);

        $rejected = collect($citations)->firstWhere('origin', 'rejected');
        $this->assertSame(9, $rejected['document_id']);
    }
}
