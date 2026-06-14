<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Ai\AiManager;
use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\AutoWikiReviewer;
use App\Services\Kb\KbSearchService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** v8.11/P7 — AutoWikiReviewer: cross-model review / novelty / contradiction gate. */
final class AutoWikiReviewerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    private function doc(array $overrides = []): KnowledgeDocument
    {
        static $n = 0;
        $n++;
        $doc = KnowledgeDocument::create(array_merge([
            'tenant_id' => 'default', 'project_key' => 'docs-v3', 'source_type' => 'markdown',
            'title' => "Doc {$n}", 'source_path' => "docs/r-{$n}.md", 'mime_type' => 'text/markdown',
            'status' => 'active', 'document_hash' => str_repeat('a', 64), 'version_hash' => 'v'.$n,
            'is_canonical' => false, 'slug' => "doc-{$n}", 'generation_source' => 'auto',
        ], $overrides));
        KnowledgeChunk::create([
            'tenant_id' => 'default', 'knowledge_document_id' => $doc->id, 'project_key' => 'docs-v3',
            'chunk_order' => 0, 'chunk_hash' => 'ch'.$n, 'heading_path' => 'H', 'chunk_text' => 'Body content for review.',
        ]);

        return $doc;
    }

    /** @param list<array{id:int,slug:string,title:string}> $neighbours */
    private function reviewer(array $json, array $neighbours = []): AutoWikiReviewer
    {
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('chat')->andReturn(new AiResponse(content: (string) json_encode($json), provider: 'fake', model: 'fake-review'));
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->andReturn($provider);

        $chunks = collect($neighbours)->map(fn (array $x): array => [
            'chunk_text' => "Snippet {$x['title']}", 'document' => ['id' => $x['id'], 'slug' => $x['slug'], 'title' => $x['title']],
        ]);
        $search = Mockery::mock(KbSearchService::class);
        $search->shouldReceive('search')->andReturn($chunks);

        return new AutoWikiReviewer($ai, $search);
    }

    private function aiNeverCalled(): AutoWikiReviewer
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('provider');
        $search = Mockery::mock(KbSearchService::class);

        return new AutoWikiReviewer($ai, $search);
    }

    public function test_reviews_auto_doc_and_persists_verdict(): void
    {
        $doc = $this->doc();
        $result = $this->reviewer([
            'grounded' => true, 'cross_refs_valid' => true, 'novelty' => 'novel',
            'contradictions' => [], 'issues' => [], 'verdict' => 'approved',
        ])->review($doc);

        $this->assertTrue($result['reviewed']);
        $this->assertSame('approved', $result['verdict']);
        $review = $doc->fresh()->frontmatter_json['_autowiki']['review'];
        $this->assertSame('approved', $review['verdict']);
        $this->assertSame('fake-review', $review['model']);
        $this->assertDatabaseHas('kb_canonical_audit', [
            'project_key' => 'docs-v3', 'event_type' => 'updated', 'actor' => 'system:autowiki-review',
        ]);
    }

    public function test_flagged_verdict_and_contradiction_filtered_to_real_neighbour(): void
    {
        $doc = $this->doc();
        $result = $this->reviewer([
            'grounded' => false, 'cross_refs_valid' => false, 'novelty' => 'duplicate',
            'contradictions' => [
                ['slug' => 'real-neighbour', 'why' => 'conflicts'],
                ['slug' => 'invented', 'why' => 'hallucinated'],
            ],
            'issues' => ['ungrounded claim'], 'verdict' => 'approved', // claims approved but...
        ], [['id' => 99, 'slug' => 'real-neighbour', 'title' => 'Real']])->review($doc);

        // Contradiction to a non-neighbour is dropped (anti-hallucination).
        $this->assertCount(1, $result['contradictions']);
        $this->assertSame('real-neighbour', $result['contradictions'][0]['slug']);
        $this->assertSame('duplicate', $result['novelty']);
    }

    public function test_firewall_skips_non_auto_doc(): void
    {
        $doc = $this->doc(['generation_source' => 'human']);
        $result = $this->aiNeverCalled()->review($doc);
        $this->assertFalse($result['reviewed']);
        $this->assertSame('not_auto', $result['reason']);
    }

    public function test_disabled_flag_is_a_clean_noop(): void
    {
        config(['kb.autowiki.review_enabled' => false]);
        $doc = $this->doc();
        $result = $this->aiNeverCalled()->review($doc);
        $this->assertFalse($result['reviewed']);
        $this->assertSame('disabled', $result['reason']);
    }

    public function test_review_merge_preserves_prior_autowiki_keys(): void
    {
        // A doc already enriched by P1 (tags/summary) + linked by P2
        // (cross_references). Reviewing it must ADD `review` without clobbering
        // the prior _autowiki content.
        $doc = $this->doc(['frontmatter_json' => ['_autowiki' => [
            'tags' => ['cache'],
            'summary' => 'Prior summary.',
            'cross_references' => [['slug' => 'dec-x', 'edge_type' => 'related_to']],
        ]]]);

        $this->reviewer([
            'grounded' => true, 'cross_refs_valid' => true, 'novelty' => 'novel',
            'contradictions' => [], 'issues' => [], 'verdict' => 'approved',
        ])->review($doc);

        $aw = $doc->fresh()->frontmatter_json['_autowiki'];
        $this->assertSame(['cache'], $aw['tags']);           // prior key preserved
        $this->assertSame('Prior summary.', $aw['summary']); // prior key preserved
        $this->assertSame('dec-x', $aw['cross_references'][0]['slug']);
        $this->assertSame('approved', $aw['review']['verdict']); // new key added
    }

    public function test_invalid_novelty_defaults_to_novel(): void
    {
        $doc = $this->doc();
        $result = $this->reviewer([
            'grounded' => true, 'cross_refs_valid' => true, 'novelty' => 'nonsense',
            'contradictions' => [], 'issues' => [], 'verdict' => 'approved',
        ])->review($doc);
        $this->assertSame('novel', $result['novelty']);
    }
}
