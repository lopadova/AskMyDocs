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
 * Five branches:
 *   1. Default cron is registered when no env overrides exist —
 *      sanity-check the literal defaults are still wired correctly.
 *   2. Override cron via `config()` and assert the registered
 *      `Schedule::Event::expression` matches verbatim.
 *   3. Override `enabled = false` and assert NO event is registered
 *      for the slot.
 *   4. Override EVERY base SLOT's cron to a recognisable sentinel and
 *      assert every registered event picks up the override — catches
 *      the regression where a SLOTS entry desyncs from the
 *      `config/askmydocs.php` + `.env.example` listing.
 *   5. Composite-gated slots (`eval_nightly`, `ai_act_regulatory_poll`)
 *      are not in the SLOTS list (bootstrap wraps them in upstream
 *      env gates), but their Tier-1 config entries must still
 *      round-trip through `registerSlot()` so the override knobs
 *      work when the upstream gate IS open.
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
        // Derive `$slotMap` straight from the registrar's slot list
        // (Copilot iter-5 #L90 — the prior hard-coded `$slotMap` would
        // have happily ignored a slot newly added to the registrar).
        // Per-slot sentinel crons are generated programmatically so
        // every slot tests against a UNIQUE expression (no false-pass
        // via collision with another slot's override). A SLOTS desync
        // from the `config/askmydocs.php` + `.env.example` listing
        // would surface either as a missing event or an unchanged
        // default cron.
        $slotMap = [];
        $i = 1;
        foreach (TierOneSchedulerRegistrar::slots() as [$slotKey, $command]) {
            $slotMap[$slotKey] = [$command, sprintf('%d 1 * * *', $i++)];
        }
        $this->assertNotEmpty($slotMap, 'TierOneSchedulerRegistrar::slots() must return at least one slot');

        // Pre-step (Copilot iter-2): seeding `config(...)` for every
        // slot BEFORE registration would mask a missing entry in
        // `config/askmydocs.php` — the sentinel would propagate just
        // fine via the test's own override even if the slot was never
        // declared anywhere else. Assert FIRST that each slot key
        // exists in `config('askmydocs.schedule')` (which the test
        // bootstrap loads verbatim from `config/askmydocs.php`), so a
        // missing slot surfaces as a failed key-existence check, not
        // a silent pass via the test-only override.
        $declaredScheduleConfig = (array) config('askmydocs.schedule', []);
        foreach (array_keys($slotMap) as $slotKey) {
            $this->assertArrayHasKey(
                $slotKey,
                $declaredScheduleConfig,
                "Slot `{$slotKey}` is registered by TierOneSchedulerRegistrar but absent from `config/askmydocs.php` — the env-override + .env.example documentation would be wrong",
            );
        }

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

    public function test_composite_gated_slots_round_trip_through_config(): void
    {
        // Composite-gated slots (`eval_nightly` + `ai_act_regulatory_poll`)
        // are NOT registered by `register()` — bootstrap/app.php wraps
        // the calls behind upstream env gates (`EVAL_NIGHTLY_ENABLED` /
        // `AI_ACT_REGULATORY_FEED_ENABLED`). The Tier-1 slots still need
        // a `config/askmydocs.php` entry so the cron/enabled override
        // works when the upstream gate IS open; this test exercises the
        // inner `registerSlot()` call directly (Copilot iter-3 caught
        // the missing coverage).
        $compositeSlots = [
            'eval_nightly' => ['eval:nightly', '11 1 * * *'],
            'ai_act_regulatory_poll' => ['ai-act:regulatory-poll', '12 1 * * *'],
        ];

        $declaredScheduleConfig = (array) config('askmydocs.schedule', []);
        foreach (array_keys($compositeSlots) as $slotKey) {
            $this->assertArrayHasKey(
                $slotKey,
                $declaredScheduleConfig,
                "Composite-gated slot `{$slotKey}` is referenced by `bootstrap/app.php` but absent from `config/askmydocs.php` — env-override + .env.example documentation would be wrong",
            );
        }

        foreach ($compositeSlots as $slotKey => [$command, $sentinelCron]) {
            config(["askmydocs.schedule.$slotKey.cron" => $sentinelCron]);
        }

        $schedule = $this->app->make(Schedule::class);
        $registrar = new TierOneSchedulerRegistrar;
        foreach ($compositeSlots as $slotKey => [$command, $sentinelCron]) {
            $registrar->registerSlot($schedule, $slotKey, $command);
        }

        foreach ($compositeSlots as $slotKey => [$command, $sentinelCron]) {
            $events = $this->collectExpressionsFor($schedule, $command);
            $this->assertContains(
                $sentinelCron,
                $events,
                "Composite-gated slot `{$slotKey}` did not propagate its overridden cron through registerSlot()",
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
