<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Ocr;

use App\Services\Kb\Converters\OcrConverter;
use App\Services\Kb\Converters\PdfConverter;
use App\Services\Kb\Ocr\Drivers\FakeOcrDriver;
use App\Services\Kb\Ocr\OcrFigureStore;
use App\Services\Kb\Ocr\OcrService;
use App\Services\Kb\Ocr\OcrLimitExceededException;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Support\Kb\SourceType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Pdf\PdfFixtureBuilder;
use Tests\TestCase;

/**
 * ADR 0029 §4 — bounded work before any driver runs (page/byte caps, endpoint
 * allow-list) and the recorded-run reuse that keeps identical bytes free.
 */
final class OcrLimitsAndReuseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
        config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'fake', 'kb.sources.disk' => 'kb', 'kb.sources.path_prefix' => '']);
    }

    private function image(string $path = 'docs/scan.png', ?string $bytes = null, array $metadata = []): SourceDocument
    {
        return new SourceDocument(
            sourcePath: $path,
            mimeType: 'image/png',
            bytes: $bytes ?? (string) base64_decode(FakeOcrDriver::PNG_1X1, true),
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: $metadata,
        );
    }

    #[Test]
    public function a_document_over_the_byte_cap_is_refused_before_the_driver_runs(): void
    {
        config(['kb.ocr.max_bytes' => 10]);

        try {
            $this->app->make(OcrConverter::class)->convert($this->image());
            $this->fail('expected the byte cap to refuse the document');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('too_many_bytes', $e->reason);
        }
        $this->assertFalse(Storage::disk('kb')->directoryExists('docs/scan.png.ocr'), 'nothing may be written for a refused document');
    }

    #[Test]
    public function a_scanned_pdf_over_the_page_cap_is_refused_before_the_driver_runs(): void
    {
        config(['kb.ocr.max_pages' => 2]);
        $pdf = new SourceDocument(
            sourcePath: 'docs/long.pdf',
            mimeType: 'application/pdf',
            bytes: PdfFixtureBuilder::build(['  ', ' ', '   ']),
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );

        $this->expectException(OcrLimitExceededException::class);
        $this->expectExceptionMessage('3 pages exceed KB_OCR_MAX_PAGES (2)');
        $this->app->make(PdfConverter::class)->convert($pdf);
    }

    #[Test]
    public function identical_bytes_reuse_the_recorded_run_without_a_second_driver_call(): void
    {
        config(['kb.ocr.fake.pages' => [['markdown' => 'Alpha', 'confidence' => 0.8, 'figures' => 1]]]);
        $converter = $this->app->make(OcrConverter::class);

        $first = $converter->convert($this->image());
        $this->assertFalse($first->extractionMeta['ocr']['reused']);
        $run = $first->extractionMeta['ocr']['run'];
        Storage::disk('kb')->assertExists("docs/scan.png.ocr/{$run}/result.json");

        // Change what the driver WOULD say: a reused run must not see it.
        config(['kb.ocr.fake.pages' => [['markdown' => 'Beta (a second run would say this)', 'confidence' => 0.1]]]);
        $second = $converter->convert($this->image());

        $this->assertTrue($second->extractionMeta['ocr']['reused']);
        $this->assertSame($first->markdown, $second->markdown);
        $this->assertSame(0.8, $second->extractionMeta['ocr']['pages'][0]['confidence']);
        $this->assertSame($first->mediaItems, $second->mediaItems);
    }

    #[Test]
    public function a_forced_rerun_bypasses_the_recorded_run(): void
    {
        config(['kb.ocr.fake.pages' => [['markdown' => 'Alpha', 'confidence' => 0.8]]]);
        $converter = $this->app->make(OcrConverter::class);
        $converter->convert($this->image());

        config(['kb.ocr.fake.pages' => [['markdown' => 'Beta', 'confidence' => 0.2]]]);
        $forced = $converter->convert($this->image(metadata: ['ocr' => ['force' => true]]));

        $this->assertFalse($forced->extractionMeta['ocr']['reused']);
        $this->assertStringContainsString('Beta', $forced->markdown);
    }

    #[Test]
    public function reuse_off_runs_the_driver_every_time_and_records_nothing(): void
    {
        config(['kb.ocr.reuse.enabled' => false, 'kb.ocr.fake.pages' => [['markdown' => 'Alpha', 'confidence' => 0.8]]]);
        $converter = $this->app->make(OcrConverter::class);
        $first = $converter->convert($this->image());
        $run = $first->extractionMeta['ocr']['run'];
        Storage::disk('kb')->assertMissing("docs/scan.png.ocr/{$run}/result.json");

        config(['kb.ocr.fake.pages' => [['markdown' => 'Beta', 'confidence' => 0.2]]]);
        $second = $converter->convert($this->image());

        $this->assertFalse($second->extractionMeta['ocr']['reused']);
        $this->assertStringContainsString('Beta', $second->markdown);
    }

    #[Test]
    public function a_recorded_run_whose_figure_went_missing_is_redone(): void
    {
        config(['kb.ocr.fake.pages' => [['markdown' => 'Alpha', 'confidence' => 0.8, 'figures' => 1]]]);
        $converter = $this->app->make(OcrConverter::class);
        $first = $converter->convert($this->image());
        $run = $first->extractionMeta['ocr']['run'];
        Storage::disk('kb')->delete("docs/scan.png.ocr/{$run}/images/fig-1-1.png");

        $again = $converter->convert($this->image());

        $this->assertFalse($again->extractionMeta['ocr']['reused']);
        Storage::disk('kb')->assertExists("docs/scan.png.ocr/{$run}/images/fig-1-1.png");
    }

    #[Test]
    public function a_forced_rerun_is_a_new_immutable_run_and_never_rewrites_the_recorded_one(): void
    {
        config(['kb.ocr.fake.pages' => [['markdown' => 'Alpha', 'figures' => 1]]]);
        $converter = $this->app->make(OcrConverter::class);
        $first = $converter->convert($this->image());
        $firstRun = (string) $first->extractionMeta['ocr']['run'];
        $firstFiles = collect(Storage::disk('kb')->allFiles("docs/scan.png.ocr/{$firstRun}"))->sort()->values()->all();
        $this->assertNotSame([], $firstFiles);

        config(['kb.ocr.fake.pages' => [['markdown' => 'Beta', 'figures' => 1]]]);
        $forced = $converter->convert($this->image(metadata: ['ocr' => ['force' => true]]));
        $forcedRun = (string) $forced->extractionMeta['ocr']['run'];

        $this->assertNotSame($firstRun, $forcedRun, 'a forced execution is a new attempt with its own run identity');
        $this->assertSame($firstFiles, collect(Storage::disk('kb')->allFiles("docs/scan.png.ocr/{$firstRun}"))->sort()->values()->all(), 'the recorded run is immutable');
        $this->assertTrue(Storage::disk('kb')->directoryExists("docs/scan.png.ocr/{$forcedRun}"));

        // A second forced execution is yet another attempt.
        $again = $converter->convert($this->image(metadata: ['ocr' => ['force' => true]]));
        $this->assertNotSame($forcedRun, (string) $again->extractionMeta['ocr']['run']);
    }

    /**
     * A run directory's first write is reserved atomically: a worker that
     * finds the reservation held waits, and past the wait it lets the job
     * retry — it never calls the driver nor meters while another worker is
     * writing the same run.
     */
    #[Test]
    public function a_concurrent_first_run_waits_on_the_reservation_and_never_bills_twice(): void
    {
        config(['kb.ocr.run_lock.wait_seconds' => 1]);
        $doc = $this->image();
        $runKey = OcrFigureStore::runKeyFor($doc->bytes, 'fake', 'fake;figures=1');
        $runDir = $this->app->make(OcrFigureStore::class)->runDirFor($doc->sourcePath, '', $runKey);
        $other = \Illuminate\Support\Facades\Cache::lock(OcrService::runLockKey('kb', $runDir), 60);
        $this->assertTrue($other->get(), 'simulate another worker holding the reservation');

        try {
            $this->app->make(OcrConverter::class)->convert($doc);
            $this->fail('expected the waiting worker to give up and let the job retry');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already in progress', $e->getMessage());
        } finally {
            $other->release();
        }
        // Nothing written, nothing recorded: the waiting worker never reached the driver.
        $this->assertFalse(Storage::disk('kb')->directoryExists("docs/scan.png.ocr/{$runKey}"), 'nothing written by the waiting worker');

        // Once the first worker recorded the run, the reservation is free and
        // the next worker reuses the run instead of paying again.
        $first = $this->app->make(OcrConverter::class)->convert($doc);
        $this->assertFalse((bool) $first->extractionMeta['ocr']['reused']);
        $this->assertTrue(\Illuminate\Support\Facades\Cache::lock(OcrService::runLockKey('kb', $runDir), 1)->get(), 'the reservation is released after the run');
        \Illuminate\Support\Facades\Cache::lock(OcrService::runLockKey('kb', $runDir), 1)->forceRelease();
        $second = $this->app->make(OcrConverter::class)->convert($doc);
        $this->assertTrue((bool) $second->extractionMeta['ocr']['reused'], 'the recorded run is reused: no second driver call');
    }

    #[Test]
    public function the_limits_apply_even_when_ocr_is_forced(): void
    {
        config(['kb.ocr.max_bytes' => 10]);

        $this->expectException(OcrLimitExceededException::class);
        $this->app->make(OcrConverter::class)->convert($this->image(metadata: ['ocr' => ['force' => true]]));
    }

    #[Test]
    public function a_recorded_run_by_another_driver_is_not_reused(): void
    {
        $converter = $this->app->make(OcrConverter::class);
        $converter->convert($this->image());
        $run = OcrFigureStore::runKeyFor((string) base64_decode(FakeOcrDriver::PNG_1X1, true), 'fake', 'fake;figures=1');
        $path = "docs/scan.png.ocr/{$run}/result.json";
        $recorded = json_decode((string) Storage::disk('kb')->get($path), true);
        $recorded['driver'] = 'tesseract';
        Storage::disk('kb')->put($path, (string) json_encode($recorded));

        $again = $converter->convert($this->image());

        $this->assertFalse($again->extractionMeta['ocr']['reused']);
    }

    #[Test]
    public function mistral_refuses_a_host_outside_the_allow_list_before_sending_anything(): void
    {
        Http::fake();
        config([
            'kb.ocr.driver' => 'mistral-ocr',
            'kb.ocr.allow_remote' => true,
            'kb.ocr.mistral.api_key' => 'k',
            'kb.ocr.mistral.url' => 'https://evil.example.test/v1/ocr',
        ]);

        try {
            $this->app->make(OcrConverter::class)->convert($this->image());
            $this->fail('expected the endpoint allow-list to refuse');
        } catch (\App\Services\Kb\Ocr\OcrDriverUnavailableException $e) {
            $this->assertStringContainsString('evil.example.test', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    #[Test]
    public function mistral_refuses_a_non_json_response(): void
    {
        Http::fake(['https://api.mistral.eu/*' => Http::response('<html>oops</html>', 200, ['Content-Type' => 'text/html'])]);
        config(['kb.ocr.driver' => 'mistral-ocr', 'kb.ocr.allow_remote' => true, 'kb.ocr.mistral.api_key' => 'k']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not JSON');
        $this->app->make(OcrConverter::class)->convert($this->image());
    }

    /**
     * ADR 0029 §4 — a `/Type /Page` floor is not a cap input: a PDF the
     * parser cannot read has no verified page count, so a remote driver
     * never receives it. Refused before any byte leaves.
     */
    #[Test]
    public function a_remote_driver_refuses_a_pdf_whose_page_count_cannot_be_verified_before_egress(): void
    {
        Http::fake();
        config(['kb.ocr.driver' => 'mistral-ocr', 'kb.ocr.allow_remote' => true, 'kb.ocr.mistral.api_key' => 'k', 'kb.ocr.max_pages' => 200]);
        $doc = new SourceDocument(
            sourcePath: 'docs/unparseable.pdf',
            mimeType: 'application/pdf',
            bytes: '%PDF-1.4 not really a pdf',
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );

        try {
            $this->app->make(OcrService::class)->convert($doc, 'pdf-converter', 'scanned_pdf');
            $this->fail('expected the uncountable PDF to be refused before egress');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('pages_uncountable', $e->reason);
            $this->assertStringContainsString('mistral-ocr', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    /** The same document through a local driver still runs: nothing leaves and the byte cap bounds the work. */
    #[Test]
    public function a_local_driver_still_runs_on_a_pdf_whose_page_count_is_only_a_floor(): void
    {
        config(['kb.ocr.driver' => 'fake', 'kb.ocr.max_pages' => 200]);
        $doc = new SourceDocument(
            sourcePath: 'docs/unparseable.pdf',
            mimeType: 'application/pdf',
            bytes: '%PDF-1.4 not really a pdf',
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );

        $converted = $this->app->make(OcrService::class)->convert($doc, 'pdf-converter', 'scanned_pdf');

        $this->assertSame('fake', $converted->extractionMeta['ocr']['driver']);
        $this->assertSame(['pages' => 1, 'exact' => false], $this->app->make(OcrService::class)->pageCountDetailFor('application/pdf', $doc->bytes));
    }

    #[Test]
    public function the_run_key_changes_with_the_engine_so_another_driver_never_overwrites_a_run(): void
    {
        $bytes = 'same bytes';
        $this->assertNotSame(
            OcrFigureStore::runKeyFor($bytes, 'fake', 'fake'),
            OcrFigureStore::runKeyFor($bytes, 'tesseract', 'lang=eng;dpi=200'),
        );
        $this->assertNotSame(
            OcrFigureStore::runKeyFor($bytes, 'vision-llm', 'model=a'),
            OcrFigureStore::runKeyFor($bytes, 'vision-llm', 'model=b'),
        );
        $this->assertSame(
            OcrFigureStore::runKeyFor($bytes, 'fake', 'fake'),
            OcrFigureStore::runKeyFor($bytes, 'fake', 'fake'),
        );
    }

    #[Test]
    public function a_multi_page_tiff_counts_every_frame_against_the_page_cap(): void
    {
        config(['kb.ocr.max_pages' => 2]);
        $tiff = $this->tiffWithFrames(3);
        $this->assertSame(3, \App\Services\Kb\Ocr\TiffFrameCounter::count($tiff));
        $this->assertSame(1, \App\Services\Kb\Ocr\TiffFrameCounter::count('not a tiff at all'));

        $doc = new SourceDocument(
            sourcePath: 'docs/multi.tiff',
            mimeType: 'image/tiff',
            bytes: $tiff,
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );

        $this->expectException(OcrLimitExceededException::class);
        $this->expectExceptionMessage('3 pages exceed KB_OCR_MAX_PAGES (2)');
        $this->app->make(OcrConverter::class)->convert($doc);
    }

    #[Test]
    public function the_page_cap_sniffs_a_tiff_carried_under_the_family_image_mime(): void
    {
        // Uploads and `knowledge_documents` record `image/png` for every
        // raster, so the cap must count frames from the bytes, not the MIME —
        // otherwise a 3-frame TIFF would slip through as "1 page".
        config(['kb.ocr.max_pages' => 2]);

        $doc = new SourceDocument(
            sourcePath: 'docs/multi.tiff',
            mimeType: 'image/png',
            bytes: $this->tiffWithFrames(3),
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );

        $this->expectException(OcrLimitExceededException::class);
        $this->expectExceptionMessage('3 pages exceed KB_OCR_MAX_PAGES (2)');
        $this->app->make(OcrConverter::class)->convert($doc);
    }

    /** Minimal little-endian TIFF with N empty IFDs chained together. */
    private function tiffWithFrames(int $frames): string
    {
        $header = 'II'.pack('v', 42).pack('V', 8);
        $ifds = '';
        for ($i = 0; $i < $frames; $i++) {
            $offset = 8 + strlen($ifds);
            $next = $i === $frames - 1 ? 0 : $offset + 2 + 12 + 4;
            // one dummy entry (ImageWidth = 1) so the IFD is well-formed
            $ifds .= pack('v', 1).pack('v', 256).pack('v', 3).pack('V', 1).pack('V', 1).pack('V', $next);
        }

        return $header.$ifds;
    }

    #[Test]
    public function image_mimes_config_matches_source_type(): void
    {
        $this->assertSame(config('kb.ocr.image_mimes'), SourceType::imageMimes());
    }
}
