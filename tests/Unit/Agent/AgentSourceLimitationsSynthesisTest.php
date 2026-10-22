<?php

namespace Tests\Unit\Agent;

use App\Agent\AgentAnswerSynthesizer;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoopOutcome;
use App\Agent\AgentSourceLimitations;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Tools\AgentToolDefinition;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AgentSourceLimitationsSynthesisTest extends TestCase
{
    public function test_a_partial_draft_cannot_drop_a_used_sources_declared_evidence_limit(): void
    {
        config(['reasoning.selective_validation' => false, 'agent.grounding.enabled' => true,
            'agent.grounding.batch.enabled' => false, 'agent.grounding.semantic.enabled' => false,
            'agent.presentation.enabled' => false]);
        Http::preventStrayRequests();
        $record = ['id' => 'RETURN-A', 'status' => 'confirmed'];
        $tool = new AgentToolDefinition(name: 'returns', displayName: 'Returns', description: 'Return management records', kind: 'api',
            inputSchema: ['type' => 'object'], readOnly: true, idempotent: true,
            physicalMinimum: 1, physicalLikely: 1, physicalMaximum: 1,
            metadata: ['output_schema' => ['type' => 'object',
                'x-evidence-limitations' => [AgentSourceLimitations::RETURN_NOT_SETTLEMENT]]]);
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addToolResult($tool, [], ['record' => $record], 42);
        $claim = ['text' => 'Il reso RETURN-A risulta confermato.', 'quote' => json_encode($record),
            'tool_execution_id' => 42, 'document_id' => null, 'evidence_hash' => $evidence->apiTools()[0]['evidence_hash']];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse('', 'fake', 'test', toolCalls: [[
            'name' => 'submit_agent_answer', 'arguments' => ['completeness' => 'partial', 'claims' => [$claim],
                'limitations' => [], 'render_table' => false, 'requires_selection' => false],
        ]]));
        $this->app->instance(AiManager::class, $ai);
        $answer = app(AgentAnswerSynthesizer::class)->synthesize('Stato del reso RETURN-A e limiti della prova?',
            new AgentExecutionContext('limits-test', 'default', 'demo', 'chat', 'user', '1', 'it', 'Europe/Rome'),
            new AgentLoopOutcome('answer', $evidence, []));
        $this->assertStringContainsString($claim['text'], $answer->answer);
        $this->assertStringContainsString('non costituiscono una verifica indipendente', $answer->answer);
        $this->assertCount(1, $answer->grounding['claims']);
        $this->assertSame(AgentSourceLimitations::RETURN_NOT_SETTLEMENT, $answer->grounding['source_limitations'][0]['code']);
    }
}
