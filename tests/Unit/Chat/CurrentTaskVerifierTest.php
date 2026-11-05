<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Services\Chat\ChatQuestionPreprocessor;
use App\Services\Chat\QuestionAction;
use App\Services\Chat\Reasoning\CurrentTaskVerifier;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CurrentTaskVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['reasoning.selective_validation' => true, 'reasoning.threshold' => .8, 'ai.providers.openrouter.key' => 'test']);
        Http::preventStrayRequests();
    }

    #[DataProvider('operationalFollowups')]
    public function test_a_copied_email_search_is_reinterpreted_as_the_current_operational_question(string $question): void
    {
        $this->decision(.03);
        $calls = [];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->twice()->andReturnUsing(function ($provider, $system, $messages) use (&$calls) {
            $input = json_decode($messages[0]['content'], true);
            $calls[] = $input;
            $this->assertArrayNotHasKey('previous_subquestions', $input['reasoning_context']);
            $this->assertArrayNotHasKey('objective', $input['reasoning_context']);
            $this->assertArrayNotHasKey('questions', $input['reasoning_context']['dialogue'][0]);
            $this->assertArrayNotHasKey('interpreted_goal', $input['reasoning_context']['dialogue'][0]);
            $this->assertStringContainsString('aspect=next_steps and fields=[]', $system);
            return $this->response(count($calls) === 1 ? $this->copiedTask() : $this->nextSteps());
        });
        $result = (new ChatQuestionPreprocessor($ai))->interpret($question, reasoningContext: $this->navigation());

        $this->assertTrue($result->available);
        $this->assertSame('invalid_current_task', $calls[1]['validation_error']);
        $this->assertSame($question, $calls[1]['question']);
        $this->assertSame(['U532638'], $result->targetIdentifiers());
        $this->assertSame('next_steps', $result->focus['aspect']);
        $this->assertSame([], $result->focus['fields']);
        $this->assertFalse($result->needsResearch());
        $this->assertSame(QuestionAction::Refine, $result->effectiveAction());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['state']['current_message'] === $question
            && $request['state']['proposed_tasks'][0]['question'] === $this->previousTask()['question']);
    }

    public static function operationalFollowups(): array
    {
        return [['Dobbiamo fare qualcosa?'], ['Do we need to do anything?'], ['Devons-nous faire quelque chose ?']];
    }

    public function test_an_intentional_repeat_or_refresh_can_reuse_the_previous_question(): void
    {
        $this->decision(.99);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response($this->copiedTask()));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Ricontrolla se ci sono email a riguardo.', reasoningContext: $this->navigation());
        $this->assertTrue($result->available);
        $this->assertSame('email', $result->focus['aspect']);
        $this->assertTrue($result->needsResearch());
        Http::assertSentCount(1);
    }

    public function test_a_second_stale_interpretation_cannot_replace_the_current_request(): void
    {
        $this->decision(.08);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->twice()->andReturn($this->response($this->copiedTask()));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Dobbiamo fare qualcosa?', reasoningContext: $this->navigation());
        $this->assertFalse($result->available);
        $this->assertSame('invalid_current_task', $result->failureReason);
        $this->assertSame([], $result->subquestions);
        Http::assertSentCount(2);
    }

    public function test_uncertain_operation_does_not_authorize_a_copied_task(): void
    {
        $this->decision(.61);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->twice()->andReturn($this->response($this->copiedTask()));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Dobbiamo fare qualcosa?', reasoningContext: $this->navigation());
        $this->assertFalse($result->available);
        $this->assertSame('invalid_current_task', $result->failureReason);
    }

    public function test_a_malformed_decision_does_not_trigger_another_generation_attempt(): void
    {
        Http::fake(['*' => Http::response(['model' => 'jev-test', 'answers' => []])]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response($this->copiedTask()));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Dobbiamo fare qualcosa?', reasoningContext: $this->navigation());
        $this->assertFalse($result->available);
        $this->assertSame('provider_unavailable', $result->failureReason);
        Http::assertSentCount(1);
    }

    public function test_a_new_correctly_scoped_task_needs_no_extra_semantic_request(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response($this->nextSteps()));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Dobbiamo fare qualcosa?', reasoningContext: $this->navigation());
        $this->assertTrue($result->available);
        Http::assertNothingSent();
    }

    public function test_exact_repetition_of_a_standalone_question_is_not_rejected_mechanically(): void
    {
        $verifier = new CurrentTaskVerifier;
        $this->assertFalse($verifier->reusesPreviousTask($this->previousTask()['question'], [$this->previousTask()], $this->navigation()));
        $this->assertFalse($verifier->reusesPreviousTask('Dobbiamo fare qualcosa?', [$this->previousTask()], []));
        Http::assertNothingSent();
    }

    private function decision(float $probability): void
    {
        Http::fake(['*' => Http::response(['model' => 'jev-test', 'answers' => ['matches' => ['type' => 'noul', 'noul' => $probability]]])]);
    }

    private function previousTask(): array
    {
        return ['topic' => 'ordine', 'identifiers' => ['U532638'], 'aspect' => 'email', 'fields' => ['*'],
            'question' => "Qualche email a riguardo per l'ordine U532638?", 'kb_queries' => ["email relative all'ordine U532638"],
            'context_mode' => 'conversation', 'requires_research' => true];
    }

    private function navigation(): array
    {
        return ['known_identifiers' => ['U532638'], 'focus' => array_intersect_key($this->previousTask(), array_flip(['topic', 'identifiers', 'aspect', 'fields'])),
            'objective' => "Cercare email relative all'ordine U532638", 'subquestions' => [$this->previousTask()],
            'dialogue' => [['role' => 'user', 'text' => 'qualche email a riguardo?', 'interpreted_goal' => 'Find order emails', 'questions' => [$this->previousTask()]],
                ['role' => 'assistant', 'answered_questions' => [$this->previousTask()]]]];
    }

    private function copiedTask(): array
    {
        return ['language' => 'it', 'intent' => "Cercare email relative all'ordine U532638", 'action' => 'research',
            'kb_queries' => $this->previousTask()['kb_queries'], 'mentions' => [], 'references_previous_turn' => true,
            'context_mode' => 'conversation', 'requires_research' => true, 'transition' => 'continue',
            'focus' => array_intersect_key($this->previousTask(), array_flip(['topic', 'identifiers', 'aspect', 'fields'])),
            'subquestions' => [$this->previousTask()], 'resolved_references' => ['U532638'], 'needs_clarification' => false, 'clarification' => ''];
    }

    private function nextSteps(): array
    {
        $focus = ['topic' => 'ordine', 'identifiers' => ['U532638'], 'aspect' => 'next_steps', 'fields' => []];
        return [...$this->copiedTask(), 'intent' => 'Valutare quali azioni restano da fare per U532638', 'action' => 'refine',
            'kb_queries' => [], 'requires_research' => false, 'focus' => $focus,
            'subquestions' => [[...$focus, 'question' => "Quali azioni restano da fare per l'ordine U532638 secondo le email consultate?",
                'kb_queries' => [], 'context_mode' => 'conversation', 'requires_research' => false]]];
    }

    private function response(array $data): AiResponse
    {
        return new AiResponse(json_encode($data, JSON_THROW_ON_ERROR), 'fake', 'test');
    }
}
