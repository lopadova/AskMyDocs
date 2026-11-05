<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\Capabilities\AgentCapabilitySnapshot;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Evidence\AgentEvidenceSummarizer;
use App\Agent\Planning\AgentCapabilityPlanner;
use App\Agent\Planning\AgentLiveSourceCoverage;
use App\Agent\Planning\AgentPlanner;
use App\Agent\Planning\AgentPlanningCoordinator;
use App\Agent\Planning\AgentPlannerModeResolver;
use App\Agent\Planning\AgentPlanParser;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\AgentRun;
use App\Services\Widget\WidgetPiiMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * The wiring between an AgentRun's stored `depth` and the text that
 * actually reaches the planner's prompt — AgentPlannerTest pins the
 * instruction text itself; this pins that AgentPlanningCoordinator
 * actually READS `input_json.depth` off the run and forwards it (clamped)
 * rather than always falling back to the default.
 */
final class AgentPlanningCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_runs_stored_depth_reaches_the_planner_prompt(): void
    {
        $prompt = $this->decideAndCapturePrompt($this->makeRun(depth: 5));

        $this->assertStringContainsString('Investigation depth is 5/5 (exhaustive)', $prompt);
    }

    public function test_a_run_with_no_depth_falls_back_to_the_configured_default(): void
    {
        $prompt = $this->decideAndCapturePrompt($this->makeRun(depth: null));

        $this->assertStringContainsString('Investigation depth is 3/5 (balanced)', $prompt);
    }

    public function test_an_out_of_range_stored_depth_is_clamped(): void
    {
        $prompt = $this->decideAndCapturePrompt($this->makeRun(depth: 99));

        $this->assertStringContainsString('Investigation depth is 5/5 (exhaustive)', $prompt);
    }

    private function decideAndCapturePrompt(AgentRun $run): string
    {
        $captured = null;
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')
            ->once()
            ->with(Mockery::on(function (string $systemPrompt) use (&$captured): bool {
                $captured = $systemPrompt;

                return true;
            }), Mockery::any(), Mockery::any())
            ->andReturn(new AiResponse(
                content: '',
                provider: 'fake',
                model: 'fake-planner',
                toolCalls: [[
                    'name' => 'submit_agent_plan',
                    'arguments' => ['decision' => 'answer', 'actions' => []],
                ]],
            ));

        $coordinator = new AgentPlanningCoordinator(
            new AgentPlanner(
                $ai,
                app(AgentPlanParser::class),
                app(AgentEvidenceSummarizer::class),
                app(AgentLiveSourceCoverage::class),
            ),
            app(AgentCapabilityPlanner::class),
            app(AgentPlannerModeResolver::class),
            app(WidgetPiiMasker::class),
        );

        $coordinator->decide(
            $run,
            1,
            'Parlami di SizeCharts',
            $this->context(),
            [],
            new AgentCapabilitySnapshot([], 'hash', 0),
            app(AgentEvidenceFactory::class)->empty(),
            [],
            [],
            null,
        );

        $this->assertNotNull($captured, 'AiManager::chatWithHistory was never called.');

        return $captured;
    }

    private function makeRun(?int $depth): AgentRun
    {
        return AgentRun::create([
            'run_id' => Str::uuid()->toString(),
            'tenant_id' => 'acme',
            'project_key' => 'crm',
            'channel' => 'chat',
            'actor_type' => 'user',
            'actor_id' => '1',
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_RUNNING,
            'started_at' => now(),
            'input_json' => $depth === null ? ['question' => 'x'] : ['question' => 'x', 'depth' => $depth],
            'counters_json' => [],
            'budget_json' => [],
        ]);
    }

    private function context(): AgentExecutionContext
    {
        return new AgentExecutionContext(
            runId: 'b03a7c27-daae-43cb-8ea2-fbe85cf66aaf',
            tenantId: 'acme',
            projectKey: 'crm',
            channel: 'chat',
            actorType: 'user',
            actorId: '1',
            locale: 'it-IT',
            timezone: 'Europe/Rome',
        );
    }
}
