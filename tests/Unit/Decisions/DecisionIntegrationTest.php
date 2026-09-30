<?php

declare(strict_types=1);

namespace Tests\Unit\Decisions;

use App\Agent\AgentAnswerSynthesizer;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoopOutcome;
use App\Agent\Artifacts\AgentTableArtifactFactory;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Grounding\AgentClaimGroundingValidator;
use App\Agent\Grounding\AgentSemanticGroundingJudge;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Services\Widget\WidgetPiiMasker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class DecisionIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.providers.openrouter.key', 'test-only-key');
        config()->set('decisions.default', 'jev');
        config()->set('decisions.models.jev.driver', \App\Decisions\Models\JevDecisionModel::class);
        config()->set('decisions.models.jev.model', 'typesafe/jev-1.13');
        config()->set('decisions.models.jev.timeout', 15);
        config()->set('decisions.max_state_bytes', 16000);
    }

    public function test_semantic_gate_is_off_by_default_and_only_evaluates_verified_claims_when_enabled(): void
    {
        Http::fake(['*' => Http::response($this->response('supported', 0.04))]);
        $judge = app(AgentSemanticGroundingJudge::class);
        $claims = [['text' => 'Ordine 88512 spedito.', 'quote' => 'Ordine 88512 spedito.']];
        $this->assertNull($judge->supported('Qual è lo stato?', $claims));
        Http::assertNothingSent();

        config()->set('agent.grounding.semantic.enabled', true);
        config()->set('agent.grounding.semantic.threshold', 0.8);
        $this->assertFalse($judge->supported('Qual è lo stato?', $claims));
        $trace = $judge->evaluate('Qual è lo stato?', $claims);
        $this->assertSame('rejected', $trace['status']);
        $this->assertSame(0.04, $trace['probability_true']);
        Http::assertSentCount(2);
    }

    public function test_semantic_gate_abstains_if_provider_is_unavailable(): void
    {
        config()->set('agent.grounding.semantic.enabled', true);
        config()->set('agent.grounding.semantic.threshold', 0.8);
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        $this->assertNull(app(AgentSemanticGroundingJudge::class)->supported('Stato?', [
            ['text' => 'Ordine spedito', 'quote' => 'Ordine spedito'],
        ]));
    }

    public function test_artisan_yes_no_command_runs_without_database_writes(): void
    {
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)
            ->registerCommand($this->app->make(\App\Console\Commands\DecisionYesNoCommand::class));
        Http::fake(['*' => Http::response($this->response('answer', 0.95))]);
        $exit = Artisan::call('decision:yes-no', [
            'text' => 'La spedizione SPD-51230 è arrivata a Messina.',
            'question' => 'Is this about a shipment?',
            '--true' => 'It identifies a shipment.', '--false' => 'It does not identify a shipment.',
            '--threshold' => '0.8', '--json' => true,
        ]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"boolean":true', Artisan::output());
    }

    public function test_agent_keeps_a_verified_source_when_semantic_provider_is_unavailable(): void
    {
        config()->set('agent.grounding.semantic.enabled', true);
        config()->set('agent.grounding.semantic.threshold', 0.8);
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument([
            'document_id' => 252, 'title' => 'Spedizione', 'source_path' => 'mail/ship.eml', 'origin' => 'primary',
            'evidence' => [['content' => 'SPD-51230 è stata consegnata a Messina.', 'evidence_hash' => 'hash-1']],
        ]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(
            content: '', provider: 'fake', model: 'fake-agent', toolCalls: [[
                'name' => 'submit_agent_answer', 'arguments' => [
                    'completeness' => 'complete',
                    'claims' => [[
                        'text' => 'La spedizione SPD-51230 è stata consegnata a Messina.',
                        'quote' => 'SPD-51230 è stata consegnata a Messina.',
                        'document_id' => 252, 'tool_execution_id' => null, 'evidence_hash' => 'hash-1',
                    ]],
                    'limitations' => [], 'requires_selection' => false, 'render_table' => false,
                ],
            ]],
        ));
        $context = new AgentExecutionContext('run-test', 'tenant-test', 'project-test', 'chat', 'user', '1', 'it', 'Europe/Rome');
        $answer = (new AgentAnswerSynthesizer($ai, app(WidgetPiiMasker::class),
            app(AgentTableArtifactFactory::class), app(AgentClaimGroundingValidator::class)))
            ->synthesize('Dove è stata consegnata SPD-51230?', $context, new AgentLoopOutcome('answer', $evidence, []));

        $this->assertSame('complete', $answer->completeness);
        $this->assertStringContainsString('Messina', $answer->answer);
        $this->assertSame([252], array_column($answer->citations, 'document_id'));
        $this->assertTrue($answer->grounding['semantic_validation'][0]['attempted']);
        $this->assertSame('error', $answer->grounding['semantic_validation'][0]['status']);
    }

    public function test_choice_and_evidence_commands_emit_machine_readable_json(): void
    {
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->registerCommand($this->app->make(\App\Console\Commands\DecisionChoiceCommand::class));
        $kernel->registerCommand($this->app->make(\App\Console\Commands\DecisionEvidenceCommand::class));
        Http::fakeSequence()
            ->push(['id' => 'choice-1', 'model' => 'typesafe/jev-1.13', 'usage' => [], 'answers' => [
                'answer' => ['type' => 'choice', 'choice' => 'shipment', 'confidence' => 0.9,
                    'probabilities' => ['shipment' => 0.9, 'order' => 0.1]],
            ]])
            ->push(['id' => 'evidence-1', 'model' => 'typesafe/jev-1.13', 'usage' => [], 'answers' => [
                'relevant' => ['type' => 'noul', 'noul' => 0.95],
                'sufficient' => ['type' => 'noul', 'noul' => 0.9],
            ]]);
        $this->assertSame(0, Artisan::call('decision:choice', [
            'text' => 'SPD-51230', 'question' => 'What is it?',
            '--option' => ['shipment:A shipment', 'order:An order'], '--json' => true,
        ]));
        $this->assertSame('shipment', json_decode(Artisan::output(), true)['answers']['answer']['choice']);
        $this->assertSame(0, Artisan::call('decision:evidence', [
            'question' => 'Where is SPD-51230?', '--evidence' => 'SPD-51230 arrived in Messina.',
            '--threshold' => '0.8', '--json' => true,
        ]));
        $this->assertTrue(json_decode(Artisan::output(), true)['boolean']['sufficient']);
    }

    /** @return array<string,mixed> */
    private function response(string $key, float $probability): array
    {
        return ['id' => 'dec-test', 'model' => 'typesafe/jev-1.13',
            'answers' => [$key => ['type' => 'noul', 'noul' => $probability]],
            'usage' => ['cost' => 0.00001]];
    }
}
