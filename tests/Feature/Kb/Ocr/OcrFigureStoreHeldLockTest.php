<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Ocr;

use App\Services\Kb\Ocr\OcrFigure;
use App\Services\Kb\Ocr\OcrFigureStore;
use App\Support\Kb\HeldLock;
use App\Support\Kb\LockLostException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PR #492 Copilot round-2 — `store()` and `refreshReservation()` both write
 * to disk under a caller-held run reservation, but neither used to check
 * that the SAME lock was still owned right before its write: the read (or
 * the loop over figures) and the write straddle a storage round-trip, and a
 * lease that lapses in that gap used to let the write proceed after a purge
 * has since started removing the very directory being written into.
 *
 * These tests hand each method a `HeldLock` wrapping a lock that was
 * constructed but NEVER `->get()`'d — `isOwnedByCurrentProcess()` on such a
 * lock answers `false` (nothing was ever recorded as owned by this
 * process), so `assertHeld()` throws `LockLostException` exactly as it
 * would for a lease that lapsed mid-operation. This is a DIFFERENT
 * scenario from `OcrServiceTouchRunBeforeCommitTest`'s no-lock-STORE
 * refusal (`cacheStoreCanLock()` rejecting the store outright): here the
 * store is perfectly capable of locking, the caller's specific lock is
 * simply no longer held.
 */
final class OcrFigureStoreHeldLockTest extends TestCase
{
    private const RUN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
    }

    private function unownedHeldLock(): HeldLock
    {
        // Deliberately never acquired: no ->get()/->block() call, so the
        // store has no record of this process owning the key.
        $lock = Cache::lock('test-ocr-figure-store-lock-'.uniqid('', true), 60);

        return new HeldLock($lock, 'test');
    }

    public function test_store_refuses_to_write_a_figure_once_the_lock_is_no_longer_held(): void
    {
        $store = app(OcrFigureStore::class);
        $figure = new OcrFigure(page: 1, index: 1, bytes: 'figure-bytes', extension: 'png');

        try {
            $store->store('kb', 'docs/x.md', '', self::RUN, [$figure], $this->unownedHeldLock());
            $this->fail('Expected LockLostException.');
        } catch (LockLostException $e) {
            // A never-acquired lock still answers isOwnedByCurrentProcess()
            // with a real `false` (the store has no owner recorded for the
            // key), not an unverifiable answer — so this is the "lost"
            // message, not the "cannot be verified" one.
            $this->assertStringContainsString('lock lost before', $e->getMessage());
        }

        $this->assertFalse(
            Storage::disk('kb')->exists('docs/x.md.ocr/'.self::RUN.'/images/fig-1-1.png'),
            'the write must never happen once assertHeld() has refused the lock',
        );
    }

    /**
     * `store()` asserts the lock INSIDE the loop, before EACH figure: proves
     * the check is not a one-time guard at the top of the method — a lock
     * lost between the first and second figure still stops the second write.
     */
    public function test_store_stops_mid_loop_once_the_lock_is_lost_between_figures(): void
    {
        $store = app(OcrFigureStore::class);
        $lock = Cache::lock('test-ocr-figure-store-mid-loop-'.uniqid('', true), 60);
        $lock->get();
        $held = new HeldLock($lock, 'test');
        $figureOne = new OcrFigure(page: 1, index: 1, bytes: 'first', extension: 'png');
        $figureTwo = new OcrFigure(page: 1, index: 2, bytes: 'second', extension: 'png');

        // The first figure writes fine (the lock is genuinely held); the
        // lock is then released BETWEEN the two writes, exactly the window
        // assertHeld() exists to close.
        $store->store('kb', 'docs/x.md', '', self::RUN, [$figureOne], $held);
        $lock->forceRelease();

        try {
            $store->store('kb', 'docs/x.md', '', self::RUN, [$figureTwo], $held);
            $this->fail('Expected LockLostException.');
        } catch (LockLostException) {
            // expected
        }

        Storage::disk('kb')->assertExists('docs/x.md.ocr/'.self::RUN.'/images/fig-1-1.png');
        Storage::disk('kb')->assertMissing('docs/x.md.ocr/'.self::RUN.'/images/fig-1-2.png');
    }

    public function test_refresh_reservation_refuses_the_rewrite_once_the_assets_lock_is_no_longer_held(): void
    {
        Storage::disk('kb')->put('docs/x.md.ocr/'.self::RUN.'/result.json', '{"pages":[]}');
        $store = app(OcrFigureStore::class);
        $runLock = Cache::lock('test-ocr-figure-store-run-'.uniqid('', true), 60);
        $runLock->get();

        try {
            $store->refreshReservation('kb', 'docs/x.md', '', self::RUN, $this->unownedHeldLock(), new HeldLock($runLock, 'test run'));
            $this->fail('Expected a RuntimeException wrapping the lost lock.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not refresh the reservation', $e->getMessage());
        }
    }

    /**
     * PR #492 Copilot round-5 — the assets lock alone used to be asserted;
     * the caller's OWN run-level reservation (the SAME key `purgeRun()`
     * acquires before it may delete the run directory) was never checked at
     * all. A run reservation lost mid-refresh — the assets lock is still
     * genuinely held — must refuse exactly like a lost assets lock does,
     * proving `$runHeld` is now asserted, not merely accepted and ignored.
     */
    public function test_refresh_reservation_refuses_the_rewrite_once_the_run_reservation_is_no_longer_held(): void
    {
        Storage::disk('kb')->put('docs/x.md.ocr/'.self::RUN.'/result.json', '{"pages":[]}');
        $store = app(OcrFigureStore::class);
        $assetsLock = Cache::lock('test-ocr-figure-store-assets-'.uniqid('', true), 60);
        $assetsLock->get();

        try {
            $store->refreshReservation('kb', 'docs/x.md', '', self::RUN, new HeldLock($assetsLock, 'test assets'), $this->unownedHeldLock());
            $this->fail('Expected a RuntimeException wrapping the lost run reservation.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not refresh the reservation', $e->getMessage());
        }
    }
}
