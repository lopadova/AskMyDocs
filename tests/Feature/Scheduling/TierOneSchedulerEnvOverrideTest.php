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
 * Four branches:
 *   1. Default cron is registered when no env overrides exist —
 *      sanity-check the literal defaults are still wired correctly.
 *   2. Override cron via `config()` and assert the registered
 *      `Schedule::Event::expression` matches verbatim.
 *   3. Override `enabled = false` and assert NO event is registered
 *      for the slot.
 *   4. Override EVERY slot's cron to a recognisable sentinel and
 *      assert every registered event picks up the override — catches
 *      the regression where a SLOTS entry desyncs from the
 *      `config/askmydocs.php` + `.env.example` listing.
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
        // Each slot key gets its own recognisable sentinel cron so the
        // assertion can verify the registrar correctly mapped THIS
        // slot's config entry to THIS slot's Schedule event (not just
        // "any cron landed for any slot"). A SLOTS desync from the
        // config / .env.example listing would surface as either a
        // missing event or an unchanged default cron.
        $slotMap = [
            'kb_prune_embedding_cache' => ['kb:prune-embedding-cache', '1 1 * * *'],
            'chat_log_prune' => ['chat-log:prune', '2 1 * * *'],
            'kb_prune_deleted' => ['kb:prune-deleted', '3 1 * * *'],
            'kb_rebuild_graph' => ['kb:rebuild-graph', '4 1 * * *'],
            'queue_prune_failed' => ['queue:prune-failed --hours=48', '5 1 * * *'],
            'admin_audit_prune' => ['admin-audit:prune', '6 1 * * *'],
            'admin_nonces_prune' => ['admin-nonces:prune', '7 1 * * *'],
            'notifications_prune' => ['notifications:prune', '8 1 * * *'],
            'kb_prune_orphan_files' => ['kb:prune-orphan-files --dry-run', '9 1 * * *'],
            'insights_compute' => ['insights:compute', '10 1 * * *'],
        ];

        foreach ($slotMap as $slotKey => [$command, $sentinelCron]) {
            config(["askmydocs.schedule.$slotKey.cron" => $sentinelCron]);
        }

        $schedule = $this->app->make(Schedule::class);
        (new TierOneSchedulerRegistrar)->register($schedule);

        foreach ($slotMap as $slotKey => [$command, $sentinelCron]) {
            $events = $this->collectExpressionsFor($schedule, $command);
            $this->assertContains(
                $sentinelCron,
                $events,
                "Tier-1 slot `{$slotKey}` did not propagate its overridden cron `{$sentinelCron}` to the `{$command}` Schedule event",
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
