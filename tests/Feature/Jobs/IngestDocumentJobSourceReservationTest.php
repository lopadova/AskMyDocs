<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\IngestDocumentJob;
use App\Services\Kb\Canonical\CanonicalParser;
use App\Services\Kb\DocumentDeleter;
use App\Support\Kb\SourceInFlight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ADR 0030 §3 — the RESERVATION an ingest holds over its source object for
 * the whole read + convert + commit window.
 *
 * The storage-key lock covers only the commit. Before it the file has no row
 * and no holder, so `kb:prune-orphan-files` sees an ordinary orphan and
 * deletes the bytes out from under a conversion that is still running — after
 * which the row commits `full_copy` over an original that is already gone.
 * The age grace narrowed that window but could not close it: an age threshold
 * is a guess about how long work takes, and an OCR run
 * (`kb.ocr.job_timeout`, 3600 s) can outlast the 3600 s grace on its own.
 */
final class IngestDocumentJobSourceReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('kb.embedding_cache.enabled', false);
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('ai.default', 'openai');
        config()->set('ai.embeddings_provider', 'openai');
        Http::fake([
            'api.openai.com/*' => static function ($request) {
                $data = [];
                foreach ($request->data()['input'] ?? [] as $i => $_text) {
                    $data[] = ['index' => $i, 'embedding' => [0.1, 0.2, 0.3]];
                }

                return Http::response(['model' => 'text-embedding-3-small', 'data' => $data, 'usage' => ['total_tokens' => count($data)]], 200);
            },
        ]);
    }

    /**
     * The job holds the reservation WHILE it works and gives it back when it
     * is done — so a sweep that runs mid-ingest keeps the file, and the one
     * after the job may judge it without waiting out a TTL.
     *
     * Observed from inside the flow (the canonical parse runs after the read
     * and before the commit, i.e. squarely inside the window), not from the
     * job's own bookkeeping.
     */
    public function test_the_job_reserves_its_source_before_it_reads_and_releases_it_after(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/hello.md', "# Hello\n\nBody paragraph.");

        $observed = [];
        $this->app->bind(CanonicalParser::class, static function () use (&$observed): CanonicalParser {
            return new class($observed) extends CanonicalParser
            {
                /** @param array<int, bool> $observed */
                public function __construct(private array &$observed) {}

                public function parse(string $markdown): ?\App\Services\Kb\Canonical\CanonicalParsedDocument
                {
                    $this->observed[] = SourceInFlight::held('kb', 'docs/hello.md');

                    return parent::parse($markdown);
                }
            };
        });

        $this->app->call([new IngestDocumentJob(projectKey: 'demo', relativePath: 'docs/hello.md', disk: 'kb'), 'handle']);

        $this->assertNotSame([], $observed, 'the flow must have reached the observation point');
        $this->assertSame([true], $observed, 'the source is reserved for the whole read + convert + commit window');
        $this->assertFalse(SourceInFlight::held('kb', 'docs/hello.md'), 'the reservation is given back when the job is done');
    }

    /**
     * The reservation and the sweep must name the SAME object under a
     * `KB_PATH_PREFIX`. They compose the path from different ends — the job
     * from the prefix the row recorded (falling back to the configured one),
     * the sweep from the configured prefix and a listing entry — and a
     * disagreement of one segment would leave the reservation a silent no-op
     * on every prefixed deployment: taken on `docs/x.md`, probed on
     * `kb/docs/x.md`, honoured by nobody.
     */
    public function test_the_reservation_and_the_sweep_name_the_same_object_under_a_path_prefix(): void
    {
        config(['kb.sources.path_prefix' => 'kb', 'kb.sources.orphan_grace_seconds' => 60]);
        Storage::fake('kb');
        Storage::disk('kb')->put('kb/docs/prefixed.md', "# Prefixed\n\nBody paragraph.");

        $observedKey = null;
        $this->app->bind(CanonicalParser::class, static function () use (&$observedKey): CanonicalParser {
            return new class($observedKey) extends CanonicalParser
            {
                public function __construct(private mixed &$observedKey) {}

                public function parse(string $markdown): ?\App\Services\Kb\Canonical\CanonicalParsedDocument
                {
                    // The path the SWEEP would compose for this file.
                    $this->observedKey = SourceInFlight::held('kb', 'kb/docs/prefixed.md');

                    return parent::parse($markdown);
                }
            };
        });

        $this->app->call([new IngestDocumentJob(projectKey: 'demo', relativePath: 'docs/prefixed.md', disk: 'kb'), 'handle']);

        $this->assertTrue($observedKey, 'the job must reserve the PREFIXED path — the one every deleting consumer probes');
    }

    /**
     * The reservation is released even when the job FAILS: a run that throws
     * mid-conversion must not pin its source until the TTL lapses, or a
     * source whose ingest keeps failing is never swept.
     */
    public function test_a_failing_job_gives_its_reservation_back(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/boom.md', "# Boom\n\nBody.");

        $this->app->bind(CanonicalParser::class, static fn (): CanonicalParser => new class extends CanonicalParser
        {
            public function parse(string $markdown): ?\App\Services\Kb\Canonical\CanonicalParsedDocument
            {
                throw new \RuntimeException('conversion exploded');
            }
        });

        try {
            $this->app->call([new IngestDocumentJob(projectKey: 'demo', relativePath: 'docs/boom.md', disk: 'kb'), 'handle']);
        } catch (\Throwable) {
            // the failure itself is not what this test is about
        }

        $this->assertFalse(SourceInFlight::held('kb', 'docs/boom.md'), 'a failed run must not pin its source until the TTL lapses');
    }

    /**
     * The sweep HONOURS the reservation: a source old enough for the grace —
     * which is exactly the OCR case the grace cannot cover — is kept while an
     * ingest holds it, and deleted once the reservation is gone.
     */
    public function test_the_orphan_sweep_keeps_a_reserved_source_and_deletes_it_once_released(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/converting.md', 'being converted right now');
        config(['kb.sources.orphan_grace_seconds' => 60]);
        $this->travel(600)->seconds(); // old enough that the grace alone would let it go

        $reservation = SourceInFlight::reserve('kb', 'docs/converting.md');
        $this->assertNotNull($reservation, 'the test cache store must be able to hold a reservation');

        $deleter = app(DocumentDeleter::class);
        $this->assertSame(
            DocumentDeleter::KEPT_IN_FLIGHT,
            $deleter->removeSourceFileIfUnreferenced('kb', 'docs/converting.md', 'docs/converting.md'),
        );
        Storage::disk('kb')->assertExists('docs/converting.md');

        $reservation->release();

        $this->assertSame(
            \App\Services\Kb\Versioning\ConversionArtifactStore::REMOVED,
            $deleter->removeSourceFileIfUnreferenced('kb', 'docs/converting.md', 'docs/converting.md'),
        );
        Storage::disk('kb')->assertMissing('docs/converting.md');
    }

    /**
     * A store that cannot exclude anyone gets NO reservation and NO false
     * confidence (R43): `reserve()` hands back null, `held()` answers false,
     * and the age grace stands alone exactly as it did before the reservation
     * existed — rather than a probe that "succeeds" and pins every file.
     */
    public function test_a_store_that_cannot_lock_takes_no_reservation_and_leaves_the_grace_in_charge(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/young.md', 'written a moment ago');
        config(['cache.default' => 'null', 'kb.sources.orphan_grace_seconds' => 3600]);
        Cache::purge('null');

        $this->assertNull(SourceInFlight::reserve('kb', 'docs/young.md'));
        $this->assertFalse(SourceInFlight::held('kb', 'docs/young.md'), 'a store that cannot exclude anyone must claim nothing');

        // …and the grace is still doing its job on its own, exactly as it did
        // before the reservation existed.
        $this->assertSame(
            DocumentDeleter::KEPT_IN_FLIGHT,
            app(DocumentDeleter::class)->removeSourceFileIfUnreferenced('kb', 'docs/young.md', 'docs/young.md'),
        );
        Storage::disk('kb')->assertExists('docs/young.md');
    }

    /**
     * A probe the cache store cannot answer is `failed` — reported, counted,
     * exit non-zero — never an unhandled throw that ends a sweep after it has
     * already deleted half its candidates, and never a silent deletion.
     */
    public function test_a_store_that_throws_on_the_probe_is_a_failed_sweep_not_a_crash(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/unprobeable.md', 'x');
        config(['kb.sources.orphan_grace_seconds' => 0]);

        Cache::shouldReceive('getStore')->andReturn(new \Illuminate\Cache\ArrayStore);
        Cache::shouldReceive('lock')->andThrow(new \RuntimeException('cache store unreachable'));

        $this->assertSame(
            \App\Services\Kb\Versioning\ConversionArtifactStore::FAILED,
            app(DocumentDeleter::class)->removeSourceFileIfUnreferenced('kb', 'docs/unprobeable.md', 'docs/unprobeable.md'),
        );
        // a source we could not ask about is never deleted
        Storage::disk('kb')->assertExists('docs/unprobeable.md');
    }

    /**
     * A reservation that could NOT be taken is reported, not silently
     * accepted as "someone else is protecting it": that is only true while
     * the other holder is still running, and the cases where it is not — two
     * tenants ingesting the same physical object, a retry starting under the
     * TTL a killed worker left behind — are exactly the ones this guard
     * exists for.
     */
    public function test_a_contended_reservation_fails_the_attempt_rather_than_assumed_safe(): void
    {
        $other = Cache::lock(SourceInFlight::key('kb', 'docs/taken.md'), 60);
        $this->assertTrue($other->get());
        try {
            SourceInFlight::reserve('kb', 'docs/taken.md');
            $this->fail('a contended reservation must not silently succeed');
        } catch (\App\Support\Kb\SourceReservationContendedException $e) {
            $this->assertStringContainsString('docs/taken.md', $e->getMessage());
        } finally {
            $other->release();
        }
    }

    /**
     * The job lets the contention exception propagate — it does NOT swallow
     * it and proceed unreserved. The queue's own `$tries`/`backoff` retries
     * the attempt once the contention has likely cleared.
     */
    public function test_the_job_fails_rather_than_convert_unreserved_when_contended(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/busy.md', "# Busy\n\nBody.");
        $other = Cache::lock(SourceInFlight::key('kb', 'docs/busy.md'), 60);
        $this->assertTrue($other->get());

        try {
            $this->expectException(\App\Support\Kb\SourceReservationContendedException::class);
            $this->app->call([new IngestDocumentJob(projectKey: 'demo', relativePath: 'docs/busy.md', disk: 'kb'), 'handle']);
        } finally {
            $other->release();
        }
    }

    /**
     * `kb:ingest-folder --sync` converts INLINE instead of queueing, so it
     * needs the same reservation for the same reason — a concurrent sweep
     * would otherwise delete a source it is halfway through converting.
     * Observed from inside the conversion, then asserted released.
     */
    public function test_the_inline_sync_ingest_reserves_its_source_too(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('a.md', "# Hi\n\nBody.");

        $observed = [];
        $this->app->bind(CanonicalParser::class, static function () use (&$observed): CanonicalParser {
            return new class($observed) extends CanonicalParser
            {
                /** @param array<int, bool> $observed */
                public function __construct(private array &$observed) {}

                public function parse(string $markdown): ?\App\Services\Kb\Canonical\CanonicalParsedDocument
                {
                    $this->observed[] = SourceInFlight::held('kb', 'a.md');

                    return parent::parse($markdown);
                }
            };
        });

        $this->artisan('kb:ingest-folder', ['--project' => 'demo', '--sync' => true])->assertSuccessful();

        $this->assertSame([true], $observed, 'the inline path reserves its source while it converts');
        $this->assertFalse(SourceInFlight::held('kb', 'a.md'), 'and gives it back when it is done');
    }

    /**
     * The HARD delete honours the reservation too. Its reference scan only
     * sees committed rows, so an ingest converting the same object right now
     * is invisible to it: without this the operator's delete takes the source
     * out from under that conversion, which then commits `full_copy` over an
     * original that is already gone.
     *
     * Nothing the operator asked for is refused — the ROW is deleted either
     * way, and `file_deleted: false` is an outcome this path already reports
     * for a key a writer holds right now. The file simply waits for the
     * orphan sweep.
     */
    public function test_the_hard_delete_keeps_a_reserved_source_and_still_deletes_the_row(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/shared.md', '# Shared');
        $doc = \App\Models\KnowledgeDocument::create([
            'project_key' => 'demo',
            'source_type' => 'markdown',
            'title' => 'Shared',
            'source_path' => 'docs/shared.md',
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', 'shared'),
            'version_hash' => hash('sha256', 'shared'),
            'metadata' => ['disk' => 'kb', 'prefix' => ''],
            'indexed_at' => now(),
        ]);

        $reservation = SourceInFlight::reserve('kb', 'docs/shared.md');
        $this->assertNotNull($reservation);
        try {
            $result = app(DocumentDeleter::class)->delete($doc, force: true);
        } finally {
            $reservation->release();
        }

        $this->assertFalse($result['file_deleted'], 'a source a conversion is reading is never taken from it');
        Storage::disk('kb')->assertExists('docs/shared.md');
        $this->assertSame(0, \App\Models\KnowledgeDocument::withTrashed()->where('id', $doc->id)->count(), 'the row still goes: only the file waits for the sweep');
    }

    /**
     * The TTL is the backstop for a worker killed mid-conversion, so it must
     * outlast the longest conversion this deployment allows: the OCR job
     * timeout plus a margin. A configured value that is not a whole number of
     * seconds falls back to that default rather than being truncated
     * (SEC-SETTING-SHAPE-001).
     */
    public function test_the_reservation_outlives_the_longest_conversion_and_refuses_a_malformed_setting(): void
    {
        config(['kb.ocr.job_timeout' => 3600, 'kb.sources.inflight_reservation_seconds' => null]);
        $this->assertSame(3900, SourceInFlight::seconds());
        $this->assertGreaterThan((int) config('kb.ocr.job_timeout'), SourceInFlight::seconds());

        config(['kb.sources.inflight_reservation_seconds' => 7200]);
        $this->assertSame(7200, SourceInFlight::seconds());

        config(['kb.sources.inflight_reservation_seconds' => 'off']);
        $this->assertSame(3900, SourceInFlight::seconds(), 'a malformed TTL is the default, never 0 (a reservation nobody holds)');

        // A value SHORTER than the longest conversion is raised to it: a
        // reservation that expires mid-conversion is the guard silently
        // switched off while appearing configured.
        SourceInFlight::resetWarnings();
        \Illuminate\Support\Facades\Log::spy();
        config(['kb.sources.inflight_reservation_seconds' => 60]);
        $this->assertSame(3900, SourceInFlight::seconds());
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, 'shorter than the longest conversion'))
            ->once();

        // A tiny OCR timeout must not make the reservation shorter than the
        // floor: a 60 s reservation would lapse under an ordinary ingest.
        config(['kb.ocr.job_timeout' => 1, 'kb.sources.inflight_reservation_seconds' => null]);
        $this->assertSame(600, SourceInFlight::seconds());
    }

    /**
     * Round-33 finding (Copilot, `DocumentDeleter.php:861` / suppressed
     * `:1171`): `SourceInFlight::held()` used to be a PROBE — acquire, give
     * back, THEN decide — so a new ingest could reserve and start reading in
     * the instant between the probe and the delete. The sweep must instead
     * ACQUIRE the reservation and hold it through its entire decision, so a
     * `reserve()` attempted from anywhere during that window finds the key
     * CONTENDED. Observed via a DB query listener: `firstDocumentReferencingStorageKey()`
     * runs a query squarely inside the decision, so a probe launched from
     * there proves the reservation is still held at that instant, not just
     * at the start.
     */
    public function test_the_orphan_sweep_holds_its_own_reservation_through_the_whole_decision_and_delete(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/held-through.md', 'x');
        config(['kb.sources.orphan_grace_seconds' => 0]);

        $observed = [];
        DB::listen(function ($query) use (&$observed) {
            // The reference re-check is the query squarely inside the
            // decision (`->where('source_path', ...)`); other queries (test
            // framework bookkeeping) are not what this test is about.
            if (str_contains($query->sql, 'source_path')) {
                $observed[] = SourceInFlight::held('kb', 'docs/held-through.md');
            }
        });

        $outcome = app(DocumentDeleter::class)->removeSourceFileIfUnreferenced('kb', 'docs/held-through.md', 'docs/held-through.md');

        $this->assertSame(\App\Services\Kb\Versioning\ConversionArtifactStore::REMOVED, $outcome);
        $this->assertNotSame([], $observed, 'at least one query must have run during the decision (the reference scan)');
        $this->assertSame([true], array_values(array_unique($observed)), 'the reservation was held for every query the decision made — never a false in the middle');
        $this->assertFalse(SourceInFlight::held('kb', 'docs/held-through.md'), 'released once the decision (and delete) finished');
    }

    /**
     * Same finding, the HARD-DELETE path (`DocumentDeleter.php:1171`,
     * suppressed): `removeSourceObjectUnderLock()` must hold its reservation
     * through the OCR-asset purge and the delete, not merely probe it before
     * either.
     */
    public function test_the_hard_delete_holds_its_own_reservation_through_the_whole_removal(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/hard-held.md', '# Hard held');
        $doc = \App\Models\KnowledgeDocument::create([
            'project_key' => 'demo',
            'source_type' => 'markdown',
            'title' => 'Hard held',
            'source_path' => 'docs/hard-held.md',
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', 'hard-held'),
            'version_hash' => hash('sha256', 'hard-held'),
            'metadata' => ['disk' => 'kb', 'prefix' => ''],
            'indexed_at' => now(),
        ]);

        // `OcrFigureStore::purgeBeside()` runs squarely inside the window
        // between the reservation being acquired and the file being deleted
        // (`$held?->assertHeld('OCR asset purge')` immediately precedes it).
        // A partial Mockery spy on the REAL instance intercepts it without
        // touching its `final` declaration or its own behaviour.
        $real = app(\App\Services\Kb\Ocr\OcrFigureStore::class);
        $observed = [];
        $spy = \Mockery::mock($real)->makePartial();
        $spy->shouldReceive('purgeBeside')->once()->andReturnUsing(function (string $disk, string $fullPath) use ($real, &$observed) {
            $observed[] = SourceInFlight::held('kb', 'docs/hard-held.md');

            return $real->purgeBeside($disk, $fullPath);
        });
        $this->app->instance(\App\Services\Kb\Ocr\OcrFigureStore::class, $spy);

        $result = app(DocumentDeleter::class)->delete($doc, force: true);

        $this->assertTrue($result['file_deleted']);
        $this->assertSame([true], $observed, 'the reservation was still held during the OCR-asset purge, squarely between acquisition and the file delete');
        $this->assertFalse(SourceInFlight::held('kb', 'docs/hard-held.md'), 'released once the removal finished');
    }

    /**
     * A deployment with conversion artifacts OFF and a cache store that
     * cannot lock at all still deletes an orphan, protected only by the age
     * grace — the same residual documented before the reservation existed
     * (R43). The reservation gate must not make this WORSE by refusing a
     * delete the pre-reservation code always allowed.
     */
    public function test_artifacts_off_with_no_lock_store_still_deletes_protected_by_the_grace_alone(): void
    {
        config(['kb.conversion_artifacts.enabled' => false, 'cache.default' => 'null', 'kb.sources.orphan_grace_seconds' => 60]);
        Cache::purge('null');
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/unlocked.md', 'x');
        $this->travel(600)->seconds();

        $outcome = app(DocumentDeleter::class)->removeSourceFileIfUnreferenced('kb', 'docs/unlocked.md', 'docs/unlocked.md');

        $this->assertSame(\App\Services\Kb\Versioning\ConversionArtifactStore::REMOVED, $outcome);
        Storage::disk('kb')->assertMissing('docs/unlocked.md');
    }
}
