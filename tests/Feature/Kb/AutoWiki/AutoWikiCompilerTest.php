<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Ai\AiManager;
use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\AutoWikiCompiler;
use App\Services\Kb\KbSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** v8.11/P1 — AutoWikiCompiler frontmatter enrichment into the auto tier. */
final class AutoWikiCompilerTest extends TestCase
{
    use RefreshDatabase;

    private function doc(array $overrides = []): KnowledgeDocument
    {
        static $n = 0;
        $n++;
        $doc = KnowledgeDocument::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'source_type' => 'markdown',
            'title' => "Cache strategy {$n}",
            'source_path' => "docs/cache-{$n}.md",
            'mime_type' => 'text/markdown',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => 'ver'.$n,
            'is_canonical' => false,
        ], $overrides));
        KnowledgeChunk::create([
            'tenant_id' => 'default',
            'knowledge_document_id' => $doc->id,
            'project_key' => 'docs-v3',
            'chunk_order' => 0,
            'chunk_hash' => 'ch'.$n,
            'heading_path' => 'Cache',
            'chunk_text' => 'How to configure the cache layer and its eviction policy.',
        ]);

        return $doc;
    }

    /** @param array<string,mixed> $json */
    private function aiReturning(array $json, ?string $expectProvider = null, ?string $expectModel = null): AiManager
    {
        $provider = Mockery::mock(AiProviderInterface::class);
        $chat = $provider->shouldReceive('chat')->once();
        if ($expectModel !== null) {
            $chat->with(Mockery::type('string'), Mockery::type('string'), Mockery::on(
                static fn (array $o): bool => ($o['model'] ?? null) === $expectModel,
            ));
        }
        $chat->andReturn(new AiResponse(
            content: (string) json_encode($json),
            provider: $expectProvider ?? 'fake',
            model: $expectModel ?? 'fake-x',
        ));

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->with($expectProvider)->andReturn($provider);

        return $ai;
    }

    private function searchEmpty(): KbSearchService
    {
        $search = Mockery::mock(KbSearchService::class);
        $search->shouldReceive('search')->andReturn(collect([]));

        return $search;
    }

    /**
     * KbSearchService mock returning neighbour chunks (shape the compiler reads).
     *
     * @param  list<array{id: int, slug: string, title: string}>  $neighbours
     */
    private function searchReturning(array $neighbours): KbSearchService
    {
        $chunks = collect($neighbours)->map(fn (array $n, int $i): array => [
            'chunk_id' => 1000 + $i,
            'chunk_text' => "Snippet for {$n['title']}.",
            'document' => ['id' => $n['id'], 'slug' => $n['slug'], 'title' => $n['title']],
        ]);
        $search = Mockery::mock(KbSearchService::class);
        $search->shouldReceive('search')->andReturn($chunks);

        return $search;
    }

    public function test_enriches_a_raw_doc_into_the_auto_tier(): void
    {
        $doc = $this->doc();
        $ai = $this->aiReturning([
            'tags' => ['Cache', 'eviction-POLICY', '#cache'],   // normalized + deduped
            'summary' => 'Explains cache configuration and eviction.',
            'aliases' => ['caching'],
            'cross_references' => [['slug' => 'dec-cache', 'title' => 'Cache decision', 'why' => 'depends', 'edge_type' => 'depends_on']],
        ]);

        // 'dec-cache' is a real neighbour, so the cross-reference survives the allowlist.
        $search = $this->searchReturning([['id' => 99, 'slug' => 'dec-cache', 'title' => 'Cache decision']]);
        $result = (new AutoWikiCompiler($ai, $search))->compile($doc);

        $this->assertTrue($result['applied']);
        $fresh = $doc->fresh();
        $this->assertSame('auto', $fresh->generation_source);
        $aw = $fresh->frontmatter_json['_autowiki'];
        $this->assertSame(['cache', 'eviction-policy'], $aw['tags']); // '#cache' dedupes to 'cache'
        $this->assertSame('Explains cache configuration and eviction.', $aw['summary']);
        $this->assertSame('dec-cache', $aw['cross_references'][0]['slug']);
        $this->assertSame('depends_on', $aw['cross_references'][0]['edge_type']);

        $this->assertDatabaseHas('kb_canonical_audit', [
            'project_key' => 'docs-v3',
            'event_type' => 'updated',
            'actor' => 'system:autowiki',
        ]);
    }

    public function test_skips_a_human_curated_canonical_doc(): void
    {
        $doc = $this->doc(['is_canonical' => true, 'generation_source' => 'human', 'canonical_status' => 'accepted', 'slug' => 'human-doc']);

        // The LLM must NOT be touched for a human-curated doc.
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('provider');

        $result = (new AutoWikiCompiler($ai, $this->searchEmpty()))->compile($doc);

        $this->assertFalse($result['applied']);
        $this->assertSame('human_curated', $result['reason']);
        $this->assertSame('human', $doc->fresh()->generation_source);
    }

    public function test_model_override_selects_the_configured_provider_and_model(): void
    {
        config(['kb.autowiki.ai_provider' => 'openrouter', 'kb.autowiki.ai_model' => 'qwen/qwen3']);
        $doc = $this->doc();

        // aiReturning asserts provider('openrouter') AND chat options model=qwen/qwen3.
        $ai = $this->aiReturning(
            ['tags' => ['x'], 'summary' => 's', 'aliases' => [], 'cross_references' => []],
            expectProvider: 'openrouter',
            expectModel: 'qwen/qwen3',
        );

        $result = (new AutoWikiCompiler($ai, $this->searchEmpty()))->compile($doc);
        $this->assertTrue($result['applied']);
        $this->assertSame('openrouter', $result['provider']);
        $this->assertSame('qwen/qwen3', $result['model']);
    }

    public function test_hallucinated_cross_reference_not_in_neighbours_is_dropped(): void
    {
        $doc = $this->doc();
        // LLM emits one real neighbour ref + one invented one — only the real survives.
        $ai = $this->aiReturning([
            'tags' => ['cache'],
            'summary' => 's',
            'aliases' => [],
            'cross_references' => [
                ['slug' => 'real-doc', 'title' => 'Real', 'why' => 'related', 'edge_type' => 'related_to'],
                ['slug' => 'invented-doc', 'title' => 'Invented', 'why' => 'hallucinated', 'edge_type' => 'related_to'],
            ],
        ]);
        $search = $this->searchReturning([['id' => 77, 'slug' => 'real-doc', 'title' => 'Real']]);

        $result = (new AutoWikiCompiler($ai, $search))->compile($doc);

        $this->assertTrue($result['applied']);
        $refs = $doc->fresh()->frontmatter_json['_autowiki']['cross_references'];
        $this->assertCount(1, $refs);
        $this->assertSame('real-doc', $refs[0]['slug']); // 'invented-doc' dropped (anti-hallucination)
    }

    public function test_unparseable_llm_reply_is_not_applied_and_doc_is_untouched(): void
    {
        $doc = $this->doc();

        // LLM returns garbage (non-JSON) → decode + validate yields an empty
        // enrichment → must NOT corrupt the doc / stamp it auto / block retry.
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('chat')->once()->andReturn(new AiResponse(
            content: 'Sorry, I could not produce JSON.',
            provider: 'fake',
            model: 'fake-x',
        ));
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->with(null)->andReturn($provider);

        $result = (new AutoWikiCompiler($ai, $this->searchEmpty()))->compile($doc);

        $this->assertFalse($result['applied']);
        $this->assertSame('empty_enrichment', $result['reason']);
        $fresh = $doc->fresh();
        $this->assertSame('human', $fresh->generation_source);          // not flipped to auto
        $this->assertArrayNotHasKey('_autowiki', (array) ($fresh->frontmatter_json ?? [])); // no empty block
        $this->assertDatabaseMissing('kb_canonical_audit', ['actor' => 'system:autowiki', 'slug' => $doc->slug]);
    }

    public function test_empty_document_is_skipped_without_calling_the_llm(): void
    {
        $doc = KnowledgeDocument::create([
            'tenant_id' => 'default', 'project_key' => 'docs-v3', 'source_type' => 'markdown',
            'title' => 'Empty', 'source_path' => 'docs/empty.md', 'mime_type' => 'text/markdown',
            'status' => 'active', 'document_hash' => str_repeat('b', 64), 'version_hash' => 'vempty',
            'is_canonical' => false,
        ]); // no chunks

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('provider');

        $result = (new AutoWikiCompiler($ai, $this->searchEmpty()))->compile($doc);
        $this->assertFalse($result['applied']);
        $this->assertSame('empty_document', $result['reason']);
    }
}
