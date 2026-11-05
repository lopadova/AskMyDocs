<?php

namespace Tests\Unit\Decisions;

use App\Agent\Grounding\FocusedClaimEvaluator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class FocusedDecisionBatchingTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_large_case_checks_every_original_in_bounded_requests_without_truncation(): void
    {
        [$evidence, $claims, $understanding] = $this->fixture();
        $this->fake();
        $result = app(FocusedClaimEvaluator::class)->evaluate('CASE-A status?', $evidence, $claims, $understanding);
        $this->assertCount(1, $result['claims']);
        $meta = $result['semantic_validation'][0];
        $this->assertSame('shared_sources_batched_v1', $meta['state_layout']);
        $this->assertSame(0, $meta['state_limit_count']);
        $this->assertGreaterThan(40000, $meta['logical_state_bytes']);
        $this->assertLessThanOrEqual(40000, $meta['state_bytes']);
        $originals = [];
        foreach (Http::recorded() as [$request]) {
            $this->assertLessThanOrEqual(40000, strlen(json_encode($request['state'], JSON_UNESCAPED_UNICODE)));
            foreach ($request['state']['sources'] ?? [] as $text) $originals[] = $text;
        }
        foreach ($evidence['documents'] as $document) $this->assertContains($document['evidence'][0]['content'], $originals);
        $this->assertSame(.8, (float) config('reasoning.threshold'));
    }

    public function test_contradiction_in_a_later_partition_cannot_be_overruled_by_a_supported_first_partition(): void
    {
        [$evidence, $claims, $understanding] = $this->fixture();
        $this->fake('conflicting');
        $result = app(FocusedClaimEvaluator::class)->evaluate('CASE-A status?', $evidence, $claims, $understanding);
        $this->assertFalse($result['valid']);
        $this->assertSame('conflicting_evidence', $result['reason']);
        $this->assertSame('conflicting', $result['semantic_validation'][0]['checks'][0]['category']);
    }

    public function test_missing_partition_answer_never_promotes_a_claim(): void
    {
        [$evidence, $claims, $understanding] = $this->fixture();
        $this->fake('missing');
        $result = app(FocusedClaimEvaluator::class)->evaluate('CASE-A status?', $evidence, $claims, $understanding);
        $this->assertFalse($result['valid']);
        $this->assertSame('decision_unavailable', $result['semantic_validation'][0]['checks'][0]['reason']);
    }

    public function test_a_failed_partition_does_not_erase_an_independent_verified_subject(): void
    {
        [$evidence, $claims, $understanding] = $this->fixture();
        $evidence['documents'][] = ['document_id' => 4, 'title' => 'CASE-B', 'evidence' => [[
            'content' => 'CASE-B status is confirmed.', 'evidence_hash' => 'hash-b', 'complete_source' => true,
        ]]];
        $claims[] = ['text' => 'CASE-B status is confirmed.', 'document_id' => 4, 'evidence_hash' => 'hash-b'];
        $understanding['subquestions'][] = ['identifiers' => ['CASE-B'], 'fields' => ['*'], 'aspect' => 'status'];
        $this->fake('missing');
        $result = app(FocusedClaimEvaluator::class)->evaluate('CASE-A and CASE-B status?', $evidence, $claims, $understanding);
        $this->assertTrue($result['valid']);
        $this->assertCount(1, $result['claims']);
        $this->assertSame('CASE-B status is confirmed.', $result['claims'][0]['text']);
        $this->assertTrue($result['partial']);
    }

    public function test_missing_answer_cannot_hide_a_confident_conflict_and_enable_literal_fallback(): void
    {
        $conflict = ['choice' => 'conflicting', 'probabilities' => ['conflicting' => .99, 'supported_relevant' => .01]];
        $this->assertSame($conflict, app(\App\Agent\Grounding\FocusedDecisionBatches::class)->combine([null, $conflict]));
        $unsupported = ['choice' => 'unsupported', 'probabilities' => ['unsupported' => 1., 'supported_relevant' => 0.]];
        $this->assertSame($conflict, app(\App\Agent\Grounding\FocusedDecisionBatches::class)->combine([$unsupported, $conflict]));
    }

    public function test_an_unsplittable_alternative_fails_closed_instead_of_disappearing(): void
    {
        [$evidence, $claims, $understanding] = $this->fixture();
        $evidence['documents'][2]['evidence'][0]['content'] = 'CASE-A '.str_repeat('Large original. ', 4000);
        Http::preventStrayRequests();
        $result = app(FocusedClaimEvaluator::class)->evaluate('CASE-A status?', $evidence, $claims, $understanding);
        $this->assertFalse($result['valid']);
        $this->assertSame('state_limit', $result['semantic_validation'][0]['checks'][0]['reason']);
        Http::assertNothingSent();
    }

    public function test_completed_partitions_are_reused_without_provider_replay(): void
    {
        [$evidence, $claims, $understanding] = $this->fixture();
        $this->fake();
        $run = \App\Models\AgentRun::create(['run_id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => 'acme',
            'project_key' => 'demo', 'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => '1',
            'locale' => 'en', 'timezone' => 'UTC', 'status' => 'running', 'started_at' => now(),
            'input_json' => [], 'result_json' => [], 'counters_json' => [], 'budget_json' => []]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $result = app(FocusedClaimEvaluator::class)->evaluate('CASE-A status?', $evidence, $claims, $understanding,
                'demo', 'acme', runId: $run->run_id);
            $this->assertCount(1, $result['claims']);
        }
        Http::assertSentCount(3); // Two full-context partitions plus original-question relevance, only once.
        $slots = $run->fresh()->result_json;
        $this->assertSame('completed', $slots['focused_decision_batch_0']['status']);
        $this->assertSame('completed', $slots['focused_decision_batch_1']['status']);
    }

    private function fixture(): array
    {
        config(['ai.providers.openrouter.key' => 'test', 'decisions.max_state_bytes' => 40000, 'reasoning.threshold' => .8]);
        Http::preventStrayRequests();
        $documents = [];
        foreach (['primary', 'earlier', 'latest'] as $index => $label) {
            $documents[] = ['document_id' => $index + 1, 'title' => 'CASE-A '.$label, 'evidence' => [[
                'evidence_hash' => 'hash-'.$index, 'complete_source' => true,
                'content' => 'CASE-A '.$label.': status confirmed. '.str_repeat('Original context retained. ', 600),
            ]]];
        }
        $sub = ['identifiers' => ['CASE-A'], 'fields' => ['*'], 'aspect' => 'status'];
        return [['documents' => $documents], [['text' => 'CASE-A status is confirmed.', 'document_id' => 1, 'evidence_hash' => 'hash-0']],
            ['focus' => $sub, 'subquestions' => [$sub]]];
    }

    private function fake(string $latest = 'supported_relevant'): void
    {
        Http::fake(function ($request) use ($latest) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) {
                if ($question['type'] === 'noul') {
                    $answers[$key] = ['type' => 'noul', 'noul' => .99];
                    continue;
                }
                $hasLatest = str_contains(json_encode($request['state']['sources']), 'CASE-A latest');
                if ($hasLatest && $latest === 'missing') continue;
                $category = $hasLatest ? $latest : 'supported_relevant';
                $probabilities = array_fill_keys(array_keys($question['criteria']), .0025);
                $probabilities[$category] = .99;
                $answers[$key] = ['type' => 'choice', 'choice' => $category, 'confidence' => .99, 'probabilities' => $probabilities];
            }
            return Http::response(['id' => 'test', 'model' => 'jev-test', 'answers' => $answers]);
        });
    }
}
