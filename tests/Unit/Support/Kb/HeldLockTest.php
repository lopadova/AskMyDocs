<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Kb;

use App\Support\Kb\HeldLock;
use App\Support\Kb\LockLostException;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ADR 0030 §3 — a critical section asserts, right before its irreversible
 * step, that the cache lock it took is STILL its own: a TTL that lapsed (or
 * a store that lost the lock) is a refusal, never a step run under another
 * holder.
 */
final class HeldLockTest extends TestCase
{
    public function test_a_lock_still_owned_passes_and_a_lost_one_refuses(): void
    {
        $lock = Cache::lock('kb:test:held', 60);
        $this->assertTrue($lock->get());
        $held = new HeldLock($lock, 'test');

        $held->assertHeld('the step'); // owned: no throw

        $lock->forceRelease(); // the TTL lapsed / the store lost it
        try {
            $held->assertHeld('the step');
            $this->fail('a lost lock must refuse the step');
        } catch (LockLostException $e) {
            $this->assertStringContainsString('test lock lost before the step', $e->getMessage());
        }
    }

    public function test_a_lock_re_taken_by_another_holder_refuses_the_first(): void
    {
        $first = Cache::lock('kb:test:retaken', 60);
        $this->assertTrue($first->get());
        $first->forceRelease();
        $second = Cache::lock('kb:test:retaken', 60);
        $this->assertTrue($second->get());

        $this->expectException(LockLostException::class);
        (new HeldLock($first, 'test'))->assertHeld('the step');
    }

    /**
     * Capability, not inheritance: a third-party lock that DOES record its
     * acquisition owner answers the ownership question just as well as
     * Laravel's own, so it is verified — passing while it owns the lock,
     * refusing once it does not — instead of being rejected outright.
     */
    public function test_a_third_party_lock_that_records_its_owner_is_verified_not_refused(): void
    {
        $capable = new class implements \Illuminate\Contracts\Cache\Lock
        {
            public bool $mine = true;

            public function get($callback = null)
            {
                return true;
            }

            public function block($seconds, $callback = null)
            {
                return true;
            }

            public function release()
            {
                return true;
            }

            public function owner()
            {
                return 'me';
            }

            public function forceRelease()
            {
            }

            public function isOwnedByCurrentProcess()
            {
                return $this->mine;
            }
        };
        $held = new HeldLock($capable, 'test');

        $held->assertHeld('the step'); // owned: no throw, no refusal

        $capable->mine = false;
        $this->expectException(LockLostException::class);
        $held->assertHeld('the step');
    }

    /**
     * The shape this capability probe exists for: a lock that HAS an
     * `isOwnedByCurrentProcess()` but does not expose it. `method_exists()`
     * says yes and the call raises an Error; `is_callable()` says no and the
     * step is refused, which is what this class owes its caller.
     */
    public function test_a_lock_whose_ownership_probe_is_not_public_is_refused_not_fatal(): void
    {
        $private = new class implements \Illuminate\Contracts\Cache\Lock
        {
            public function get($callback = null)
            {
                return true;
            }

            public function block($seconds, $callback = null)
            {
                return true;
            }

            public function release()
            {
                return true;
            }

            public function owner()
            {
                return 'me';
            }

            public function forceRelease() {}

            private function isOwnedByCurrentProcess(): bool
            {
                return true;
            }
        };

        $this->expectException(LockLostException::class);
        $this->expectExceptionMessageMatches('/ownership cannot be verified/');
        (new HeldLock($private, 'test'))->assertHeld('the step');
    }

    /** A probe that answers something other than a bool (a `__call` proxy, a double) is not an answer either. */
    public function test_a_probe_that_does_not_answer_a_bool_is_refused_as_unverifiable(): void
    {
        $vague = new class implements \Illuminate\Contracts\Cache\Lock
        {
            public function get($callback = null)
            {
                return true;
            }

            public function block($seconds, $callback = null)
            {
                return true;
            }

            public function release()
            {
                return true;
            }

            public function owner()
            {
                return 'me';
            }

            public function forceRelease() {}

            public function isOwnedByCurrentProcess()
            {
                return null; // a proxy that forwards nothing
            }
        };

        $this->expectException(LockLostException::class);
        $this->expectExceptionMessageMatches('/ownership cannot be verified/');
        (new HeldLock($vague, 'test'))->assertHeld('the step');
    }

    /**
     * The probe is a driver round-trip (Redis `get`) and, on a `__call`-backed
     * proxy or a test double, it may THROW rather than answer. A throw is not
     * an answer either: it must converge on the same refusal instead of
     * escaping as a type the callers (which catch LockLostException) would
     * miss — a hard delete that let it escape would fail AFTER its row
     * transaction had already committed.
     */
    public function test_a_probe_that_throws_is_refused_as_unverifiable_not_propagated(): void
    {
        $angry = new class implements \Illuminate\Contracts\Cache\Lock
        {
            public function get($callback = null)
            {
                return true;
            }

            public function block($seconds, $callback = null)
            {
                return true;
            }

            public function release()
            {
                return true;
            }

            public function owner()
            {
                return 'me';
            }

            public function forceRelease() {}

            public function __call(string $name, array $arguments): mixed
            {
                throw new \BadMethodCallException("no such method [{$name}]");
            }
        };

        $this->expectException(LockLostException::class);
        $this->expectExceptionMessageMatches('/ownership cannot be verified/');
        (new HeldLock($angry, 'test'))->assertHeld('the step');
    }

    /**
     * A lock that records no acquisition owner cannot answer the ownership
     * question at all, so the step is REFUSED — the same posture as a store
     * that cannot lock (SEC-FAILCLOSED-001), never an assertion that
     * silently passes. The reason is reported once per class (the report
     * resets through the test seam).
     */
    public function test_a_third_party_lock_without_an_owner_to_compare_is_refused_and_warns_once(): void
    {
        $bare = new class implements \Illuminate\Contracts\Cache\Lock
        {
            public function get($callback = null)
            {
                return true;
            }

            public function block($seconds, $callback = null)
            {
                return true;
            }

            public function release()
            {
                return true;
            }

            public function owner()
            {
                return 'me';
            }

            public function forceRelease()
            {
            }
        };
        \Illuminate\Support\Facades\Log::spy();
        $held = new HeldLock($bare, 'test');

        foreach ([1, 2] as $attempt) {
            $thrown = null;
            try {
                $held->assertHeld('the step');
            } catch (LockLostException $e) {
                $thrown = $e;
            }
            $this->assertInstanceOf(LockLostException::class, $thrown, "attempt {$attempt} must refuse");
            $this->assertStringContainsString('ownership cannot be verified before the step', $thrown->getMessage());
        }
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message): bool => str_contains($message, 'records no acquisition owner'));

        HeldLock::resetWarnings();
        try {
            $held->assertHeld('the step');
        } catch (LockLostException) {
            // expected again: the refusal does not depend on the report
        }
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->twice()->withArgs(static fn (string $message): bool => str_contains($message, 'records no acquisition owner'));
    }
}
