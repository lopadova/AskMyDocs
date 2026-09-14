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
     * The one branch that does NOT refuse: a lock class that is not
     * Laravel's own exposes no current owner to compare, so the assertion
     * is inert there — documented, and reported once per process (the
     * report resets through the test seam). A regression pins it so the
     * fail-open shape stays deliberate and visible (SEC-FAILCLOSED-001).
     */
    public function test_a_third_party_lock_without_an_owner_to_compare_passes_and_warns_once(): void
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

        $held->assertHeld('the step'); // inert: no throw
        $held->assertHeld('the step');
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message): bool => str_contains($message, 'has no owner to compare'));

        HeldLock::resetWarnings();
        $held->assertHeld('the step');
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->twice()->withArgs(static fn (string $message): bool => str_contains($message, 'has no owner to compare'));
    }
}
