<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Contracts\AgentRunHandler;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SessionFreshnessReplayTest extends TestCase
{
    use RefreshDatabase;

    public static function questions(): array
    {
        return [
            'chat 290' => ['A cosa serve WearFrame?', false],
            'selected order' => ['qual è lo stato dell’ordine oggi?', true],
            'freshness check unavailable' => ['qual è lo stato dell’ordine oggi?', true, true],
        ];
    }

    #[DataProvider('questions')]
    public function test_original_message_selects_evidence_before_answering(string $question, bool $live, bool $outage = false): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config(['agent.concurrent_session' => false, 'reasoning.enabled' => true, 'reasoning.selective_validation' => true,
            'kb.investigation.enabled' => false, 'ai.providers.openrouter.key' => 'fixture', 'connector-api.ssrf.resolve_dns' => false]);
        app(TenantContext::class)->set('acme');
        $user = User::create(['name' => 'QA', 'email' => 'freshness@example.test', 'password' => bcrypt('fixture')]);
        $this->actingAs($user);
        $project = 'wwmdai';
        ProjectMembership::create(['tenant_id' => 'acme', 'project_key' => $project, 'user_id' => $user->id, 'role' => 'member']);
        $chat = Conversation::create(['tenant_id' => 'acme', 'project_key' => $project, 'user_id' => $user->id]);
        $turn = $chat->messages()->create(['role' => 'user', 'content' => $question]);
        $run = AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'acme', 'project_key' => $project,
            'user_id' => $user->id, 'conversation_id' => $chat->id, 'actor_type' => 'user', 'actor_id' => (string) $user->id,
            'channel' => 'chat', 'locale' => 'it', 'timezone' => 'UTC', 'status' => 'queued',
            'input_json' => ['question' => $question, 'user_message_id' => $turn->id, 'session_evidence' => true,
                ...$live ? ['selection' => ['record' => ['id' => 'A17']]] : ['live_sources' => ['api' => [], 'mcp' => []]]]]);
        $connector = ApiConnector::create(['tenant_id' => 'acme', 'project_key' => $project, 'name' => 'ERP', 'is_active' => true]);
        $url = 'https://erp.example.test/orders/A17';
        ApiRoute::create(['tenant_id' => 'acme', 'api_connector_id' => $connector->id, 'project_key' => $project,
            'name' => 'get_order', 'slug' => 'get_order', 'http_method' => 'GET', 'url' => $url,
            'mode' => 'tool', 'status' => 'active', 'endpoint_type' => 'detail',
            'tool_definition' => ['name' => 'get_order', 'description' => 'Read order A17', 'input_schema' => ['type' => 'object', 'properties' => []]]]);
        $facts = ['Manuale WearFrame' => 'Per chi prepara immagini per cataloghi, e-commerce e contenuti editoriali.',
            'Guida commerciale' => 'WearFrame parte da fotografie e riferimenti del prodotto per generare proposte still life e immagini di outfit.'];
        $hits = [];
        foreach ($facts as $title => $body) {
            $hash = hash('sha256', $body);
            $document = KnowledgeDocument::create(['tenant_id' => 'acme', 'project_key' => $project, 'source_type' => 'markdown',
                'title' => $title, 'source_path' => $title.'.md', 'document_hash' => $hash, 'version_hash' => $hash, 'status' => 'active']);
            $chunk = $document->chunks()->create(['tenant_id' => 'acme', 'project_key' => $project, 'chunk_order' => 0, 'chunk_text' => $body, 'chunk_hash' => $hash]);
            $hits[] = ['chunk_id' => $chunk->id, 'chunk_text' => $body, 'document' => $document->toArray()];
        }
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->times($live ? 0 : 1)->andReturn(new SearchResult(collect($hits), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        Http::fake(function ($request) use ($url, $question, $live, $outage) {
            if ($request->url() === $url) return Http::response(['id' => 'A17', 'status' => 'spedito']);
            $answers = [];
            foreach ($request['questions'] as $key => $criterion) {
                if ($key === 'documented_information') {
                    $this->assertSame($question, $request['state']['message']);
                    if ($outage) return Http::response([], 503);
                }
                if ($criterion['type'] === 'noul') {
                    $answers[$key] = ['type' => 'noul', 'noul' => $key === 'documented_information' && $live ? .01 : .99];
                } else {
                    $probabilities = array_fill_keys(array_keys($criterion['criteria']), 0);
                    $first = array_key_first($probabilities);
                    $probabilities[$first] = 1;
                    $answers[$key] = ['type' => 'choice', 'choice' => $first, 'confidence' => 1, 'probabilities' => $probabilities];
                }
            }
            return Http::response(['model' => 'fixture', 'answers' => $answers]);
        });
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturnUsing(function ($provider, $system, $messages, $options) use ($question, $live) {
            $this->assertSame($question, json_decode($messages[0]['content'], true)['question']);
            $focus = ['topic' => $live ? 'ordine' : 'WearFrame', 'identifiers' => [], 'aspect' => $live ? 'stato' : 'utilizzo', 'fields' => ['*']];
            // Reproduce the erroneous first proposal from 290 even after improving the instructions.
            $deliverable = ['kind' => $live ? 'answer' : 'summary', 'quantity' => null, 'freshness' => 'current'];
            $task = $live ? $question : "A cosa serve WearFrame e quali sono alcuni esempi di casi d'uso?";
            $queries = $live ? [] : ['WearFrame utilizzo'];
            return new AiResponse(json_encode(['language' => 'it', 'intent' => $task, 'action' => 'research', 'kb_queries' => $queries,
                'mentions' => [], 'references_previous_turn' => false, 'context_mode' => 'new', 'requires_research' => true,
                'transition' => 'new', 'focus' => $focus, 'subquestions' => [[...$focus, 'question' => $task, 'context_mode' => 'new',
                    'requires_research' => true, 'kb_queries' => $queries, 'deliverable' => $deliverable]], 'deliverable' => $deliverable,
                'resolved_references' => [], 'needs_clarification' => false, 'clarification' => '', ...isset($options['response_format']['json_schema']['schema']['properties']['memory_decisions']) ? ['memory_decisions' => []] : []]), 'fake', 'fixture');
        });
        $ai->shouldReceive('chatWithHistory')->andReturnUsing(function ($system, $messages, $options) use ($live) {
            $state = json_decode($messages[0]['content'], true);
            $tool = $options['tool_choice']['function']['name'];
            if ($tool === 'submit_agent_plan') {
                $actions = $state['completed_actions'] ? [] : [['id' => 'lookup', 'tool' => $live ? 'get_order' : 'search_knowledge_base',
                    'arguments' => $live ? [] : ['query' => 'WearFrame utilizzo'], 'depends_on' => [], 'purpose' => 'Leggi la fonte pertinente']];
                $payload = ['decision' => $actions ? 'tools' : 'answer', 'actions' => $actions];
            } else {
                $claims = [];
                foreach ($state['evidence']['documents'] as $doc) {
                    $p = $doc['evidence'][0];
                    $claims[] = ['document_id' => $doc['document_id'], 'evidence_hash' => $p['evidence_hash'], 'quote' => $p['content'], 'text' => $p['content'], 'subquestion_id' => 0];
                }
                foreach ($state['evidence']['api_tools'] as $observation) {
                    $claims[] = ['tool_execution_id' => $observation['execution_id'], 'evidence_hash' => $observation['evidence_hash'],
                        'text' => 'L’ordine A17 è spedito.', 'quote' => 'spedito', 'subquestion_id' => 0];
                }
                $payload = ['completeness' => 'complete', 'claims' => $claims, 'limitations' => [], 'requires_selection' => false, 'render_table' => false];
            }
            return new AiResponse('', 'fake', 'fixture', toolCalls: [['name' => $tool, 'arguments' => $payload]]);
        });
        $this->app->instance(AiManager::class, $ai);
        app(AgentRunHandler::class)->handle($run);
        $state = $run->fresh()->result_json;
        $this->assertSame($live ? 'current' : 'source_context', data_get($state, 'question_understanding.deliverable.freshness'));
        $this->assertSame($question, data_get($state, 'question_understanding.subquestions.0.question'));
        $this->assertSame('complete', data_get($state, 'response.completeness'));
        $answer = $chat->messages()->where('role', 'assistant')->sole()->content;
        if ($live) {
            Http::assertSent(fn ($request) => $request->url() === $url);
            $this->assertNotEmpty(data_get($state, 'response.tool_sources'));
            $this->assertStringContainsString('spedito', $answer);
        } else {
            Http::assertNotSent(fn ($request) => $request->url() === $url);
            $this->assertEqualsCanonicalizing(array_column(array_column($hits, 'document'), 'id'), array_column($state['response']['citations'], 'document_id'));
            foreach ($facts as $fact) $this->assertStringContainsString($fact, $answer);
        }
    }
}
