<?php

declare(strict_types=1);

namespace Tests\Unit\Decisions;

use App\Agent\AgentAnswerSynthesizer;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoopOutcome;
use App\Agent\Artifacts\AgentTableArtifactFactory;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Grounding\AgentClaimGroundingValidator;
use App\Agent\Grounding\AgentClaimBatchEvaluator;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Services\Widget\WidgetPiiMasker;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class AgentClaimBatchEvaluatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.providers.openrouter.key', 'test-only-key');
        config()->set('decisions.default', 'jev');
        config()->set('decisions.models.jev.driver', \App\Decisions\Models\JevDecisionModel::class);
        config()->set('decisions.models.jev.model', 'typesafe/jev-1.13');
        config()->set('agent.grounding.semantic.threshold', 0.8);
    }

    public function test_one_decision_keeps_a_real_email_and_two_mcp_facts_despite_a_nonliteral_draft_quote(): void
    {
        Http::fake(['*' => Http::response($this->decision([0.97, 0.96, 0.93]))]);
        [$evidence, $claims] = $this->orderCase();
        $result = app(AgentClaimBatchEvaluator::class)->evaluate('Dettagli ordine PO-5582', $evidence, $claims, ['PO-5582']);

        $this->assertTrue($result['valid']);
        $this->assertCount(3, $result['claims']);
        $this->assertStringContainsString('Le confermo i riferimenti', $result['claims'][2]['quote']);
        $this->assertSame('completed', $result['semantic_validation'][0]['status']);
        $this->assertSame('accepted', $result['semantic_validation'][0]['checks'][2]['status']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => count($request['questions']) === 3
            && str_contains($request['state']['pairs'][2]['source'], 'PO-5582'));
    }

    public function test_a_different_order_is_excluded_before_jev_and_a_confident_no_is_omitted(): void
    {
        Http::fake(['*' => Http::response($this->decision([0.95, 0.03]))]);
        [$evidence, $claims] = $this->orderCase();
        $evidence['documents'][] = ['document_id' => 999, 'evidence' => [[
            'evidence_hash' => 'other-hash', 'content' => 'Ordine PO-9999 consegnato.',
        ]]];
        $claims[] = ['text' => 'PO-5582 consegnato.', 'quote' => 'PO-9999 consegnato.',
            'document_id' => 999, 'tool_execution_id' => null, 'evidence_hash' => 'other-hash'];
        $result = app(AgentClaimBatchEvaluator::class)->evaluate('Dettagli ordine PO-5582', $evidence,
            [$claims[0], $claims[2], $claims[3]], ['PO-5582']);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['partial']);
        $this->assertCount(1, $result['claims']);
        $this->assertContains('entity_mismatch', array_column($result['semantic_validation'][0]['checks'], 'reason'));
        Http::assertSentCount(1);
    }

    public function test_ten_candidate_emails_do_not_make_unrelated_orders_eligible(): void
    {
        Http::fake(['*' => Http::response($this->decision([0.99]))]);
        [$evidence, $claims] = $this->orderCase();
        $candidateClaims = [$claims[2]];
        for ($index = 1; $index <= 9; $index++) {
            $evidence['documents'][] = ['document_id' => 200 + $index, 'evidence' => [[
                'evidence_hash' => 'other-'.$index, 'content' => 'Ordine PO-'.(6000 + $index).' confermato.',
            ]]];
            $candidateClaims[] = ['text' => 'Ordine confermato.', 'quote' => 'wrong',
                'document_id' => 200 + $index, 'tool_execution_id' => null, 'evidence_hash' => 'other-'.$index];
        }
        $result = app(AgentClaimBatchEvaluator::class)->evaluate('Dettagli ordine PO-5582', $evidence,
            $candidateClaims, ['PO-5582']);

        $this->assertCount(1, $result['claims']);
        $this->assertCount(9, array_filter($result['semantic_validation'][0]['checks'],
            static fn (array $check): bool => ($check['reason'] ?? null) === 'entity_mismatch'));
        Http::assertSent(fn ($request) => count($request['questions']) === 1);
    }

    public function test_cross_project_evidence_cannot_be_approved_by_jev(): void
    {
        Http::fake();
        [$evidence, $claims] = $this->orderCase();
        $evidence['documents'][0]['project_key'] = 'another-project';
        $result = app(AgentClaimBatchEvaluator::class)->evaluate('Dettagli ordine PO-5582', $evidence,
            [$claims[2]], ['PO-5582'], 'test-project');

        $this->assertFalse($result['valid']);
        Http::assertNothingSent();
    }

    public function test_mcp_record_from_another_project_cannot_be_approved_by_jev(): void
    {
        Http::fake();
        [$evidence, $claims] = $this->orderCase();
        $evidence['api_tools'][0]['result']['companyKey'] = 'another-project';
        $result = app(AgentClaimBatchEvaluator::class)->evaluate('Dettagli ordine PO-5582', $evidence,
            [$claims[0]], ['PO-5582'], 'test-project', 'test-tenant');

        $this->assertFalse($result['valid']);
        Http::assertNothingSent();
    }

    public function test_uncertain_decision_does_not_rescue_a_nonliteral_quote(): void
    {
        Http::fake(['*' => Http::response($this->decision([0.5]))]);
        [$evidence, $claims] = $this->orderCase();
        $result = app(AgentClaimBatchEvaluator::class)->evaluate('Dettagli ordine PO-5582', $evidence,
            [$claims[2]], ['PO-5582']);

        $this->assertFalse($result['valid']);
        $this->assertSame('inconclusive', $result['semantic_validation'][0]['checks'][0]['status']);
        Http::assertSentCount(1);
    }

    public function test_provider_failure_uses_only_deterministically_bound_claims_as_partial(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        [$evidence, $claims] = $this->orderCase();
        $claims[0]['quote'] = '{"id":"PO-5582","status":"accepted_for_dispatch"}';
        $claims[1]['quote'] = '{"id":"RL-2024-1120","orderId":"PO-5582","status":"scheduled"}';
        $result = app(AgentClaimBatchEvaluator::class)->evaluate('Dettagli ordine PO-5582', $evidence, $claims, ['PO-5582']);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['partial']);
        $this->assertSame('error', $result['semantic_validation'][0]['status']);
        $this->assertCount(2, $result['claims']);
        Http::assertSentCount(1);
    }

    public function test_agent_renders_the_original_email_and_mcp_citations_after_one_batch_call(): void
    {
        config()->set('agent.grounding.batch.enabled', true);
        Http::fake(['*' => Http::response($this->decision([0.97, 0.96, 0.93]))]);
        [$data, $claims] = $this->orderCase();
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->import($data);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(
            content: '', provider: 'fake', model: 'fake-agent', toolCalls: [[
                'name' => 'submit_agent_answer', 'arguments' => [
                    'completeness' => 'complete', 'claims' => $claims, 'limitations' => [],
                    'requires_selection' => false, 'render_table' => false,
                ],
            ]],
        ));
        $context = new AgentExecutionContext('test-run', 'test-tenant', 'test-project', 'chat', 'user', '1', 'it', 'Europe/Rome');
        $answer = (new AgentAnswerSynthesizer($ai, app(WidgetPiiMasker::class),
            app(AgentTableArtifactFactory::class), app(AgentClaimGroundingValidator::class)))
            ->synthesize('Dettagli ordine PO-5582', $context, new AgentLoopOutcome('answer', $evidence, []),
                null, ['available' => true, 'language' => 'it', 'mentions' => ['PO-5582']]);

        $this->assertSame('complete', $answer->completeness);
        $this->assertCount(1, $answer->citations);
        $this->assertCount(2, $answer->toolSources);
        $this->assertStringContainsString('13:00', $answer->answer);
        $this->assertSame('completed', $answer->grounding['semantic_validation'][0]['status']);
        Http::assertSentCount(1);
    }

    public function test_customer_code_without_digits_selects_its_record_before_bounding_the_jev_state(): void
    {
        Http::fake(['*' => Http::response($this->decision([0.96]))]);
        $record = ['id' => 'RO-LONGO', 'name' => 'Veronica Longo', 'role' => 'cliente business',
            'orderIds' => ['PO-5582']];
        $result = ['companyKey' => 'rotta-logistics', 'query' => 'RO-LONGO',
            'artifact' => ['text' => str_repeat('Verbose MCP transport envelope. ', 100)],
            'records' => [$record], 'total' => 1];
        $evidence = ['documents' => [], 'api_tools' => [[
            'execution_id' => 48, 'evidence_hash' => 'customer-hash', 'result' => $result,
        ]]];
        $claim = ['text' => 'RO-LONGO è Veronica Longo, cliente business.',
            'quote' => json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'document_id' => null, 'tool_execution_id' => 48, 'evidence_hash' => 'customer-hash'];

        $answer = app(AgentClaimBatchEvaluator::class)->evaluate('Chi è RO-LONGO?', $evidence,
            [$claim], ['RO-LONGO'], 'rotta-logistics');

        $this->assertTrue($answer['valid']);
        $this->assertSame('accepted', $answer['semantic_validation'][0]['checks'][0]['status']);
        Http::assertSent(fn ($request) => count($request['questions']) === 1
            && strlen($request['state']['pairs'][0]['source']) < 1200
            && str_contains($request['state']['pairs'][0]['source'], 'Veronica Longo'));
        Http::assertSentCount(1);
    }

    public function test_inconclusive_jev_keeps_exact_shipment_fields_without_trusting_draft_claims(): void
    {
        Http::fake(['*' => Http::response($this->decision([0.78, 0.29]))]);
        $record = ['id' => 'RL-2024-1120', 'trackingCode' => 'RL-TRACK-9355',
            'status' => 'scheduled_for_dispatch', 'orderId' => 'PO-5582',
            'customerId' => 'RO-LONGO', 'productIds' => ['RO-LAMPO-24H']];
        $evidence = ['documents' => [], 'api_tools' => [[
            'execution_id' => 51, 'evidence_hash' => 'shipment-hash',
            'result' => ['companyKey' => 'rotta-logistics', 'query' => 'RL-TRACK-9355',
                'records' => [$record], 'artifact' => ['text' => str_repeat('Verbose MCP envelope. ', 100)]],
        ]]];
        $claim = ['text' => 'Spedizione RL-TRACK-9355 programmata.', 'quote' => 'nonliteral',
            'document_id' => null, 'tool_execution_id' => 51, 'evidence_hash' => 'shipment-hash'];

        $answer = app(AgentClaimBatchEvaluator::class)->evaluate('Tutti i dati della spedizione RL-TRACK-9355',
            $evidence, [$claim, $claim], ['RL-TRACK-9355'], 'rotta-logistics');

        $this->assertTrue($answer['valid']);
        $this->assertTrue($answer['partial']);
        $this->assertCount(1, $answer['claims']);
        $this->assertStringContainsString('| trackingCode | RL-TRACK-9355 |', $answer['claims'][0]['text']);
        $this->assertStringContainsString('| orderId | PO-5582 |', $answer['claims'][0]['text']);
        $this->assertStringNotContainsString('nonliteral', $answer['claims'][0]['quote']);
        Http::assertSentCount(1);
    }

    public function test_confident_semantic_rejection_does_not_use_structured_fallback(): void
    {
        Http::fake(['*' => Http::response($this->decision([0.05]))]);
        $record = ['id' => 'RL-2024-1120', 'trackingCode' => 'RL-TRACK-9355',
            'status' => 'scheduled_for_dispatch'];
        $evidence = ['documents' => [], 'api_tools' => [[
            'execution_id' => 51, 'evidence_hash' => 'shipment-hash',
            'result' => ['companyKey' => 'rotta-logistics', 'records' => [$record]],
        ]]];
        $claim = ['text' => 'La spedizione RL-TRACK-9355 è stata consegnata.', 'quote' => 'nonliteral',
            'document_id' => null, 'tool_execution_id' => 51, 'evidence_hash' => 'shipment-hash'];

        $answer = app(AgentClaimBatchEvaluator::class)->evaluate('Stato RL-TRACK-9355?', $evidence,
            [$claim], ['RL-TRACK-9355'], 'rotta-logistics');

        $this->assertFalse($answer['valid']);
        $this->assertSame('rejected', $answer['semantic_validation'][0]['checks'][0]['status']);
    }

    public function test_broad_company_question_sends_four_bounded_document_chunks_without_an_identifier(): void
    {
        Http::fake(['*' => Http::response($this->decision([0.91, 0.92, 0.93, 0.94]))]);
        $facts = [
            'Rotta Sicura Logistics è un operatore di logistica e spedizioni.',
            'L’azienda offre tre livelli di servizio di spedizione.',
            'L’ultimo miglio è affidato a vettori partner.',
            'Le merci ADR classe 3 passano per un hub abilitato.',
        ];
        $evidence = ['documents' => [], 'api_tools' => []];
        $claims = [];
        foreach ($facts as $index => $fact) {
            $content = $fact."\n".str_repeat('Dettaglio operativo della rete logistica. ', 48);
            $evidence['documents'][] = ['document_id' => 20 + $index,
                'project_key' => 'rotta-logistics', 'evidence' => [[
                    'evidence_hash' => 'doc-'.$index, 'content' => $content,
                ]]];
            $claims[] = ['text' => $fact, 'quote' => $fact, 'document_id' => 20 + $index,
                'tool_execution_id' => null, 'evidence_hash' => 'doc-'.$index];
        }

        $answer = app(AgentClaimBatchEvaluator::class)->evaluate('Cosa fa questa azienda?', $evidence,
            $claims, [], 'rotta-logistics');

        $this->assertTrue($answer['valid']);
        $this->assertCount(4, $answer['claims']);
        Http::assertSent(fn ($request) => count($request['questions']) === 4
            && count($request['state']['pairs']) === 4);
        Http::assertSentCount(1);
    }

    /** @return array{array<string,mixed>,list<array<string,mixed>>} */
    private function orderCase(): array
    {
        $email = "L'ordine PO-5582 partirà in serata.\nLe confermo i riferimenti: RL-2024-1120. Consegna domani entro le 13:00.";
        $evidence = [
            'documents' => [['document_id' => 121, 'evidence' => [['evidence_hash' => 'mail-hash', 'content' => $email]]]],
            'api_tools' => [
                ['execution_id' => 45, 'evidence_hash' => 'order-hash', 'result' => ['records' => [[
                    'id' => 'PO-5582', 'status' => 'accepted_for_dispatch',
                ]]]],
                ['execution_id' => 46, 'evidence_hash' => 'shipment-hash', 'result' => ['records' => [[
                    'id' => 'RL-2024-1120', 'orderId' => 'PO-5582', 'status' => 'scheduled',
                ]]]],
            ],
        ];
        $claims = [
            ['text' => 'PO-5582 è accettato.', 'quote' => 'wrong', 'document_id' => null,
                'tool_execution_id' => 45, 'evidence_hash' => 'order-hash'],
            ['text' => 'La spedizione è programmata.', 'quote' => 'wrong', 'document_id' => null,
                'tool_execution_id' => 46, 'evidence_hash' => 'shipment-hash'],
            ['text' => 'La consegna è prevista domani entro le 13:00.',
                'quote' => "L'ordine PO-5582 partirà in serata. Consegna domani entro le 13:00.",
                'document_id' => 121, 'tool_execution_id' => null, 'evidence_hash' => 'mail-hash'],
        ];
        return [$evidence, $claims];
    }

    /** @param list<float> $probabilities @return array<string,mixed> */
    private function decision(array $probabilities): array
    {
        $answers = [];
        foreach ($probabilities as $index => $probability) {
            $answers['claim_'.($index + 1)] = ['type' => 'noul', 'noul' => $probability];
        }
        return ['id' => 'test-batch', 'model' => 'typesafe/jev-1.13', 'answers' => $answers,
            'usage' => ['cost' => 0.001]];
    }
}
