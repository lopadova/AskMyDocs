<?php

declare(strict_types=1);

namespace App\Support\Kb;

use Illuminate\Cache\Lock;
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
 * step itself (ADR 0030 §3). A lock class that is not Laravel's own
 * (a third-party store registered with `Cache::extend()` returning a bare
 * contract implementation) has no owner to compare: the assertion is inert
 * there, and says so once per process.
 */
final class HeldLock
{
    /** @var array<string, true> lock classes already reported as inert */
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
        if (! $this->lock instanceof Lock) {
            if (! isset(self::$warned[$this->lock::class])) {
                self::$warned[$this->lock::class] = true;
                Log::warning('HeldLock: the cache lock class has no owner to compare, so the lapsed-lock check is inert; a TTL that lapses mid-section is not detected on this store', ['lock' => $this->lock::class]);
            }

            return;
        }
        if ($this->lock->isOwnedByCurrentProcess()) {
            return;
        }
        throw new LockLostException(sprintf('%s lock lost before %s: its TTL lapsed while the critical section ran (or the store lost it); refusing rather than running unguarded.', $this->name, $operation));
    }
}
