<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentChatTurnStarter;
use App\Agent\AgentExecutionContext;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Message;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class AgentQualifiedToolNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_heiner_question_reaches_a_verified_saved_answer_after_a_qualified_tool_call(): void
    {
        config(['reasoning.enabled' => true, 'reasoning.selective_validation' => true,
            'kb.investigation.enabled' => false, 'ai.providers.openrouter.key' => 'test']);
        $context = new AgentExecutionContext((string) Str::uuid(), 'acme', 'crm', 'chat', 'user', '1', 'it', 'UTC');
        $context->activate();
        $user = User::create(['id' => 1, 'name' => 'Agent tester', 'email' => 'agent-tools@example.test', 'password' => bcrypt('test')]);
        ProjectMembership::create(['tenant_id' => 'acme', 'project_key' => 'crm', 'user_id' => $user->id, 'role' => 'member']);
        $conversation = Conversation::create(['tenant_id' => 'acme', 'project_key' => 'crm', 'user_id' => $user->id]);
        // Synthetic source reproducing the customer's request and the merchant's confirmation.
        $source = "EASTPAK FLEX ULTRA MARINE ist leider nicht verfügbar.\n\n"
            ."Ich bitte um Rückerstattung. Heiner Olbrich\n\n"
            .'Wir haben Ihre Bestellung storniert. Sie erhalten die Rückerstattung innerhalb von 2-3 Werktagen.';
        $hash = hash('sha256', $source);
        $document = KnowledgeDocument::create(['tenant_id' => 'acme', 'project_key' => 'crm', 'source_type' => 'markdown',
            'title' => 'Artikelverfügbarkeit', 'source_path' => 'tickets/heiner.md', 'status' => 'active', 'document_hash' => $hash]);
        $chunk = KnowledgeChunk::create(['tenant_id' => 'acme', 'project_key' => 'crm', 'knowledge_document_id' => $document->id,
            'chunk_order' => 0, 'chunk_text' => $source, 'chunk_hash' => $hash]);
        $search = new SearchResult(collect([['chunk_id' => $chunk->id, 'chunk_text' => $source, 'chunk_hash' => $hash,
            'project_key' => 'crm', 'document' => $document->toArray()]]), collect(), collect());
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->twice()->andReturn($search);
        $this->app->instance(ChatRetrievalService::class, $retrieval);

        $question = 'Dimmi tutto quello che sai e cosa è successo a Heiner Olbrich';
        $focus = ['topic' => 'customer', 'identifiers' => ['Heiner Olbrich'], 'aspect' => 'details', 'fields' => ['*']];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn(new AiResponse(json_encode([
            'language' => 'it', 'intent' => $question, 'action' => 'research', 'kb_queries' => ['Heiner Olbrich'],
            'mentions' => [['text' => 'Heiner Olbrich', 'type' => 'name']], 'references_previous_turn' => false, 'focus' => $focus,
            'subquestions' => [$focus + ['question' => $question, 'kb_queries' => ['Heiner Olbrich'],
                'context_mode' => 'new', 'requires_research' => true]],
            'context_mode' => 'new', 'requires_research' => true, 'resolved_references' => [], 'transition' => 'new',
            'needs_clarification' => false, 'clarification' => '',
        ], JSON_THROW_ON_ERROR), 'fake', 'interpreter'));
        $answer = 'Heiner Olbrich ha chiesto il rimborso per EASTPAK FLEX ULTRA MARINE, non disponibile. '
            .'Il negozio ha annullato l’ordine e annunciato il rimborso entro 2–3 giorni lavorativi.';
        $ai->shouldReceive('chatWithHistory')->times(3)->andReturn(
            new AiResponse('', 'fake', 'planner', toolCalls: [['name' => 'submit_agent_plan', 'arguments' => [
                'decision' => 'tools', 'actions' => [[
                    'id' => 'refund', 'tool' => 'functions.search_knowledge_base',
                    'arguments' => ['query' => 'Heiner Olbrich conferma rimborso'], 'depends_on' => [],
                    'purpose' => 'Verifico la conferma del rimborso',
                ]],
            ]]]),
            new AiResponse('', 'fake', 'planner', toolCalls: [['name' => 'submit_agent_plan', 'arguments' => [
                'decision' => 'answer', 'actions' => [],
            ]]]),
            new AiResponse('', 'fake', 'synthesizer', toolCalls: [['name' => 'submit_agent_answer', 'arguments' => [
                'completeness' => 'complete', 'claims' => [[
                    'text' => $answer, 'document_id' => $document->id, 'evidence_hash' => $hash, 'quote' => $source,
                ]], 'limitations' => [], 'requires_selection' => false, 'render_table' => false,
            ]]]),
        );
        $this->app->instance(AiManager::class, $ai);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($question, $source) {
            if (isset($request['state']['original_question'])) {
                $this->assertSame($question, $request['state']['original_question']);

                return Http::response(['id' => 'relevance', 'model' => 'jev-test', 'answers' => [
                    'relevance_0' => ['type' => 'noul', 'noul' => .99],
                ]]);
            }
            $this->assertContains($source, $request['state']['sources']);

            return Http::response(['id' => 'claims', 'model' => 'jev-test', 'answers' => [
                'claim_0' => ['type' => 'choice', 'choice' => 'supported_relevant', 'confidence' => .99,
                    'probabilities' => ['supported_relevant' => .99, 'supported_off_topic' => .0025,
                        'unsupported' => .0025, 'conflicting' => .0025, 'insufficient_context' => .0025]],
            ]]);
        });

        $turn = app(AgentChatTurnStarter::class)->start($context, $conversation, $user, $question,
            ['question' => $question, 'depth' => 5, 'live_sources' => ['api' => [], 'mcp' => []]], inline: true);
        $run = $turn->run;
        $this->assertSame(AgentRun::STATUS_COMPLETED, $run->status, json_encode([
            'error' => $run->error_code, 'stop_reason' => data_get($run->result_json, 'stop_reason'),
            'response' => data_get($run->result_json, 'response'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->assertNull($run->error_code);
        $this->assertSame('final', data_get($run->result_json, 'phase'));
        $this->assertSame('complete', data_get($run->result_json, 'response.completeness'));
        $this->assertSame($answer, data_get($run->result_json, 'response.answer'));
        $this->assertSame(['search_knowledge_base'], $run->toolExecutions()->pluck('tool_name')->all());
        $this->assertSame(['completed'], $run->toolExecutions()->pluck('status')->all());
        $message = Message::query()->where('agent_run_id', $run->id)->sole();
        $this->assertSame($answer, $message->content);
        $this->assertSame($document->id, data_get($message->metadata, 'citations.0.document_id'));
        $this->assertSame(['focused_claims', 'original_question_relevance'],
            array_column(data_get($message->metadata, 'grounding.semantic_validation'), 'stage'));
        $this->assertSame('accepted', data_get($message->metadata, 'grounding.semantic_validation.1.checks.0.status'));
        $this->assertContains('synthesis.started', $run->events()->pluck('type')->all());
        $this->assertSame('run.completed', $run->events()->latest('sequence')->first()->type);
        Http::assertSentCount(2);
    }
}
