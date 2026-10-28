<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoop;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\AgentRun;
use App\Models\KbRetrievalProfile;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class AgentFollowUpResearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kb.investigation.enabled' => true, 'agent.limits.consecutive_unproductive_searches' => 1]);
        app(TenantContext::class)->set('acme');
        KbRetrievalProfile::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'company_context' => 'Ordini e resi']);
        Http::preventStrayRequests();
    }

    public function test_unrelated_new_documents_do_not_reset_progress_or_enter_evidence(): void
    {
        [$document, $candidate] = $this->source('Reply', 'Michael Friedenberger: il barcode è presente.');
        [, $unrelated] = $this->source('Altro reso RU99999', 'Un altro cliente richiede un rimborso.');
        $run = $this->runWithEvidence($candidate);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('chatWithProvider');
        $ai->shouldReceive('chat')->once()->andReturnUsing(function ($system, $payload) use ($document) {
            $input = json_decode($payload, true);
            $this->assertSame('Informazioni su Michael Friedenberger', $input['intent']['objective']);
            return new AiResponse(json_encode(['selected_document_ids' => [$document->id],
                'supported_facts' => ['Il barcode è presente.'], 'complete' => true]), 'fake', 'assessor');
        });
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(
            $this->plan('Michael Friedenberger RU58511 altri messaggi'),
            $this->plan('Michael Friedenberger cronologia completa'),
        );
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->withArgs(fn ($query, $project, $filters, $auxiliary) => $auxiliary === false)
            ->andReturn(new SearchResult(collect([$unrelated]), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);

        $outcome = app(AgentLoop::class)->run($run, AgentExecutionContext::fromArray($run->toArray()));

        $this->assertSame('unproductive_search_limit', $outcome->stopReason);
        $this->assertSame([$document->id], array_column($outcome->evidence->documents(), 'document_id'));
        $this->assertSame(1, $run->fresh()->counters_json['consecutive_unproductive_searches']);
        $execution = $run->toolExecutions()->sole();
        $this->assertSame([$document->id], array_column($execution->result_meta_json['result']['documents'], 'document_id'));
        $this->assertSame([$document->id], data_get($execution->result_meta_json, 'stats.investigation.attempts.0.selected_document_ids'));
        Http::assertNothingSent();
    }

    public function test_new_read_content_from_an_existing_document_counts_as_progress(): void
    {
        [$document, $candidate] = $this->source('Reply', 'Michael Friedenberger: il barcode manca.');
        KnowledgeChunk::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'knowledge_document_id' => $document->id,
            'chunk_order' => 1, 'chunk_text' => 'Aggiornamento: il barcode è presente.',
            'chunk_hash' => hash('sha256', 'Aggiornamento: il barcode è presente.')]);
        $run = $this->runWithEvidence($candidate);
        $run->forceFill(['counters_json' => ['consecutive_unproductive_searches' => 0]])->save();
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->once()->andReturn(new AiResponse(json_encode([
            'selected_document_ids' => [$document->id], 'supported_facts' => ['Il barcode è presente.'], 'complete' => true,
        ]), 'fake', 'assessor'));
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn($this->plan('Michael Friedenberger aggiornamenti'),
            new AiResponse('', 'fake', 'planner', toolCalls: [['name' => 'submit_agent_plan',
                'arguments' => ['decision' => 'answer', 'actions' => []]]]));
        $this->app->instance(AiManager::class, $ai);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect([$candidate]), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);

        $outcome = app(AgentLoop::class)->run($run, AgentExecutionContext::fromArray($run->toArray()));

        $this->assertSame('answer', $outcome->decision);
        $this->assertCount(1, $outcome->evidence->documents());
        $this->assertStringContainsString('Aggiornamento: il barcode è presente.', $outcome->evidence->documents()[0]['evidence'][0]['content']);
        $this->assertSame(0, $run->fresh()->counters_json['consecutive_unproductive_searches']);
    }

    private function plan(string $query): AiResponse
    {
        return new AiResponse('', 'fake', 'planner', toolCalls: [['name' => 'submit_agent_plan', 'arguments' => [
            'decision' => 'tools', 'actions' => [['id' => 'followup', 'tool' => 'search_knowledge_base',
                'arguments' => ['query' => $query], 'depends_on' => [], 'purpose' => 'Verificare altri messaggi sul caso']],
        ]]]);
    }

    private function source(string $title, string $text): array
    {
        $hash = hash('sha256', $text);
        $document = KnowledgeDocument::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'source_type' => 'markdown',
            'title' => $title, 'source_path' => 'mail/'.Str::uuid(), 'status' => 'active', 'document_hash' => $hash]);
        $chunk = KnowledgeChunk::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'knowledge_document_id' => $document->id,
            'chunk_order' => 0, 'chunk_text' => $text, 'chunk_hash' => $hash]);
        return [$document, ['chunk_id' => $chunk->id, 'chunk_text' => $text, 'chunk_hash' => $hash,
            'project_key' => 'orders', 'document' => $document->toArray()]];
    }

    private function runWithEvidence(array $candidate): AgentRun
    {
        $focus = ['topic' => 'person', 'identifiers' => ['Michael Friedenberger'], 'aspect' => 'details', 'fields' => ['*']];
        return AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'acme', 'project_key' => 'orders',
            'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => '1', 'locale' => 'it', 'timezone' => 'UTC',
            'status' => AgentRun::STATUS_RUNNING, 'started_at' => now(),
            'input_json' => ['question' => 'Informazioni su Michael Friedenberger'],
            'result_json' => ['retrieval_completed' => true,
                'question_understanding' => ['available' => true, 'intent' => 'Informazioni su Michael Friedenberger',
                    'action' => 'research', 'focus' => $focus, 'kb_queries' => ['Michael Friedenberger'], 'requires_research' => true],
                'evidence' => ['documents' => [['document_id' => $candidate['document']['id'], 'title' => $candidate['document']['title'],
                    'origin' => 'primary', 'evidence' => [['chunk_id' => $candidate['chunk_id'],
                        'content' => $candidate['chunk_text'], 'evidence_hash' => $candidate['chunk_hash']]]]]]]]);
    }
}
