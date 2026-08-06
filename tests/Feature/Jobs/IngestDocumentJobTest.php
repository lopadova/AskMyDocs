<?php

namespace Tests\Feature\Jobs;

use App\Jobs\AnalyzeDocumentChangeJob;
use App\Jobs\AutoWikiCompilerJob;
use App\Jobs\IngestDocumentJob;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class IngestDocumentJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kb.embedding_cache.enabled', false);
        config()->set('ai.default', 'openai');
        config()->set('ai.embeddings_provider', 'openai');

        Http::fake([
            'api.openai.com/*' => function ($request) {
                $inputs = $request->data()['input'] ?? [];
                $data = [];
                foreach ($inputs as $i => $_text) {
                    $data[] = ['index' => $i, 'embedding' => [0.1, 0.2, 0.3]];
                }

                return Http::response([
                    'model' => 'text-embedding-3-small',
                    'data' => $data,
                    'usage' => ['total_tokens' => count($inputs)],
                ], 200);
            },
        ]);
    }

    public function test_handle_reads_from_disk_and_stores_document(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/hello.md', "# Hello\n\nBody paragraph.");
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');

        $job = new IngestDocumentJob(
            projectKey: 'demo',
            relativePath: 'docs/hello.md',
            disk: 'kb',
            title: 'Hello Doc',
        );

        $this->app->call([$job, 'handle']);

        $doc = KnowledgeDocument::first();
        $this->assertNotNull($doc);
        $this->assertSame('demo', $doc->project_key);
        $this->assertSame('Hello Doc', $doc->title);
        $this->assertSame('docs/hello.md', $doc->source_path);
    }

    public function test_handle_throws_when_file_is_missing_so_queue_retries(): void
    {
        Storage::fake('kb');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');

        $job = new IngestDocumentJob(
            projectKey: 'demo',
            relativePath: 'missing.md',
            disk: 'kb',
        );

        $this->expectException(RuntimeException::class);
        $this->app->call([$job, 'handle']);
    }

    public function test_handle_respects_configured_path_prefix(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('tenant-a/docs/guide.md', "# Guide\n\nBody.");
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', 'tenant-a/');

        $job = new IngestDocumentJob(
            projectKey: 'tenant-a',
            relativePath: 'docs/guide.md',
            disk: 'kb',
        );

        $this->app->call([$job, 'handle']);

        $this->assertSame(1, KnowledgeDocument::count());
    }

    public function test_metadata_is_forwarded_to_ingestor(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/x.md', 'body');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');

        $job = new IngestDocumentJob(
            projectKey: 'demo',
            relativePath: 'docs/x.md',
            disk: 'kb',
            metadata: ['language' => 'en', 'author' => 'alice'],
        );

        $this->app->call([$job, 'handle']);

        $doc = KnowledgeDocument::first();
        $this->assertSame('en', $doc->language);
        $this->assertSame('alice', $doc->metadata['author'] ?? null);
        $this->assertSame('kb', $doc->metadata['disk'] ?? null);
    }

    public function test_generated_email_fixture_skips_post_ingest_ai_jobs(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('emails/generated.md', "# Generated email\n\nSynthetic fixture body.");
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('kb.change_analysis.enabled', true);
        config()->set('kb.autowiki.enabled', true);
        Queue::fake([
            AnalyzeDocumentChangeJob::class,
            AutoWikiCompilerJob::class,
        ]);

        $job = new IngestDocumentJob(
            projectKey: 'demo',
            relativePath: 'emails/generated.md',
            disk: 'kb',
            metadata: ['generated_fixture' => true],
        );

        $this->app->call([$job, 'handle']);

        $doc = KnowledgeDocument::first();
        $this->assertNotNull($doc, 'Generated fixtures must still complete normal ingestion.');
        $this->assertFalse((bool) $doc->is_canonical);
        $this->assertTrue($doc->metadata['generated_fixture'] ?? false);
        Queue::assertNotPushed(AnalyzeDocumentChangeJob::class);
        Queue::assertNotPushed(AutoWikiCompilerJob::class);
    }

    #[DataProvider('ordinaryDocumentProvider')]
    public function test_ordinary_documents_keep_post_ingest_ai_dispatch_for_both_canonical_states(
        string $path,
        string $markdown,
        bool $expectedCanonical,
    ): void {
        Storage::fake('kb');
        Storage::disk('kb')->put($path, $markdown);
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('kb.change_analysis.enabled', true);
        config()->set('kb.autowiki.enabled', true);
        Queue::fake([
            AnalyzeDocumentChangeJob::class,
            AutoWikiCompilerJob::class,
        ]);

        $job = new IngestDocumentJob(
            projectKey: 'demo',
            relativePath: $path,
            disk: 'kb',
        );

        $this->app->call([$job, 'handle']);

        $doc = KnowledgeDocument::firstOrFail();
        $this->assertSame($expectedCanonical, (bool) $doc->is_canonical);
        Queue::assertPushed(
            AnalyzeDocumentChangeJob::class,
            fn (AnalyzeDocumentChangeJob $queued): bool => $queued->documentId === $doc->id
                && $queued->tenantId === 'default',
        );
        Queue::assertPushed(
            AutoWikiCompilerJob::class,
            fn (AutoWikiCompilerJob $queued): bool => $queued->documentId === $doc->id
                && $queued->tenantId === 'default',
        );
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function ordinaryDocumentProvider(): array
    {
        return [
            'non-canonical document' => [
                'docs/ordinary.md',
                "# Ordinary document\n\nBody.",
                false,
            ],
            'canonical document' => [
                'docs/canonical.md',
                <<<'MD'
---
id: DEC-2026-EMAIL-GATE
slug: dec-email-fixture-ai-gate
type: decision
status: accepted
---
# Canonical document

Body.
MD,
                true,
            ],
        ];
    }

    #[DataProvider('nonBooleanGeneratedFixtureProvider')]
    public function test_generated_fixture_gate_requires_literal_boolean_true(mixed $marker): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('emails/gold.md', "# Gold email\n\nCurated fixture body.");
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('kb.change_analysis.enabled', true);
        config()->set('kb.autowiki.enabled', true);
        Queue::fake([
            AnalyzeDocumentChangeJob::class,
            AutoWikiCompilerJob::class,
        ]);

        $job = new IngestDocumentJob(
            projectKey: 'demo',
            relativePath: 'emails/gold.md',
            disk: 'kb',
            metadata: ['generated_fixture' => $marker],
        );

        $this->app->call([$job, 'handle']);

        $doc = KnowledgeDocument::firstOrFail();
        Queue::assertPushed(
            AnalyzeDocumentChangeJob::class,
            fn (AnalyzeDocumentChangeJob $queued): bool => $queued->documentId === $doc->id,
        );
        Queue::assertPushed(
            AutoWikiCompilerJob::class,
            fn (AutoWikiCompilerJob $queued): bool => $queued->documentId === $doc->id,
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonBooleanGeneratedFixtureProvider(): array
    {
        return [
            'false keeps gold fixture behaviour' => [false],
            'string true is not accepted' => ['true'],
            'integer one is not accepted' => [1],
            'null is not accepted' => [null],
        ];
    }

    public function test_queue_name_comes_from_config(): void
    {
        config()->set('kb.ingest.queue', 'custom-queue-name');

        $job = new IngestDocumentJob(
            projectKey: 'demo',
            relativePath: 'x.md',
            disk: 'kb',
        );

        $this->assertSame('custom-queue-name', $job->queue);
    }
}
