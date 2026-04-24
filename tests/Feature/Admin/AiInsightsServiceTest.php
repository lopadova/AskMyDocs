<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Ai\AiManager;
use App\Models\ChatLog;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Message;
use App\Models\User;
use App\Services\Admin\AiInsightsService;
use App\Services\Kb\Canonical\PromotionSuggestService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase I — AiInsightsService feature tests.
 *
 * Every test seeds DB state and stubs LLM calls with Http::fake().
 * Covers each of the six insight functions individually + edge cases
 * (empty corpus, LLM returns non-JSON, provider 500 → RuntimeException).
 */
class AiInsightsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function svc(): AiInsightsService
    {
        return new AiInsightsService(
            app(AiManager::class),
            app(PromotionSuggestService::class),
        );
    }

    // ------------------------------------------------------------------
    // suggestPromotions
    // ------------------------------------------------------------------

    public function test_suggest_promotions_picks_non_canonical_with_citations(): void
    {
        $nonCanon = $this->makeDoc(canonical: false, path: 'raw/hot.md');
        $canon = $this->makeDoc(canonical: true, path: 'canonical/cold.md');

        // Cite the non-canonical doc 5×, the canonical doc 2×.
        for ($i = 0; $i < 5; $i++) {
            $this->makeChatLog([
                ['project' => $nonCanon->project_key, 'path' => $nonCanon->source_path, 'title' => 'x'],
            ]);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->makeChatLog([
                ['project' => $canon->project_key, 'path' => $canon->source_path, 'title' => 'y'],
            ]);
        }

        $out = $this->svc()->suggestPromotions();

        $this->assertNotEmpty($out);
        $this->assertSame($nonCanon->id, $out[0]['document_id']);
        $this->assertSame(5, $out[0]['score']);
        // Make sure a canonical doc never appears.
        foreach ($out as $row) {
            $this->assertNotSame($canon->id, $row['document_id']);
        }
    }

    public function test_suggest_promotions_empty_when_no_citations(): void
    {
        $this->makeDoc(canonical: false, path: 'lonely.md');
        $this->assertSame([], $this->svc()->suggestPromotions());
    }

    // ------------------------------------------------------------------
    // detectOrphans
    // ------------------------------------------------------------------

    public function test_detect_orphans_surfaces_canonical_with_no_edges_and_no_citations(): void
    {
        $orphan = $this->makeDoc(canonical: true, path: 'orphan.md', slug: 'orphan-doc');
        $connected = $this->makeDoc(canonical: true, path: 'connected.md', slug: 'connected-doc');

        // Create a node + edge so `connected` has at least one edge.
        KbNode::create([
            'node_uid' => $connected->slug,
            'node_type' => 'decision',
            'label' => 'Connected',
            'project_key' => $connected->project_key,
            'source_doc_id' => $connected->doc_id,
            'payload_json' => [],
        ]);
        KbNode::create([
            'node_uid' => 'peer',
            'node_type' => 'decision',
            'label' => 'Peer',
            'project_key' => $connected->project_key,
            'source_doc_id' => null,
            'payload_json' => [],
        ]);
        KbEdge::create([
            'edge_uid' => 'e-1',
            'from_node_uid' => $connected->slug,
            'to_node_uid' => 'peer',
            'edge_type' => 'related_to',
            'project_key' => $connected->project_key,
            'source_doc_id' => $connected->doc_id,
            'weight' => 1.0,
            'provenance' => 'wikilink',
            'payload_json' => [],
        ]);

        $out = $this->svc()->detectOrphans();

        $ids = array_column($out, 'document_id');
        $this->assertContains($orphan->id, $ids);
        $this->assertNotContains($connected->id, $ids);
    }

    public function test_detect_orphans_ignores_docs_with_citations(): void
    {
        $doc = $this->makeDoc(canonical: true, path: 'cited.md', slug: 'cited-doc');
        $this->makeChatLog([
            ['project' => $doc->project_key, 'path' => $doc->source_path, 'title' => 't'],
        ]);

        $out = $this->svc()->detectOrphans();
        $ids = array_column($out, 'document_id');
        $this->assertNotContains($doc->id, $ids);
    }

    // ------------------------------------------------------------------
    // suggestTagsBatch
    // ------------------------------------------------------------------

    public function test_suggest_tags_batch_calls_llm_and_returns_per_doc(): void
    {
        $doc = $this->makeDoc(canonical: true, path: 'tag-me.md', slug: 'tag-me-doc');
        $doc->chunks()->create([
            'project_key' => $doc->project_key,
            'chunk_order' => 0,
            'chunk_hash' => str_repeat('a', 64),
            'heading_path' => '# Redis',
            'chunk_text' => 'Redis eviction policies and LRU cache sizing.',
            'metadata' => [],
            'embedding' => [],
        ]);

        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '["redis","lru","cache-sizing"]'],
                    'finish_reason' => 'stop',
                ]],
                'model' => 'gpt-4o-mini',
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], 200),
        ]);

        $out = $this->svc()->suggestTagsBatch();

        $this->assertNotEmpty($out);
        $this->assertSame($doc->id, $out[0]['document_id']);
        $this->assertSame(['redis', 'lru', 'cache-sizing'], $out[0]['tags_proposed']);
    }

    public function test_suggest_tags_batch_empty_when_no_canonical_docs(): void
    {
        $this->assertSame([], $this->svc()->suggestTagsBatch());
    }

    public function test_suggest_tags_ignores_non_json_llm_response(): void
    {
        $doc = $this->makeDoc(canonical: true, path: 'bad.md', slug: 'bad-doc');
        $doc->chunks()->create([
            'project_key' => $doc->project_key,
            'chunk_order' => 0,
            'chunk_hash' => str_repeat('b', 64),
            'heading_path' => '',
            'chunk_text' => 'Some text.',
            'metadata' => [],
            'embedding' => [],
        ]);

        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'I am sorry, I cannot comply.'],
                    'finish_reason' => 'stop',
                ]],
                'model' => 'gpt-4o-mini',
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], 200),
        ]);

        $out = $this->svc()->suggestTagsBatch();
        // Service drops the entry when the LLM returned no tags.
        $this->assertSame([], $out);
    }

    public function test_suggest_tags_for_document_bubbles_provider_http_error(): void
    {
        $doc = $this->makeDoc(canonical: true, path: 'err.md', slug: 'err-doc');
        $doc->chunks()->create([
            'project_key' => $doc->project_key,
            'chunk_order' => 0,
            'chunk_hash' => str_repeat('c', 64),
            'heading_path' => '',
            'chunk_text' => 'Some text.',
            'metadata' => [],
            'embedding' => [],
        ]);

        Http::fake([
            '*' => Http::response(['error' => 'boom'], 500),
        ]);

        // Http::throw() in the provider raises RequestException — the
        // service doesn't catch it, so it bubbles out.
        $this->expectException(\Throwable::class);
        $this->svc()->suggestTagsForDocument($doc);
    }

    // ------------------------------------------------------------------
    // coverageGaps
    // ------------------------------------------------------------------

    public function test_coverage_gaps_clusters_low_confidence_questions(): void
    {
        $this->makeChatLog([], 'How do I deploy?', 0);
        $this->makeChatLog([], 'Where is the backup?', 1);
        $this->makeChatLog([], 'What is the SSO url?', 0);

        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '[{"topic":"Deployment","sample_questions":["How do I deploy?"]}]'],
                    'finish_reason' => 'stop',
                ]],
                'model' => 'gpt-4o-mini',
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], 200),
        ]);

        $out = $this->svc()->coverageGaps();

        $this->assertNotEmpty($out);
        $this->assertSame('Deployment', $out[0]['topic']);
        $this->assertGreaterThanOrEqual(1, $out[0]['zero_citation_count']);
    }

    public function test_coverage_gaps_empty_when_no_low_confidence_rows(): void
    {
        // All rows have chunks_count >= 2 and sources, so nothing
        // qualifies as low-confidence.
        $this->makeChatLog([
            ['project' => 'hr-portal', 'path' => 'a.md', 'title' => 'a'],
            ['project' => 'hr-portal', 'path' => 'b.md', 'title' => 'b'],
        ], 'covered', 4);

        $this->assertSame([], $this->svc()->coverageGaps());
    }

    // ------------------------------------------------------------------
    // detectStaleDocs
    // ------------------------------------------------------------------

    public function test_detect_stale_docs_surfaces_old_docs_with_negative_ratings(): void
    {
        $doc = $this->makeDoc(canonical: true, path: 'stale.md', slug: 'stale-doc');
        $doc->update(['indexed_at' => Carbon::now()->subYear()]);

        // Create a rated message pointing at the doc via metadata
        // (simulating the Reranker citation shape).
        $user = User::create([
            'name' => 'U',
            'email' => 'u-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $conversation = \App\Models\Conversation::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'project_key' => $doc->project_key,
            'pinned' => false,
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Answer',
            'metadata' => [
                'citations' => [
                    ['project' => $doc->project_key, 'path' => $doc->source_path, 'title' => 't'],
                ],
            ],
            'rating' => -1,
            'created_at' => Carbon::now()->subMonth(),
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Answer',
            'metadata' => [
                'citations' => [
                    ['project' => $doc->project_key, 'path' => $doc->source_path, 'title' => 't'],
                ],
            ],
            'rating' => -1,
            'created_at' => Carbon::now()->subMonth(),
        ]);

        $out = $this->svc()->detectStaleDocs();
        $ids = array_column($out, 'document_id');
        $this->assertContains($doc->id, $ids);
    }

    public function test_detect_stale_docs_ignores_recent_docs(): void
    {
        $doc = $this->makeDoc(canonical: true, path: 'fresh.md', slug: 'fresh-doc');
        $doc->update(['indexed_at' => Carbon::now()]);

        $out = $this->svc()->detectStaleDocs();
        $ids = array_column($out, 'document_id');
        $this->assertNotContains($doc->id, $ids);
    }

    // ------------------------------------------------------------------
    // qualityReport
    // ------------------------------------------------------------------

    public function test_quality_report_histograms_chunk_lengths(): void
    {
        $doc = $this->makeDoc(canonical: true, path: 'q.md', slug: 'q-doc');
        // Short outlier
        $this->makeChunk($doc, 'x', 0);
        // Mid-range
        $this->makeChunk($doc, str_repeat('y ', 100), 1);
        // Long outlier
        $this->makeChunk($doc, str_repeat('z ', 1500), 2);

        $q = $this->svc()->qualityReport();

        $this->assertGreaterThanOrEqual(1, $q['outlier_short']);
        $this->assertGreaterThanOrEqual(1, $q['outlier_long']);
        $this->assertSame(3, $q['total_chunks']);
        $this->assertArrayHasKey('chunk_length_distribution', $q);
        $this->assertArrayHasKey('under_100', $q['chunk_length_distribution']);
    }

    public function test_quality_report_empty_corpus_returns_zeros(): void
    {
        $q = $this->svc()->qualityReport();
        $this->assertSame(0, $q['total_chunks']);
        $this->assertSame(0, $q['outlier_short']);
        $this->assertSame(0, $q['outlier_long']);
    }

    public function test_quality_report_counts_missing_frontmatter(): void
    {
        $withFm = $this->makeDoc(canonical: true, path: 'withfm.md', slug: 'wfm');
        $withFm->update(['frontmatter_json' => ['doc_id' => 'x']]);

        $noFm = $this->makeDoc(canonical: true, path: 'nofm.md', slug: 'nfm');
        $noFm->update(['frontmatter_json' => null]);

        $q = $this->svc()->qualityReport();
        $this->assertGreaterThanOrEqual(1, $q['missing_frontmatter']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeDoc(bool $canonical, string $path, ?string $slug = null): KnowledgeDocument
    {
        $slug = $slug ?? 'slug-'.Str::random(5);

        return KnowledgeDocument::create([
            'project_key' => 'hr-portal',
            'source_type' => 'markdown',
            'title' => 'Test Doc '.$path,
            'source_path' => $path,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'default',
            'status' => 'indexed',
            'document_hash' => hash('sha256', $path),
            'version_hash' => hash('sha256', $path.'v1'),
            'metadata' => [],
            'is_canonical' => $canonical,
            'canonical_type' => $canonical ? 'decision' : null,
            'canonical_status' => $canonical ? 'accepted' : null,
            'slug' => $canonical ? $slug : null,
            'doc_id' => $canonical ? ('dec-'.Str::random(5)) : null,
            'retrieval_priority' => 50,
            'source_of_truth' => true,
            'indexed_at' => Carbon::now()->subDay(),
        ]);
    }

    /**
     * @param  array<int, array<string, string>>  $sources
     */
    private function makeChatLog(array $sources, string $question = 'q', int $chunks = 2): ChatLog
    {
        return ChatLog::create([
            'session_id' => (string) Str::uuid(),
            'user_id' => null,
            'question' => $question,
            'answer' => 'a',
            'project_key' => 'hr-portal',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o',
            'chunks_count' => $chunks,
            'sources' => $sources ?: null,
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
            'latency_ms' => 100,
            'created_at' => Carbon::now()->subDays(5),
        ]);
    }

    private function makeChunk(KnowledgeDocument $doc, string $text, int $order): KnowledgeChunk
    {
        return KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => $order,
            'chunk_hash' => hash('sha256', $doc->id.':'.$order),
            'heading_path' => '',
            'chunk_text' => $text,
            'metadata' => [],
            'embedding' => [],
        ]);
    }
}
