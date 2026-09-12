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

    /**
     * v8.36 — a deterministic OCR refusal (over the byte/page cap) is failed
     * immediately instead of being retried three times (R14).
     */
    public function test_handle_fails_fast_on_a_deterministic_ocr_refusal(): void
    {
        Storage::fake('kb');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('kb.ocr.enabled', true);
        config()->set('kb.ocr.driver', 'fake');
        config()->set('kb.ocr.max_bytes', 10);
        Storage::disk('kb')->put('scan.png', (string) base64_decode(\App\Services\Kb\Ocr\Drivers\FakeOcrDriver::PNG_1X1, true));

        $job = new IngestDocumentJob(projectKey: 'demo', relativePath: 'scan.png', disk: 'kb', mimeType: 'image/png');
        $queueJob = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->shouldReceive('fail')->once()->withArgs(function (\Throwable $e): bool {
            return $e->getPrevious() instanceof \App\Services\Kb\Ocr\OcrLimitExceededException
                && str_contains($e->getMessage(), 'KB_OCR_MAX_BYTES');
        });
        $queueJob->shouldReceive('isReleased')->andReturn(false)->byDefault();
        $queueJob->shouldReceive('hasFailed')->andReturn(false)->byDefault();
        $job->setJob($queueJob);

        // No exception escapes: the job is failed, not re-queued.
        $this->app->call([$job, 'handle']);

        $this->assertSame(0, KnowledgeDocument::query()->where('source_path', 'scan.png')->count());
    }

    public function test_a_refusal_without_a_queue_job_surfaces_as_an_exception(): void
    {
        Storage::fake('kb');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('kb.ocr.enabled', true);
        config()->set('kb.ocr.driver', 'fake');
        config()->set('kb.ocr.max_bytes', 10);
        Storage::disk('kb')->put('scan.png', (string) base64_decode(\App\Services\Kb\Ocr\Drivers\FakeOcrDriver::PNG_1X1, true));

        $job = new IngestDocumentJob(projectKey: 'demo', relativePath: 'scan.png', disk: 'kb', mimeType: 'image/png');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('KB_OCR_MAX_BYTES');
        $this->app->call([$job, 'handle']);
    }

    /**
     * v8.36 — the re-run lock a forced OCR re-run carries is released on the
     * terminal outcome only: after success, and in failed(); never while a
     * retry is still queued (R21).
     */
    public function test_rerun_lock_is_released_after_success_but_kept_while_a_retry_is_pending(): void
    {
        Storage::fake('kb');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        Storage::disk('kb')->put('note.md', "# Note\n\nbody");

        $key = \App\Services\Kb\Ocr\OcrService::rerunLockKey('default', 42);
        $lock = \Illuminate\Support\Facades\Cache::lock($key, 600);
        $this->assertTrue($lock->get());
        $carried = ['ocr' => ['rerun_lock' => ['key' => $key, 'owner' => $lock->owner()]]];

        // A failing attempt with retries left keeps the lock.
        $failing = new IngestDocumentJob(projectKey: 'demo', relativePath: 'missing.md', disk: 'kb', metadata: $carried);
        try {
            $this->app->call([$failing, 'handle']);
            $this->fail('expected the missing file to throw');
        } catch (RuntimeException) {
        }
        $this->assertFalse(\Illuminate\Support\Facades\Cache::lock($key, 1)->get(), 'the lock must survive a retryable failure');

        // failed() (attempts exhausted) releases it.
        $failing->failed(new RuntimeException('exhausted'));
        $probe = \Illuminate\Support\Facades\Cache::lock($key, 1);
        $this->assertTrue($probe->get(), 'failed() must release the lock');
        $probe->release();

        // A successful run releases it too.
        $lock = \Illuminate\Support\Facades\Cache::lock($key, 600);
        $this->assertTrue($lock->get());
        $ok = new IngestDocumentJob(projectKey: 'demo', relativePath: 'note.md', disk: 'kb', metadata: ['ocr' => ['rerun_lock' => ['key' => $key, 'owner' => $lock->owner()]]]);
        $this->app->call([$ok, 'handle']);
        $this->assertTrue(\Illuminate\Support\Facades\Cache::lock($key, 1)->get(), 'success must release the lock');
    }

    /**
     * The production release path: `$this->fail()` → `Job::fail()` →
     * `failed()` on a fresh instance resolved from the serialized payload.
     * The sync driver runs exactly that chain, so a serialization gap on
     * `metadata.ocr.rerun_lock` would leave the lock held until the TTL.
     */
    public function test_a_refusal_on_a_real_queue_job_reaches_failed_and_releases_the_rerun_lock(): void
    {
        Storage::fake('kb');
        config()->set('queue.default', 'sync');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('kb.ocr.enabled', true);
        config()->set('kb.ocr.driver', 'fake');
        config()->set('kb.ocr.max_bytes', 10);
        Storage::disk('kb')->put('scan.png', (string) base64_decode(\App\Services\Kb\Ocr\Drivers\FakeOcrDriver::PNG_1X1, true));

        $key = \App\Services\Kb\Ocr\OcrService::rerunLockKey('default', 7);
        $lock = \Illuminate\Support\Facades\Cache::lock($key, 600);
        $this->assertTrue($lock->get());

        \Illuminate\Support\Facades\Event::fake([\Illuminate\Queue\Events\JobFailed::class]);
        IngestDocumentJob::dispatchSync(
            projectKey: 'demo',
            relativePath: 'scan.png',
            disk: 'kb',
            mimeType: 'image/png',
            metadata: ['ocr' => ['rerun_lock' => ['key' => $key, 'owner' => $lock->owner()]]],
        );

        \Illuminate\Support\Facades\Event::assertDispatched(\Illuminate\Queue\Events\JobFailed::class, function (\Illuminate\Queue\Events\JobFailed $event): bool {
            return str_contains($event->exception->getMessage(), 'KB_OCR_MAX_BYTES');
        });
        $this->assertTrue(\Illuminate\Support\Facades\Cache::lock($key, 1)->get(), 'failed() on the real queue path must release the lock');
        $this->assertSame(0, KnowledgeDocument::query()->where('source_path', 'scan.png')->count());
    }

    /**
     * A lock that lapsed while the job waited is re-armed at attempt start;
     * one that a NEWER re-run took over makes the older job fail instead of
     * producing a duplicate paid run.
     */
    public function test_rerun_lock_is_rearmed_at_attempt_start_and_a_superseded_job_fails(): void
    {
        Storage::fake('kb');
        config()->set('queue.default', 'sync');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        Storage::disk('kb')->put('note.md', "# Note\n\nbody");

        $key = \App\Services\Kb\Ocr\OcrService::rerunLockKey('default', 9);

        // Lapsed while queued: nobody holds it, the job re-acquires and runs.
        $lapsed = new IngestDocumentJob(projectKey: 'demo', relativePath: 'note.md', disk: 'kb', metadata: ['ocr' => ['rerun_lock' => ['key' => $key, 'owner' => 'job-a']]]);
        $this->app->call([$lapsed, 'handle']);
        $this->assertSame(1, KnowledgeDocument::query()->where('source_path', 'note.md')->count());
        $this->assertTrue(\Illuminate\Support\Facades\Cache::lock($key, 1)->get(), 'success releases the re-armed lock');
        \Illuminate\Support\Facades\Cache::lock($key, 1)->forceRelease();

        // Taken over by a newer re-run: the older job must not run.
        $newer = \Illuminate\Support\Facades\Cache::lock($key, 600);
        $this->assertTrue($newer->get());
        \Illuminate\Support\Facades\Event::fake([\Illuminate\Queue\Events\JobFailed::class]);
        IngestDocumentJob::dispatchSync(
            projectKey: 'demo',
            relativePath: 'note.md',
            disk: 'kb',
            metadata: ['ocr' => ['rerun_lock' => ['key' => $key, 'owner' => 'job-a']]],
        );
        \Illuminate\Support\Facades\Event::assertDispatched(\Illuminate\Queue\Events\JobFailed::class, function (\Illuminate\Queue\Events\JobFailed $event): bool {
            return str_contains($event->exception->getMessage(), 'superseded by a newer OCR re-run');
        });
        $this->assertFalse(\Illuminate\Support\Facades\Cache::lock($key, 1)->get(), 'the newer owner keeps its lock');
        $newer->release();

        // Bare handle() with a foreign owner throws (no queue job to fail).
        $newer = \Illuminate\Support\Facades\Cache::lock($key, 600);
        $this->assertTrue($newer->get());
        $bare = new IngestDocumentJob(projectKey: 'demo', relativePath: 'note.md', disk: 'kb', metadata: ['ocr' => ['rerun_lock' => ['key' => $key, 'owner' => 'job-a']]]);
        try {
            $this->app->call([$bare, 'handle']);
            $this->fail('expected the superseded job to throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('superseded by a newer OCR re-run', $e->getMessage());
        }
        $newer->release();
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
