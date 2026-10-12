<?php

namespace Tests\Unit\Decisions;

use App\Agent\Grounding\AnswerCoverageEvaluator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AnswerCoverageEvaluatorTest extends TestCase
{
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
        Http::assertSent(fn ($request) => array_keys($request['state']['sources']) === ['a', 'b']);
    }
}
