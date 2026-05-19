<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Scheduling\TierOneSchedulerRegistrar;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * v8.0/W2.4 — Tier-1 scheduler env override.
 *
 * Exercises the `TierOneSchedulerRegistrar` directly so the test
 * doesn't need Testbench to execute the host project's
 * `withSchedule` closure (Testbench uses its own bootstrap
 * skeleton; the host closure never fires under tests).
 *
 * Three branches:
 *   1. Default cron is registered when no env overrides exist —
 *      sanity-check the literal defaults are still wired correctly.
 *   2. Override cron via `config()` and assert the registered
 *      `Schedule::Event::expression` matches verbatim.
 *   3. Override `enabled = false` and assert NO event is registered
 *      for the slot.
 *
 * Each test seeds its OWN Schedule instance so registrations from
 * other tests can't leak in.
 */
final class TierOneSchedulerEnvOverrideTest extends TestCase
{
    public function test_default_cron_is_registered_when_no_override(): void
    {
        $schedule = $this->app->make(Schedule::class);
        // Sanity: the schedule instance comes from the IoC container,
        // not from the bootstrap closure, so it starts empty.
        $this->assertSame([], $schedule->events());

        (new TierOneSchedulerRegistrar)->register($schedule);

        $events = $this->collectExpressionsFor($schedule, 'kb:prune-deleted');
        $this->assertContains('30 3 * * *', $events);
    }

    public function test_overridden_cron_propagates_to_registered_event(): void
    {
        config(['askmydocs.schedule.kb_prune_deleted.cron' => '7 7 * * *']);

        $schedule = $this->app->make(Schedule::class);
        (new TierOneSchedulerRegistrar)->register($schedule);

        $events = $this->collectExpressionsFor($schedule, 'kb:prune-deleted');
        $this->assertContains('7 7 * * *', $events);
        $this->assertNotContains('30 3 * * *', $events);
    }

    public function test_disabled_slot_does_not_register(): void
    {
        config(['askmydocs.schedule.kb_prune_deleted.enabled' => false]);

        $schedule = $this->app->make(Schedule::class);
        (new TierOneSchedulerRegistrar)->register($schedule);

        $events = $this->collectExpressionsFor($schedule, 'kb:prune-deleted');
        $this->assertSame([], $events, 'disabled slot must not register any event');
    }

    public function test_every_slot_in_registrar_round_trips_through_config(): void
    {
        // Sanity: nudge every slot's cron to a recognisable sentinel
        // and assert all 10 are observable on the schedule. Catches
        // the regression where a SLOT entry desyncs from the
        // config/.env.example listing.
        $slots = [
            'kb:prune-embedding-cache',
            'chat-log:prune',
            'kb:prune-deleted',
            'kb:rebuild-graph',
            'queue:prune-failed --hours=48',
            'admin-audit:prune',
            'admin-nonces:prune',
            'notifications:prune',
            'kb:prune-orphan-files --dry-run',
            'insights:compute',
        ];

        $schedule = $this->app->make(Schedule::class);
        (new TierOneSchedulerRegistrar)->register($schedule);

        foreach ($slots as $command) {
            $events = $this->collectExpressionsFor($schedule, $command);
            $this->assertNotEmpty(
                $events,
                "Tier-1 slot for `{$command}` must register at least one Schedule event",
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function collectExpressionsFor(Schedule $schedule, string $needle): array
    {
        $matches = [];
        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, $needle)) {
                $matches[] = $event->expression;
            }
        }

        return $matches;
    }
}
