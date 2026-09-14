<?php

declare(strict_types=1);

namespace App\Support\Kb;

use Illuminate\Contracts\Cache\Lock as LockContract;
use Illuminate\Support\Facades\Log;

/**
 * The lock a critical section runs under, handed to the section so it can
 * assert — right before its irreversible step — that the lock is STILL its
 * own. A cache lock has a TTL and no renewal: a slow scan or a large commit
 * can outlive it, and a second holder would then enter the same section. The
 * assertion makes that lapse a refusal (LockLostException) instead of a
 * silent race: the caller discards, keeps, or reports `failed`. It is a
 * check right before the step, not a renewal: the window shrinks to the
 * step itself (ADR 0030 §3). Ownership is read from Laravel's own lock, or
 * from any lock that answers `isOwnedByCurrentProcess()` — capability, not
 * inheritance. A lock that records no acquisition owner at all (a
 * third-party store registered with `Cache::extend()` returning a bare
 * contract implementation) cannot prove ownership, so its step is REFUSED —
 * the same posture as a store that cannot lock at all, where a publish is
 * refused and a removal reported `failed` (SEC-FAILCLOSED-001). On such a
 * store that is not a degradation but a stop: every artifact-enabled ingest
 * rolls back and retries, and every prune reports `failed`. The reason is
 * therefore reported once per class, so an operator sees which store to
 * replace with a lock-capable one (Redis in production).
 */
final class HeldLock
{
    /** @var array<string, true> lock classes already reported as unverifiable */
    private static array $warned = [];

    public function __construct(private readonly LockContract $lock, private readonly string $name) {}

    /**
     * The companion of every section: a release the store refused (a blip)
     * never turns a decision already taken — a commit, a publish, a delete —
     * into a failure; the lock lapses with its TTL. `null` (no lock was
     * needed) passes through.
     */
    public static function releaseQuietly(?LockContract $lock): void
    {
        if ($lock === null) {
            return;
        }
        try {
            $lock->release();
        } catch (\Throwable) {
            // lapses with its TTL
        }
    }

    /** Test seam: forget which lock classes were reported (mirrors ConversionArtifactStore::resetWarnings()). */
    public static function resetWarnings(): void
    {
        self::$warned = [];
    }

    /**
     * @throws LockLostException when the lock is no longer owned by this process
     */
    public function assertHeld(string $operation): void
    {
        // Capability, not inheritance: a third-party lock that records its
        // acquisition owner answers the question just as well as Laravel's
        // own, and is verified rather than rejected. `is_callable`, not
        // `method_exists`: a NON-PUBLIC `isOwnedByCurrentProcess()` exists
        // but cannot be called, and calling it would raise an Error instead
        // of the refusal this class owes its caller.
        // The probe is a driver round-trip (Redis `get`), and on a
        // `__call`-backed proxy or a test double it may THROW rather than
        // answer. A throw is not an answer either, so it converges on the
        // same refusal instead of escaping as a type this class's callers
        // (which catch LockLostException) would miss.
        try {
            $owned = is_callable([$this->lock, 'isOwnedByCurrentProcess'])
                ? $this->lock->isOwnedByCurrentProcess()
                : null;
        } catch (\Throwable) {
            $owned = null;
        }
        // `null` covers every shape of "cannot answer": no callable probe at
        // all, a probe that threw, and a probe that answered something other
        // than a bool. None is an answer, and none may be read as "lost" —
        // the operator must see WHY.
        if (! is_bool($owned)) {
            if (! isset(self::$warned[$this->lock::class])) {
                self::$warned[$this->lock::class] = true;
                Log::warning('HeldLock: the cache lock class records no acquisition owner to compare against owner(), so a lapsed TTL cannot be detected; every guarded step is REFUSED on this store — an ingest fails and retries, a prune reports failed. Configure a lock-capable cache store (Redis in production)', ['lock' => $this->lock::class]);
            }

            throw new LockLostException(sprintf('%s lock ownership cannot be verified before %s on %s: refusing rather than running unguarded.', $this->name, $operation, $this->lock::class));
        }
        if ($owned) {
            return;
        }
        throw new LockLostException(sprintf('%s lock lost before %s: its TTL lapsed while the critical section ran (or the store lost it); refusing rather than running unguarded.', $this->name, $operation));
    }
}
