<?php

namespace Tests\Unit\Decisions;

use App\Agent\Grounding\FocusedClaimEvaluator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FocusedClaimEvaluatorTest extends TestCase
{
    private function fixture(): array
    {
        $record = ['id' => 'SHIP-12', 'trackingCode' => 'TRACK-55', 'status' => 'scheduled', 'orderId' => 'ORDER-22', 'customerId' => 'RO-LONGO'];
        $hub = 'HUB-MI-07 accepts ADR class 3 freight.';
        $evidence = ['documents' => [['document_id' => 26, 'tenant_id' => 'acme', 'project_key' => 'demo', 'evidence' => [['chunk_id' => 1, 'evidence_hash' => 'hub', 'content' => $hub]]]],
            'api_tools' => [['execution_id' => 67, 'evidence_hash' => 'tool', 'result' => ['query' => 'TRACK-55', 'records' => [$record]]]]];
        $claims = [['text' => 'TRACK-55 is scheduled.', 'quote' => 'paraphrased', 'tool_execution_id' => 67, 'evidence_hash' => 'tool'],
            ['text' => 'HUB-MI-07 accepts ADR class 3 freight.', 'quote' => 'paraphrased', 'document_id' => 26, 'evidence_hash' => 'hub']];
        $focus = ['topic' => 'shipment', 'identifiers' => ['TRACK-55'], 'aspect' => 'status', 'fields' => ['status']];
        return [$evidence, $claims, ['focus' => $focus, 'subquestions' => [$focus], 'transition' => 'switch']];
    }

    private function fake(array $categories = [], float $probability = .95): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8,
            'decisions.default' => 'jev', 'decisions.models.jev.driver' => \App\Decisions\Models\JevDecisionModel::class]);
        Http::fake(function ($request) use ($categories, $probability) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) {
                $category = $categories[$key] ?? 'supported_relevant';
                $probabilities = array_fill_keys(array_keys($question['criteria']), (1 - $probability) / 4);
                $probabilities[$category] = $probability;
                $answers[$key] = ['type' => 'choice', 'choice' => $category, 'confidence' => $probability, 'probabilities' => $probabilities];
            }
            return Http::response(['id' => 'decision', 'model' => 'typesafe/jev-test', 'answers' => $answers, 'usage' => ['cost' => .001]]);
        });
    }

    public function test_multi_topic_failure_preserves_valid_shipment_and_does_not_claim_the_hub_is_absent(): void
    {
        $this->fake();
        [$evidence, $claims, $u] = $this->fixture();
        $u['subquestions'][] = ['topic' => 'hub', 'identifiers' => ['HUB-MI-07'], 'aspect' => 'details', 'fields' => []];
        $claims[1]['evidence_hash'] = 'nonexistent';
        $result = app(FocusedClaimEvaluator::class)->evaluate('Hub and shipment', $evidence, $claims, $u, 'demo', 'acme');
        $this->assertTrue($result['valid']);
        $this->assertCount(1, $result['claims']);
        $this->assertTrue($result['partial']);
        $this->assertSame('unverified', $result['subquestions'][1]['status']);
        $this->assertContains('source_hash_mismatch', array_column($result['semantic_validation'][0]['checks'], 'reason'));
        Http::assertSentCount(1);
    }

    public function test_followup_does_not_include_previous_hub_paragraph(): void
    {
        $this->fake();
        [$evidence, $claims, $u] = $this->fixture();
        $result = app(FocusedClaimEvaluator::class)->evaluate('Ora la spedizione', $evidence, $claims, $u, 'demo', 'acme');
        $this->assertCount(1, $result['claims']);
        $this->assertSame($claims[0]['text'], $result['claims'][0]['text']);
        Http::assertSent(fn ($r) => $r['state']['focus']['identifiers'] === ['TRACK-55']
            && ! str_contains($r['state']['sources'][$r['state']['pairs'][0]['source_ref']], 'artifact'));
    }

    public function test_uncertain_document_paraphrase_is_not_saved_by_a_literal_quote(): void
    {
        $this->fake([], .28);
        [$evidence, $claims, $u] = $this->fixture();
        $u['subquestions'][0]['identifiers'] = ['HUB-MI-07'];
        $claims[1]['quote'] = $claims[1]['text'];
        $result = app(FocusedClaimEvaluator::class)->evaluate('Hub?', $evidence, [$claims[1]], $u, 'demo', 'acme');
        $this->assertFalse($result['valid']);
        $this->assertNull($result['semantic_validation'][0]['checks'][0]['fallback']);
    }

    public function test_provider_error_renders_only_requested_exact_fields(): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'decisions.default' => 'jev', 'decisions.models.jev.driver' => \App\Decisions\Models\JevDecisionModel::class]);
        Http::fake(['*' => Http::response([], 503)]);
        [$evidence, $claims, $u] = $this->fixture();
        $result = app(FocusedClaimEvaluator::class)->evaluate('Status?', $evidence, [$claims[0]], $u, 'demo', 'acme');
        $this->assertTrue($result['valid']);
        $this->assertStringContainsString('| status | scheduled |', $result['claims'][0]['text']);
        $this->assertStringNotContainsString('customerId', $result['claims'][0]['text']);
        $this->assertTrue($result['partial']);
    }

    public function test_confident_off_topic_is_not_reintroduced_by_structured_fallback(): void
    {
        $this->fake(['claim_0' => 'supported_off_topic']);
        [$evidence, $claims, $u] = $this->fixture();
        $result = app(FocusedClaimEvaluator::class)->evaluate('Status?', $evidence, [$claims[0]], $u, 'demo', 'acme');
        $this->assertFalse($result['valid']);
    }

    public function test_query_wrapper_and_foreign_keys_do_not_match_the_wrong_record(): void
    {
        $this->fake();
        [$evidence, $claims, $u] = $this->fixture();
        $evidence['api_tools'][0]['result']['records'][0]['trackingCode'] = 'TRACK-OTHER';
        $evidence['api_tools'][0]['result']['records'][0]['emailEvidence'] = [['reference' => 'TRACK-55']];
        $result = app(FocusedClaimEvaluator::class)->evaluate('Shipment', $evidence, [$claims[0]], $u, 'demo', 'acme');
        $this->assertFalse($result['valid']);
        Http::assertNothingSent();
    }

    public function test_identifiers_without_digits_work_and_scope_failure_precedes_jev(): void
    {
        $this->fake();
        [$evidence, $claims, $u] = $this->fixture();
        $u['subquestions'][0]['identifiers'] = ['RO-LONGO'];
        $evidence['api_tools'][0]['result']['records'] = [['id' => 'RO-LONGO', 'name' => 'Customer']];
        $claims[0]['text'] = 'RO-LONGO is Customer.';
        $result = app(FocusedClaimEvaluator::class)->evaluate('Who?', $evidence, [$claims[0]], $u, 'demo', 'acme');
        $this->assertTrue($result['valid']);
        $evidence['api_tools'][0]['tenant_id'] = 'other';
        $result = app(FocusedClaimEvaluator::class)->evaluate('Who?', $evidence, [$claims[0]], $u, 'demo', 'acme');
        $this->assertFalse($result['valid']);
        Http::assertSentCount(1);
    }

    public function test_size_limits_do_not_truncate_a_necessary_passage(): void
    {
        $this->fake();
        config(['decisions.max_state_bytes' => 32768]);
        [$evidence, $claims, $u] = $this->fixture();
        $u['subquestions'][0]['identifiers'] = ['HUB-MI-07'];
        $evidence['documents'][0]['evidence'][0]['content'] .= str_repeat(' necessary text', 4000);
        $result = app(FocusedClaimEvaluator::class)->evaluate('Hub', $evidence, [$claims[1]], $u, 'demo', 'acme');
        $this->assertFalse($result['valid']);
        $this->assertSame('state_limit', $result['semantic_validation'][0]['checks'][0]['reason']);
        $meta = $result['semantic_validation'][0];
        $this->assertSame(1, $meta['state_limit_count']);
        $this->assertSame(0, $meta['claim_limit_count']);
        $this->assertSame($meta['checks'][0]['state_bytes_with_candidate'] - 32768, $meta['checks'][0]['state_overflow_bytes']);
        Http::assertNothingSent();
    }

    public function test_uncited_conflicting_source_is_included_in_the_same_decision(): void
    {
        $this->fake(['claim_0' => 'conflicting']);
        [$evidence, $claims, $u] = $this->fixture();
        $evidence['documents'][0]['evidence'][0]['content'] = 'TRACK-55 was delivered today.';
        $result = app(FocusedClaimEvaluator::class)->evaluate('Shipment status', $evidence, [$claims[0]], $u, 'demo', 'acme');
        $this->assertFalse($result['valid']);
        $this->assertSame('conflicting_evidence', $result['reason']);
        Http::assertSent(fn ($r) => count($r['state']['pairs'][0]['related_evidence']) === 1);
    }

    public function test_more_than_ten_candidates_are_explicitly_unassessed_without_more_calls(): void
    {
        $this->fake();
        [$evidence, $claims, $u] = $this->fixture();
        $result = app(FocusedClaimEvaluator::class)->evaluate('Status', $evidence, array_fill(0, 11, $claims[0]), $u, 'demo', 'acme');
        $this->assertContains('claim_limit', array_column($result['semantic_validation'][0]['checks'], 'reason'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => count($r['questions']) === 10);
    }

    public function test_tables_are_scoped_to_requested_record_or_explicit_foreign_key(): void
    {
        [$evidence, , $u] = $this->fixture();
        $evidence['api_tools'][0]['result']['records'][] = ['id' => 'OTHER', 'trackingCode' => 'TRACK-OTHER'];
        $tools = app(\App\Services\Chat\Reasoning\EvidenceProjector::class)->forTable($evidence['api_tools'], $u, 'acme', 'demo');
        $this->assertCount(1, $tools[0]['result']['records']);
        $this->assertSame('TRACK-55', $tools[0]['result']['records'][0]['trackingCode']);
    }

    public function test_duplicate_facts_are_rendered_once_without_a_false_partial_warning(): void
    {
        $this->fake();
        [$evidence, $claims, $u] = $this->fixture();
        $result = app(FocusedClaimEvaluator::class)->evaluate('Status?', $evidence, [$claims[0], $claims[0]], $u, 'demo', 'acme');
        $this->assertCount(1, $result['claims']);
        $this->assertFalse($result['partial']);
        $this->assertSame('duplicate_fact', $result['semantic_validation'][0]['checks'][1]['reason']);
    }

    public function test_already_communicated_fact_is_not_reported_as_unverifiable(): void
    {
        $this->fake();
        [$evidence, $claims, $u] = $this->fixture();
        $first = app(FocusedClaimEvaluator::class)->evaluate('Status?', $evidence, [$claims[0]], $u, 'demo', 'acme');
        $next = app(FocusedClaimEvaluator::class)->evaluate('Altri dettagli?', $evidence, [$claims[0]], $u, 'demo', 'acme', [$first['claims'][0]['fact_id']]);
        $this->assertFalse($next['valid']);
        $this->assertSame('no_new_detail', $next['reason']);
    }

    public function test_chat_28_keeps_primary_hub_and_routes_shipment_by_exact_identifier(): void
    {
        $this->fake();
        [$evidence, $claims, $u] = $this->fixture();
        $u['focus'] = ['topic' => 'hub', 'identifiers' => ['HUB-MI-07'], 'aspect' => 'details', 'fields' => []];
        // Captured failure shape: only the secondary shipment is in subquestions,
        // and the generator assigns both claims the same subquestion index.
        $claims[0]['subquestion_id'] = $claims[1]['subquestion_id'] = 0;
        $result = app(FocusedClaimEvaluator::class)->evaluate('Hub and shipment?', $evidence, $claims, $u, 'demo', 'acme');
        $this->assertCount(2, $result['claims']);
        $this->assertSame(['answered', 'answered'], array_column($result['subquestions'], 'status'));
        $this->assertNotContains('entity_mismatch', array_column($result['semantic_validation'][0]['checks'], 'reason'));
        Http::assertSentCount(1);
    }

    public function test_partial_field_availability_does_not_erase_the_existing_requested_field(): void
    {
        $this->fake([], .58);
        [$evidence, $claims, $u] = $this->fixture();
        $u['focus']['fields'] = $u['subquestions'][0]['fields'] = ['status', 'carrier', 'estimatedDelivery'];
        $result = app(FocusedClaimEvaluator::class)->evaluate('Status and carrier?', $evidence, [$claims[0]], $u, 'demo', 'acme');
        $this->assertTrue($result['valid']);
        $this->assertStringContainsString('| status | scheduled |', $result['claims'][0]['text']);
        $this->assertStringNotContainsString('customerId', $result['claims'][0]['text']);
        $check = $result['semantic_validation'][0]['checks'][0];
        $this->assertSame(['carrier', 'estimatedDelivery'], $check['missing_fields']);
    }

    public function test_general_details_fallback_renders_original_scalar_fields_not_model_paraphrase(): void
    {
        $this->fake([], .58);
        [$evidence, $claims, $u] = $this->fixture();
        $u['focus']['fields'] = $u['subquestions'][0]['fields'] = ['*'];
        $claims[0]['text'] = 'TRACK-55 will arrive tomorrow (unsupported).';
        $result = app(FocusedClaimEvaluator::class)->evaluate('Details of TRACK-55?', $evidence, [$claims[0]], $u, 'demo', 'acme');
        $this->assertTrue($result['valid']);
        $this->assertStringContainsString('| status | scheduled |', $result['claims'][0]['text']);
        $this->assertStringContainsString('| orderId | ORDER-22 |', $result['claims'][0]['text']);
        $this->assertStringNotContainsString('tomorrow', $result['claims'][0]['text']);
        $this->assertSame('exact_record_fields', $result['semantic_validation'][0]['checks'][0]['fallback']);
    }

    public function test_independent_task_cannot_borrow_a_siblings_source_or_be_reassigned_to_that_task(): void
    {
        $this->fake();
        [$evidence, $claims, $u] = $this->fixture();
        $evidence['api_tools'][0]['research_flow_ids'] = [0];
        $evidence['documents'][0]['evidence'][0]['research_flow_ids'] = [1];
        $u['subquestions'][] = ['topic' => 'hub', 'identifiers' => ['HUB-MI-07'], 'aspect' => 'details', 'fields' => []];
        $u['independent_research'] = true;
        $claims[0]['subquestion_id'] = 0;
        // This fact is valid in task 1, but the draft from task 0 has no authority to borrow it.
        $claims[1]['subquestion_id'] = 0;
        $result = app(FocusedClaimEvaluator::class)->evaluate('Shipment and hub', $evidence, $claims, $u, 'demo', 'acme');
        $this->assertCount(1, $result['claims']);
        $excluded = collect($result['semantic_validation'][0]['checks'])->firstWhere('claim_index', 1);
        $this->assertSame('source_not_in_authorized_evidence', $excluded['reason']);
        $this->assertSame(0, $excluded['subquestion_id']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => count($r['state']['pairs']) === 1);
    }

    public function test_chat_37_validates_all_nine_candidates_without_repeating_original_passages(): void
    {
        $this->fake();
        config(['decisions.max_state_bytes' => 16000]);
        $fixture = json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/Agent/multi-question-source-validation.json'), true, flags: JSON_THROW_ON_ERROR);
        $result = app(FocusedClaimEvaluator::class)->evaluate($fixture['question'], $fixture['evidence'], $fixture['claims'],
            $fixture['understanding'], $fixture['project'], $fixture['tenant']);
        $meta = $result['semantic_validation'][0];
        $this->assertSame('completed', $meta['status']);
        $this->assertCount(9, $result['claims']);
        $this->assertSame(['answered', 'answered', 'answered', 'answered'], array_column($result['subquestions'], 'status'));
        $this->assertNotContains('state_limit', array_column($meta['checks'], 'reason'));
        $this->assertNotContains('invalid_source_identity', array_column($meta['checks'], 'reason'));
        $this->assertSame('shared_sources_v1', $meta['state_layout']);
        $this->assertSame(9, $meta['pair_count']);
        $this->assertLessThanOrEqual(15000, $meta['state_bytes']);
        $recovered = array_values(array_filter($meta['checks'], fn ($c) => isset($c['source_resolution'])));
        $this->assertCount(3, $recovered);
        foreach ($recovered as $check) {
            $this->assertSame('unique_evidence_hash', $check['source_resolution']['method']);
            $this->assertSame(4101, $check['source']['tool_execution_id']);
            $this->assertSame('passed', $check['deterministic']['status']);
        }
        Http::assertSentCount(1);
        $state = Http::recorded()[0][0]['state'];
        $this->assertSame($meta['state_bytes'], strlen(json_encode($state, JSON_UNESCAPED_UNICODE)));
        $this->assertCount(4, $state['sources']); // Three complete documents + one identical MCP record, from two executions.
        $this->assertCount(9, Http::recorded()[0][0]['questions']);
        foreach ($fixture['evidence']['documents'] as $doc) {
            $this->assertContains($doc['evidence'][0]['content'], $state['sources']);
        }
        $record = json_encode($fixture['evidence']['api_tools'][0]['result']['records'][0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertContains($record, $state['sources']);
        $oldLayout = array_diff_key($state, ['sources' => true, 'subquestions' => true]);
        foreach ($state['pairs'] as $i => $pair) {
            $this->assertArrayHasKey($pair['source_ref'], $state['sources']);
            $this->assertArrayHasKey($pair['subquestion_id'], $state['subquestions']);
            $oldLayout['pairs'][$i] = [...array_diff_key($pair, ['source_ref' => true]),
                'source' => $state['sources'][$pair['source_ref']], 'subquestion' => $state['subquestions'][$pair['subquestion_id']]];
            foreach ($pair['related_evidence'] as $j => $related) {
                $this->assertArrayHasKey($related['source_ref'], $state['sources']);
                $oldLayout['pairs'][$i]['related_evidence'][$j] = [...array_diff_key($related, ['source_ref' => true]),
                    'source' => $state['sources'][$related['source_ref']]];
            }
        }
        $this->assertGreaterThan(16000, strlen(json_encode($oldLayout, JSON_UNESCAPED_UNICODE)));
        $repairedClaims = array_filter($result['claims'], fn ($c) => ($c['tool_execution_id'] ?? null) === 4101);
        $this->assertCount(3, $repairedClaims);
        foreach ($repairedClaims as $claim) {
            $this->assertSame($record, $claim['quote']);
        }
    }

    public function test_recovered_identity_still_cannot_cross_scope_or_select_the_wrong_record(): void
    {
        $this->fake();
        foreach (['tenant', 'project', 'record', 'record_path', 'flow'] as $failure) {
            [$evidence, $claims, $u] = $this->fixture();
            unset($claims[0]['tool_execution_id']);
            $claims[0]['document_id=null,'] = 67;
            match ($failure) {
                'tenant' => $evidence['api_tools'][0]['tenant_id'] = 'other',
                'project' => $evidence['api_tools'][0]['project_key'] = 'other',
                'record' => $evidence['api_tools'][0]['result']['records'][0]['trackingCode'] = 'TRACK-OTHER',
                'record_path' => $claims[0]['record_path'] = 'records.99',
                'flow' => $evidence['api_tools'][0]['research_flow_ids'] = [1],
            };
            if ($failure === 'flow') {
                $u['independent_research'] = true;
                $claims[0]['subquestion_id'] = 0;
            }
            $result = app(FocusedClaimEvaluator::class)->evaluate('Shipment', $evidence, [$claims[0]], $u, 'demo', 'acme');
            $this->assertFalse($result['valid'], $failure);
            $this->assertSame('failed', $result['semantic_validation'][0]['checks'][0]['deterministic']['status'], $failure);
        }
        Http::assertNothingSent();
    }

    public function test_oversized_related_source_is_not_dropped_and_does_not_pollute_other_pairs(): void
    {
        $this->fake();
        config(['decisions.max_state_bytes' => 16000]);
        [$evidence, $claims, $u] = $this->fixture();
        $u['subquestions'][] = ['topic' => 'hub', 'identifiers' => ['HUB-MI-07'], 'aspect' => 'details', 'fields' => []];
        $evidence['documents'][] = ['document_id' => 27, 'evidence' => [[
            'evidence_hash' => 'conflicting', 'content' => 'TRACK-55 has been delivered.'.str_repeat(' Full necessary context.', 1000),
        ]]];
        $result = app(FocusedClaimEvaluator::class)->evaluate('Shipment and hub', $evidence, $claims, $u, 'demo', 'acme');
        $this->assertCount(1, $result['claims']);
        $this->assertSame($claims[1]['text'], $result['claims'][0]['text']);
        $this->assertSame('state_limit', collect($result['semantic_validation'][0]['checks'])->firstWhere('claim_index', 0)['reason']);
        Http::assertSentCount(1);
        $state = Http::recorded()[0][0]['state'];
        $this->assertSame([$evidence['documents'][0]['evidence'][0]['content']], array_values($state['sources']));
        $this->assertCount(1, $state['pairs']);
    }

    public function test_chat_39_nine_facts_and_eleven_distinct_sources_fit_the_new_default_in_one_call(): void
    {
        $this->fake();
        $this->assertSame(32768, config('decisions.max_state_bytes'));
        $fixture = json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/Agent/multi-question-state-budget.json'), true, flags: JSON_THROW_ON_ERROR);
        $result = app(FocusedClaimEvaluator::class)->evaluate($fixture['question'], $fixture['evidence'], $fixture['claims'],
            $fixture['understanding'], $fixture['project'], $fixture['tenant']);
        $meta = $result['semantic_validation'][0];
        $this->assertSame('completed', $meta['status']);
        $this->assertSame(9, $meta['pair_count']);
        $this->assertSame(11, $meta['source_count']);
        $this->assertSame(0, $meta['state_limit_count']);
        $this->assertSame('sanitized_json_utf8', $meta['state_byte_encoding']);
        $this->assertGreaterThan(16000, $meta['state_bytes']);
        $this->assertLessThanOrEqual(32768, $meta['state_bytes']);
        $this->assertCount(9, $result['claims']);
        $this->assertFalse($result['partial']);
        $this->assertSame(['answered', 'answered', 'answered', 'answered'], array_column($result['subquestions'], 'status'));
        Http::assertSentCount(1);
        $state = Http::recorded()[0][0]['state'];
        $this->assertSame($meta['state_bytes'], strlen(json_encode($state, JSON_UNESCAPED_UNICODE)));
        // Full documents AND overlapping passages remain original and distinct.
        foreach ($state['sources'] as $source) {
            $originals = array_merge(...array_map(fn ($d) => array_column($d['evidence'], 'content'), $fixture['evidence']['documents']));
            $record = json_encode($fixture['evidence']['api_tools'][0]['result']['records'][0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertContains($source, [...$originals, $record]);
        }
    }

    public function test_exact_sanitized_budget_is_used_without_a_hidden_reserve(): void
    {
        $this->fake();
        [$evidence, $claims, $u] = $this->fixture();
        // The mask grows a short email; Unicode is counted in bytes, not chars.
        $evidence['documents'][0]['evidence'][0]['content'] .= ' Contatto a@b.it. Città 東京.';
        $u['focus']['identifiers'] = $u['subquestions'][0]['identifiers'] = ['HUB-MI-07'];
        $first = app(FocusedClaimEvaluator::class)->evaluate('Hub?', $evidence, [$claims[1]], $u, 'demo', 'acme');
        $bytes = $first['semantic_validation'][0]['state_bytes'];
        $state = Http::recorded()[0][0]['state'];
        $this->assertSame($bytes, strlen(json_encode($state, JSON_UNESCAPED_UNICODE)));
        $this->assertStringContainsString('[EMAIL]', implode('', $state['sources']));
        config(['decisions.max_state_bytes' => $bytes]);
        $exact = app(FocusedClaimEvaluator::class)->evaluate('Hub?', $evidence, [$claims[1]], $u, 'demo', 'acme');
        $this->assertTrue($exact['valid']);
        $this->assertSame(0, $exact['semantic_validation'][0]['state_limit_count']);
        Http::assertSentCount(2);

        config(['decisions.max_state_bytes' => $bytes - 1]);
        $tooLarge = app(FocusedClaimEvaluator::class)->evaluate('Hub?', $evidence, [$claims[1]], $u, 'demo', 'acme');
        $this->assertFalse($tooLarge['valid']);
        $this->assertSame('state_limit', $tooLarge['semantic_validation'][0]['checks'][0]['reason']);
        $this->assertSame(1, $tooLarge['semantic_validation'][0]['checks'][0]['state_overflow_bytes']);
        Http::assertSentCount(2); // No provider attempt for the over-budget candidate.
    }
}
