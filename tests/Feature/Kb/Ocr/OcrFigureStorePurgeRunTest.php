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

    /**
     * v8.36 / PR #479 Copilot review round 5 (R43) — `purge()`/`purgeBeside()`
     * used to call `Cache::lock()` unconditionally, so a caller on a store
     * without `LockProvider` (`PruneOrphanFilesCommand`'s own "no mutex at
     * all" fallback) got an uncaught throw instead of the grace-only purge
     * that branch's own comment promises. On such a store the age grace is
     * the ONLY guard — an aged run purges by age alone, with no lock at all.
     */
    public function test_purge_beside_on_a_store_without_locks_removes_an_aged_run_by_grace_alone(): void
    {
        \Illuminate\Support\Facades\Cache::extend('nolock', static fn ($app) => \Illuminate\Support\Facades\Cache::repository(new \Tests\Fixtures\Cache\NoLockStore));
        config(['cache.stores.nolock' => ['driver' => 'nolock'], 'cache.default' => 'nolock']);

        $purged = app(OcrFigureStore::class)->purgeBeside('kb', 'docs/x.md');

        $this->assertTrue($purged);
        Storage::disk('kb')->assertMissing('docs/x.md.ocr/'.self::RUN);
    }

    /**
     * Same no-lock store, but the run is still inside the grace: kept, not
     * thrown, not reported failed. `Storage::fake('kb')` mtimes are the
     * REAL filesystem clock, not Carbon's — this class's `setUp()` travels
     * Carbon's `now()` forward so the threshold computed FROM it moves past
     * every file written before that point (the pattern the other tests in
     * this file rely on), which means a file written fresh IN this test
     * body would still read as older than that already-advanced threshold.
     * `touch()`-ing it to a REAL future mtime is the one way to land it
     * inside the grace regardless (see `OcrServiceTouchRunBeforeCommitTest`'s
     * docblock for the same distinction from the other side).
     */
    public function test_purge_beside_on_a_store_without_locks_keeps_a_run_still_inside_the_grace(): void
    {
        Storage::disk('kb')->put('docs/y.md.ocr/'.self::RUN.'/result.json', '{}');
        $absolute = Storage::disk('kb')->path('docs/y.md.ocr/'.self::RUN.'/result.json');
        touch($absolute, time() + 3600);
        clearstatcache(true, $absolute);
        \Illuminate\Support\Facades\Cache::extend('nolock', static fn ($app) => \Illuminate\Support\Facades\Cache::repository(new \Tests\Fixtures\Cache\NoLockStore));
        config(['cache.stores.nolock' => ['driver' => 'nolock'], 'cache.default' => 'nolock']);

        $purged = app(OcrFigureStore::class)->purgeBeside('kb', 'docs/y.md');

        $this->assertFalse($purged);
        Storage::disk('kb')->assertExists('docs/y.md.ocr/'.self::RUN.'/result.json');
    }

    /**
     * PR #492 Copilot round-2 — unlike `purgeAt()` (which has the documented
     * grace-only fallback exercised above), `purgeRun()` has NO no-lock
     * fallback: on a store `cacheStoreCanLock()` rejects, `$reservation->get()`
     * used to report success unconditionally (`NoLockStore`'s `ArrayStore`
     * inner accepts every write), so a converter reusing this exact run
     * would have had ZERO protection while `purgeRun()` deleted it out from
     * under it — and still reported `true` (purged), not a refusal. Refused
     * outright now, run kept, regardless of age.
     */
    public function test_purge_run_on_a_store_without_locks_refuses_rather_than_deleting_unguarded(): void
    {
        \Illuminate\Support\Facades\Cache::extend('nolock', static fn ($app) => \Illuminate\Support\Facades\Cache::repository(new \Tests\Fixtures\Cache\NoLockStore));
        config(['cache.stores.nolock' => ['driver' => 'nolock'], 'cache.default' => 'nolock']);

        $purged = app(OcrFigureStore::class)->purgeRun('kb', 'docs/x.md', '', self::RUN);

        $this->assertFalse($purged, 'a store that cannot exclude a concurrent converter must refuse the purge, not report success');
        $this->assertTrue(Storage::disk('kb')->directoryExists('docs/x.md.ocr/'.self::RUN));
    }

    /**
     * PR #492 Copilot round-3 — `$referencedCheck` only sees COMMITTED
     * `knowledge_documents` rows: an ingest that already called convert()
     * (this run's result.json is on disk — the fixture in setUp()) but has
     * not yet committed its document row is invisible to it, and this run
     * is past the in-flight grace, so BOTH existing guards would let the
     * purge proceed. That ingest DOES hold {@see \App\Support\Kb\SourceInFlight}
     * for its whole read+convert+commit window — reserved here directly,
     * the same call `IngestDocumentJob::reserveSource()` makes — and the
     * run must be kept on that alone, even with a `$referencedCheck` that
     * (correctly, from the DB's point of view) says "not referenced".
     */
    public function test_a_run_whose_source_an_ingest_still_holds_is_kept_even_though_no_row_references_it_yet(): void
    {
        $lock = \App\Support\Kb\SourceInFlight::reserve('kb', 'docs/x.md');

        try {
            $purged = app(OcrFigureStore::class)->purgeRun('kb', 'docs/x.md', '', self::RUN, fn (): bool => false);

            $this->assertFalse($purged, 'an ingest still holding the source reservation must keep the run, regardless of the committed-rows check');
            $this->assertTrue(Storage::disk('kb')->directoryExists('docs/x.md.ocr/'.self::RUN));
        } finally {
            $lock?->release();
        }
    }

    /**
     * PR #492 Copilot round-7 (Finding B) — the run reservation
     * (`OcrService::runLockKey()`) and the source reservation
     * (`SourceInFlight::acquireForRemoval()`) were asymmetric: `purgeRun()`
     * asserted the RUN lock was still held right before the delete, via
     * {@see \App\Support\Kb\HeldLock}, but the SOURCE reservation — taken
     * earlier in the same call, right before `isInFlight()` reads the
     * directory — was only checked for successful ACQUISITION, never for
     * still being owned at the decisive moment. A source reservation whose
     * lease lapsed mid-call (a slow filesystem, GC pause, or a driver whose
     * TTL is shorter than this method's own work) left the delete completely
     * unguarded from the source's point of view: the fix Finding A closes
     * for `HostIngestionBridge` had a twin gap here.
     *
     * `LapsingLockStore` grants the source reservation (so
     * `acquireForRemoval()` returns a non-null lock and `purgeRun()`
     * proceeds past that guard) but reports it unowned from the first
     * `isOwnedByCurrentProcess()` call onward — the deterministic stand-in
     * for "the lease lapsed between acquisition and the assertion right
     * before the irreversible step" (see the fixture's own docblock). The
     * predicate is scoped to `SourceInFlight`'s key prefix only, so the RUN
     * reservation (a DIFFERENT key, `OcrService::runLockKey()`) stays
     * genuinely owned via the delegated `ArrayStore` — proving the run purge
     * refuses on the SOURCE reservation specifically, not merely because
     * every lock in the store is fake.
     */
    public function test_a_run_is_kept_when_the_source_reservation_lapses_before_the_delete(): void
    {
        \Illuminate\Support\Facades\Cache::extend('lapsing', static fn ($app) => \Illuminate\Support\Facades\Cache::repository(
            new \Tests\Fixtures\Cache\LapsingLockStore(static fn (string $name): bool => str_starts_with($name, 'kb:source-inflight:'))
        ));
        config(['cache.stores.lapsing' => ['driver' => 'lapsing'], 'cache.default' => 'lapsing']);

        $purged = app(OcrFigureStore::class)->purgeRun('kb', 'docs/x.md', '', self::RUN, fn (): bool => false);

        $this->assertFalse($purged, 'a source reservation that lapsed before the delete must defer the purge, not proceed unguarded');
        $this->assertTrue(Storage::disk('kb')->directoryExists('docs/x.md.ocr/'.self::RUN));
    }
}
