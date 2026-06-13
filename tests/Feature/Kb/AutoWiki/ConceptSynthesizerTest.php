<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Ai\AiManager;
use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Models\KnowledgeDocument;
use App\Services\Kb\AutoWiki\ConceptSynthesizer;
use App\Services\Kb\DocumentIngestor;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * v8.11/P3 — ConceptSynthesizer: detect recurring concepts across a project and
 * synthesize auto-tier domain-concept pages, deduped + capped + gated.
 */
final class ConceptSynthesizerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{path: string, md: string}> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        Storage::fake('kb');
        config(['kb.sources.disk' => 'kb', 'kb.sources.path_prefix' => '']);
    }

    /** @param list<string> $tags */
    private function doc(array $tags, string $tenant = 'default', string $project = 'docs-v3', ?string $slug = null): KnowledgeDocument
    {
        static $n = 0;
        $n++;

        return KnowledgeDocument::create([
            'tenant_id' => $tenant,
            'project_key' => $project,
            'source_type' => 'markdown',
            'title' => "Doc {$n}",
            'source_path' => "docs/c-{$tenant}-{$n}.md",
            'mime_type' => 'text/markdown',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => 'ver'.$tenant.$n,
            'is_canonical' => $slug !== null,
            'slug' => $slug,
            'frontmatter_json' => ['_autowiki' => ['tags' => $tags, 'summary' => 'About '.implode(',', $tags)]],
        ]);
    }

    private function aiReturning(array $json): AiManager
    {
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('chat')->andReturn(new AiResponse(
            content: (string) json_encode($json), provider: 'fake', model: 'fake-x',
        ));
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->andReturn($provider);

        return $ai;
    }

    private function ingestorCapturing(): DocumentIngestor
    {
        $this->captured = [];
        $ingestor = Mockery::mock(DocumentIngestor::class);
        $ingestor->shouldReceive('ingestMarkdown')->andReturnUsing(
            function (string $projectKey, string $sourcePath, string $title, string $markdown, array $metadata = []) {
                $this->captured[] = ['path' => $sourcePath, 'md' => $markdown];
                // Derive the slug from the frontmatter so the returned doc is realistic.
                preg_match('/slug:\s*(\S+)/', $markdown, $m);

                return KnowledgeDocument::create([
                    'tenant_id' => 'default', 'project_key' => $projectKey, 'source_type' => 'markdown',
                    'title' => $title, 'source_path' => $sourcePath, 'mime_type' => 'text/markdown',
                    'status' => 'active', 'document_hash' => str_repeat('b', 64),
                    'version_hash' => 'cv'.($m[1] ?? 'x'), 'is_canonical' => true,
                    'slug' => $m[1] ?? null, 'canonical_type' => 'domain-concept', 'generation_source' => 'auto',
                ]);
            });

        return $ingestor;
    }

    private function synthesizer(AiManager $ai, DocumentIngestor $ingestor): ConceptSynthesizer
    {
        return new ConceptSynthesizer($ai, $ingestor, app(TenantContext::class));
    }

    public function test_synthesizes_a_recurring_concept_into_the_auto_tier(): void
    {
        // 'cache' appears in 3 docs (>= default min_frequency 3).
        $this->doc(['cache', 'eviction']);
        $this->doc(['cache']);
        $this->doc(['cache', 'redis']);

        $ai = $this->aiReturning(['title' => 'Cache', 'summary' => 'Caching overview.', 'body' => 'Cache body.']);
        $ingestor = $this->ingestorCapturing();

        $result = $this->synthesizer($ai, $ingestor)->synthesize('default', 'docs-v3');

        $this->assertTrue($result['ran']);
        $this->assertContains('auto-cache', $result['created']);

        // The synthesized markdown is auto-tier canonical domain-concept.
        $md = $this->captured[0]['md'];
        $this->assertStringContainsString('type: domain-concept', $md);
        $this->assertStringContainsString('generation_source: auto', $md);
        $this->assertStringContainsString('slug: auto-cache', $md);

        // Written to the KB disk for reconstructability.
        Storage::disk('kb')->assertExists('domain-concepts/auto-cache.md');

        // Audited.
        $this->assertDatabaseHas('kb_canonical_audit', [
            'project_key' => 'docs-v3', 'event_type' => 'promoted', 'actor' => 'system:autowiki', 'slug' => 'auto-cache',
        ]);
    }

    public function test_below_frequency_threshold_is_not_a_candidate(): void
    {
        $this->doc(['rare']);
        $this->doc(['rare']); // only 2 < min 3

        $ingestor = $this->ingestorCapturing();
        $result = $this->synthesizer($this->aiReturning(['title' => 'x', 'summary' => 's', 'body' => 'b']), $ingestor)
            ->synthesize('default', 'docs-v3');

        $this->assertSame([], $result['created']);
        $this->assertSame([], $this->captured);
    }

    public function test_existing_concept_page_is_skipped(): void
    {
        $this->doc(['cache']);
        $this->doc(['cache']);
        $this->doc(['cache']);
        // A page already owns the auto slug.
        $this->doc(['cache'], slug: 'auto-cache');

        $ingestor = $this->ingestorCapturing();
        $result = $this->synthesizer($this->aiReturning(['title' => 'x', 'summary' => 's', 'body' => 'b']), $ingestor)
            ->synthesize('default', 'docs-v3');

        $this->assertContains('auto-cache', $result['skipped']);
        $this->assertSame([], $this->captured);
    }

    public function test_disabled_flag_is_a_clean_noop(): void
    {
        config(['kb.autowiki.concepts_enabled' => false]);
        $this->doc(['cache']);
        $this->doc(['cache']);
        $this->doc(['cache']);

        $ingestor = $this->ingestorCapturing();
        $result = $this->synthesizer($this->aiReturning(['title' => 'x', 'summary' => 's', 'body' => 'b']), $ingestor)
            ->synthesize('default', 'docs-v3');

        $this->assertFalse($result['ran']);
        $this->assertSame('disabled', $result['reason']);
        $this->assertSame([], $this->captured);
    }

    public function test_respects_the_per_run_cap(): void
    {
        // Two distinct recurring concepts, each in 3 docs.
        foreach (['alpha', 'beta'] as $c) {
            $this->doc([$c]);
            $this->doc([$c]);
            $this->doc([$c]);
        }

        $ingestor = $this->ingestorCapturing();
        $result = $this->synthesizer($this->aiReturning(['title' => 'x', 'summary' => 's', 'body' => 'b']), $ingestor)
            ->synthesize('default', 'docs-v3', 1);

        $this->assertSame(2, $result['candidates']);
        $this->assertCount(1, $result['created']);
        $this->assertCount(1, $this->captured);
    }

    public function test_sweep_is_tenant_scoped(): void
    {
        // 'cache' recurs in tenant 'other' but the sweep targets 'default'.
        $this->doc(['cache'], tenant: 'other');
        $this->doc(['cache'], tenant: 'other');
        $this->doc(['cache'], tenant: 'other');

        $ingestor = $this->ingestorCapturing();
        $result = $this->synthesizer($this->aiReturning(['title' => 'x', 'summary' => 's', 'body' => 'b']), $ingestor)
            ->synthesize('default', 'docs-v3');

        $this->assertSame(0, $result['candidates']);
        $this->assertSame([], $this->captured);
    }
}
