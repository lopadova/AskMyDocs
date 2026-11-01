<?php

namespace Tests\Unit\Decisions;

use App\Agent\Grounding\AnswerCoverageEvaluator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AnswerCoverageEvaluatorTest extends TestCase
{
    public function test_coverage_checks_the_same_declared_source_limit_that_the_answer_will_render(): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8]);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['model' => 'jev-test',
            'answers' => ['covered' => ['type' => 'noul', 'noul' => .79]]]));
        $result = app(AnswerCoverageEvaluator::class)->evaluate('Status and proof limit?', ['api_tools' => [[
            'kind' => 'api', 'execution_id' => 42, 'evidence_hash' => 'h', 'result' => ['status' => 'confirmed'],
            'presentation' => ['evidence_limitations' => [\App\Agent\AgentSourceLimitations::RETURN_NOT_SETTLEMENT]],
        ]]], [['text' => 'Confirmed.', 'tool_execution_id' => 42, 'evidence_hash' => 'h']], ['language' => 'it']);
        $this->assertFalse($result['sufficient']); // The source note never bypasses the unchanged .8 gate.
        Http::assertSent(fn ($request) => count($request['state']['answer']) === 2
            && str_contains($request['state']['answer'][1], 'non costituiscono una verifica indipendente'));
    }

    public function test_coverage_keeps_observation_time_distinct_from_a_record_event_date(): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8]);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['model' => 'jev-test',
            'answers' => ['covered' => ['type' => 'noul', 'noul' => .9]]]));
        $time = '2026-10-22T07:52:08+02:00';
        $result = app(AnswerCoverageEvaluator::class)->evaluate('Current status?', ['api_tools' => [[
            'execution_id' => 42, 'evidence_hash' => 'h', 'retrieved_at' => $time,
            'result' => ['code' => 'ORDER-A', 'date' => '2025-12-01', 'status' => 'SHIPPED'],
        ]]], [['text' => 'ORDER-A is SHIPPED, observed at '.$time.'.']]);
        $this->assertTrue($result['sufficient']);
        Http::assertSent(fn ($request) => $request['state']['sources']['h']['observed_at'] === $time
            && $request['state']['sources']['h']['result']['date'] === '2025-12-01');
    }

    public function test_hash_aliases_of_one_snapshot_do_not_overflow_coverage_or_merge_distinct_documents(): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8]);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['model' => 'jev-test',
            'answers' => ['covered' => ['type' => 'noul', 'noul' => .99]]]));
        $text = str_repeat('A bounded original source. ', 600);
        $chunk = ['content' => $text, 'complete_source' => false];
        $result = app(AnswerCoverageEvaluator::class)->evaluate('Compare the two records', ['documents' => [
            ['document_id' => 1, 'evidence' => [$chunk + ['evidence_hash' => 'original'],
                ['content' => $text, 'complete_source' => true, 'evidence_hash' => 'reread']]],
            ['document_id' => 2, 'evidence' => [$chunk + ['evidence_hash' => 'other-record']]],
        ]], [['text' => 'Two records.', 'document_id' => 1], ['text' => 'Same wording.', 'document_id' => 2]]);
        $this->assertTrue($result['sufficient']);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $sources = array_values($request['state']['sources']);
            return count($sources) === 2 && $sources[0]['complete_source'] === true
                && $sources[1]['document_id'] === 2;
        });
    }

    public function test_only_accepted_or_requested_sources_enter_coverage_even_when_other_documents_have_identifiers(): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8]);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['id' => 'coverage', 'model' => 'jev-test',
            'answers' => ['covered' => ['type' => 'noul', 'noul' => .02]]]));
        $evidence = ['documents' => [
            ['document_id' => 1, 'title' => 'CASE-A', 'evidence' => [['evidence_hash' => 'a', 'content' => 'Replacement offered.']]],
            ['document_id' => 2, 'title' => 'OTHER-22', 'evidence' => [['evidence_hash' => 'other', 'content' => str_repeat('Unrelated text. ', 1000),
                'verified_identifier_hashes' => [hash('sha256', 'OTHER-22')]]]],
            ['document_id' => 3, 'title' => 'Masked subject', 'evidence' => [['evidence_hash' => 'b', 'content' => 'Refund promised.',
                'verified_identifier_hashes' => [hash('sha256', 'CASE-B')]]]],
        ]];
        $result = app(AnswerCoverageEvaluator::class)->evaluate('Compare CASE-A and CASE-B', $evidence,
            [['text' => 'CASE-A has a replacement offer.', 'document_id' => 1]], ['subquestions' => [
                ['identifiers' => ['CASE-A']], ['identifiers' => ['CASE-B']],
            ]]);
        $this->assertFalse($result['sufficient']);
        $this->assertEqualsCanonicalizing([1, 3], $result['source_document_ids']);
        Http::assertSent(fn ($request) => array_column($request['state']['sources'], 'document_id') === [1, 3]);
    }

    public function test_compound_case_coverage_keeps_distinct_requested_anchors_and_excludes_unrelated_sources(): void
    {
        config(['ai.providers.openrouter.key' => 'test']);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['model' => 'jev-test', 'answers' => ['covered' => ['type' => 'noul', 'noul' => .99]]]));
        $documents = [];
        foreach (['ORDER-A', 'RETURN-A', 'LABEL-A', 'OTHER-A'] as $index => $id) {
            $documents[] = ['document_id' => $index + 1, 'title' => $id,
                'evidence' => [['content' => $id.' original source.', 'evidence_hash' => 'h'.$index, 'complete_source' => true]]];
        }
        $result = app(AnswerCoverageEvaluator::class)->evaluate('Compare order, return and label', ['documents' => $documents],
            [['text' => 'The label exists.', 'document_id' => 3]], ['subquestions' => [['identifiers' => ['ORDER-A', 'RETURN-A', 'LABEL-A']]]]);
        $this->assertTrue($result['sufficient']);
        $this->assertEqualsCanonicalizing([1, 2, 3], $result['source_document_ids']);
        Http::assertSent(fn ($request) => array_column($request['state']['sources'], 'document_id') === [1, 2, 3]);
    }

    public function test_coverage_budget_failure_reports_safe_measured_details_without_dispatching(): void
    {
        config(['decisions.max_state_bytes' => 100]);
        Http::preventStrayRequests();
        $result = app(AnswerCoverageEvaluator::class)->evaluate('CASE-A?', ['documents' => [[
            'document_id' => 1, 'title' => 'CASE-A', 'evidence' => [['content' => str_repeat('Original context. ', 100), 'evidence_hash' => 'h']],
        ]]], []);
        $this->assertNull($result['sufficient']);
        $this->assertGreaterThan(100, $result['state_bytes']);
        $this->assertSame(100, $result['state_max_bytes']);
        $this->assertSame('Decision state exceeds the configured limit.', $result['decision_error']);
        Http::assertNothingSent();
    }
}
