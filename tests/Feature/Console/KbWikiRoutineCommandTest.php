<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Routines\WikiMaintenanceRoutineTarget;
use App\Services\Kb\AutoWiki\WikiMaintainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Padosoft\Routines\Targets\TargetRegistry;
use Tests\TestCase;

/**
 * v8.39/W5 (ADR 0033 §8) — `kb:wiki-routine {status|run}`. `status` is
 * always available (R43 both states); `run` requires the flag on.
 */
final class KbWikiRoutineCommandTest extends TestCase
{
    use RefreshDatabase;

    private function bindMaintainerAndRegisterTarget(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(WikiMaintainer::class);
        $this->app->instance(WikiMaintainer::class, $mock);
        app(TargetRegistry::class)->register(app(WikiMaintenanceRoutineTarget::class));

        return $mock;
    }

    public function test_status_reports_disabled_when_the_flag_is_off(): void
    {
        config(['kb.wiki_routine.enabled' => false]);

        $this->artisan('kb:wiki-routine', ['action' => 'status'])
            ->expectsOutputToContain('"disabled": true')
            ->assertSuccessful();
    }

    public function test_run_refuses_when_the_flag_is_off(): void
    {
        config(['kb.wiki_routine.enabled' => false]);

        $this->artisan('kb:wiki-routine', ['action' => 'run'])
            ->assertFailed();
    }

    public function test_status_reports_not_provisioned_when_enabled_but_no_routine_yet(): void
    {
        config(['kb.wiki_routine.enabled' => true]);
        $this->bindMaintainerAndRegisterTarget();

        $this->artisan('kb:wiki-routine', ['action' => 'status', '--tenant' => 'acme'])
            ->expectsOutputToContain('No wiki-maintenance routine provisioned yet')
            ->assertSuccessful();
    }

    public function test_run_provisions_and_fires_the_routine(): void
    {
        config(['kb.wiki_routine.enabled' => true]);
        $mock = $this->bindMaintainerAndRegisterTarget();
        $mock->shouldReceive('maintain')->once()
            ->andReturn(['projects' => ['docs'], 'lint_issues' => 0, 'backfilled' => 4, 'fixed' => 0]);

        $this->artisan('kb:wiki-routine', ['action' => 'run', '--tenant' => 'acme'])
            ->expectsOutputToContain('4 doc(s) backfilled')
            ->assertSuccessful();
    }

    public function test_unknown_action_fails(): void
    {
        $this->artisan('kb:wiki-routine', ['action' => 'nonsense'])
            ->assertFailed();
    }
}
