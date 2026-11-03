<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Decisions\DecisionException;
use App\Decisions\Models\E2eFixtureDecisionModel;
use App\Services\Dev\AgentRetrievalFixture as Fixture;
use App\Agent\AgentExecutionContext;
use App\Agent\Capabilities\AgentCapabilitySnapshot;
use App\Agent\Planning\AgentPlanner;
use App\Agent\Tools\AgentToolDefinition;
use App\Services\Chat\ChatQuestionPreprocessor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class E2eFixtureDecisionModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.default' => 'fake', 'decisions.e2e_fixture_enabled' => true]);
    }

    private function state(array $record = Fixture::ORDERS[0], ?string $text = null): array
    {
        return ['question' => Fixture::QUESTIONS[0], 'pairs' => [['claim_id' => 'claim_4',
            'claim' => $text ?? Fixture::orderText(Fixture::ORDERS[0]), 'source_ref' => 'record']],
            'sources' => ['record' => json_encode($record, JSON_THROW_ON_ERROR)]];
    }

    private function question(): array
    {
        return ['claim_4' => ['type' => 'choice', 'criteria' => array_fill_keys([
            'supported_relevant', 'supported_off_topic', 'unsupported', 'conflicting', 'insufficient_context',
        ], 'fixture criterion')]];
    }

    public function test_offline_interpretation_passes_the_real_focus_and_initial_plan_contract_without_http(): void
    {
        Http::preventStrayRequests();
        config(['ai.question_preprocessor.provider' => 'fake']);
        $tools = [];
        foreach (['find_customer', 'get_orders'] as $name) {
            $tools[$name] = new AgentToolDefinition($name, $name, 'Synthetic retrieval', 'api',
                ['type' => 'object'], true, true, 1, 1, 2);
        }
        $context = new AgentExecutionContext('fixture-run', 'default', 'hrportal', 'chat', 'user', '1', 'it', 'UTC');
        $contract = app(AgentPlanner::class)->initialPlanningContract($context, $tools, new AgentCapabilitySnapshot([], 'fixture', 0), 3);
        $understanding = app(ChatQuestionPreprocessor::class)->interpret(Fixture::QUESTIONS[0], reasoningContext: [], initialPlanning: $contract);
        $this->assertTrue($understanding->available, json_encode($understanding->toArray()));
        $this->assertTrue($understanding->needsResearch());
        $this->assertSame([], $understanding->focus['identifiers']);
        $this->assertSame([['text' => 'Tizio', 'type' => 'name']], $understanding->mentions);
        $this->assertSame('find_customer', $understanding->initialPlan['actions'][0]['tool']);
        $this->assertSame('{"name":"Tizio"}', $understanding->initialPlan['actions'][0]['arguments_json']);
        Http::assertNothingSent();
    }

    public function test_only_the_canonical_statement_for_the_matching_record_is_supported(): void
    {
        $driver = new E2eFixtureDecisionModel;
        $this->assertSame('supported_relevant', $driver->decide($this->state(), $this->question())->answers['claim_4']['choice']);
        $this->assertSame('unsupported', $driver->decide($this->state(Fixture::ORDERS[1]), $this->question())->answers['claim_4']['choice']);
        $this->assertSame('unsupported', $driver->decide($this->state(text: 'Ordine A-100: totale 999.'), $this->question())->answers['claim_4']['choice']);
    }

    public function test_unlisted_interpretation_does_not_propose_any_canned_lookup(): void
    {
        Http::preventStrayRequests();
        config(['ai.question_preprocessor.provider' => 'fake']);
        $understanding = app(ChatQuestionPreprocessor::class)->interpret('Dammi tutti gli ordini di Tizio e di Caio', reasoningContext: []);
        $this->assertFalse($understanding->available);
        $this->assertNull($understanding->initialPlan);
        Http::assertNothingSent();
    }

    public function test_only_the_canonical_document_content_can_supply_policy_coverage(): void
    {
        $driver = new E2eFixtureDecisionModel;
        $state = ['question' => Fixture::POLICY_QUESTION, 'answer' => [Fixture::POLICY],
            'sources' => [['document_id' => 1, 'title' => 'Remote Work Policy', 'text' => Fixture::POLICY, 'complete_source' => true]]];
        $questions = ['covered' => ['type' => 'noul', 'criteria' => ['true' => 'covered', 'false' => 'missing']]];
        $this->assertSame(1.0, $driver->decide($state, $questions)->answers['covered']['probability_true']);
        $state['sources'][0]['title'] = 'e2e-remote-work';
        $this->assertSame(1.0, $driver->decide($state, $questions)->answers['covered']['probability_true']);
        $state['sources'] = [['result' => ['metadata' => Fixture::POLICY]]];
        $this->assertArrayHasKey('covered', $driver->decideIndependently($state, $questions)->answerErrors);
        $state['sources'] = [['metadata' => ['text' => Fixture::POLICY]]];
        $result = $driver->decideIndependently($state, $questions);
        $this->assertArrayNotHasKey('covered', $result->answers);
        $this->assertArrayHasKey('covered', $result->answerErrors);
    }

    public function test_changed_source_values_are_not_approved(): void
    {
        $this->expectException(DecisionException::class);
        (new E2eFixtureDecisionModel)->decide($this->state([...Fixture::ORDERS[0], 'total' => 999]), $this->question());
    }

    public function test_independent_unknown_decision_abstains_without_erasing_a_valid_sibling(): void
    {
        $result = (new E2eFixtureDecisionModel)->decideIndependently($this->state(), [
            ...$this->question(), 'unknown' => ['type' => 'noul', 'criteria' => ['true' => 'yes', 'false' => 'no']],
        ]);
        $this->assertSame('supported_relevant', $result->answers['claim_4']['choice']);
        $this->assertArrayNotHasKey('unknown', $result->answers);
        $this->assertArrayHasKey('unknown', $result->answerErrors);
    }

    public function test_a_changed_related_record_prevents_approval(): void
    {
        $state = $this->state();
        $state['pairs'][0]['related_evidence'] = [['source_ref' => 'changed']];
        $state['sources']['changed'] = json_encode([...Fixture::ORDERS[0], 'total' => 999], JSON_THROW_ON_ERROR);
        $this->expectException(DecisionException::class);
        (new E2eFixtureDecisionModel)->decide($state, $this->question());
    }

    public function test_coverage_requires_every_available_canonical_order(): void
    {
        $state = ['question' => Fixture::QUESTIONS[0], 'sources' => [['result' => ['items' => Fixture::ORDERS]]],
            'answer' => array_map(fn ($record) => Fixture::orderText($record), Fixture::ORDERS)];
        $questions = ['covered' => ['type' => 'noul', 'criteria' => ['true' => 'covered', 'false' => 'missing']]];
        $driver = new E2eFixtureDecisionModel;
        $this->assertSame(1.0, $driver->decide($state, $questions)->answers['covered']['probability_true']);
        array_pop($state['answer']);
        $this->assertSame(0.0, $driver->decide($state, $questions)->answers['covered']['probability_true']);
        $state['question'] = Fixture::QUESTIONS[1];
        $this->assertSame(0.0, $driver->decide($state, $questions)->answers['covered']['probability_true']);
    }

    public function test_added_source_context_and_unrelated_coverage_tasks_abstain(): void
    {
        $this->assertNull(Fixture::matchOrder([...Fixture::ORDERS[0], 'customer_name' => 'Caio']));
        $state = ['question' => Fixture::QUESTIONS[0], 'sources' => Fixture::ORDERS,
            'answer' => array_map(fn ($record) => Fixture::orderText($record), Fixture::ORDERS),
            'requested_tasks' => [['question' => 'Was the refund paid?']]];
        foreach (['task_0', 'task_99'] as $key) {
            $result = (new E2eFixtureDecisionModel)->decideIndependently($state, [
                $key => ['type' => 'noul', 'criteria' => ['true' => 'yes', 'false' => 'no']],
            ]);
            $this->assertArrayNotHasKey($key, $result->answers);
            $this->assertArrayHasKey($key, $result->answerErrors);
        }
    }

    public function test_a_canonical_order_hidden_in_metadata_is_not_returned_record_evidence(): void
    {
        $state = ['question' => Fixture::QUESTIONS[0], 'answer' => [Fixture::orderText(Fixture::ORDERS[0])],
            'sources' => [['result' => ['metadata' => ['cached_order' => Fixture::ORDERS[0]]]]]];
        $questions = ['covered' => ['type' => 'noul', 'criteria' => ['true' => 'covered', 'false' => 'missing']]];
        $result = (new E2eFixtureDecisionModel)->decideIndependently($state, $questions);
        $this->assertArrayNotHasKey('covered', $result->answers);
        $this->assertArrayHasKey('covered', $result->answerErrors);
    }

    public function test_unknown_questions_and_decisions_fail_closed(): void
    {
        foreach ([['question' => 'An arbitrary customer request'], []] as $difference) {
            try {
                (new E2eFixtureDecisionModel)->decide([...$this->state(), ...$difference], ['unknown' => ['type' => 'noul', 'criteria' => ['true' => 'yes', 'false' => 'no']]]);
                $this->fail('An unsupported fixture decision was approved.');
            } catch (DecisionException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_explicit_opt_in_and_testing_environment_are_both_required(): void
    {
        $driver = new E2eFixtureDecisionModel;
        config(['decisions.e2e_fixture_enabled' => false]);
        try { $driver->decide($this->state(), $this->question()); $this->fail('Missing opt-in admitted.'); }
        catch (DecisionException) { $this->addToAssertionCount(1); }
        config(['decisions.e2e_fixture_enabled' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(DecisionException::class);
        try { $driver->decide($this->state(), $this->question()); }
        finally { $this->app->detectEnvironment(fn () => 'testing'); }
    }
}
