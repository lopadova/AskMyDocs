<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\KbWikiRoutineStatusTool;
use App\Routines\WikiMaintenanceRoutineTarget;
use App\Routines\WikiRoutineService;
use App\Services\Kb\AutoWiki\WikiMaintainer;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Mockery;
use Padosoft\Routines\Targets\TargetRegistry;
use Tests\TestCase;

/**
 * v8.39/W5 (ADR 0033 §8) — read-only MCP status surface. No `run` tool
 * exists on this server for this capability (documented R44 exception,
 * proven by {@see \Tests\Unit\Mcp\KnowledgeBaseServerRegistrationTest}'s
 * file-vs-registration roster diff).
 */
final class KbWikiRoutineStatusToolTest extends TestCase
{
    use RefreshDatabase;

    private function bindMaintainerAndRegisterTarget(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(WikiMaintainer::class);
        $this->app->instance(WikiMaintainer::class, $mock);
        app(TargetRegistry::class)->register(app(WikiMaintenanceRoutineTarget::class));

        return $mock;
    }

    private function callTool(): array
    {
        $response = (new KbWikiRoutineStatusTool())->handle(
            new Request([]),
            app(WikiRoutineService::class),
            app(TenantContext::class),
        );

        return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_it_answers_disabled_when_the_flag_is_off(): void
    {
        config(['kb.wiki_routine.enabled' => false]);

        $this->assertSame(['disabled' => true, 'flag' => 'KB_WIKI_ROUTINE_ENABLED'], $this->callTool());
    }

    public function test_it_reports_not_provisioned_when_enabled_but_no_routine_yet(): void
    {
        config(['kb.wiki_routine.enabled' => true]);
        $this->bindMaintainerAndRegisterTarget();

        $this->assertFalse($this->callTool()['provisioned']);
    }

    public function test_it_reports_status_for_a_provisioned_routine(): void
    {
        config(['kb.wiki_routine.enabled' => true]);
        $mock = $this->bindMaintainerAndRegisterTarget();
        $mock->shouldReceive('maintain')->once()->andReturn(['projects' => [], 'lint_issues' => 0, 'backfilled' => 0, 'fixed' => 0]);

        app(WikiRoutineService::class)->run(app(TenantContext::class)->current());

        $status = $this->callTool();
        $this->assertTrue($status['provisioned']);
        $this->assertSame('active', $status['status']);
    }

    public function test_it_does_not_see_another_tenants_routine(): void
    {
        config(['kb.wiki_routine.enabled' => true]);
        $mock = $this->bindMaintainerAndRegisterTarget();
        $mock->shouldReceive('maintain')->once()->andReturn(['projects' => [], 'lint_issues' => 0, 'backfilled' => 0, 'fixed' => 0]);

        $tenants = app(TenantContext::class);
        $previous = $tenants->current();
        $tenants->set('other-tenant');
        app(WikiRoutineService::class)->run('other-tenant');
        $tenants->set($previous);

        $this->assertFalse($this->callTool()['provisioned']);
    }

    public function test_the_tool_is_annotated_read_only(): void
    {
        $reflection = new \ReflectionClass(KbWikiRoutineStatusTool::class);
        $names = array_map(static fn ($a): string => $a->getName(), $reflection->getAttributes());

        $this->assertContains(\Laravel\Mcp\Server\Tools\Annotations\IsReadOnly::class, $names);
    }
}
