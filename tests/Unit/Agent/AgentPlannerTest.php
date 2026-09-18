<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Evidence\AgentEvidenceSummarizer;
use App\Agent\Planning\AgentLiveSourceCoverage;
use App\Agent\Planning\AgentPlanner;
use App\Agent\Planning\AgentPlanParser;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use Mockery;
use Tests\TestCase;

/**
 * "Livello di approfondimento": AgentPlanner::depthInstruction() is what
 * actually makes a higher depth DO more cascading knowledge-base searches
 * — AgentBudgetTracker only raises the ceiling of how many calls are
 * ALLOWED, it does not make the planner want to use them. These tests pin
 * that the depth-specific instruction text actually reaches the model
 * prompt, varies by level, and defaults to level 3 (today's unscaled
 * behaviour) when the caller doesn't pass one.
 */
final class AgentPlannerTest extends TestCase
{
    public function test_default_depth_is_3_balanced(): void
    {
        $prompt = $this->capturedPrompt(fn (AgentPlanner $planner) => $planner->decide(
            'Parlami di SizeCharts',
            $this->context(),
            [],
            app(AgentEvidenceFactory::class)->empty(),
        ));

        $this->assertStringContainsString('Investigation depth is 3/5 (balanced)', $prompt);
    }

    public function test_depth_1_asks_for_a_quick_single_source_answer(): void
    {
        $prompt = $this->capturedPrompt(fn (AgentPlanner $planner) => $planner->decide(
            'Parlami di SizeCharts',
            $this->context(),
            [],
            app(AgentEvidenceFactory::class)->empty(),
            depth: 1,
        ));

        $this->assertStringContainsString('Investigation depth is 1/5 (quick)', $prompt);
        $this->assertStringContainsString('Do not plan a follow-up knowledge-base search', $prompt);
    }

    public function test_depth_5_asks_for_cascading_sub_question_searches(): void
    {
        $prompt = $this->capturedPrompt(fn (AgentPlanner $planner) => $planner->decide(
            'Parlami di SizeCharts',
            $this->context(),
            [],
            app(AgentEvidenceFactory::class)->empty(),
            depth: 5,
        ));

        $this->assertStringContainsString('Investigation depth is 5/5 (exhaustive)', $prompt);
        $this->assertStringContainsString('issue a separate tool call for EACH one', $prompt);
        // R: must not steer every sub-question toward the SAME content-search
        // tool by name — a catalog-shaped sub-question needs a different tool.
        $this->assertStringContainsString('pick the RIGHT tool per sub-question', $prompt);
        $this->assertStringContainsString('list_knowledge_documents instead', $prompt);
    }

    private function capturedPrompt(callable $call): string
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

        $planner = new AgentPlanner(
            $ai,
            app(AgentPlanParser::class),
            app(AgentEvidenceSummarizer::class),
            app(AgentLiveSourceCoverage::class),
        );
        $call($planner);

        $this->assertNotNull($captured, 'AiManager::chatWithHistory was never called.');

        return $captured;
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
