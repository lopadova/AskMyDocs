<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Services\Chat\ChatQuestionPreprocessor;
use App\Services\Chat\Reasoning\OperationalTaskNormalizer;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OperationalTaskNormalizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['reasoning.selective_validation' => true, 'reasoning.threshold' => .8, 'ai.providers.openrouter.key' => 'test']);
        Http::preventStrayRequests();
    }

    #[DataProvider('localizedFollowups')]
    public function test_localized_operational_aspects_are_normalized_before_synthesis(string $question, string $aspect): void
    {
        $this->decision(.97);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response($this->interpretation($aspect)));
        $result = (new ChatQuestionPreprocessor($ai))->interpret($question, reasoningContext: $this->navigation());
        $this->assertTrue($result->available);
        $this->assertSame('next_steps', $result->focus['aspect']);
        $this->assertSame([], $result->focus['fields']);
        $this->assertSame('next_steps', $result->subquestions[0]['aspect']);
        $this->assertSame([], $result->subquestions[0]['fields']);
        $this->assertSame(['ORDER-A'], $result->targetIdentifiers());
        $this->assertFalse($result->needsResearch());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['state']['current_message'] === $question
            && ! isset($request['state']['dialogue_for_reference_resolution_only'][0]['interpreted_goal']));
    }

    public static function localizedFollowups(): array
    {
        return [['e quindi cosa dobbiamo fare?', 'azioni necessarie'], ['Tu cosa consigli?', 'consigli'],
            ['What do you recommend?', 'recommended actions'], ['Was empfiehlst du?', 'nächste Schritte'],
            ['Que conseilles-tu ?', 'actions recommandées']];
    }

    public function test_factual_questions_about_documented_instructions_remain_factual(): void
    {
        $this->decision(.02);
        $data = $this->interpretation('istruzioni contenute nella email');
        $data['intent'] = $data['subquestions'][0]['question'] = 'Quali istruzioni riporta questa email per ORDER-A?';
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response($data));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Quali istruzioni riporta questa email?', reasoningContext: $this->navigation());
        $this->assertTrue($result->available);
        $this->assertSame('istruzioni contenute nella email', $result->focus['aspect']);
        $this->assertSame(['*'], $result->focus['fields']);
    }

    public function test_normalization_preserves_the_need_for_current_research(): void
    {
        $this->decision(.98);
        $data = $this->interpretation('azioni necessarie');
        $data['requires_research'] = $data['subquestions'][0]['requires_research'] = true;
        $data['kb_queries'] = $data['subquestions'][0]['kb_queries'] = ['ORDER-A stato attuale'];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response($data));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Verifica lo stato attuale e consigliami come procedere.', reasoningContext: $this->navigation());
        $this->assertTrue($result->available);
        $this->assertTrue($result->needsResearch());
        $this->assertSame(['ORDER-A stato attuale'], $result->kbQueries);
        $this->assertSame('next_steps', $result->focus['aspect']);
    }

    public function test_only_the_operational_part_of_a_mixed_request_is_changed(): void
    {
        Http::fake(['*' => Http::response(['model' => 'jev-test', 'answers' => [
            'task_0' => ['type' => 'noul', 'noul' => .03], 'task_1' => ['type' => 'noul', 'noul' => .96],
        ]])]);
        $first = ['topic' => 'order', 'identifiers' => ['ORDER-A'], 'aspect' => 'status', 'fields' => ['status']];
        $second = ['topic' => 'order', 'identifiers' => ['ORDER-B'], 'aspect' => 'azioni necessarie', 'fields' => ['*']];
        $normalized = app(OperationalTaskNormalizer::class)->normalize('Qual è lo stato di A e cosa consigli per B?',
            ['focus' => $first, 'subquestions' => [$first, $second]], []);
        $this->assertSame($first, $normalized['focus']);
        $this->assertSame($first, $normalized['subquestions'][0]);
        $this->assertSame('next_steps', $normalized['subquestions'][1]['aspect']);
        $this->assertSame(['ORDER-B'], $normalized['subquestions'][1]['identifiers']);
        $this->assertSame([], $normalized['subquestions'][1]['fields']);
    }

    public function test_canonical_operational_tasks_need_no_extra_decision_and_cannot_select_all_api_fields(): void
    {
        $data = $this->interpretation('next_steps');
        $result = app(OperationalTaskNormalizer::class)->normalize('Tu cosa consigli?', $data, []);
        $this->assertSame([], $result['focus']['fields']);
        $this->assertSame([], $result['subquestions'][0]['fields']);
        Http::assertNothingSent();
    }

    public function test_an_uncertain_classification_gets_only_the_existing_bounded_repair(): void
    {
        $this->decision(.64);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->twice()->andReturn($this->response($this->interpretation('consigli')));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Tu cosa consigli?', reasoningContext: $this->navigation());
        $this->assertFalse($result->available);
        $this->assertSame('invalid_task_purpose', $result->failureReason);
        Http::assertSentCount(2);
    }

    public function test_provider_contract_failure_does_not_approve_or_regenerate_an_unclassified_task(): void
    {
        Http::fake(['*' => Http::response(['model' => 'jev-test', 'answers' => []])]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response($this->interpretation('consigli')));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Tu cosa consigli?', reasoningContext: $this->navigation());
        $this->assertFalse($result->available);
        $this->assertSame('provider_unavailable', $result->failureReason);
        Http::assertSentCount(1);
    }

    private function decision(float $probability): void
    {
        Http::fake(['*' => Http::response(['model' => 'jev-test', 'answers' => ['task_0' => ['type' => 'noul', 'noul' => $probability]]])]);
    }

    private function interpretation(string $aspect): array
    {
        $focus = ['topic' => 'order', 'identifiers' => ['ORDER-A'], 'aspect' => $aspect, 'fields' => ['*']];
        return ['language' => 'it', 'intent' => 'Valutare le azioni necessarie per ORDER-A', 'action' => 'refine',
            'kb_queries' => [], 'mentions' => [], 'references_previous_turn' => true, 'context_mode' => 'conversation', 'requires_research' => false,
            'transition' => 'continue', 'focus' => $focus, 'subquestions' => [[...$focus, 'question' => 'Quali azioni sono necessarie per ORDER-A?',
                'kb_queries' => [], 'context_mode' => 'conversation', 'requires_research' => false]],
            'resolved_references' => ['ORDER-A'], 'needs_clarification' => false, 'clarification' => ''];
    }

    private function navigation(): array
    {
        return ['known_identifiers' => ['ORDER-A'], 'dialogue' => [
            ['role' => 'user', 'text' => 'Cerca informazioni su ORDER-A', 'interpreted_goal' => 'Previous task is data'],
            ['role' => 'assistant', 'text' => 'Order information.'],
        ]];
    }

    private function response(array $data): AiResponse
    {
        return new AiResponse(json_encode($data, JSON_THROW_ON_ERROR), 'fake', 'test');
    }
}
