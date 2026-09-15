<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Ocr;

use App\Services\Kb\Ocr\OcrFigureStore;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `purgeRun()`'s run reservation (`OcrService::runLockKey()`) protects
 * against a CONCURRENT CONVERTER reusing the same run — it says nothing
 * about a document row committing a NEW reference to it. Every caller
 * decides "unreferenced" from a database snapshot taken BEFORE this call,
 * and a restore or a fresh ingest can commit a row naming the run in the gap
 * between that snapshot and the deletion. `$referencedCheck` closes it: run
 * under the SAME held reservation, immediately before the delete.
 */
final class OcrFigureStorePurgeRunTest extends TestCase
{
    private const RUN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/x.md.ocr/'.self::RUN.'/result.json', '{}');
        // Past the in-flight grace, so isInFlight() alone does not decide
        // this test's outcome.
        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();
    }

    /**
     * A row committed a reference to the run AFTER the caller's own snapshot
     * decided it was stale: `$referencedCheck`, evaluated under the held
     * reservation right before the delete, catches it and keeps the run.
     */
    public function test_a_run_referenced_after_the_snapshot_is_kept_not_purged(): void
    {
        $purged = app(OcrFigureStore::class)->purgeRun('kb', 'docs/x.md', '', self::RUN, fn (): bool => true);

        $this->assertFalse($purged);
        $this->assertTrue(Storage::disk('kb')->directoryExists('docs/x.md.ocr/'.self::RUN));
    }

    /** No new reference: the run purges exactly as it did before the check existed. */
    public function test_a_run_still_unreferenced_at_the_recheck_is_purged(): void
    {
        $purged = app(OcrFigureStore::class)->purgeRun('kb', 'docs/x.md', '', self::RUN, fn (): bool => false);

        $this->assertTrue($purged);
        $this->assertFalse(Storage::disk('kb')->directoryExists('docs/x.md.ocr/'.self::RUN));
    }

    /** Backward compatible: no callback at all behaves exactly as before this parameter existed. */
    public function test_no_referenced_check_purges_as_before(): void
    {
        $purged = app(OcrFigureStore::class)->purgeRun('kb', 'docs/x.md', '', self::RUN);

        $this->assertTrue($purged);
        $this->assertFalse(Storage::disk('kb')->directoryExists('docs/x.md.ocr/'.self::RUN));
    }
}
