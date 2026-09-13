<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Folder;

use App\Flow\Steps\Folder\DispatchIngestFanOutStep;
use App\Jobs\IngestDocumentJob;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

final class DispatchIngestFanOutStepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
    }

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_dispatches_one_job_per_file(): void
    {
        Queue::fake();
        Storage::disk('kb')->put('docs/a.md', '# a');
        Storage::disk('kb')->put('docs/b.md', '# b');

        $step = $this->app->make(DispatchIngestFanOutStep::class);
        $result = $step->execute($this->context(['docs/a.md', 'docs/b.md']));

        $this->assertSame(2, $result->output['dispatched_count']);
        $this->assertSame(0, $result->output['failure_count']);
        Queue::assertPushed(IngestDocumentJob::class, 2);
    }

    public function test_unsupported_extension_recorded_as_failure_not_thrown(): void
    {
        Queue::fake();
        $step = $this->app->make(DispatchIngestFanOutStep::class);

        $result = $step->execute($this->context(['docs/a.md', 'docs/b.png']));

        $this->assertSame(1, $result->output['dispatched_count']);
        $this->assertSame(1, $result->output['failure_count']);
    }

    /**
     * v8.36 / ADR 0029 — R43: the same `.png` that is a failure with OCR off
     * is dispatched with OCR on (the walker honours the flag).
     */
    public function test_image_is_dispatched_only_when_ocr_is_enabled(): void
    {
        Queue::fake();
        config(['kb.ocr.enabled' => true]);
        Storage::disk('kb')->put('docs/b.png', (string) base64_decode(\App\Services\Kb\Ocr\Drivers\FakeOcrDriver::PNG_1X1, true));
        $step = $this->app->make(DispatchIngestFanOutStep::class);

        $result = $step->execute($this->context(['docs/a.md', 'docs/b.png']));

        $this->assertSame(2, $result->output['dispatched_count']);
        $this->assertSame(0, $result->output['failure_count']);
        Queue::assertPushed(IngestDocumentJob::class, fn (IngestDocumentJob $job) => $job->relativePath === 'docs/b.png' && $job->mimeType === 'image/png');
    }

    /**
     * ADR 0029 §2 — an image is dispatched with its EXACT raster MIME read
     * from its BYTES, never the family label `image/png` and never the
     * extension: a JPEG named `.png` is `image/jpeg`. The MIME reaches the
     * converter registry and the document row as what the bytes are; bytes
     * that are no known raster are a per-file failure, not a queued job.
     */
    public function test_images_are_dispatched_with_the_raster_mime_of_their_bytes_not_their_extension(): void
    {
        Queue::fake();
        config(['kb.ocr.enabled' => true]);
        $png = (string) base64_decode(\App\Services\Kb\Ocr\Drivers\FakeOcrDriver::PNG_1X1, true);
        $jpeg = "\xFF\xD8\xFF\xE0".str_repeat("\x00", 16);
        $tiff = 'II'.pack('v', 42).pack('V', 8).str_repeat("\x00", 16);
        $webp = 'RIFF'.pack('V', 24).'WEBPVP8 '.str_repeat("\x00", 8);
        $files = [
            'docs/a.jpg' => [$jpeg, 'image/jpeg'],
            'docs/b.jpeg' => [$jpeg, 'image/jpeg'],
            'docs/c.tif' => [$tiff, 'image/tiff'],
            'docs/d.tiff' => [$tiff, 'image/tiff'],
            'docs/e.webp' => [$webp, 'image/webp'],
            'docs/f.PNG' => [$png, 'image/png'],
            // the extension lies: the bytes decide
            'docs/g.png' => [$jpeg, 'image/jpeg'],
        ];
        foreach ($files as $path => [$bytes]) {
            Storage::disk('kb')->put($path, $bytes);
        }
        Storage::disk('kb')->put('docs/h.png', 'not an image at all');
        $step = $this->app->make(DispatchIngestFanOutStep::class);

        $result = $step->execute($this->context([...array_keys($files), 'docs/h.png']));

        $this->assertSame(7, $result->output['dispatched_count']);
        $this->assertSame(1, $result->output['failure_count']);
        $this->assertStringContainsString('unrecognised_bytes', (string) $result->output['failures'][0]['reason']);
        $this->assertSame('docs/h.png', $result->output['failures'][0]['path']);
        foreach ($files as $path => [, $mime]) {
            Queue::assertPushed(IngestDocumentJob::class, fn (IngestDocumentJob $job) => $job->relativePath === $path && $job->mimeType === $mime);
        }
        Queue::assertNotPushed(IngestDocumentJob::class, fn (IngestDocumentJob $job) => $job->relativePath === 'docs/h.png');
    }

    public function test_invalid_path_recorded_as_failure_not_thrown(): void
    {
        Queue::fake();
        $step = $this->app->make(DispatchIngestFanOutStep::class);

        // KbPath::normalize() rejects '..' traversal segments — this MUST
        // surface as a per-file failure so the rest of the batch keeps
        // dispatching.
        $result = $step->execute($this->context(['docs/../escape.md', 'docs/ok.md']));

        $this->assertSame(1, $result->output['dispatched_count']);
        $this->assertSame(1, $result->output['failure_count']);
    }

    public function test_dry_run_skipped(): void
    {
        Queue::fake();
        $step = $this->app->make(DispatchIngestFanOutStep::class);

        $result = $step->execute($this->context(['docs/a.md'], dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        Queue::assertNothingPushed();
    }

    public function test_throws_on_missing_tenant_id(): void
    {
        $step = $this->app->make(DispatchIngestFanOutStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.ingest-folder',
            input: ['project_key' => 'p'],
            stepOutputs: ['list-files' => ['disk' => 'kb', 'matched_files' => []]],
        );

        $this->expectException(FlowInputException::class);
        $step->execute($context);
    }

    public function test_throws_on_missing_project_key(): void
    {
        Queue::fake();
        $step = $this->app->make(DispatchIngestFanOutStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.ingest-folder',
            input: ['tenant_id' => 'default'],
            stepOutputs: ['list-files' => ['disk' => 'kb', 'matched_files' => ['a.md']]],
        );

        $this->expectException(\RuntimeException::class);
        $step->execute($context);
    }

    public function test_dispatch_carries_tenant_id_into_job(): void
    {
        Queue::fake();
        Storage::disk('kb')->put('docs/a.md', '# a');

        $step = $this->app->make(DispatchIngestFanOutStep::class);
        $step->execute($this->context(['docs/a.md'], tenantId: 'tenant-x'));

        Queue::assertPushed(IngestDocumentJob::class, fn ($job): bool => $job->tenantId === 'tenant-x');
    }

    /**
     * @param  list<string>  $files
     */
    private function context(array $files, string $tenantId = 'default', bool $dryRun = false): FlowContext
    {
        return new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.ingest-folder',
            input: [
                'tenant_id' => $tenantId,
                'project_key' => 'p',
                'sync' => false,
                'prefix' => '',
            ],
            stepOutputs: [
                'list-files' => [
                    'disk' => 'kb',
                    'matched_files' => $files,
                ],
            ],
            dryRun: $dryRun,
        );
    }
}
