<?php

declare(strict_types=1);

namespace Tests\Feature\Routines;

use App\Routines\WikiMaintenanceRoutineTarget;
use App\Services\Kb\AutoWiki\WikiMaintainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Padosoft\Routines\Contracts\Execution\FireReason;
use Padosoft\Routines\Contracts\Execution\RoutineExecution;
use Padosoft\Routines\Contracts\Routine\RoutineRef;
use Padosoft\Routines\Contracts\Target\TargetOutcome;
use Tests\TestCase;

/**
 * v8.39/W5 (ADR 0033 §3) — the adapter's own contract, in isolation from
 * the real engine (which {@see WikiRoutineServiceTest} exercises
 * end-to-end). Mirrors {@see \Tests\Feature\Kb\AutoWiki\WikiMaintainTriSurfaceTest}'s
 * pattern of mocking {@see WikiMaintainer}.
 */
final class WikiMaintenanceRoutineTargetTest extends TestCase
{
    use RefreshDatabase;

    private function bindMaintainer(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(WikiMaintainer::class);
        $this->app->instance(WikiMaintainer::class, $mock);

        return $mock;
    }

    private function execution(array $payload): RoutineExecution
    {
        return new RoutineExecution(
            routine: new RoutineRef('rt_1', 'Test routine', 'system:test'),
            runId: 'run_1',
            reason: FireReason::Manual,
            payload: $payload,
            idempotencyKey: 'idem_1',
            scheduledFor: new \DateTimeImmutable('now'),
        );
    }

    public function test_type_is_stable(): void
    {
        $this->assertSame('askmydocs.wiki_maintenance', WikiMaintenanceRoutineTarget::TYPE);
        $target = app(WikiMaintenanceRoutineTarget::class);
        $this->assertSame('askmydocs.wiki_maintenance', $target->type());
    }

    public function test_descriptor_declares_the_two_action_classes_the_maintainer_actually_uses(): void
    {
        $target = app(WikiMaintenanceRoutineTarget::class);
        $descriptor = $target->descriptor();

        $this->assertSame(['kb.wiki.compile', 'kb.wiki.lint'], $descriptor->actionClasses);
        $this->assertFalse($descriptor->supportsPause);
        $this->assertFalse($descriptor->reportsCost);
        $this->assertArrayHasKey('tenant', $descriptor->fields);
    }

    public function test_validate_requires_a_tenant(): void
    {
        $target = app(WikiMaintenanceRoutineTarget::class);

        $this->assertTrue($target->validate(['tenant' => 'acme'])->valid);
        $this->assertFalse($target->validate([])->valid);
        $this->assertFalse($target->validate(['tenant' => ''])->valid);
    }

    public function test_validate_rejects_wrong_types_for_optional_fields(): void
    {
        $target = app(WikiMaintenanceRoutineTarget::class);

        $result = $target->validate(['tenant' => 'acme', 'fix' => 'yes', 'backfill_limit' => -1]);
        $this->assertFalse($result->valid);
        $this->assertArrayHasKey('fix', $result->errors);
        $this->assertArrayHasKey('backfill_limit', $result->errors);
    }

    public function test_fire_delegates_to_the_maintainer_with_the_payload_translated(): void
    {
        $mock = $this->bindMaintainer();
        $mock->shouldReceive('maintain')
            ->once()
            ->with('acme', 'docs-v3', true, 10)
            ->andReturn(['projects' => ['docs-v3'], 'lint_issues' => 2, 'backfilled' => 5, 'fixed' => 1]);

        $target = app(WikiMaintenanceRoutineTarget::class);
        $result = $target->fire($this->execution([
            'tenant' => 'acme',
            'project' => 'docs-v3',
            'fix' => true,
            'backfill_limit' => 10,
        ]));

        $this->assertSame(TargetOutcome::Succeeded, $result->outcome);
        $this->assertStringContainsString('5 doc(s) backfilled', $result->message);
        $this->assertStringContainsString('1 dangling pruned', $result->message);
        $this->assertSame(['projects' => ['docs-v3'], 'lint_issues' => 2, 'backfilled' => 5, 'fixed' => 1], $result->metadata);
    }

    public function test_fire_maintains_every_project_when_project_is_omitted(): void
    {
        $mock = $this->bindMaintainer();
        $mock->shouldReceive('maintain')
            ->once()
            ->with('acme', null, false, null)
            ->andReturn(['projects' => ['a', 'b'], 'lint_issues' => 0, 'backfilled' => 0, 'fixed' => 0]);

        $target = app(WikiMaintenanceRoutineTarget::class);
        $result = $target->fire($this->execution(['tenant' => 'acme']));

        $this->assertSame(TargetOutcome::Succeeded, $result->outcome);
    }
}
