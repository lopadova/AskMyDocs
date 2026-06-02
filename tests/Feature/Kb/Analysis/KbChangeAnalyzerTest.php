<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Analysis;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Analysis\KbChangeAnalyzer;
use App\Services\Kb\KbSearchService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * v8.7/W3–W4 — `KbChangeAnalyzer`: builds the LLM prompt from the doc +
 * its neighbours, decodes the strict-JSON reply, and validates the shape
 * (drop malformed entries, never throw on a bad reply).
 */
final class KbChangeAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        config()->set('kb.change_analysis.neighbor_limit', 5);
    }

    private function makeDocWithChunk(): KnowledgeDocument
    {
        $doc = KnowledgeDocument::create([
            'project_key' => 'proj-az',
            'source_path' => 'docs/a.md',
            'source_type' => 'markdown',
            'title' => 'Caching strategy',
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', 'a'),
            'version_hash' => hash('sha256', 'a'),
            'metadata' => [],
            'indexed_at' => now(),
        ]);
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => 'proj-az',
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'chunk'),
            'heading_path' => 'Caching',
            'chunk_text' => 'We cache results in Redis for 1 hour.',
            'metadata' => [],
        ]);

        return $doc;
    }

    private function analyzer(string $llmJson, array $neighbours = []): KbChangeAnalyzer
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->once()
            ->andReturn(new AiResponse(content: $llmJson, provider: 'test', model: 'test-model'));

        $search = Mockery::mock(KbSearchService::class);
        $search->shouldReceive('search')->andReturn(collect($neighbours));

        return new KbChangeAnalyzer($ai, $search);
    }

    public function test_builds_validated_structured_output(): void
    {
        $doc = $this->makeDocWithChunk();
        $json = json_encode([
            'enhancement_suggestions' => ['Add a TTL rationale', '   ', 123],
            'cross_references' => [['slug' => 'dec-cache', 'title' => 'Cache decision', 'why' => 'same domain']],
            'impacted_docs' => [
                ['slug' => 'old-cache', 'title' => 'Old cache', 'impact' => 'superseded', 'suggested_action' => 'deprecate'],
                ['slug' => '', 'title' => '', 'impact' => 'x', 'suggested_action' => 'y'], // dropped (no id)
            ],
        ]);

        $result = $this->analyzer($json, [
            ['document' => ['id' => 99, 'title' => 'Neighbour', 'slug' => 'nb'], 'chunk_text' => 'related'],
        ])->analyze($doc, 'ingested');

        $analysis = $result['analysis'];
        // Non-string / blank suggestions dropped.
        $this->assertSame(['Add a TTL rationale'], $analysis['enhancement_suggestions']);
        $this->assertCount(1, $analysis['cross_references']);
        $this->assertSame('dec-cache', $analysis['cross_references'][0]['slug']);
        // The no-id impacted entry is dropped; one valid remains.
        $this->assertCount(1, $analysis['impacted_docs']);
        $this->assertSame('deprecate', $analysis['impacted_docs'][0]['suggested_action']);
        $this->assertSame('test-model', $result['model']);
    }

    public function test_malformed_json_degrades_to_empty_analysis(): void
    {
        $doc = $this->makeDocWithChunk();

        $result = $this->analyzer('not json at all')->analyze($doc, 'ingested');

        $this->assertSame([], $result['analysis']['enhancement_suggestions']);
        $this->assertSame([], $result['analysis']['cross_references']);
        $this->assertSame([], $result['analysis']['impacted_docs']);
    }

    public function test_strips_code_fences_around_json(): void
    {
        $doc = $this->makeDocWithChunk();
        $fenced = "```json\n".json_encode([
            'enhancement_suggestions' => ['Document the eviction policy'],
            'cross_references' => [],
            'impacted_docs' => [],
        ])."\n```";

        $result = $this->analyzer($fenced)->analyze($doc, 'modified');

        $this->assertSame(['Document the eviction policy'], $result['analysis']['enhancement_suggestions']);
    }
}
