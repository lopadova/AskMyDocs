<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\Waves\AgentWaveKnowledgeSearch;
use App\Models\AgentRun;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Services\Kb\Retrieval\SearchResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class AgentWaveKnowledgeSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_sources_survive_semantic_failure_and_obey_scope_and_boundaries(): void
    {
        app(TenantContext::class)->set('acme');
        $wanted = $this->document('acme', 'orders', 'Messaggio generico', ['Introduzione.', 'Il documento U532638 è completo.']);
        $this->document('acme', 'orders', 'U532638R_1', ['Solo un codice diverso U532638R_1.']);
        $this->document('other', 'orders', 'Alien', ['U532638']);
        $this->document('acme', 'other', 'Another project', ['U532638']);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->withArgs(fn ($q, $p, $f, $aux) => $aux === false)->andThrow(new \RuntimeException('unavailable'));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $run = new AgentRun(['result_json' => ['question_understanding' => ['available' => true,
            'focus' => ['identifiers' => ['U532638']], 'mentions' => []]]]);
        $context = new AgentExecutionContext('run', 'acme', 'orders', 'chat', 'user', '1', 'it', 'UTC');
        $result = app(AgentWaveKnowledgeSearch::class)->search('Trova U532638', $run, $context, new RetrievalFilters(projectKeys: ['orders']));
        $this->assertSame([$wanted->id], array_column($result->body['documents'], 'document_id'));
        $this->assertStringContainsString('U532638', $result->body['documents'][0]['evidence'][0]['content']);
        $this->assertTrue($result->stats['semantic_lookup_error']);
        $this->assertFalse($result->complete);
        $this->assertSame('retrieval_partial', $result->stopReason);
    }

    public function test_conceptual_seeds_scope_targets_and_explicit_ids_do_not_pull_other_subjects(): void
    {
        app(TenantContext::class)->set('acme');
        $a = $this->document('acme', 'orders', 'Risposta', ['Barcode U532638 disponibile.']);
        $b = $this->document('acme', 'orders', 'Antwort', ['Barcode U998877 verfügbar.']);
        $irrelevant = [];
        for ($i = 0; $i < 8; $i++) {
            $doc = $this->document('acme', 'orders', 'Return '.$i, ['Generic return process '.$i]);
            $chunk = $doc->chunks()->first();
            $irrelevant[] = ['chunk_id' => $chunk->id, 'chunk_text' => $chunk->chunk_text, 'document' => $doc->toArray()];
        }
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->twice()->andReturn(new SearchResult(collect($irrelevant), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $run = new AgentRun(['result_json' => ['question_understanding' => [
            'available' => true, 'mentions' => [], 'focus' => ['identifiers' => ['U532638', 'U998877']],
            'subquestions' => [
                ['identifiers' => ['U532638'], 'kb_queries' => ['barcode reso']],
                ['identifiers' => ['U998877'], 'kb_queries' => ['barcode reso']],
            ],
        ]]]);
        $context = new AgentExecutionContext('run', 'acme', 'orders', 'chat', 'user', '1', 'it', 'UTC');
        $filters = new RetrievalFilters(projectKeys: ['orders']);
        $service = app(AgentWaveKnowledgeSearch::class);
        $both = $service->search('barcode reso', $run, $context, $filters);
        $this->assertSame([$a->id, $b->id], array_slice(array_column($both->body['documents'], 'document_id'), 0, 2));
        $this->assertCount(5, $both->body['documents']);
        $onlyA = $service->search('barcode U532638', $run, $context, $filters);
        $this->assertSame($a->id, $onlyA->body['documents'][0]['document_id']);
        $this->assertNotContains($b->id, array_column($onlyA->body['documents'], 'document_id'));
        $this->assertCount(5, $onlyA->body['documents']);
    }

    public function test_semantic_policy_without_record_id_is_kept_after_exact_source(): void
    {
        app(TenantContext::class)->set('acme');
        $exact = $this->document('acme', 'orders', 'Email', ['U532638 barcode ricevuto.']);
        $policy = $this->document('acme', 'orders', 'Policy resi', ['La restituzione è gratuita.']);
        $chunk = $policy->chunks()->first();
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect([
            ['chunk_id' => $chunk->id, 'chunk_text' => $chunk->chunk_text, 'document' => $policy->toArray()],
        ]), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $run = new AgentRun(['result_json' => ['question_understanding' => [
            'available' => true, 'mentions' => [], 'focus' => ['identifiers' => ['U532638']],
        ]]]);
        $context = new AgentExecutionContext('run', 'acme', 'orders', 'chat', 'user', '1', 'it', 'UTC');
        $result = app(AgentWaveKnowledgeSearch::class)->search('barcode reso', $run, $context, new RetrievalFilters(projectKeys: ['orders']));
        $this->assertSame([$exact->id, $policy->id], array_column($result->body['documents'], 'document_id'));
    }

    public function test_followup_skips_already_collected_exact_sources_and_returns_new_policy(): void
    {
        app(TenantContext::class)->set('acme');
        $existing = [];
        for ($i = 0; $i < 5; $i++) {
            $text = "U532638 messaggio $i.";
            $doc = $this->document('acme', 'orders', 'Email '.$i, [$text]);
            $existing[] = ['document_id' => $doc->id, 'evidence' => [['evidence_hash' => hash('sha256', $text)]]];
        }
        $policy = $this->document('acme', 'orders', 'Policy', ['Restituzione gratuita.']);
        $chunk = $policy->chunks()->first();
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect([
            ['chunk_id' => $chunk->id, 'chunk_text' => $chunk->chunk_text, 'document' => $policy->toArray()],
        ]), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $run = new AgentRun(['result_json' => [
            'question_understanding' => ['available' => true, 'mentions' => [], 'focus' => ['identifiers' => ['U532638']]],
            'evidence' => ['documents' => $existing],
        ]]);
        $context = new AgentExecutionContext('run', 'acme', 'orders', 'chat', 'user', '1', 'it', 'UTC');
        $result = app(AgentWaveKnowledgeSearch::class)->search('U532638 policy', $run, $context, new RetrievalFilters(projectKeys: ['orders']));
        $this->assertSame([$policy->id], array_column($result->body['documents'], 'document_id'));
        $this->assertSame(1, $result->stats['selected_sources']);
    }

    public function test_long_document_retains_distinct_relevant_windows_with_their_own_provenance(): void
    {
        app(TenantContext::class)->set('acme');
        config(['kb.investigation.full_source_max_chars' => 10, 'kb.investigation.long_source_neighbor_radius' => 0]);
        $doc = $this->document('acme', 'orders', 'Report', [
            'U532638 primo evento.', 'Testo intermedio.', 'U532638 secondo evento.',
        ]);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect(), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $run = new AgentRun(['result_json' => ['question_understanding' => [
            'available' => true, 'mentions' => [], 'focus' => ['identifiers' => ['U532638']],
        ]]]);
        $context = new AgentExecutionContext('run', 'acme', 'orders', 'chat', 'user', '1', 'it', 'UTC');
        $result = app(AgentWaveKnowledgeSearch::class)->search('barcode reso', $run, $context, new RetrievalFilters(projectKeys: ['orders']));
        $this->assertSame([$doc->id], array_column($result->body['documents'], 'document_id'));
        $evidence = $result->body['documents'][0]['evidence'];
        $this->assertCount(2, $evidence);
        $this->assertNotSame($evidence[0]['evidence_hash'], $evidence[1]['evidence_hash']);
        $this->assertNotSame($evidence[0]['source_chunk_ids'], $evidence[1]['source_chunk_ids']);
        $this->assertFalse($evidence[0]['complete_source']);
    }

    public function test_generic_query_retains_semantic_sources_and_document_filter(): void
    {
        app(TenantContext::class)->set('acme');
        config(['kb.mentions.mode' => 'filter']);
        $wanted = $this->document('acme', 'orders', 'Manuale', ['Procedura di reso.']);
        $other = $this->document('acme', 'orders', 'Other', ['Other procedure.']);
        $candidates = KnowledgeChunk::whereIn('knowledge_document_id', [$wanted->id, $other->id])->with('document')->get()
            ->map(fn ($c) => ['chunk_id' => $c->id, 'chunk_text' => $c->chunk_text, 'document' => $c->document->toArray()]);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult($candidates, collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $run = new AgentRun(['result_json' => ['question_understanding' => ['available' => true, 'mentions' => []]]]);
        $context = new AgentExecutionContext('run', 'acme', 'orders', 'chat', 'user', '1', 'it', 'UTC');
        $result = app(AgentWaveKnowledgeSearch::class)->search('Procedura di reso', $run, $context,
            new RetrievalFilters(projectKeys: ['orders'], docIds: [$wanted->id]));
        $this->assertSame([$wanted->id], array_column($result->body['documents'], 'document_id'));
    }

    public function test_mention_boost_keeps_other_semantic_documents(): void
    {
        app(TenantContext::class)->set('acme');
        config(['kb.mentions.mode' => 'boost']);
        $wanted = $this->document('acme', 'orders', 'Manuale', ['Procedura.']);
        $other = $this->document('acme', 'orders', 'Altro', ['Altra procedura.']);
        $candidates = KnowledgeChunk::whereIn('knowledge_document_id', [$wanted->id, $other->id])->with('document')->get()
            ->map(fn ($c) => ['chunk_id' => $c->id, 'chunk_text' => $c->chunk_text, 'document' => $c->document->toArray()]);
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult($candidates, collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $run = new AgentRun(['result_json' => ['question_understanding' => ['available' => true, 'mentions' => []]]]);
        $context = new AgentExecutionContext('run', 'acme', 'orders', 'chat', 'user', '1', 'it', 'UTC');
        $result = app(AgentWaveKnowledgeSearch::class)->search('procedura', $run, $context,
            new RetrievalFilters(projectKeys: ['orders'], docIds: [$wanted->id]));
        $this->assertEqualsCanonicalizing([$wanted->id, $other->id], array_column($result->body['documents'], 'document_id'));
    }

    public function test_email_original_is_verified_and_restricted_source_is_not_exposed(): void
    {
        app(TenantContext::class)->set('acme');
        \Illuminate\Support\Facades\Storage::fake('kb');
        $original = "Return U532638: please send a label.";
        $email = $this->document('acme', 'orders', 'Email', [$original."\nGenerated triage card."]);
        $email->forceFill(['metadata' => ['connector' => 'imap', 'disk' => 'kb',
            'email_body_hash' => hash('sha256', $original),
            'email_triage' => ['evaluation_id' => 1, 'original_hash' => hash('sha256', $original)]]])->save();
        \Illuminate\Support\Facades\Storage::disk('kb')->put($email->source_path, $original);
        $restricted = $this->document('acme', 'orders', 'Private', ['U532638 private.']);
        $restricted->forceFill(['source_acl_enforced_at' => now()])->save();
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new SearchResult(collect(), collect(), collect()));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $run = new AgentRun(['result_json' => ['question_understanding' => [
            'available' => true, 'mentions' => [], 'focus' => ['identifiers' => ['U532638']],
        ]]]);
        $context = new AgentExecutionContext('run', 'acme', 'orders', 'chat', 'user', '1', 'it', 'UTC');
        $result = app(AgentWaveKnowledgeSearch::class)->search('U532638', $run, $context, new RetrievalFilters(projectKeys: ['orders']));
        $this->assertSame([$email->id], array_column($result->body['documents'], 'document_id'));
        $evidence = $result->body['documents'][0]['evidence'][0];
        $this->assertSame($original, $evidence['content']);
        $this->assertSame(hash('sha256', $original), $evidence['evidence_hash']);
        $this->assertSame('verified_email_original', $evidence['evidence_origin']);
    }

    public function test_semantic_failure_without_exact_hits_is_partial_even_when_empty(): void
    {
        app(TenantContext::class)->set('acme');
        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andThrow(new \RuntimeException('unavailable'));
        $this->app->instance(ChatRetrievalService::class, $retrieval);
        $run = new AgentRun(['result_json' => ['question_understanding' => [
            'available' => true, 'mentions' => [], 'focus' => ['identifiers' => ['U532638']],
        ]]]);
        $context = new AgentExecutionContext('run', 'acme', 'orders', 'chat', 'user', '1', 'it', 'UTC');
        $result = app(AgentWaveKnowledgeSearch::class)->search('barcode reso', $run, $context, new RetrievalFilters(projectKeys: ['orders']));
        $this->assertSame([], $result->body['documents']);
        $this->assertFalse($result->complete);
        $this->assertSame('retrieval_partial', $result->stopReason);
    }

    private function document(string $tenant, string $project, string $title, array $texts): KnowledgeDocument
    {
        $doc = KnowledgeDocument::create(['tenant_id' => $tenant, 'project_key' => $project, 'source_type' => 'markdown',
            'title' => $title, 'source_path' => uniqid().'.md', 'status' => 'active', 'document_hash' => hash('sha256', implode('', $texts))]);
        foreach ($texts as $order => $text) KnowledgeChunk::create(['tenant_id' => $tenant, 'project_key' => $project,
            'knowledge_document_id' => $doc->id, 'chunk_order' => $order, 'chunk_text' => $text, 'chunk_hash' => hash('sha256', $text)]);
        return $doc;
    }
}
