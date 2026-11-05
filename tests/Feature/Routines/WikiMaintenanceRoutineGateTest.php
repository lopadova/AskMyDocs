<?php

declare(strict_types=1);

namespace Tests\Feature\Routines;

use App\Routines\WikiMaintenanceRoutineGate;
use App\Routines\WikiMaintenanceRoutineTarget;
use App\Services\Kb\AutoWiki\WikiMaintainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Targets\TargetRegistry;
use Tests\TestCase;

/**
 * v8.39/W5 (ADR 0033 §6) — the "no double run, no orphaned run" decision.
 * Returns `true` (keep the legacy cron — the SAFE default) unless ALL
 * three conditions hold; every path that returns `true` is a distinct
 * scenario this test proves explicitly.
 */
final class WikiMaintenanceRoutineGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Bind a mock so registering the target never needs a real
        // WikiMaintainer for these gate-only tests.
        $this->app->instance(WikiMaintainer::class, Mockery::mock(WikiMaintainer::class));
    }

    public function test_flag_off_keeps_the_cron(): void
    {
        config(['kb.wiki_routine.enabled' => false]);

        $this->assertTrue(app(WikiMaintenanceRoutineGate::class)->cronSlotShouldStayActive());
    }

    public function test_flag_on_but_target_not_registered_keeps_the_cron(): void
    {
        config(['kb.wiki_routine.enabled' => true]);
        // No app(TargetRegistry::class)->register(...) call in this test.

        $this->assertTrue(app(WikiMaintenanceRoutineGate::class)->cronSlotShouldStayActive());
    }

    public function test_flag_on_target_registered_but_no_active_routine_yet_keeps_the_cron(): void
    {
        config(['kb.wiki_routine.enabled' => true]);
        app(TargetRegistry::class)->register(app(WikiMaintenanceRoutineTarget::class));

        $this->assertTrue(app(WikiMaintenanceRoutineGate::class)->cronSlotShouldStayActive());
    }

    /**
     * The legacy `kb_wiki_maintain` slot only ever maintains tenant
     * `default` ({@see \App\Console\Commands\KbWikiMaintainCommand}'s
     * own `--tenant=default`), so ONLY an active routine for THAT tenant
     * may disable it.
     */
    public function test_flag_on_target_registered_and_an_active_routine_for_the_legacy_tenant_disables_the_cron(): void
    {
        config(['kb.wiki_routine.enabled' => true]);
        app(TargetRegistry::class)->register(app(WikiMaintenanceRoutineTarget::class));

        Routine::create([
            'owner' => 'system:test',
            'organization_id' => 'default',
            'name' => 'Wiki maintenance — default',
            'target_type' => WikiMaintenanceRoutineTarget::TYPE,
            'target_payload' => ['tenant' => 'default'],
            'trigger_kind' => 'cron',
            'cron' => '40 4 * * *',
        ]);

        $this->assertFalse(app(WikiMaintenanceRoutineGate::class)->cronSlotShouldStayActive());
    }

    /**
     * Subagent review, PR #512 — must-fix #1: an active routine for a
     * DIFFERENT tenant (e.g. `acme` turning the feature on for itself)
     * must NEVER disable the shared `kb_wiki_maintain` slot that tenant
     * `default` still depends on. An un-scoped `exists()` check here would
     * silently starve `default` of all wiki maintenance the moment ANY
     * other tenant enables the routine — with no error, no log naming the
     * loss.
     */
    public function test_flag_on_and_an_active_routine_for_a_different_tenant_still_keeps_the_cron(): void
    {
        config(['kb.wiki_routine.enabled' => true]);
        app(TargetRegistry::class)->register(app(WikiMaintenanceRoutineTarget::class));

        Routine::create([
            'owner' => 'system:test',
            'organization_id' => 'acme',
            'name' => 'Wiki maintenance — acme',
            'target_type' => WikiMaintenanceRoutineTarget::TYPE,
            'target_payload' => ['tenant' => 'acme'],
            'trigger_kind' => 'cron',
            'cron' => '40 4 * * *',
        ]);

        $this->assertTrue(app(WikiMaintenanceRoutineGate::class)->cronSlotShouldStayActive());
    }

    public function test_flag_on_but_only_a_paused_routine_exists_for_the_legacy_tenant_keeps_the_cron(): void
    {
        config(['kb.wiki_routine.enabled' => true]);
        app(TargetRegistry::class)->register(app(WikiMaintenanceRoutineTarget::class));

        $routine = Routine::create([
            'owner' => 'system:test',
            'organization_id' => 'default',
            'name' => 'Wiki maintenance — default',
            'target_type' => WikiMaintenanceRoutineTarget::TYPE,
            'target_payload' => ['tenant' => 'default'],
            'trigger_kind' => 'cron',
            'cron' => '40 4 * * *',
        ]);
        $routine->pause();

        $this->assertTrue(app(WikiMaintenanceRoutineGate::class)->cronSlotShouldStayActive());
    }

    /**
     * ADR 0033 §6 — `withSchedule()`'s closure runs on every console boot,
     * not only `schedule:run`; an unreachable database must never turn
     * into a crash, only "keep the cron."
     */
    public function test_a_database_error_on_the_routine_check_fails_safe_and_keeps_the_cron(): void
    {
        config(['kb.wiki_routine.enabled' => true]);
        app(TargetRegistry::class)->register(app(WikiMaintenanceRoutineTarget::class));
        Schema::drop('routines');

        $this->assertTrue(app(WikiMaintenanceRoutineGate::class)->cronSlotShouldStayActive());
    }
}
