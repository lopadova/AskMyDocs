<?php

declare(strict_types=1);

namespace Tests\Feature\Routines;

use App\Routines\WikiMaintenanceRoutineTarget;
use App\Routines\WikiRoutineService;
use App\Services\Kb\AutoWiki\WikiMaintainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Targets\TargetRegistry;
use Tests\TestCase;

/**
 * v8.39/W5 (ADR 0033 §4/§8) — end-to-end against the REAL
 * `padosoft/laravel-routines` engine (a `require` dependency of this app,
 * ADR 0033 §2 — not mocked). Registers the target manually per test
 * (mirrors {@see \App\Providers\AppServiceProvider::registerWikiRoutineTarget()})
 * since these tests need it registered regardless of the config flag.
 */
final class WikiRoutineServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Binds the WikiMaintainer mock FIRST, then builds+registers the
     * target — WikiMaintenanceRoutineTarget resolves its WikiMaintainer
     * dependency at CONSTRUCTION time, so registering it before the mock
     * is bound would capture the real service instead.
     */
    private function bindMaintainerAndRegisterTarget(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(WikiMaintainer::class);
        $this->app->instance(WikiMaintainer::class, $mock);
        app(TargetRegistry::class)->register(app(WikiMaintenanceRoutineTarget::class));

        return $mock;
    }

    public function test_status_reports_not_provisioned_when_no_routine_exists_yet(): void
    {
        $status = app(WikiRoutineService::class)->status('acme');

        $this->assertFalse($status['provisioned']);
    }

    public function test_run_provisions_a_routine_on_first_use(): void
    {
        $mock = $this->bindMaintainerAndRegisterTarget();
        $mock->shouldReceive('maintain')->once()
            ->with('acme', null, false, null)
            ->andReturn(['projects' => ['docs'], 'lint_issues' => 0, 'backfilled' => 2, 'fixed' => 0]);

        $service = app(WikiRoutineService::class);
        $result = $service->run('acme');

        $this->assertSame('succeeded', $result['outcome']);
        $this->assertDatabaseHas('routines', [
            'target_type' => WikiMaintenanceRoutineTarget::TYPE,
            'organization_id' => 'acme',
            'status' => 'active',
        ]);

        $status = $service->status('acme');
        $this->assertTrue($status['provisioned']);
        $this->assertSame('active', $status['status']);
        $this->assertNotNull($status['last_run']);
        $this->assertSame('succeeded', $status['last_run']['outcome']);
    }

    public function test_run_reuses_the_existing_routine_for_the_same_tenant_rather_than_provisioning_a_second_one(): void
    {
        $mock = $this->bindMaintainerAndRegisterTarget();
        $mock->shouldReceive('maintain')->twice()
            ->andReturn(['projects' => [], 'lint_issues' => 0, 'backfilled' => 0, 'fixed' => 0]);

        $service = app(WikiRoutineService::class);
        $service->run('acme');
        $service->run('acme');

        $this->assertSame(1, Routine::query()->where('target_type', WikiMaintenanceRoutineTarget::TYPE)->where('organization_id', 'acme')->count());
    }

    public function test_two_tenants_get_two_independent_routines_r30(): void
    {
        $mock = $this->bindMaintainerAndRegisterTarget();
        $mock->shouldReceive('maintain')->twice()
            ->andReturn(['projects' => [], 'lint_issues' => 0, 'backfilled' => 0, 'fixed' => 0]);

        $service = app(WikiRoutineService::class);
        $service->run('tenant-a');
        $service->run('tenant-b');

        $this->assertTrue($service->status('tenant-a')['provisioned']);
        $this->assertTrue($service->status('tenant-b')['provisioned']);
        $this->assertSame(2, Routine::query()->where('target_type', WikiMaintenanceRoutineTarget::TYPE)->count());
    }

    public function test_run_grants_no_mandate_the_routine_runs_as_the_application_adr_0033_7(): void
    {
        $mock = $this->bindMaintainerAndRegisterTarget();
        $mock->shouldReceive('maintain')->once()
            ->andReturn(['projects' => [], 'lint_issues' => 0, 'backfilled' => 0, 'fixed' => 0]);

        app(WikiRoutineService::class)->run('acme');

        $routine = Routine::query()->where('organization_id', 'acme')->first();
        $this->assertNotNull($routine);
        $this->assertNull($routine->mandate);
        $this->assertNull($routine->mandate_digest);
    }
}
