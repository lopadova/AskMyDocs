<?php

declare(strict_types=1);

namespace Tests\Fixtures\Cache;

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Store;

/**
 * A cache store that stores like the array store but is NOT a
 * `LockProvider` — the shape of `apc`, `session` and any custom store —
 * so a test can prove the artifact temp lease degrades to the age
 * threshold instead of taking every ingest down with
 * "This cache store does not support locking."
 */
final class NoLockStore implements Store
{
    private ArrayStore $inner;

    public function __construct()
    {
        $this->inner = new ArrayStore;
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
