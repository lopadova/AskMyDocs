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
        config(['kb.ocr.reuse.enabled' => false, 'kb.ocr.fake.pages' => [['markdown' => 'Alpha', 'confidence' => 0.8, 'figures' => 1]]]);
        $converter = $this->app->make(OcrConverter::class);
        $first = $converter->convert($this->image());
        $run = $first->extractionMeta['ocr']['run'];
        Storage::disk('kb')->assertMissing("docs/scan.png.ocr/{$run}/result.json");
        Storage::disk('kb')->assertExists("docs/scan.png.ocr/{$run}/images/fig-1-1.png");

        config(['kb.ocr.fake.pages' => [['markdown' => 'Beta', 'confidence' => 0.2, 'figures' => 1]]]);
        $second = $converter->convert($this->image());

        $this->assertFalse($second->extractionMeta['ocr']['reused']);
        $this->assertStringContainsString('Beta', $second->markdown);
        // With reuse off every ingest is a NEW run with its own identity
        // (like a forced re-run): the deterministic key would point both
        // ingests at ONE directory and the second would overwrite the
        // figures the first document version still references.
        $secondRun = $second->extractionMeta['ocr']['run'];
        $this->assertNotSame($run, $secondRun, 'reuse off: a fresh attempt identity per ingest');
        Storage::disk('kb')->assertExists("docs/scan.png.ocr/{$run}/images/fig-1-1.png");
        Storage::disk('kb')->assertExists("docs/scan.png.ocr/{$secondRun}/images/fig-1-1.png");
    }

    /**
     * ADR 0029 §4 — the page cap counted every TIFF frame, but a driver that
     * hands the raster to its engine as ONE image would be metered for N
     * pages and transcribe the first: refused before any work, never a
     * silent first frame. `docling` decodes every frame and accepts it.
     */
    #[Test]
    public function a_multi_frame_tiff_is_refused_for_a_driver_that_transcribes_one_frame_per_image(): void
    {
        Http::fake();
        config(['kb.ocr.driver' => 'mistral-ocr', 'kb.ocr.allow_remote' => true, 'kb.ocr.mistral.api_key' => 'k']);
        $this->assertTrue(app(\App\Services\Kb\Ocr\OcrDriverRegistry::class)->refusesMultiFrameImages('mistral-ocr'));
        $this->assertTrue(app(\App\Services\Kb\Ocr\OcrDriverRegistry::class)->refusesMultiFrameImages('tesseract'));
        $this->assertTrue(app(\App\Services\Kb\Ocr\OcrDriverRegistry::class)->refusesMultiFrameImages('vision-llm'));
        $this->assertFalse(app(\App\Services\Kb\Ocr\OcrDriverRegistry::class)->refusesMultiFrameImages('docling'));
        $this->assertTrue(app(\App\Services\Kb\Ocr\OcrDriverRegistry::class)->refusesMultiFrameImages('no-such-driver'), 'unknown → refuse');

        try {
            $this->app->make(OcrConverter::class)->convert($this->image('docs/multi.tiff', $this->tiffWithFrames(3)));
            $this->fail('a multi-frame TIFF must be refused for a one-frame driver');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('multi_frame_image', $e->reason);
            $this->assertStringContainsString('3-frame TIFF', $e->getMessage());
            $this->assertStringContainsString('mistral-ocr', $e->getMessage());
        }
        Http::assertNothingSent();

        // A single-frame TIFF is one page and runs.
        config(['kb.ocr.driver' => 'fake']);
        $one = $this->app->make(OcrConverter::class)->convert($this->image('docs/one.tiff', $this->tiffWithFrames(1)));
        $this->assertSame(1, $one->extractionMeta['page_count']);
    }

    /**
     * ADR 0029 §6 — a purge removes a run only under the run's own
     * reservation: while a worker holds it (its figures verified, its
     * `result.json` about to be re-stamped) the run is kept, whatever its
     * age, so an ingest can never commit references to figures a purge took.
     */
    #[Test]
    public function a_purge_never_removes_a_run_whose_reservation_another_worker_holds(): void
    {
        config(['kb.ocr.fake.pages' => [['markdown' => 'Alpha', 'figures' => 1]]]);
        $first = $this->app->make(OcrConverter::class)->convert($this->image());
        $run = $first->extractionMeta['ocr']['run'];
        $figure = "docs/scan.png.ocr/{$run}/images/fig-1-1.png";
        Storage::disk('kb')->assertExists($figure);
        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();

        $runDir = $this->app->make(OcrFigureStore::class)->runDirFor('docs/scan.png', '', $run);
        $other = \Illuminate\Support\Facades\Cache::lock(OcrService::runLockKey('kb', $runDir), 30);
        $this->assertTrue($other->get(), 'simulate a worker reusing the run under its reservation');
        try {
            $this->assertFalse($this->app->make(OcrFigureStore::class)->purge('kb', 'docs/scan.png'), 'kept: reserved');
            Storage::disk('kb')->assertExists($figure);
        } finally {
            $other->release();
        }

        $this->assertTrue($this->app->make(OcrFigureStore::class)->purge('kb', 'docs/scan.png'), 'released: removed');
        Storage::disk('kb')->assertMissing($figure);
    }

    /**
     * The purge and the converter's write phase share the assets-directory
     * lock: a purge cannot remove the tree while a converter is writing into
     * it (a run that started after the purge enumerated an empty directory
     * would otherwise be deleted with its parent), and a converter waits for
     * a purge in progress instead of writing into a directory being removed.
     */
    #[Test]
    public function the_purge_and_the_write_phase_exclude_each_other_on_the_assets_directory(): void
    {
        config(['kb.ocr.fake.pages' => [['markdown' => 'Alpha', 'confidence' => 0.8, 'figures' => 1]]]);
        $first = $this->app->make(OcrConverter::class)->convert($this->image());
        $run = $first->extractionMeta['ocr']['run'];
        $figure = "docs/scan.png.ocr/{$run}/images/fig-1-1.png";

        $assetsDir = $this->app->make(OcrFigureStore::class)->assetsDirFor('docs/scan.png', '');
        $holder = \Illuminate\Support\Facades\Cache::lock(OcrService::assetsLockKey('kb', $assetsDir), 30);
        $this->assertTrue($holder->get(), 'simulate a purge holding the assets directory');
        try {
            // A converter that must write while a purge holds the directory
            // waits, then gives up loudly (retryable) — it never writes into
            // a directory that is being removed. (Real clock: `block()` is
            // timed on it, a frozen `travel()` clock would never elapse.)
            config(['kb.ocr.assets_lock.wait_seconds' => 1, 'kb.ocr.reuse_enabled' => false]);
            try {
                $this->app->make(OcrConverter::class)->convert($this->image());
                $this->fail('expected the writer to give up on a held assets lock');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('being purged', $e->getMessage());
            }

        } finally {
            $holder->release();
        }

        // The converse, once the run has aged past the grace: the purge
        // finds the assets lock held by a writer and defers. (The lock is
        // taken AFTER the travel: the store's clock moved with it.)
        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();
        $writer = \Illuminate\Support\Facades\Cache::lock(OcrService::assetsLockKey('kb', $assetsDir), 30);
        $this->assertTrue($writer->get(), 'simulate a converter in its write phase');
        try {
            $this->assertFalse($this->app->make(OcrFigureStore::class)->purge('kb', 'docs/scan.png'), 'deferred: a converter is writing');
            Storage::disk('kb')->assertExists($figure);
        } finally {
            $writer->release();
        }

        $this->assertTrue($this->app->make(OcrFigureStore::class)->purge('kb', 'docs/scan.png'), 'released: removed');
        Storage::disk('kb')->assertMissing($figure);
        $this->assertTrue(\Illuminate\Support\Facades\Cache::lock(OcrService::assetsLockKey('kb', $assetsDir), 1)->get(), 'the purge released the assets lock');
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
        $runKey = OcrFigureStore::runKeyFor($doc->bytes, 'fake', OcrService::runVariant('fake', true));
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

    /**
     * ADR 0029 §4 — one pre-egress boundary for every ingress: bytes that do
     * not carry the signature of the declared type never reach a driver,
     * whichever entry point declared the label.
     */
    #[Test]
    public function bytes_that_are_not_the_declared_type_are_refused_before_any_driver_runs(): void
    {
        try {
            $this->app->make(OcrConverter::class)->convert($this->image('docs/fake.png', 'this is not an image at all'));
            $this->fail('expected the byte signature check to refuse the document');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('unrecognised_bytes', $e->reason);
            $this->assertStringContainsString('fake.png', $e->getMessage());
        }
        $this->assertFalse(Storage::disk('kb')->directoryExists('docs/fake.png.ocr'), 'nothing may be written for refused bytes');

        // A PDF header is required where a PDF is declared.
        $pdf = new SourceDocument(sourcePath: 'docs/fake.pdf', mimeType: 'application/pdf', bytes: (string) base64_decode(FakeOcrDriver::PNG_1X1, true), externalUrl: null, externalId: null, connectorType: 'local', metadata: []);
        try {
            $this->app->make(OcrService::class)->convert($pdf, 'ocr', 'forced');
            $this->fail('a PNG declared as a PDF must be refused');
        } catch (OcrLimitExceededException $e) {
            $this->assertSame('unrecognised_bytes', $e->reason);
        }
    }

    /**
     * ADR 0029 §6 — a dry run holds no reference: it shows a recorded run
     * read-only and never touches `.ocr/`, not even to refresh its reservation.
     */
    #[Test]
    public function a_dry_run_shows_a_recorded_run_without_refreshing_its_reservation(): void
    {
        $converter = $this->app->make(OcrConverter::class);
        $converter->convert($this->image());
        $run = OcrFigureStore::runKeyFor((string) base64_decode(FakeOcrDriver::PNG_1X1, true), 'fake', OcrService::runVariant('fake', true));
        $result = "docs/scan.png.ocr/{$run}/result.json";
        touch(Storage::disk('kb')->path($result), time() - 3600);
        clearstatcache();
        $before = Storage::disk('kb')->lastModified($result);

        $preview = $converter->convert($this->image(metadata: ['dry_run' => true]));

        $this->assertTrue((bool) $preview->extractionMeta['ocr']['reused']);
        $this->assertTrue((bool) $preview->extractionMeta['ocr']['dry_run']);
        clearstatcache();
        $this->assertSame($before, Storage::disk('kb')->lastModified($result), 'a dry run never rewrites the recorded run');
    }

    /**
     * ADR 0029 §6 — a reservation that cannot be refreshed is a loud failure
     * (the ingest fails and the job retries), never a logged warning behind a
     * row that would cite figures a purge may take before it commits.
     * `OcrService::convert()` calls it unguarded under the run lock.
     */
    #[Test]
    public function a_reservation_that_cannot_be_refreshed_fails_loudly(): void
    {
        $store = $this->app->make(OcrFigureStore::class);
        $run = OcrFigureStore::runKeyFor((string) base64_decode(FakeOcrDriver::PNG_1X1, true), 'fake', OcrService::runVariant('fake', true));

        // No recorded run at that key: there is nothing to re-record, the
        // reservation cannot be refreshed, and that is an exception — not a
        // warning that lets the conversion commit a dangling reference.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not refresh the reservation');
        $store->refreshReservation('kb', 'docs/scan.png', '', $run);
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
        $run = OcrFigureStore::runKeyFor((string) base64_decode(FakeOcrDriver::PNG_1X1, true), 'fake', OcrService::runVariant('fake', true));
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

    /** SEC-SSRF-001 — an allow-listed endpoint cannot redirect the bytes to a host that is not. */
    #[Test]
    public function mistral_never_follows_a_redirect_off_the_allow_list(): void
    {
        Http::fake([
            'https://api.mistral.eu/*' => Http::response('', 307, ['Location' => 'https://evil.example.test/v1/ocr']),
            'https://evil.example.test/*' => Http::response(['pages' => []], 200),
        ]);
        config(['kb.ocr.driver' => 'mistral-ocr', 'kb.ocr.allow_remote' => true, 'kb.ocr.mistral.api_key' => 'k']);

        try {
            $this->app->make(OcrConverter::class)->convert($this->image());
            $this->fail('expected the redirect to be treated as a failed call');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 307', $e->getMessage());
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'evil.example.test'));
    }

    /**
     * SEC-EXTRESP-001 — the input cap bounds what leaves; the response of a
     * remote driver is validated against the same number before anything is
     * stored, recorded or metered.
     */
    #[Test]
    public function a_remote_response_whose_page_count_differs_from_the_document_is_discarded_before_anything_is_recorded(): void
    {
        config(['kb.ocr.driver' => 'mistral-ocr', 'kb.ocr.allow_remote' => true, 'kb.ocr.mistral.api_key' => 'k']);

        // More pages than the one-page image: spend the cap never admitted.
        Http::fake(['https://api.mistral.eu/*' => Http::response(['pages' => [
            ['index' => 0, 'markdown' => 'one'], ['index' => 1, 'markdown' => 'two'], ['index' => 2, 'markdown' => 'three'],
        ]], 200)]);
        try {
            $this->app->make(OcrConverter::class)->convert($this->image());
            $this->fail('three pages for a one-page image must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('returned 3 pages', $e->getMessage());
            $this->assertStringContainsString('a 1-page document', $e->getMessage());
        }
        $this->assertSame([], Storage::disk('kb')->allFiles('docs/scan.png.ocr'), 'nothing recorded, no figure stored');
    }

    /** Fewer pages than the document: a truncated answer that would otherwise be recorded, reused and metered as if complete. */
    #[Test]
    public function a_remote_response_with_fewer_pages_than_the_document_is_discarded_too(): void
    {
        config(['kb.ocr.driver' => 'mistral-ocr', 'kb.ocr.allow_remote' => true, 'kb.ocr.mistral.api_key' => 'k']);
        Http::fake(['https://api.mistral.eu/*' => Http::response(['pages' => [['index' => 0, 'markdown' => 'only one']]], 200)]);
        $scan = new SourceDocument(
            sourcePath: 'docs/two.pdf',
            mimeType: 'application/pdf',
            bytes: \Tests\Fixtures\Pdf\PdfFixtureBuilder::build(['  ', '  '], [1, 2]),
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );
        try {
            $this->app->make(\App\Services\Kb\Converters\PdfConverter::class)->convert($scan);
            $this->fail('one page for a two-page scan must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('returned 1 pages', $e->getMessage());
            $this->assertStringContainsString('a 2-page document', $e->getMessage());
        }
        $this->assertSame([], Storage::disk('kb')->allFiles('docs/two.pdf.ocr'), 'nothing recorded');
    }

    /** SEC-EXTRESP-001 — the response body is read in bounded chunks and abandoned past the cap, never buffered whole first. */
    #[Test]
    public function mistral_abandons_a_response_larger_than_the_cap_while_reading_it(): void
    {
        config(['kb.ocr.driver' => 'mistral-ocr', 'kb.ocr.allow_remote' => true, 'kb.ocr.mistral.api_key' => 'k', 'kb.ocr.mistral.max_response_bytes' => 256]);
        Http::fake(['https://api.mistral.eu/*' => Http::response(['pages' => [['index' => 0, 'markdown' => str_repeat('x', 4096)]]], 200)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds the configured size limit');
        $this->app->make(OcrConverter::class)->convert($this->image());
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

    /**
     * ADR 0029 §5 — the reservation lease is sized from the driver's declared
     * worst case for the verified page count, never a fixed guess shorter
     * than the work; the re-run lock outlives one job attempt window.
     */
    #[Test]
    public function the_run_reservation_lease_outlives_the_drivers_declared_worst_case(): void
    {
        // A budget above every declared worst case, so the lease below IS the
        // declared worst case; the default budget is asserted at the end.
        config(['kb.ocr.tesseract.timeout' => 300, 'kb.ocr.vision_llm.timeout' => 300, 'kb.ocr.docling.timeout' => 600, 'kb.ocr.mistral.timeout' => 120, 'kb.ocr.job_timeout' => 200000]);
        $registry = $this->app->make(\App\Services\Kb\Ocr\OcrDriverRegistry::class);
        $tesseract = $registry->resolve('tesseract');
        $docling = $registry->resolve('docling');

        // 200 pages × (text + tsv) × 300 s + the two PDF setup processes
        // (pdfinfo + pdftoppm): the fixed floor would expire mid-run.
        $this->assertSame(300 * (2 + 2 * 200), $tesseract->maxDurationSeconds(200));
        $this->assertSame(300 * (2 + 200), app(\App\Services\Kb\Ocr\Drivers\VisionLlmOcrDriver::class)->maxDurationSeconds(200));
        $this->assertGreaterThan(OcrService::RUN_LOCK_TTL, OcrService::leaseFor($tesseract, 200));
        $this->assertSame($tesseract->maxDurationSeconds(200) + OcrService::RUN_LOCK_MARGIN, OcrService::leaseFor($tesseract, 200));
        // A per-document driver's lease is its timeout, floored at the minimum.
        $this->assertSame(600, $docling->maxDurationSeconds(200));
        $this->assertSame(OcrService::RUN_LOCK_TTL, OcrService::leaseFor($docling, 200));
        $this->assertSame(OcrService::RUN_LOCK_TTL, OcrService::leaseFor($registry->resolve('fake'), 1));

        // The re-run lock is re-armed at attempt start and must outlive ONE
        // attempt: the job timeout + the queue retry_after + the largest
        // backoff. For an OCR-able document the job budget IS the driver's
        // worst case, so the lease is derived from it — a 200-page Tesseract
        // run can never outlive its own lock; a Markdown job keeps the floor.
        config(['kb.ocr.driver' => 'tesseract', 'kb.ocr.max_pages' => 200]);
        $job = new \App\Jobs\IngestDocumentJob(projectKey: 'p', relativePath: 'a.pdf', disk: 'kb', mimeType: 'application/pdf');
        $this->assertSame(OcrService::leaseFor($tesseract, 200), $job->timeout, 'the job budget is the driver worst case');
        $this->assertGreaterThan($job->timeout + 330 + max($job->backoff), OcrService::rerunLockTtlFor('application/pdf'));
        $this->assertGreaterThan(OcrService::RERUN_LOCK_TTL, OcrService::rerunLockTtlFor('application/pdf'));
        $markdownJob = new \App\Jobs\IngestDocumentJob(projectKey: 'p', relativePath: 'a.md', disk: 'kb', mimeType: 'text/markdown');
        $this->assertSame(OcrService::RERUN_LOCK_TTL, OcrService::rerunLockTtlFor('text/markdown'));
        $this->assertGreaterThan($markdownJob->timeout + 330 + max($markdownJob->backoff), OcrService::rerunLockTtlFor('text/markdown'));

        // Under the DEFAULT budget the same 200-page run is bounded by
        // KB_OCR_JOB_TIMEOUT: lease, job timeout and re-run lock all follow
        // it, which is what the queue's retry_after has to exceed.
        config(['kb.ocr.job_timeout' => 3600]);
        $this->assertSame(3600 + OcrService::RUN_LOCK_MARGIN, OcrService::leaseFor($tesseract, 200));
        $bounded = new \App\Jobs\IngestDocumentJob(projectKey: 'p', relativePath: 'a.pdf', disk: 'kb', mimeType: 'application/pdf');
        $this->assertSame(3600 + OcrService::RUN_LOCK_MARGIN, $bounded->timeout);
        $this->assertSame(3600 + OcrService::RUN_LOCK_MARGIN + OcrService::RERUN_LOCK_MARGIN, OcrService::rerunLockTtlFor('application/pdf'));
    }

    /** The figure caps shape the output (a figure past them is omitted), so a changed cap is a new run, never a reused result. */
    #[Test]
    public function the_run_key_changes_with_the_figure_caps(): void
    {
        config(['kb.ocr.fake.pages' => [['markdown' => 'Alpha', 'confidence' => 0.8, 'figures' => 1]]]);
        $converter = $this->app->make(OcrConverter::class);
        $first = $converter->convert($this->image());

        config(['kb.ocr.max_figures_per_run' => 7]);
        $second = $converter->convert($this->image());
        $this->assertNotSame($first->extractionMeta['ocr']['run'], $second->extractionMeta['ocr']['run']);
        $this->assertFalse((bool) $second->extractionMeta['ocr']['reused']);

        config(['kb.ocr.figures.enabled' => false]);
        $third = $converter->convert($this->image());
        $this->assertNotSame($second->extractionMeta['ocr']['run'], $third->extractionMeta['ocr']['run'], 'figures off is another output');
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
