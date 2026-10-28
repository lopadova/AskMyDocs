<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoop;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\KbRetrievalProfile;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class ExactKnowledgeWaveIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_wave_delivers_exact_documents_to_common_planner_when_semantic_top_eight_miss_the_order(): void
    {
        config(['agent.waves.enabled' => true, 'agent.planner.mode' => 'classic',
            'kb.investigation.enabled' => true, 'reasoning.enabled' => true]);
        app(TenantContext::class)->set('acme');
        $user = User::create(['name' => 'Research', 'email' => 'exact-wave@example.test', 'password' => 'password']);
        ProjectMembership::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'user_id' => $user->id, 'role' => 'member']);
        $this->actingAs($user);
        KbRetrievalProfile::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'company_context' => 'Shipping and returns']);
        $wanted = [];
        foreach (['Riepilogo DHL', 'Etichetta reso', 'Email reso', 'Email barcode'] as $title) {
            $wanted[] = $this->document($title, 'Documento relativo al codice U532638.')->id;
        }
        $noise = [];
        for ($i = 0; $i < 8; $i++) {
            $noise[] = $this->document('Bordero '.$i, 'Documento di spedizione relativo a un altro ordine.')->id;
        }
        $semantic = KnowledgeChunk::whereIn('knowledge_document_id', $noise)->with('document')->orderBy('id')->get()
            ->map(fn ($chunk) => ['chunk_id' => $chunk->id, 'chunk_text' => $chunk->chunk_text,
                'chunk_hash' => $chunk->chunk_hash, 'document' => $chunk->document->toArray()]);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->twice()->withArgs(fn ($query, $project, $filters, $auxiliary) => $auxiliary === false)
            ->andReturn(new SearchResult($semantic, collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);

        $conversation = Conversation::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'user_id' => $user->id]);
        $question = 'Dammi tutto quello che riesci a trovare su U532638';
        $run = AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'acme', 'project_key' => 'orders',
            'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => (string) $user->id, 'user_id' => $user->id,
            'conversation_id' => $conversation->id, 'locale' => 'it', 'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_RUNNING, 'input_json' => ['question' => $question],
            'budget_json' => [], 'counters_json' => [], 'started_at' => now(),
            'result_json' => ['question_understanding' => ['available' => true, 'language' => 'it',
                'intent' => $question, 'action' => 'research', 'requires_research' => true, 'context_mode' => 'new',
                'focus' => ['topic' => 'ordine', 'identifiers' => ['U532638'], 'aspect' => 'all', 'fields' => []],
                'kb_queries' => ['Documenti U532638', 'Comunicazioni U532638'],
                'mentions' => [['text' => 'U532638', 'type' => 'identifier']]]]]);
        $plans = 0;
        $ai = Mockery::mock(AiManager::class);
        $expected = [...$wanted, $noise[0]];
        $ai->shouldReceive('chatWithHistory')->twice()->andReturnUsing(function ($system, $messages) use (&$plans, $expected) {
            if (++$plans === 1) {
                $payload = ['decision' => 'tools', 'actions' => [
                    ['id' => 'documents', 'tool' => 'search_knowledge_base', 'arguments' => ['query' => 'Documenti U532638'],
                        'depends_on' => [], 'purpose' => 'Trova documenti ordine'],
                    ['id' => 'communications', 'tool' => 'search_knowledge_base', 'arguments' => ['query' => 'Comunicazioni U532638'],
                        'depends_on' => [], 'purpose' => 'Trova comunicazioni ordine'],
                ]];
            } else {
                $input = json_decode($messages[0]['content'], true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($expected, array_column($input['evidence_summary']['documents'], 'document_id'));
                $this->assertCount(2, $input['completed_actions']);
                $payload = ['decision' => 'answer', 'actions' => []];
            }

            return new AiResponse('', 'fake', 'planner', toolCalls: [['name' => 'submit_agent_plan', 'arguments' => $payload]]);
        });
        $this->app->instance(AiManager::class, $ai);

        $outcome = app(AgentLoop::class)->run($run, AgentExecutionContext::fromArray($run->toArray()));

        $this->assertSame('answer', $outcome->decision, (string) $outcome->stopReason);
        $this->assertSame($expected, array_column($outcome->evidence->documents(), 'document_id'));
        foreach ($outcome->evidence->documents() as $document) {
            $this->assertCount(1, $document['evidence'], 'The common join must deduplicate the two searches.');
            $source = $document['evidence'][0];
            if (in_array($document['document_id'], $wanted, true)) {
                $this->assertStringContainsString('U532638', $source['content']);
                $this->assertContains(hash('sha256', 'U532638'), $source['verified_identifier_hashes']);
            } else {
                $this->assertSame([], $source['verified_identifier_hashes'], 'Semantic candidates are not verified facts about this order.');
            }
            $this->assertTrue($source['complete_source']);
        }
        $this->assertSame(0, $run->fresh()->counters_json['physical_calls']);
        $this->assertCount(1, data_get($run->fresh()->result_json, 'waves'));
    }

    private function document(string $title, string $text): KnowledgeDocument
    {
        $document = KnowledgeDocument::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'source_type' => 'markdown',
            'title' => $title, 'source_path' => (string) Str::uuid().'.md', 'status' => 'active',
            'document_hash' => hash('sha256', $text)]);
        KnowledgeChunk::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'knowledge_document_id' => $document->id,
            'chunk_order' => 0, 'chunk_text' => $text, 'chunk_hash' => hash('sha256', $text)]);

        return $document;
    }
}
