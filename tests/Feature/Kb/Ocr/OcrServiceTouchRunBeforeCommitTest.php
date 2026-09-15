<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Ocr;

use App\Services\Kb\Ocr\OcrFigureStore;
use App\Services\Kb\Ocr\OcrService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v8.36 — the archived-version prune race Copilot found on PR #479: the OCR
 * run reservation is released once `OcrService::convert()` finishes (driver
 * call, figure writes, result.json), well BEFORE the caller's document row
 * commits. `PruneArchivedVersionsCommand`'s OCR-run purge decides
 * "unreferenced" from a database snapshot taken before that commit, and once
 * the run's `isInFlight()` grace has elapsed since OCR finished, it purges —
 * leaving the about-to-commit `metadata.converter.ocr.run` pointer dangling.
 *
 * `DocumentIngestor` calls `touchRunBeforeCommit()` immediately before the
 * write phase that commits the row (see its two call sites in
 * `persistDrafts()` / `persistFromDrafts()`); these tests exercise the
 * method directly against the SAME `isInFlight()` decision `purgeRun()`
 * makes, without needing a full ingest pipeline.
 *
 * `Storage::fake('kb')` uses REAL filesystem mtimes (Flysystem's local
 * adapter, `filemtime()`) — NOT Carbon's virtual clock, so `$this->travel()`
 * cannot simulate "this file is aged" the way it does for tests that fix
 * the write once and travel the THRESHOLD forward (`OcrFigureStorePurgeRunTest`).
 * Proving a touch resets freshness needs the opposite shape: backdate the
 * file's REAL mtime directly with `touch()`, so the run APPEARS aged to
 * `isInFlight()` without moving the clock at all — then a real rewrite
 * (the touch under test) genuinely gets a fresh, current real mtime.
 */
final class OcrServiceTouchRunBeforeCommitTest extends TestCase
{
    private const RUN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
    }

    private function writeAgedResult(string $path): void
    {
        Storage::disk('kb')->put($path, '{}');
        $absolute = Storage::disk('kb')->path($path);
        $aged = time() - (OcrFigureStore::inFlightGraceSeconds() + 60);
        touch($absolute, $aged);
        clearstatcache(true, $absolute);
    }

    /**
     * The run's result.json is backdated past the in-flight grace — the
     * control test below proves that, left alone, purgeRun() deletes it.
     * Touching it right before commit rewrites result.json at the REAL
     * current time, resetting the freshness clock: the SAME purge call that
     * would have deleted it now keeps it.
     */
    public function test_touching_a_recorded_run_resets_its_in_flight_freshness(): void
    {
        $this->writeAgedResult('docs/x.md.ocr/'.self::RUN.'/result.json');

        app(OcrService::class)->touchRunBeforeCommit(
            ['disk' => 'kb', 'prefix' => '', 'converter' => ['ocr' => ['run' => self::RUN]]],
            'docs/x.md',
        );

        $purged = app(OcrFigureStore::class)->purgeRun('kb', 'docs/x.md', '', self::RUN);

        $this->assertFalse($purged, 'the touch should have reset the freshness clock, keeping the run');
        $this->assertTrue(Storage::disk('kb')->directoryExists('docs/x.md.ocr/'.self::RUN));
    }

    /** Control: the SAME aged run, never touched, purges — proving the aging setup actually works. */
    public function test_without_the_touch_an_aged_run_still_purges(): void
    {
        $this->writeAgedResult('docs/x.md.ocr/'.self::RUN.'/result.json');

        $purged = app(OcrFigureStore::class)->purgeRun('kb', 'docs/x.md', '', self::RUN);

        $this->assertTrue($purged);
        $this->assertFalse(Storage::disk('kb')->directoryExists('docs/x.md.ocr/'.self::RUN));
    }

    /** No `converter.ocr.run` in metadata (the vast majority of documents): a silent no-op. */
    public function test_metadata_without_an_ocr_run_is_a_no_op(): void
    {
        app(OcrService::class)->touchRunBeforeCommit(['disk' => 'kb', 'prefix' => ''], 'docs/x.md');

        $this->assertFalse(Storage::disk('kb')->directoryExists('docs/x.md.ocr'));
    }

    /**
     * Reuse disabled: OcrService::convert() never writes result.json (ADR
     * 0029 §6 — nothing to reuse from), so there is nothing to touch. A
     * naive unconditional refreshReservation() call would throw here —
     * exactly the regression this fix introduced and then closed
     * (OcrIngestPipelineTest::test_with_reuse_off_an_identical_re_ingest_...).
     */
    public function test_a_run_with_no_recorded_result_json_is_a_no_op(): void
    {
        // Figures dir exists (figures can still be written with reuse off)
        // but no result.json was ever recorded for this run.
        Storage::disk('kb')->put('docs/x.md.ocr/'.self::RUN.'/images/fig-1-1.png', 'figure');

        app(OcrService::class)->touchRunBeforeCommit(
            ['disk' => 'kb', 'prefix' => '', 'converter' => ['ocr' => ['run' => self::RUN]]],
            'docs/x.md',
        );

        $this->assertFalse(Storage::disk('kb')->exists('docs/x.md.ocr/'.self::RUN.'/result.json'));
    }
}
