<?php

declare(strict_types=1);

namespace Tests\Fixtures\Cache;

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;

/**
 * A cache store that behaves like the real array store for every lock
 * EXCEPT the ones `$lapses` names: those still GRANT (`get()` succeeds —
 * the caller believes it acquired the reservation) but report NOT owned
 * as soon as anyone asks (`isOwnedByCurrentProcess()` always false) — the
 * simplest deterministic stand-in for "the TTL lapsed between acquisition
 * and the assertion right before the irreversible step", without needing
 * real concurrency or timing in a synchronous test.
 * {@see \App\Support\Kb\HeldLock::assertHeld()} treats it exactly like a
 * lease that genuinely expired mid-operation: `LockLostException`.
 *
 * Scoped by lock NAME (not store-wide) so a test can make ONE reservation
 * lapse — e.g. a source reservation — while a SIBLING lock acquired from
 * the same store in the same call (e.g. the run reservation) stays
 * genuinely owned throughout.
 */
final class LapsingLockStore implements Store, LockProvider
{
    private ArrayStore $inner;

    /** @param  callable(string): bool  $lapses  true for a lock NAME that should grant-but-report-unowned */
    public function __construct(private $lapses)
    {
        $this->inner = new ArrayStore;
    }

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        if (($this->lapses)($name)) {
            return new LapsingLock($name);
        }

        return $this->inner->lock($name, $seconds, $owner);
    }

    public function restoreLock($name, $owner): Lock
    {
        if (($this->lapses)($name)) {
            return new LapsingLock($name);
        }

        return $this->inner->restoreLock($name, $owner);
    }

    public function get($key)
    {
        return $this->inner->get($key);
    }

    public function many(array $keys)
    {
        return $this->inner->many($keys);
    }

    public function put($key, $value, $seconds)
    {
        return $this->inner->put($key, $value, $seconds);
    }

    public function putMany(array $values, $seconds)
    {
        return $this->inner->putMany($values, $seconds);
    }

    public function increment($key, $value = 1)
    {
        return $this->inner->increment($key, $value);
    }

    public function decrement($key, $value = 1)
    {
        return $this->inner->decrement($key, $value);
    }

    public function forever($key, $value)
    {
        return $this->inner->forever($key, $value);
    }

    public function touch($key, $seconds)
    {
        return $this->inner->touch($key, $seconds);
    }

    public function forget($key)
    {
        return $this->inner->forget($key);
    }

    public function flush()
    {
        return $this->inner->flush();
    }

    public function getPrefix()
    {
        return $this->inner->getPrefix();
    }
}
