<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\AgentAnswerSynthesizer;
use App\Agent\Artifacts\AgentTableArtifactFactory;
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
                    'requires_selection' => false,
                    // Selection continuations still render a one-row collection if the model misses this.
                    'render_table' => false,
                ],
            ]],
        ));

        $answer = (new AgentAnswerSynthesizer(
            $ai,
            app(WidgetPiiMasker::class),
            app(AgentTableArtifactFactory::class),
        ))->synthesize(
            'Quali ordini ha Tizio?',
            $this->context(),
            new AgentLoopOutcome('answer', $evidence, []),
            json_encode(['current_selection' => ['record' => ['id' => 77]]], JSON_THROW_ON_ERROR),
        );

        $this->assertSame('it-IT', $answer->locale);
        $this->assertSame('complete', $answer->completeness);
        $this->assertSame([12], array_column($answer->citations, 'document_id'));
        $this->assertSame([55], array_column($answer->toolSources, 'execution_id'));
        $this->assertArrayNotHasKey('result', $answer->toolSources[0]);
        $this->assertSame(
            'Ho organizzato i risultati nella tabella qui sotto: apri una riga per vedere i dettagli.',
            $answer->answer,
        );
        $this->assertStringNotContainsString('A-100', $answer->answer);
        $this->assertStringNotContainsString('admin@example.com', $answer->answer);
        $this->assertStringContainsString('Bearer [TOKEN]', $answer->limitations[0]);
        $this->assertSame('view', data_get($answer->artifact, 'interaction_mode'));
        $this->assertSame('A-100', data_get($answer->artifact, 'rows.0.values.number'));
    }

    public function test_it_marks_ambiguous_singular_results_as_a_selectable_table(): void
    {
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $tool = new AgentToolDefinition(
            name: 'search_customers',
            displayName: 'Search customers',
            description: 'Search matching customers',
            kind: 'api',
            inputSchema: ['type' => 'object'],
            readOnly: true,
            idempotent: true,
            physicalMinimum: 1,
            physicalLikely: 1,
            physicalMaximum: 1,
            executorReference: 10,
        );
        $evidence->addToolResult($tool, ['query' => 'Riccardo Lorini'], ['items' => [
            ['id' => 101, 'name' => 'Riccardo Lorini'],
            ['id' => 102, 'name' => 'Riccardo Lorini'],
        ]], 56);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(
            content: '',
            provider: 'fake',
            model: 'fake-agent',
            toolCalls: [[
                'name' => 'submit_agent_answer',
                'arguments' => [
                    'answer' => 'Ho trovato più utenti con questo nome. Quale vuoi scegliere?',
                    'completeness' => 'complete',
                    'document_ids' => [],
                    'tool_execution_ids' => [56],
                    'limitations' => [],
                    // The runtime ambiguity guard must override a mistaken model classification.
                    'requires_selection' => false,
                    'render_table' => true,
                ],
            ]],
        ));

        $answer = (new AgentAnswerSynthesizer(
            $ai,
            app(WidgetPiiMasker::class),
            app(AgentTableArtifactFactory::class),
        ))->synthesize(
            'Cerca Riccardo Lorini',
            $this->context(),
            new AgentLoopOutcome('answer', $evidence, [], 'ambiguous_selection_required'),
        );

        $this->assertTrue($answer->requiresSelection);
        $this->assertSame(
            'Ho trovato più risultati possibili: scegli una riga per continuare.',
            $answer->answer,
        );
        $this->assertSame('selection', data_get($answer->artifact, 'interaction_mode'));
        $this->assertSame(['101', '102'], array_column(data_get($answer->artifact, 'rows'), 'key'));
    }

    public function test_it_never_repeats_collection_rows_in_text_when_an_artifact_is_rendered(): void
    {
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $tool = new AgentToolDefinition(
            name: 'list_orders',
            displayName: 'Orders list',
            description: 'Latest orders',
            kind: 'mcp',
            inputSchema: ['type' => 'object'],
            readOnly: true,
            idempotent: true,
            physicalMinimum: 1,
            physicalLikely: 1,
            physicalMaximum: 1,
            executorReference: 11,
        );
        $evidence->addToolResult($tool, [], ['orders' => [
            ['public_id' => 'ORDER-100', 'status' => 'paid', 'total' => 120],
            ['public_id' => 'ORDER-101', 'status' => 'pending', 'total' => 80],
        ]], 57);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(
            content: '',
            provider: 'fake',
            model: 'fake-agent',
            toolCalls: [[
                'name' => 'submit_agent_answer',
                'arguments' => [
                    'answer' => "Ecco gli ultimi ordini:\n\n| ID | Stato | Totale |\n|---|---|---|\n| ORDER-100 | paid | 120 |\n| ORDER-101 | pending | 80 |",
                    'completeness' => 'complete',
                    'document_ids' => [],
                    'tool_execution_ids' => [57],
                    'limitations' => [],
                    'requires_selection' => false,
                    'render_table' => true,
                ],
            ]],
        ));

        $answer = (new AgentAnswerSynthesizer(
            $ai,
            app(WidgetPiiMasker::class),
            app(AgentTableArtifactFactory::class),
        ))->synthesize(
            'Mostrami gli ultimi ordini',
            $this->context(),
            new AgentLoopOutcome('answer', $evidence, []),
        );

        $this->assertSame(
            'Ho organizzato i risultati nella tabella qui sotto: apri una riga per vedere i dettagli.',
            $answer->answer,
        );
        $this->assertStringNotContainsString('ORDER-100', $answer->answer);
        $this->assertSame('view', data_get($answer->artifact, 'interaction_mode'));
        $this->assertSame('ORDER-100', data_get($answer->artifact, 'rows.0.values.public_id'));
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
