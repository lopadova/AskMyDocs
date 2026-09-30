<?php

namespace Tests\Unit\Decisions;

use App\Agent\AgentAnswerSynthesizer;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoopOutcome;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SelectiveSynthesisTest extends TestCase
{
    public static function channels(): array
    {
        return [['chat', 'user', '1'], ['widget', 'anonymous_widget', null]];
    }

    #[DataProvider('channels')]
    public function test_original_passage_replaces_bad_quote_and_one_decision_builds_the_saved_answer(string $channel, string $actor, ?string $actorId): void
    {
        config(['reasoning.enabled' => true, 'reasoning.selective_validation' => true, 'ai.providers.openrouter.key' => 'test']);
        Http::fake(function ($request) {
            return Http::response(['id' => 'decision', 'model' => 'jev-test', 'usage' => ['cost' => .001], 'answers' => [
                'claim_0' => ['type' => 'choice', 'choice' => 'supported_relevant', 'confidence' => .96,
                    'probabilities' => ['supported_relevant' => .96, 'supported_off_topic' => .01, 'unsupported' => .01, 'conflicting' => .01, 'insufficient_context' => .01]],
            ]]);
        });
        $source = 'TRACK-55 parte alle 18:00. Il magazzino prepara tre colli. Arrivo previsto domani.';
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument(['document_id' => 1, 'title' => 'Email spedizione', 'evidence' => [['chunk_id' => 1, 'content' => $source, 'evidence_hash' => 'original']]]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(content: '', provider: 'fake', model: 'test', toolCalls: [[
            'name' => 'submit_agent_answer', 'arguments' => ['claims' => [[
                'text' => 'TRACK-55 parte alle 18:00.', 'document_id' => 1, 'evidence_hash' => 'original',
                'quote' => 'TRACK-55 parte alle 18:00. Arrivo previsto domani.',
            ]], 'completeness' => 'complete', 'render_table' => false, 'requires_selection' => false, 'limitations' => []],
        ]]));
        $this->app->instance(AiManager::class, $ai);
        $focus = ['topic' => 'shipment', 'identifiers' => ['TRACK-55'], 'aspect' => 'departure', 'fields' => []];
        $answer = app(AgentAnswerSynthesizer::class)->synthesize('Quando parte TRACK-55?',
            new AgentExecutionContext('run', 'default', 'demo', $channel, $actor, $actorId, 'it', 'UTC'),
            new AgentLoopOutcome('answer', $evidence, []), understanding: ['focus' => $focus, 'subquestions' => [$focus]]);
        $this->assertSame('TRACK-55 parte alle 18:00.', $answer->answer);
        $this->assertSame($source, $answer->grounding['claims'][0]['quote']);
        $this->assertCount(1, $answer->citations);
        Http::assertSentCount(1);
    }

    public function test_rollback_keeps_text_and_sources_instead_of_clearing_claims(): void
    {
        config(['reasoning.selective_validation' => false, 'agent.grounding.batch.enabled' => false,
            'agent.grounding.enabled' => false, 'decisions.agent.enabled' => false]);
        $text = 'TRACK-55 is scheduled.';
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument(['document_id' => 1, 'title' => 'Source', 'evidence' => [['content' => $text, 'evidence_hash' => 'h']]]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(content: '', provider: 'fake', model: 'test', toolCalls: [[
            'name' => 'submit_agent_answer', 'arguments' => ['claims' => [['text' => $text, 'quote' => $text, 'document_id' => 1, 'evidence_hash' => 'h']],
                'completeness' => 'complete', 'render_table' => false, 'requires_selection' => false, 'limitations' => []],
        ]]));
        $this->app->instance(AiManager::class, $ai);
        $answer = app(AgentAnswerSynthesizer::class)->synthesize('Status?',
            new AgentExecutionContext('run', 'default', 'demo', 'chat', 'user', '1', 'en', 'UTC'), new AgentLoopOutcome('answer', $evidence, []));
        $this->assertSame($text, $answer->answer);
        $this->assertCount(1, $answer->citations);
    }

    #[DataProvider('channels')]
    public function test_malformed_mcp_source_reference_is_recovered_before_selective_synthesis(string $channel, string $actor, ?string $actorId): void
    {
        config(['reasoning.enabled' => true, 'reasoning.selective_validation' => true, 'ai.providers.openrouter.key' => 'test']);
        Http::fake(['*' => Http::response(['id' => 'decision', 'model' => 'jev-test', 'answers' => [
            'claim_0' => ['type' => 'choice', 'choice' => 'supported_relevant', 'confidence' => .96,
                'probabilities' => ['supported_relevant' => .96, 'supported_off_topic' => .01, 'unsupported' => .01, 'conflicting' => .01, 'insufficient_context' => .01]],
        ]])]);
        $record = ['id' => 'SHIP-1', 'trackingCode' => 'TRACK-55', 'status' => 'scheduled'];
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->import(['api_tools' => [['execution_id' => 86, 'evidence_hash' => 'tool-hash', 'result' => ['records' => [$record]]]]]);
        $input = null;
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturnUsing(function ($system, $messages) use (&$input) {
            $input = json_decode($messages[0]['content'], true);
            return new AiResponse('', 'fake', 'test', toolCalls: [['name' => 'submit_agent_answer', 'arguments' => [
                'claims' => [['text' => 'TRACK-55 is scheduled.', 'document_id=null,' => 86,
                    'evidence_hash' => 'tool-hash', 'quote' => 'Generated paraphrase, not evidence']],
                'completeness' => 'complete', 'render_table' => false, 'requires_selection' => false,
            ]]]);
        });
        $this->app->instance(AiManager::class, $ai);
        $focus = ['topic' => 'shipment', 'identifiers' => ['TRACK-55'], 'aspect' => 'status', 'fields' => ['status']];
        $answer = app(AgentAnswerSynthesizer::class)->synthesize('Status of TRACK-55?',
            new AgentExecutionContext('run', 'default', 'demo', $channel, $actor, $actorId, 'en', 'UTC'),
            new AgentLoopOutcome('answer', $evidence, []), understanding: ['focus' => $focus, 'subquestions' => [$focus]]);
        $this->assertSame(['document_id' => null, 'tool_execution_id' => 86, 'evidence_hash' => 'tool-hash'], $input['evidence']['api_tools'][0]['claim_source']);
        $this->assertSame('TRACK-55 is scheduled.', $answer->answer);
        $this->assertSame(86, $answer->grounding['claims'][0]['tool_execution_id']);
        $this->assertSame(json_encode($record), $answer->grounding['claims'][0]['quote']);
        $this->assertSame('unique_evidence_hash', $answer->grounding['semantic_validation'][0]['checks'][0]['source_resolution']['method']);
        Http::assertSentCount(1);
    }

    #[DataProvider('channels')]
    public function test_independent_drafts_use_only_their_task_sources_and_one_batch_decision_preserves_other_answers(string $channel, string $actor, ?string $actorId): void
    {
        config(['reasoning.enabled' => true, 'reasoning.selective_validation' => true, 'ai.providers.openrouter.key' => 'test']);
        Http::fake(function ($request) {
            $state = $request['state'];
            $this->assertCount(3, $state['pairs']);
            $answers = [];
            foreach ($state['pairs'] as $pair) {
                $i = $pair['subquestion_id'];
                $this->assertSame(['ITEM-'.$i], $state['subquestions'][$i]['identifiers']);
                $this->assertSame('ITEM-'.$i.' is active.', $state['sources'][$pair['source_ref']]);
                $category = $i === 1 ? 'unsupported' : 'supported_relevant';
                $probabilities = array_fill_keys(['supported_relevant', 'supported_off_topic', 'unsupported', 'conflicting', 'insufficient_context'], .01);
                $probabilities[$category] = .96;
                $answers[$pair['claim_id']] = ['type' => 'choice', 'choice' => $category, 'confidence' => .96, 'probabilities' => $probabilities];
            }
            return Http::response(['id' => 'decision', 'model' => 'jev-test', 'usage' => ['cost' => .001], 'answers' => $answers]);
        });
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $subs = [];
        for ($i = 0; $i < 4; $i++) {
            $subs[] = ['topic' => 'item', 'identifiers' => ['ITEM-'.$i], 'aspect' => 'details', 'fields' => ['*'],
                'question' => 'Tell me about ITEM-'.$i, 'kb_queries' => ['ITEM-'.$i]];
            $evidence->import(\App\Agent\Evidence\ResearchEvidence::tagged(['documents' => [['document_id' => $i + 1,
                'evidence' => [['content' => 'ITEM-'.$i.' is active.', 'evidence_hash' => 'hash-'.$i]]]]], $i));
        }
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->times(4)->andReturnUsing(function ($system, $messages) {
            $input = json_decode($messages[0]['content'], true);
            $i = (int) substr($input['question'], -1);
            $this->assertCount(1, $input['evidence']['documents']);
            $this->assertSame($i + 1, $input['evidence']['documents'][0]['document_id']);
            $this->assertSame('Tell me about ITEM-'.$i, $input['question']);
            if ($i === 3) {
                throw new \RuntimeException('One draft unavailable');
            }
            return new AiResponse('', 'fake', 'test', toolCalls: [['name' => 'submit_agent_answer', 'arguments' => [
                'claims' => [['text' => 'ITEM-'.$i.' is active.', 'document_id' => $i + 1, 'evidence_hash' => 'hash-'.$i,
                    'subquestion_id' => 99, 'quote' => 'bad generated quote']],
                'section_title' => 'Item ITEM-'.$i,
                'completeness' => 'complete', 'render_table' => false, 'requires_selection' => false,
            ]]]);
        });
        $this->app->instance(AiManager::class, $ai);
        $u = new \App\Services\Chat\QuestionUnderstanding('en', 'Four tasks', ['ITEM-0'], [], false, true,
            array_intersect_key($subs[0], array_flip(['topic', 'identifiers', 'aspect', 'fields'])), $subs);
        $answer = app(AgentAnswerSynthesizer::class)->synthesize('Four independent questions',
            new AgentExecutionContext('run', 'default', 'demo', $channel, $actor, $actorId, 'en', 'UTC'),
            new AgentLoopOutcome('partial', $evidence, []), understanding: [...$u->toArray(), 'independent_research' => true]);
        $this->assertStringContainsString('ITEM-0 is active.', $answer->answer);
        $this->assertStringContainsString('ITEM-2 is active.', $answer->answer);
        $this->assertStringNotContainsString('ITEM-1 is active.', $answer->answer);
        $this->assertStringContainsString('This part of the answer could not be completed.', $answer->answer);
        $this->assertSame(4, substr_count($answer->answer, '## '));
        $this->assertStringContainsString("## Item ITEM-0\n\n", $answer->answer);
        $this->assertStringContainsString("## Details — ITEM-1\n\n", $answer->answer);
        $this->assertStringNotContainsString('Tell me about', $answer->answer);
        $this->assertSame('partial', $answer->completeness);
        $this->assertCount(2, $answer->citations);
        Http::assertSentCount(1);
    }
}
