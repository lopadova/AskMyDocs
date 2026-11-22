<?php

namespace Tests\Unit\Decisions;

use App\Agent\Grounding\AnswerCoverageEvaluator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AnswerCoverageEvaluatorTest extends TestCase
{
    public function test_recovery_uses_the_uncited_manual_and_preserves_the_covered_task(): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (isset($request['questions']['covered'])) {
                return Http::response(['model' => 'fixture', 'answers' => [
                    'covered' => ['type' => 'noul', 'noul' => .36],
                    'task_0' => ['type' => 'noul', 'noul' => .99],
                    'task_1' => ['type' => 'noul', 'noul' => .20],
                ]]);
            }
            $this->assertSame('Give two documented examples.', $request['state']['missing_task']);
            $answers = [];
            foreach ($request['questions'] as $key => $criterion) {
                $j = (int) substr($key, 7);
                $action = $request['state']['sources'][$j]['document_id'] === 2 ? 'compose' : 'search';
                $answers[$key] = ['type' => 'choice', 'choice' => $action, 'confidence' => 1,
                    'probabilities' => array_replace(array_fill_keys(array_keys($criterion['criteria']), 0), [$action => 1])];
            }
            return Http::response(['model' => 'fixture', 'answers' => $answers]);
        });
        $result = app(AnswerCoverageEvaluator::class)->evaluate('What does it do? Give two examples.', ['documents' => [
            ['document_id' => 1, 'title' => 'Presentation', 'evidence' => [['content' => 'The tool prepares outfit images.']]],
            ['document_id' => 2, 'title' => 'Manual', 'evidence' => [['content' => 'Keep the garment and vary the pose. Then keep the pose and vary only the backdrop.']]],
        ]], [['text' => 'The tool prepares outfit images.', 'document_id' => 1, 'subquestion_id' => 0]],
            ['deliverable' => ['kind' => 'examples', 'quantity' => 2, 'freshness' => 'source_context'],
                'subquestions' => [['question' => 'What does it do?', 'identifiers' => ['OUTFIT']], ['question' => 'Give two documented examples.']]],
            run: new \App\Models\AgentRun(['channel' => 'chat', 'user_id' => 1, 'conversation_id' => 1, 'input_json' => ['session_evidence' => true]]));
        $this->assertSame('compose', $result['next_action']);
        $this->assertSame([1, 2], $result['source_document_ids']);
        $this->assertSame(['satisfied', 'missing'], array_column($result['tasks'], 'status'));
        $this->assertSame([2], $result['tasks'][1]['source_document_ids']);
        Http::assertSentCount(2);
    }

    public static function partitionRelevance(): array
    {
        return [[.02, false, true], [.02, true, false], [.25, false, false]];
    }

    public function test_a_known_document_without_text_can_be_read_but_cannot_support_composition(): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (isset($request['questions']['covered'])) {
                $this->assertSame([], $request['state']['sources']);
                return Http::response(['model' => 'fixture', 'answers' => array_fill_keys(array_keys($request['questions']), ['type' => 'noul', 'noul' => .01])]);
            }
            $source = $request['questions']['source_0'];
            $this->assertArrayNotHasKey('compose', $source['criteria']);
            $this->assertTrue($request['state']['sources'][0]['navigation_only']);
            return Http::response(['model' => 'fixture', 'answers' => ['source_0' => ['type' => 'choice', 'choice' => 'read', 'confidence' => 1,
                'probabilities' => ['read' => 1, 'search' => 0]]]]);
        });
        $run = new \App\Models\AgentRun(['channel' => 'chat', 'user_id' => 1, 'conversation_id' => 1, 'input_json' => ['session_evidence' => true]]);
        $result = app(AnswerCoverageEvaluator::class)->evaluate('Show a worked example.', ['document_coordinates' => [[
            'document_id' => 12, 'chunk_id' => 75, 'title' => 'Worked examples', 'heading' => 'Outfits',
        ]]], [], run: $run);
        $this->assertFalse($result['sufficient']);
        $this->assertSame([], $result['source_document_ids']);
        $this->assertSame('read', $result['next_action']);
        $this->assertSame([12], $result['tasks'][0]['source_document_ids']);
        $this->assertSame(75, $result['tasks'][0]['source_passages'][0]['chunk_id']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('partitionRelevance')]
    public function test_only_confidently_unrelated_partitions_add_no_obligation_and_missing_material_still_vetoes(float $oldEpisodeRelevance, bool $missingUpdate, bool $sufficient): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'decisions.max_state_bytes' => 24000, 'reasoning.threshold' => .8]);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($oldEpisodeRelevance) {
            $text = json_encode($request['state']['sources']);
            $old = str_contains($text, 'Old packaging discussion');
            $probability = $old ? .05 : (str_contains($text, 'Missing pickup update') ? .2 : .99);
            $answers = array_fill_keys(array_keys($request['questions']), ['type' => 'noul', 'noul' => $probability]);
            $answers['material_in_partition']['noul'] = $old ? $oldEpisodeRelevance : .99;
            return Http::response(['model' => 'jev-test', 'answers' => $answers]);
        });
        $labels = ['Pickup confirmation', 'Old packaging discussion'];
        if ($missingUpdate) $labels[] = 'Missing pickup update';
        $documents = [];
        foreach ($labels as $index => $label) $documents[] = ['document_id' => $index + 1, 'title' => 'CASE-A',
            'evidence' => [['content' => $label.str_repeat(' Original context. ', 800), 'complete_source' => true]]];
        $tasks = array_map(fn ($i) => ['question' => 'Pickup aspect '.$i, 'identifiers' => ['CASE-A']], range(0, 4));
        $result = app(AnswerCoverageEvaluator::class)->evaluate('What confirms the pickup?', ['documents' => $documents],
            [['text' => 'Pickup was confirmed.', 'document_id' => 1]], ['subquestions' => $tasks]);
        $this->assertSame($sufficient, $result['sufficient'], json_encode($result));
        $this->assertSame(count($documents), $result['source_partition_count']);
        $this->assertSame($oldEpisodeRelevance <= .2, $result['checks'][1]['ignored_as_unrelated']);
        if ($missingUpdate) $this->assertSame(.2, $result['probability_true']);
        $seen = [];
        foreach (Http::recorded() as [$request]) {
            $this->assertSame(7, count($request['questions']));
            array_push($seen, ...array_column($request['state']['sources'], 'document_id'));
        }
        $this->assertSame(range(1, count($documents)), $seen);
    }

    public function test_one_missing_task_vetoes_a_coarse_positive_coverage_judgment(): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8]);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['model' => 'jev-test', 'answers' => [
            'covered' => ['type' => 'noul', 'noul' => .99], 'task_0' => ['type' => 'noul', 'noul' => .99],
            'task_1' => ['type' => 'noul', 'noul' => .2],
        ]]));
        $result = app(AnswerCoverageEvaluator::class)->evaluate('Compare the item and explain the refund condition.',
            ['documents' => [['document_id' => 1, 'title' => 'CASE-A', 'evidence' => [[
                'content' => 'CASE-A ordered ITEM-A. The merchant proposed a refund only if the returned pair is identical and conforming.', 'evidence_hash' => 'h',
            ]]]]], [['text' => 'CASE-A ordered ITEM-A.', 'document_id' => 1]], ['subquestions' => [
                ['question' => 'What item was ordered?', 'identifiers' => ['CASE-A']],
                ['question' => 'What condition did the merchant state for the refund?', 'identifiers' => ['CASE-A']],
            ]]);
        $this->assertFalse($result['sufficient']);
        $this->assertSame(.2, $result['probability_true']);
        $this->assertSame(2, $result['requested_task_count']);
        $this->assertSame([.99, .2], $result['checks'][0]['task_probabilities']);
        $this->assertSame([1], $result['checks'][0]['source_document_ids']);
        Http::assertSent(fn ($request) => count($request['questions']) === 3 && count($request['state']['requested_tasks']) === 2);
    }

    public function test_all_ten_tasks_are_checked_without_exceeding_the_transport_question_limit(): void
    {
        config(['ai.providers.openrouter.key' => 'test']);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            return Http::response(['model' => 'jev-test', 'answers' => array_fill_keys(array_keys($request['questions']),
                ['type' => 'noul', 'noul' => .99])]);
        });
        $tasks = array_map(fn ($i) => ['question' => 'Requested aspect '.$i, 'identifiers' => ['CASE-A']], range(0, 9));
        $result = app(AnswerCoverageEvaluator::class)->evaluate('All requested aspects?', ['documents' => [[
            'document_id' => 1, 'title' => 'CASE-A', 'evidence' => [['content' => 'CASE-A original evidence.', 'evidence_hash' => 'h']],
        ]]], [['text' => 'CASE-A.', 'document_id' => 1]], ['subquestions' => $tasks]);
        $this->assertTrue($result['sufficient'], json_encode($result));
        $this->assertSame([6, 6], array_map(fn ($pair) => count($pair[0]['questions']), Http::recorded()->all()));
        $this->assertSame(range(0, 9), array_merge(...array_column($result['batches'], 'task_indexes')));
    }

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

    public function test_large_coverage_keeps_every_source_and_requires_every_partition_to_pass(): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'decisions.max_state_bytes' => 24000, 'reasoning.threshold' => .8]);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response(['model' => 'jev-test',
            'answers' => ['covered' => ['type' => 'noul', 'noul' => str_contains(json_encode($request['state']['sources']), 'Missing resolution') ? .2 : .99],
                'material_in_partition' => ['type' => 'noul', 'noul' => .99]]]));
        $documents = [];
        foreach (['Opening request', 'Missing resolution', 'Final update'] as $index => $label) {
            $documents[] = ['document_id' => $index + 1, 'title' => 'CASE-A',
                'evidence' => [['content' => $label.str_repeat(' Original source. ', 800), 'complete_source' => true]]];
        }
        $result = app(AnswerCoverageEvaluator::class)->evaluate('CASE-A history?', ['documents' => $documents],
            [['text' => 'CASE-A was requested.', 'document_id' => 1]], ['focus' => ['identifiers' => ['CASE-A']]]);
        $this->assertFalse($result['sufficient']);
        $this->assertSame(.2, $result['probability_true']);
        $this->assertSame(3, $result['source_partition_count']);
        $this->assertLessThanOrEqual(24000, $result['state_bytes']);
        $this->assertGreaterThan(24000, $result['logical_state_bytes']);
        $seen = [];
        foreach (Http::recorded() as [$request]) {
            $this->assertLessThanOrEqual(24000, strlen(json_encode($request['state'], JSON_UNESCAPED_UNICODE)));
            array_push($seen, ...array_column($request['state']['sources'], 'text'));
        }
        foreach ($documents as $document) $this->assertContains($document['evidence'][0]['content'], $seen);
        Http::assertSentCount(3);
    }
}
