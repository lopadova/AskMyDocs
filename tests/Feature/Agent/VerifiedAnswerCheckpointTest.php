<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentAnswer;
use App\Agent\VerifiedAnswerCheckpoint;
use App\Jobs\ExecuteAgentRunJob;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Message;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VerifiedAnswerCheckpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('acme');
        app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class)->set('acme');
        Http::preventStrayRequests();
    }

    public function test_timeout_recovers_once_and_projects_the_verified_answer(): void
    {
        [$run, $sources] = $this->fixture();
        $this->save($run, $sources);
        $this->assertSame(AgentRun::STATUS_RUNNING, $run->fresh()->status);
        $this->assertSame(0, Message::query()->count());

        $job = new ExecuteAgentRunJob($run->id, 'acme');
        $job->failed(new TimeoutExceededException('timeout'));
        $job->failed(new TimeoutExceededException('timeout'));

        $run->refresh();
        $this->assertSame(AgentRun::STATUS_PARTIAL, $run->status);
        $this->assertSame('worker_timeout', data_get($run->result_json, 'response.grounding.recovery.reason'));
        $message = Message::query()->where('agent_run_id', $run->id)->sole();
        $this->assertStringContainsString($sources[0]['text'], $message->content);
        $this->assertSame('partial', data_get($message->metadata, 'completeness'));
        $this->assertSame(1, $run->events()->where('type', 'run.partial')->count());
        $this->assertSame(0, $run->events()->where('type', 'run.failed')->count());
        Http::assertNothingSent();
    }

    public function test_changed_question_or_execution_context_rejects_snapshot(): void
    {
        [$run, $sources] = $this->fixture();
        $this->save($run, $sources);
        $original = $run->input_json;
        $run->update(['input_json' => ['question' => 'A different question']]);
        $this->assertFalse(app(VerifiedAnswerCheckpoint::class)->recover($run, 'worker_timeout'));
        $run->update(['input_json' => $original, 'locale' => 'en']);
        $this->assertFalse(app(VerifiedAnswerCheckpoint::class)->recover($run, 'worker_timeout'));
        $this->assertSame(AgentRun::STATUS_RUNNING, $run->fresh()->status);
        $this->assertSame(0, Message::query()->count());
    }

    public function test_archived_or_changed_source_cannot_be_recovered(): void
    {
        foreach (['archived', 'changed'] as $change) {
            [$run, $sources] = $this->fixture();
            $this->save($run, $sources);
            if ($change === 'archived') {
                $sources[0]['document']->update(['status' => 'archived']);
            } else {
                $sources[0]['chunk']->update(['chunk_text' => 'The source has been replaced.', 'chunk_hash' => hash('sha256', 'The source has been replaced.')]);
            }
            $this->assertFalse(app(VerifiedAnswerCheckpoint::class)->recover($run, 'worker_timeout'), $change);
            $this->assertSame(AgentRun::STATUS_RUNNING, $run->fresh()->status);
            $this->assertSame(0, Message::query()->where('agent_run_id', $run->id)->count());
        }
    }

    public function test_soft_deleted_user_cannot_recover_checkpoint(): void
    {
        [$run, $sources] = $this->fixture();
        $this->save($run, $sources);
        $run->user->delete();

        $this->assertFalse(app(VerifiedAnswerCheckpoint::class)->recover($run->fresh(), 'worker_timeout'));
        $this->assertSame(AgentRun::STATUS_RUNNING, $run->fresh()->status);
        $this->assertSame(0, Message::query()->where('agent_run_id', $run->id)->count());
    }

    public function test_changed_title_rejects_unchanged_excerpt(): void
    {
        [$run, $sources] = $this->fixture();
        $this->save($run, $sources);
        $sources[0]['document']->update(['title' => 'Un titolo diverso']);

        $this->assertFalse(app(VerifiedAnswerCheckpoint::class)->recover($run, 'worker_timeout'));
        $this->assertSame(0, Message::query()->where('agent_run_id', $run->id)->count());
    }

    public function test_rebinding_the_same_actor_to_another_conversation_rejects_snapshot(): void
    {
        [$run, $sources] = $this->fixture();
        $this->save($run, $sources);
        $conversation = Conversation::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'user_id' => $run->user_id]);
        $run->update(['conversation_id' => $conversation->id]);

        $this->assertFalse(app(VerifiedAnswerCheckpoint::class)->recover($run, 'worker_timeout'));
        $this->assertSame(0, Message::query()->where('agent_run_id', $run->id)->count());
    }

    public function test_appended_negation_invalidates_the_original_excerpt(): void
    {
        [$run, $sources] = $this->fixture();
        $this->save($run, $sources);
        $changed = $sources[0]['text'].' Questa informazione non è corretta.';
        $sources[0]['chunk']->update(['chunk_text' => $changed, 'chunk_hash' => hash('sha256', $changed)]);

        $this->assertFalse(app(VerifiedAnswerCheckpoint::class)->recover($run, 'worker_timeout'));
        $this->assertSame(AgentRun::STATUS_RUNNING, $run->fresh()->status);
        $this->assertSame(0, Message::query()->where('agent_run_id', $run->id)->count());
    }

    public function test_recovery_restores_the_previous_tenant_and_authenticated_user(): void
    {
        [$run, $sources] = $this->fixture();
        $this->save($run, $sources);
        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => bcrypt('test')]);
        app(TenantContext::class)->set('other');
        app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class)->set('other-package');
        $this->actingAs($other);

        $this->assertTrue(app(VerifiedAnswerCheckpoint::class)->recover($run, 'worker_timeout'));
        $this->assertSame('other', app(TenantContext::class)->current());
        $this->assertSame('other-package', app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class)->current());
        $this->assertSame($other->id, auth()->id());
    }

    public function test_recovery_keeps_only_claims_and_citations_with_current_sources(): void
    {
        [$run, $sources] = $this->fixture(2);
        $this->save($run, $sources);
        $snapshot = $run->fresh()->result_json;
        $snapshot['verified_answer_checkpoint']['answer']['grounding']['semantic_validation'] = [
            'checks' => [['text' => $sources[1]['text']]],
        ];
        $run->update(['result_json' => $snapshot]);
        $sources[1]['document']->update(['status' => 'archived']);

        $this->assertTrue(app(VerifiedAnswerCheckpoint::class)->recover($run, 'worker_timeout'));
        $response = $run->fresh()->result_json['response'];
        $this->assertStringContainsString($sources[0]['text'], $response['answer']);
        $this->assertStringNotContainsString($sources[1]['text'], json_encode($response));
        $this->assertStringNotContainsString($sources[1]['text'], json_encode($run->events()->where('type', 'run.partial')->sole()->toArray()));
        $this->assertStringNotContainsString($sources[1]['text'], json_encode(Message::query()->where('agent_run_id', $run->id)->sole()->metadata));
        $this->assertSame([$sources[0]['document']->id], array_column($response['citations'], 'document_id'));
        $this->assertSame([$sources[0]['document']->id], array_column($response['grounding']['claims'], 'document_id'));
        $this->assertSame([$sources[0]['document']->id], array_column(Message::query()->where('agent_run_id', $run->id)->sole()->metadata['citations'], 'document_id'));
    }

    public function test_changed_window_does_not_leak_through_citation_for_same_document(): void
    {
        config(['kb.investigation.full_source_max_chars' => 1, 'kb.investigation.long_source_neighbor_radius' => 0]);
        [$run, $sources] = $this->fixture(2);
        $sources[1]['chunk']->update(['knowledge_document_id' => $sources[0]['document']->id, 'chunk_order' => 2]);
        $sources[1]['document'] = $sources[0]['document'];
        $this->save($run, $sources);
        $result = $run->fresh()->result_json;
        $result['verified_answer_checkpoint']['answer']['citations'][0]['chunks'] = [['snippet' => $sources[1]['text']]];
        $result['verified_answer_checkpoint']['answer']['citations'][0]['claims'] = [['text' => $sources[1]['text']]];
        $run->update(['result_json' => $result]);
        $sources[1]['chunk']->update(['chunk_text' => 'Contenuto modificato.']);

        $this->assertTrue(app(VerifiedAnswerCheckpoint::class)->recover($run, 'worker_timeout'));
        $response = $run->fresh()->result_json['response'];
        $this->assertStringContainsString($sources[0]['text'], $response['answer']);
        $this->assertStringNotContainsString($sources[1]['text'], json_encode($response));
        $this->assertStringNotContainsString($sources[1]['text'], json_encode($run->events()->where('type', 'run.partial')->sole()->toArray()));
        $this->assertStringNotContainsString($sources[1]['text'], json_encode(Message::query()->where('agent_run_id', $run->id)->sole()->metadata));
    }

    public function test_cancelled_run_is_untouched(): void
    {
        [$run, $sources] = $this->fixture();
        $this->save($run, $sources);
        $run->update(['status' => AgentRun::STATUS_CANCELLED, 'completed_at' => now()]);
        (new ExecuteAgentRunJob($run->id, 'acme'))->failed(new TimeoutExceededException('timeout'));
        $this->assertSame(AgentRun::STATUS_CANCELLED, $run->fresh()->status);
        $this->assertSame(0, Message::query()->where('agent_run_id', $run->id)->count());
        $this->assertSame(0, $run->events()->count());
    }

    private function fixture(int $count = 1): array
    {
        $user = User::create(['name' => 'Tester', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test'), 'is_active' => true]);
        ProjectMembership::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'user_id' => $user->id, 'role' => 'member']);
        $this->actingAs($user);
        $conversation = Conversation::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'user_id' => $user->id]);
        $run = AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'acme', 'project_key' => 'orders',
            'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => (string) $user->id, 'user_id' => $user->id,
            'conversation_id' => $conversation->id, 'locale' => 'it', 'timezone' => 'UTC', 'status' => AgentRun::STATUS_RUNNING,
            'input_json' => ['question' => 'Quali sono le informazioni verificate?']]);
        $sources = [];
        for ($i = 0; $i < $count; $i++) {
            $text = 'Informazione verificata numero '.($i + 1).'.';
            $hash = hash('sha256', $text);
            $document = KnowledgeDocument::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'source_type' => 'markdown',
                'title' => 'Documento '.($i + 1), 'source_path' => (string) Str::uuid(), 'status' => 'active', 'document_hash' => $hash]);
            $chunk = KnowledgeChunk::create(['tenant_id' => 'acme', 'project_key' => 'orders', 'knowledge_document_id' => $document->id,
                'chunk_order' => 0, 'chunk_text' => $text, 'chunk_hash' => $hash]);
            $sources[] = compact('text', 'hash', 'document', 'chunk');
        }
        return [$run, $sources];
    }

    private function save(AgentRun $run, array $sources): void
    {
        $claims = $citations = $documents = [];
        foreach ($sources as $source) {
            $claims[] = ['text' => $source['text'], 'quote' => $source['text'], 'evidence_hash' => $source['hash'],
                'document_id' => $source['document']->id, 'tool_execution_id' => null];
            $citations[] = ['document_id' => $source['document']->id, 'title' => $source['document']->title];
            $documents[] = ['document_id' => $source['document']->id, 'title' => $source['document']->title,
                'origin' => 'primary', 'evidence' => [['chunk_id' => $source['chunk']->id, 'content' => $source['text'], 'evidence_hash' => $source['hash']]]];
        }
        $answer = new AgentAnswer(implode("\n\n", array_column($claims, 'text')), 'it', 'complete', $citations, [],
            grounding: ['status' => 'grounded', 'claims' => $claims]);
        app(VerifiedAnswerCheckpoint::class)->save($run, $answer, ['documents' => $documents, 'api_tools' => [], 'warnings' => []]);
    }
}
