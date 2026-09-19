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
            'evidence_tier' => 'official',
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
        // P1b — evidence_tier derived + persisted to both the column and _autowiki.
        $this->assertSame('official', $aw['evidence_tier']);
        $this->assertSame('official', $fresh->evidence_tier);

        $this->assertDatabaseHas('kb_canonical_audit', [
            'project_key' => 'docs-v3',
            'event_type' => 'updated',
            'actor' => 'system:autowiki',
        ]);
    }

    public function test_human_set_evidence_tier_survives_re_compilation(): void
    {
        // A human (via EvidenceTierService) has set this auto-tier doc to
        // 'official'; there is no prior _autowiki block. A re-compile whose LLM
        // guesses 'blog' must NOT clobber the human value in the column, though
        // the fresh guess still lands in _autowiki (firewall: human > auto).
        $doc = $this->doc(['evidence_tier' => 'official']);
        $ai = $this->aiReturning([
            'tags' => ['cache'], 'summary' => 's', 'aliases' => [], 'cross_references' => [],
            'evidence_tier' => 'blog',
        ]);

        $result = (new AutoWikiCompiler($ai, $this->searchEmpty()))->compile($doc);

        $this->assertTrue($result['applied']);
        $fresh = $doc->fresh();
        $this->assertSame('official', $fresh->evidence_tier);                       // human override preserved
        $this->assertSame('blog', $fresh->frontmatter_json['_autowiki']['evidence_tier']); // fresh guess recorded
    }

    public function test_auto_derived_evidence_tier_is_refreshed_on_re_compilation(): void
    {
        // First compile sets the column + _autowiki to 'blog' (auto, untouched
        // by a human). A later compile with a better guess refreshes BOTH —
        // auto values are not frozen ("knowledge improves over time").
        $doc = $this->doc([
            'evidence_tier' => 'blog',
            'frontmatter_json' => ['_autowiki' => ['evidence_tier' => 'blog']],
        ]);
        $ai = $this->aiReturning([
            'tags' => ['cache'], 'summary' => 's', 'aliases' => [], 'cross_references' => [],
            'evidence_tier' => 'official',
        ]);

        $result = (new AutoWikiCompiler($ai, $this->searchEmpty()))->compile($doc);

        $this->assertTrue($result['applied']);
        $this->assertSame('official', $doc->fresh()->evidence_tier); // auto value refreshed
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

    /**
     * Copilot PR #494 round 9 (must-fix) — a non-canonical OCR row that a
     * human approved via Digitization Review (`KbReviewService::approve()`,
     * `generation_source` auto -> human, `is_canonical` untouched) must
     * NOT be re-enriched by a later compile pass. Before this fix, the
     * canonical-only firewall missed it entirely (is_canonical=false), so
     * compile() would proceed, call the LLM, and apply() would stamp
     * generation_source back to 'auto' — silently undoing the approval
     * while the audit trail still says 'promoted'.
     */
    public function test_skips_a_human_approved_non_canonical_ocr_row(): void
    {
        $doc = $this->doc([
            'is_canonical' => false,
            'generation_source' => 'human',
            'metadata' => ['converter' => ['provenance' => 'ocr']],
        ]);

        // The LLM must NOT be touched for an approved OCR row.
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('provider');

        $result = (new AutoWikiCompiler($ai, $this->searchEmpty()))->compile($doc);

        $this->assertFalse($result['applied']);
        $this->assertSame('human_curated', $result['reason']);
        $this->assertSame('human', $doc->fresh()->generation_source, 'a human-approved OCR row must never be flipped back to auto by AutoWiki');
    }

    /**
     * The narrower firewall must NOT over-reach: an ordinary non-canonical
     * `human`-default raw document (no frontmatter, never OCR'd — the
     * pre-W3 default for every non-canonical row) is exactly the content
     * this compiler exists to enrich. Only the OCR-origin combination is
     * exempt.
     */
    public function test_still_enriches_an_ordinary_non_canonical_human_default_doc(): void
    {
        $doc = $this->doc(['is_canonical' => false, 'generation_source' => 'human']);
        $ai = $this->aiReturning([
            'tags' => ['cache'], 'summary' => 's', 'aliases' => [], 'cross_references' => [],
        ]);

        $result = (new AutoWikiCompiler($ai, $this->searchEmpty()))->compile($doc);

        $this->assertTrue($result['applied']);
        $this->assertSame('auto', $doc->fresh()->generation_source);
    }

    /**
     * Copilot PR #494 round 10 (must-fix) — compile()'s firewall check
     * runs BEFORE the (potentially slow) LLM call, against a snapshot
     * that can go stale: a human can approve the exact same document
     * while that call is in flight. Simulate this by having the mocked
     * chat() call itself perform "the concurrent approval" — updating
     * the DB directly and writing its own audit row, bypassing the
     * in-memory $doc instance entirely, exactly like the round-9
     * WikiExplorerService::promote() race test. apply() must re-check
     * the CURRENT row state inside its own lock and abort rather than
     * flip the now-human row back to 'auto'.
     */
    public function test_aborts_the_write_when_a_human_approves_while_the_llm_call_is_in_flight(): void
    {
        $doc = $this->doc([
            'is_canonical' => false,
            'generation_source' => 'auto',
            'metadata' => ['converter' => ['provenance' => 'ocr']],
        ]);

        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('chat')->once()->andReturnUsing(function () use ($doc): AiResponse {
            \Illuminate\Support\Facades\DB::table('knowledge_documents')
                ->where('id', $doc->id)
                ->update(['generation_source' => 'human']);
            \App\Models\KbCanonicalAudit::create([
                'tenant_id' => 'default',
                'project_key' => (string) $doc->project_key,
                'doc_id' => $doc->doc_id,
                'slug' => $doc->slug,
                'event_type' => 'promoted',
                'actor' => 'user:2',
                'before_json' => ['generation_source' => 'auto'],
                'after_json' => ['generation_source' => 'human'],
                'metadata_json' => ['source' => 'concurrent_approval_during_llm_call'],
            ]);

            return new AiResponse(
                content: (string) json_encode(['tags' => ['cache'], 'summary' => 's', 'aliases' => [], 'cross_references' => []]),
                provider: 'fake',
                model: 'fake-x',
            );
        });
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->with(null)->andReturn($provider);

        $result = (new AutoWikiCompiler($ai, $this->searchEmpty()))->compile($doc);

        $this->assertFalse($result['applied'], 'a human approval that lands during the LLM call must not be undone');
        $this->assertSame('human_curated', $result['reason']);
        $this->assertSame('human', $doc->fresh()->generation_source, 'the concurrent approval must survive apply()');
        // exactly the concurrent approval's audit row — no autowiki 'updated' row
        $this->assertDatabaseCount('kb_canonical_audit', 1);
    }

    /**
     * Copilot PR #494 round 11 (must-fix) — save() returns false when a model
     * event vetoes the write. Before this fix, the ignored return value let
     * apply()'s transaction still write the 'updated' AutoWiki audit row and
     * compile() report applied=true, while frontmatter_json/generation_source
     * stayed untouched on disk. A `saving` listener scoped to THIS document's
     * id simulates the veto; the whole transaction must roll back, so neither
     * the enrichment nor its audit row survives, and compile() must surface
     * the failure through the same best-effort exception envelope as any
     * other apply()-time exception rather than a false applied:true.
     */
    public function test_compile_writes_nothing_when_the_save_is_vetoed(): void
    {
        $doc = $this->doc(['is_canonical' => false, 'generation_source' => 'auto']);

        KnowledgeDocument::saving(fn (KnowledgeDocument $model): bool => $model->getKey() !== $doc->id);

        $ai = $this->aiReturning(['tags' => ['cache'], 'summary' => 's', 'aliases' => [], 'cross_references' => []]);

        $thrown = null;
        try {
            try {
                (new AutoWikiCompiler($ai, $this->searchEmpty()))->compile($doc);
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }
        } finally {
            \Illuminate\Support\Facades\Event::forget('eloquent.saving: '.KnowledgeDocument::class);
        }

        $this->assertNotNull($thrown, 'a vetoed save must surface as a thrown exception, not a silent applied:true');
        $doc->refresh();
        $this->assertNull($doc->frontmatter_json, 'a vetoed save must leave frontmatter_json untouched');
        $this->assertSame('auto', $doc->generation_source, 'a vetoed save must leave generation_source untouched');
        $this->assertDatabaseCount('kb_canonical_audit', 0);
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
