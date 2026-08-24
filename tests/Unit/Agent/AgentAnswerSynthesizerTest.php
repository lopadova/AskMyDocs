<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\AgentAnswerSynthesizer;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoopOutcome;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Tools\AgentToolDefinition;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Services\Widget\WidgetPiiMasker;
use Mockery;
use Tests\TestCase;

final class AgentAnswerSynthesizerTest extends TestCase
{
    public function test_it_combines_sources_and_discards_fabricated_source_ids(): void
    {
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument([
            'document_id' => 12,
            'title' => 'Policy ordini',
            'source_path' => 'policy/orders.md',
            'origin' => 'primary',
            'evidence' => [['content' => 'Gli ordini pagati sono definitivi.', 'evidence_hash' => 'doc-hash']],
        ]);
        $tool = new AgentToolDefinition(
            name: 'get_orders',
            displayName: 'Ordini ERP',
            description: 'Ordini aggiornati',
            kind: 'api',
            inputSchema: ['type' => 'object'],
            readOnly: true,
            idempotent: true,
            physicalMinimum: 1,
            physicalLikely: 1,
            physicalMaximum: 1,
            executorReference: 9,
        );
        $evidence->addToolResult($tool, ['customer_id' => 77], ['orders' => [['number' => 'A-100']]], 55);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(
            content: '',
            provider: 'fake',
            model: 'fake-agent',
            toolCalls: [[
                'name' => 'submit_agent_answer',
                'arguments' => [
                    'answer' => 'Tizio ha l’ordine **A-100**; contatto admin@example.com.',
                    'completeness' => 'complete',
                    'document_ids' => [12, 999],
                    'tool_execution_ids' => [55, 999],
                    'limitations' => ['Non mostrare Bearer eyJabcdefghijk.abcdefghijklmnopqrstu'],
                ],
            ]],
        ));

        $answer = (new AgentAnswerSynthesizer($ai, app(WidgetPiiMasker::class)))->synthesize(
            'Quali ordini ha Tizio?',
            $this->context(),
            new AgentLoopOutcome('answer', $evidence, []),
        );

        $this->assertSame('it-IT', $answer->locale);
        $this->assertSame('complete', $answer->completeness);
        $this->assertSame([12], array_column($answer->citations, 'document_id'));
        $this->assertSame([55], array_column($answer->toolSources, 'execution_id'));
        $this->assertArrayNotHasKey('result', $answer->toolSources[0]);
        $this->assertStringContainsString('A-100', $answer->answer);
        $this->assertStringContainsString('[EMAIL]', $answer->answer);
        $this->assertStringNotContainsString('admin@example.com', $answer->answer);
        $this->assertStringContainsString('Bearer [TOKEN]', $answer->limitations[0]);
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
