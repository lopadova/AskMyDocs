<?php

declare(strict_types=1);

namespace Tests\Fixtures\Cache;

use Illuminate\Contracts\Cache\Lock;

/**
 * Always grants (`get()` succeeds), never reports owned
 * (`isOwnedByCurrentProcess()` always false) — see {@see LapsingLockStore}.
 */
final class LapsingLock implements Lock
{
    public function __construct(private readonly string $name) {}

    public function get($callback = null)
    {
        if ($callback !== null) {
            return $callback();
        }

        return true;
    }

    public function block($seconds, $callback = null)
    {
        return $this->get($callback);
    }

    public function release(): bool
    {
        return true;
    }

    public function owner()
    {
        return '';
    }

    public function forceRelease(): void {}

    /** The capability {@see \App\Support\Kb\HeldLock::assertHeld()} probes via `is_callable`. */
    public function isOwnedByCurrentProcess(): bool
    {
        return false;
    }
}
