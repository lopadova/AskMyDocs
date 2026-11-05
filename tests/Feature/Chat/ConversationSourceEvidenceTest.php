<?php

namespace Tests\Feature\Chat;

use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Chat\QuestionUnderstanding;
use App\Services\Chat\Reasoning\ConversationSourceEvidence;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ConversationSourceEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $chat;
    private AgentRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        config(['reasoning.enabled' => true]);
        Http::preventStrayRequests();
        $user = User::create(['name' => 'Tester', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test')]);
        \App\Models\ProjectMembership::create(['user_id' => $user->id, 'project_key' => 'demo', 'role' => 'member', 'scope_allowlist' => []]);
        $this->chat = Conversation::create(['user_id' => $user->id, 'project_key' => 'demo']);
        $this->run = AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'default', 'project_key' => 'demo',
            'user_id' => $user->id, 'conversation_id' => $this->chat->id, 'channel' => 'chat', 'actor_type' => 'user',
            'actor_id' => (string) $user->id, 'locale' => 'en', 'timezone' => 'UTC', 'status' => 'running']);
    }

    private function source(string $title, string $text, string $project = 'demo'): array
    {
        $document = KnowledgeDocument::create(['project_key' => $project, 'source_type' => 'manual', 'source_path' => Str::uuid().'.txt',
            'title' => $title, 'content_hash' => hash('sha256', $text), 'status' => 'ready']);
        $chunk = KnowledgeChunk::create(['project_key' => $project, 'knowledge_document_id' => $document->id,
            'chunk_order' => 0, 'chunk_text' => $text, 'chunk_hash' => hash('sha256', $text)]);
        $state = $this->chat->fresh()->reasoning_state ?? [];
        $state['evidence']['doc:'.$document->id.':'.$chunk->id] = ['kind' => 'document', 'document_id' => $document->id,
            'chunk_id' => $chunk->id, 'hash' => hash('sha256', 'old excerpt'), 'identifiers' => [], 'status' => 'found'];
        $this->chat->forceFill(['reasoning_state' => $state])->save();
        $this->run->unsetRelation('conversation');
        return [$document, $chunk];
    }

    private function understanding(array $ids = ['CASE-A'], bool $context = true): QuestionUnderstanding
    {
        return QuestionUnderstanding::fromArray(['available' => true, 'language' => 'en', 'intent' => 'Did the later message confirm completion?',
            'references_previous_turn' => $context, 'action' => 'refine', 'context_mode' => $context ? 'conversation' : 'new',
            'requires_research' => true, 'focus' => ['identifiers' => $ids],
            'subquestions' => [['topic' => 'case', 'identifiers' => $ids, 'aspect' => 'confirmation', 'fields' => ['*'],
                'question' => 'Did the later message confirm completion?', 'context_mode' => $context ? 'conversation' : 'new', 'requires_research' => true]]]);
    }

    private function read(?QuestionUnderstanding $understanding = null, ?RetrievalFilters $filters = null, int $bytes = 524288): array
    {
        return app(ConversationSourceEvidence::class)->forRun($this->run, $understanding ?? $this->understanding(),
            $filters ?? new RetrievalFilters(projectKeys: ['demo']), $bytes);
    }

    public function test_a_previously_found_but_unpublished_confirmation_is_reread_from_its_source(): void
    {
        [$old] = $this->source('CASE-A request', 'The warehouse is waiting for the mission change.');
        [$later] = $this->source('CASE-A confirmation', 'The mission has been changed.');
        $result = $this->read();
        $this->assertSame([$old->id, $later->id], array_column($result['documents'], 'document_id'));
        $this->assertSame('The mission has been changed.', $result['documents'][1]['evidence'][0]['content']);
        $this->assertSame(hash('sha256', 'The mission has been changed.'), $result['documents'][1]['evidence'][0]['evidence_hash']);
        $this->assertSame([0], $result['documents'][1]['evidence'][0]['research_flow_ids']);
        $this->assertSame([], $result['api_tools']);
        $this->assertSame(0, \App\Models\SessionMemoryFact::count(), 'A reread does not manufacture approved facts.');
        Http::assertNothingSent();
    }

    public function test_new_topics_unresolved_references_and_other_cases_are_not_imported(): void
    {
        $this->source('CASE-A', 'Current case');
        $this->source('CASE-B', 'Another case');
        $this->assertSame([], $this->read($this->understanding(context: false))['documents']);
        $this->assertSame([], $this->read($this->understanding([]))['documents']);
        $this->assertCount(1, $this->read()['documents']);
    }

    public function test_current_permissions_archival_project_and_filters_are_rechecked(): void
    {
        [$allowed] = $this->source('CASE-A allowed', 'Visible');
        [$archived] = $this->source('CASE-A archived', 'No longer visible');
        $archived->update(['status' => 'archived']);
        $this->source('CASE-A other project', 'Private', 'elsewhere');
        $result = $this->read(filters: new RetrievalFilters(projectKeys: ['demo'], docIds: [$allowed->id]));
        $this->assertSame([$allowed->id], array_column($result['documents'], 'document_id'));
        $this->assertSame([], $this->read(filters: new RetrievalFilters(projectKeys: ['__no_access__']))['documents']);
    }

    public function test_changed_text_is_a_fresh_read_with_its_own_hash_not_a_rebound_old_claim(): void
    {
        [$document, $chunk] = $this->source('CASE-A', 'Previous statement.');
        $chunk->update(['chunk_text' => 'Updated statement.', 'chunk_hash' => hash('sha256', 'Updated statement.')]);
        $result = $this->read();
        $this->assertSame('Updated statement.', $result['documents'][0]['evidence'][0]['content']);
        $this->assertSame(hash('sha256', 'Updated statement.'), $result['documents'][0]['evidence'][0]['evidence_hash']);
        $this->assertTrue($result['documents'][0]['conversation_reference_reread']);
    }

    public function test_bounded_reads_skip_whole_passages_and_expose_the_limit(): void
    {
        [$document] = $this->source('CASE-A', 'A complete source must not be truncated.');
        $result = $this->read(bytes: 1);
        $this->assertSame([], $result['documents']);
        $this->assertSame([['code' => 'conversation_source_evidence_limit', 'source' => 'document:'.$document->id]], $result['warnings']);
    }

    public function test_another_tenant_cannot_use_the_conversation_navigation(): void
    {
        $this->source('CASE-A', 'Private source.');
        app(TenantContext::class)->set('other');
        $this->expectException(\DomainException::class);
        $this->read();
    }

    public function test_revoked_project_membership_cannot_supply_a_reread(): void
    {
        $this->source('CASE-A', 'Private source.');
        \App\Models\ProjectMembership::query()->where('user_id', $this->run->user_id)->delete();
        $this->assertSame([], $this->read()['documents']);
    }

    public function test_each_reread_receives_only_its_current_task_ownership(): void
    {
        [$one] = $this->source('CASE-A', 'First case.');
        [$two] = $this->source('CASE-B', 'Second case.');
        $u = $this->understanding()->toArray();
        $u['subquestions'][] = [...$u['subquestions'][0], 'identifiers' => ['CASE-B']];
        $result = $this->read(QuestionUnderstanding::fromArray($u));
        $this->assertSame([0], $result['documents'][0]['evidence'][0]['research_flow_ids']);
        $this->assertSame([1], $result['documents'][1]['evidence'][0]['research_flow_ids']);
        $this->assertSame([$one->id], array_column(\App\Agent\Evidence\ResearchEvidence::forFlow($result, 0)['documents'], 'document_id'));
        $this->assertSame([$two->id], array_column(\App\Agent\Evidence\ResearchEvidence::forFlow($result, 1)['documents'], 'document_id'));
    }

    public function test_a_planner_failure_keeps_both_freshly_read_messages_for_grounding(): void
    {
        [$request] = $this->source('CASE-A', 'The warehouse is waiting for the mission change.');
        [$confirmation] = $this->source('CASE-A', 'The mission has been changed.');
        $this->run->forceFill(['input_json' => ['question' => 'Was the mission change subsequently confirmed?'],
            'result_json' => ['question_understanding' => $this->understanding()->toArray()]])->save();
        config(['agent.waves.enabled' => true]);
        $ai = \Mockery::mock(\App\Ai\AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andThrow(new \UnexpectedValueException('Invalid plan fixture.'));
        $this->app->instance(\App\Ai\AiManager::class, $ai);
        $outcome = app(\App\Agent\AgentLoop::class)->run($this->run, \App\Agent\AgentExecutionContext::fromArray($this->run->toArray()));
        $this->assertSame('planner_recovery', $outcome->stopReason);
        $this->assertSame([$request->id, $confirmation->id], array_column($outcome->evidence->documents(), 'document_id'));
        $this->assertSame(2, data_get($this->run->fresh()->result_json, 'context_document_reread.documents'));
        $this->assertSame(0, $this->run->toolExecutions()->count());
        Http::assertNothingSent();
    }
}
