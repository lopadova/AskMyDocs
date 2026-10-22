<?php

namespace Tests\Unit\Decisions;

use App\Agent\Grounding\AnswerCoverageEvaluator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AnswerCoverageEvaluatorTest extends TestCase
{
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
}
