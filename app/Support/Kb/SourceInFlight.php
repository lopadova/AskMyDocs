<?php

declare(strict_types=1);

namespace App\Support\Kb;

use App\Services\Kb\Versioning\ConversionArtifactStore;
use Illuminate\Contracts\Cache\Lock as LockContract;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The reservation an ingest holds over its SOURCE object for the whole time
 * it is reading and converting it, and the probe a deleting consumer asks
 * before removing one.
 *
 * The storage-key lock (SourceKeyLock) covers the COMMIT, which is the half
 * that races a `markdown_only` drop. It does not cover the half before it:
 * a job reads the source and converts it — an OCR run takes minutes — and in
 * that window the file has no row and no holder, so an orphan sweep sees an
 * ordinary orphan and deletes the bytes out from under the conversion. The
 * row then commits `full_copy` over an original that is already gone.
 *
 * `kb.sources.orphan_grace_seconds` narrowed that window but cannot close
 * it: an age threshold is a guess about how long work takes, and OCR
 * (`kb.ocr.job_timeout`, 3600 s by default) can outlast the 3600 s grace on
 * its own. A reservation states the fact instead of estimating it.
 *
 * The key is `(disk, sha1(full path))` and deliberately carries no tenant:
 * a source object is a shared PHYSICAL resource, the same R30 exception
 * `SourceKeyLock` already takes for the same reason. `disk` is the configured
 * disk NAME, not the bucket behind it — two names pointing at one bucket are
 * two keys, the same convention (and the same limit) as `SourceKeyLock`.
 *
 * Same posture as the temp lease and the OCR run reservation: a cache store
 * that cannot lock gets NO reservation and NO false confidence — `reserve()`
 * and `acquireForRemoval()` both return null, so the caller falls back to
 * the age grace exactly as before (R43). The TTL is a backstop for a worker
 * killed mid-conversion, not the mechanism: a reservation is released in a
 * `finally`, and one that outlives its holder expires rather than pinning a
 * file forever.
 *
 * A CONTENDED key is a different case from a store that cannot lock at all,
 * and the two callers treat it differently on purpose. `reserve()` (an
 * ingest about to READ) throws {@see SourceReservationContendedException}:
 * proceeding unreserved would mean converting for a possibly minutes-long
 * window with no exclusion. `acquireForRemoval()` (a DELETING consumer
 * about to decide) returns null on contention: that is the expected,
 * frequent, non-exceptional case — the file is in flight and the caller
 * keeps it, never fails a whole sweep over one orphan that turned out not
 * to be one.
 */
final class SourceInFlight
{
    /** Seconds a `reserve()` waits for a just-released holder to hand over. */
    private const HANDOVER_WAIT_SECONDS = 1;

    /** @var array<string, true> configuration warnings already emitted in this process */
    private static array $warned = [];

    public static function key(string $disk, string $fullPath): string
    {
        return 'kb:source-inflight:'.$disk.':'.sha1($fullPath);
    }

    /**
     * Seconds a reservation lives when its holder dies
     * (`kb.sources.inflight_reservation_seconds`). The default is the OCR job
     * timeout plus a margin, because the conversion is what the window is
     * made of.
     *
     * Two guards, both reported once (SEC-SETTING-SHAPE-001): a value that is
     * not a whole number of seconds falls back to the default rather than
     * being truncated, and a value SHORTER than that default is raised to it
     * — a reservation that expires mid-conversion makes its holder
     * indistinguishable from a dead one exactly in the window it exists for,
     * which is the feature silently switched off while appearing configured.
     * Same clamp, and the same reasoning, as the artifact temp lease.
     */
    public static function seconds(): int
    {
        $default = self::defaultSeconds();
        $configured = config('kb.sources.inflight_reservation_seconds', $default);
        $seconds = SettingInt::whole($configured, 1);
        if ($seconds === null) {
            self::warnOnce('inflight_shape', 'SourceInFlight: kb.sources.inflight_reservation_seconds is not a positive number of seconds; using the default', [
                'configured' => is_scalar($configured) ? $configured : gettype($configured),
                'default' => $default,
            ]);

            return $default;
        }
        if ($seconds < $default) {
            self::warnOnce('inflight_vs_conversion', 'SourceInFlight: kb.sources.inflight_reservation_seconds is shorter than the longest conversion this deployment allows; raising it so a slow ingest is never swept under', [
                // `$configured`, not `$seconds`: an invalid value was already
                // replaced above, and reporting that as "configured" would
                // contradict the first warning.
                'configured_inflight_reservation_seconds' => is_scalar($configured) ? $configured : gettype($configured),
                'effective_inflight_reservation_seconds' => $default,
            ]);

            return $default;
        }

        return $seconds;
    }

    /**
     * The OCR job timeout plus a margin, floored so an ordinary ingest always
     * fits. The fallback is the shipped `kb.ocr.job_timeout` default, not a
     * smaller one: a malformed timeout must not quietly shorten the
     * reservation that is supposed to outlast the conversion.
     */
    private const SHIPPED_OCR_JOB_TIMEOUT = 3600;

    private static function defaultSeconds(): int
    {
        return max(600, (SettingInt::whole(config('kb.ocr.job_timeout'), 1) ?? self::SHIPPED_OCR_JOB_TIMEOUT) + 300);
    }

    /**
     * Reserve the source for this ingest, or null ONLY when the store
     * cannot exclude anyone at all (R43 — no reservation is better than one
     * nobody honours, and the age grace is the sole guard from here on).
     *
     * A CONTENDED key throws rather than degrading (see the class docblock):
     * a short block first lets a just-released holder hand over, and a
     * reservation still not taken after that is
     * {@see SourceReservationContendedException} — the caller must fail
     * this attempt rather than convert unprotected.
     *
     * @throws SourceReservationContendedException the key is held by
     *         another holder right now.
     */
    public static function reserve(string $disk, string $fullPath): ?LockContract
    {
        if (! ConversionArtifactStore::cacheStoreCanLock()) {
            return null;
        }
        $lock = Cache::lock(self::key($disk, $fullPath), self::seconds());
        try {
            if ($lock->block(self::HANDOVER_WAIT_SECONDS)) {
                return $lock;
            }
        } catch (LockTimeoutException) {
            throw new SourceReservationContendedException($disk, $fullPath);
        } catch (\Throwable $e) {
            // A store that threw cannot be said to have refused: report it
            // the same way as R43 and let the grace stand — a driver blip is
            // not "someone else is protecting it".
            Log::warning('SourceInFlight: could not reserve the source; only the age grace guards this ingest', [
                'disk' => $disk,
                'path' => $fullPath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        throw new SourceReservationContendedException($disk, $fullPath);
    }

    /**
     * Seconds a deleting consumer holds the reservation for while it decides
     * and removes: comparable in scope to `SourceKeyLock`'s own 60 s default
     * (age check + storage-key lock wait + reference re-check + delete —
     * never a minutes-long conversion), never `seconds()` — a deleter that
     * crashed mid-hold must not pin a legitimate re-ingest of the SAME file
     * for up to an hour.
     */
    private const DELETION_HOLD_SECONDS = 60;

    /**
     * Is someone reading or converting this object right now? A READ-ONLY
     * probe — try to take it for a moment and give it back — for callers
     * that do not act on the answer (the `--dry-run` preview). A caller that
     * IS about to remove the file must use {@see acquireForRemoval()}
     * instead: a probe here would leave the exact TOCTOU gap this class
     * exists to close, since a new ingest could reserve the instant after
     * this returns and before the removal runs.
     *
     * The release is owner-scoped by the cache store, so a probe can never
     * take a reservation away from its holder. Throws whatever the cache
     * store throws: the caller decides.
     */
    public static function held(string $disk, string $fullPath): bool
    {
        if (! ConversionArtifactStore::cacheStoreCanLock()) {
            return false;
        }
        $probe = Cache::lock(self::key($disk, $fullPath), 1);
        if (! $probe->get()) {
            return true;
        }
        $probe->release();

        return false;
    }

    /**
     * Take the reservation for a DELETING consumer, held through the whole
     * decision-and-removal window instead of released after a probe: null
     * here means "an ingest holds it right now" — the file is in flight —
     * and the caller must keep it, never slip a delete between a probe and
     * the removal itself (the TOCTOU {@see held()}'s docblock warns against).
     *
     * Unlike `reserve()`, contention here is NOT exceptional: it is the
     * expected, frequent outcome for most orphan candidates that turn out
     * not to be orphans, so it returns null rather than throwing — the
     * caller reports `KEPT_IN_FLIGHT` and moves on to the next candidate,
     * never failing a whole sweep over one file in flight.
     *
     * Assumes the caller already confirmed the store can lock
     * (`ConversionArtifactStore::cacheStoreCanLock()`), matching every other
     * lock acquisition in `DocumentDeleter`.
     */
    public static function acquireForRemoval(string $disk, string $fullPath): ?LockContract
    {
        $lock = Cache::lock(self::key($disk, $fullPath), self::DELETION_HOLD_SECONDS);

        return $lock->get() ? $lock : null;
    }

    private static function warnOnce(string $key, string $message, array $context): void
    {
        if (self::$warned[$key] ?? false) {
            return;
        }
        self::$warned[$key] = true;
        Log::warning($message, $context);
    }

    /** Test seam: forget which configuration warnings this process already emitted. */
    public static function resetWarnings(): void
    {
        self::$warned = [];
    }
}
