<?php

namespace Tests\Feature\Chat;

use App\Agent\AgentExecutionContext;
use App\Agent\Evidence\AgentEvidenceAccess;
use App\Agent\Tools\AgentToolRegistry;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Chat\Reasoning\ConversationReasoning;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvidenceAccessTest extends TestCase
{
    use RefreshDatabase;

    private function runFixture(?Conversation $conversation = null): AgentRun
    {
        app(TenantContext::class)->set('default');
        config(['reasoning.enabled' => true]);
        if ($conversation === null) {
            $user = User::create(['name' => 'Tester', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test')]);
            $conversation = Conversation::create(['user_id' => $user->id, 'project_key' => 'demo']);
            \App\Models\ProjectMembership::create(['user_id' => $user->id, 'project_key' => 'demo', 'role' => 'member', 'scope_allowlist' => []]);
        }
        return AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'default', 'project_key' => 'demo',
            'user_id' => $conversation->user_id, 'conversation_id' => $conversation->id, 'channel' => 'chat',
            'actor_type' => 'user', 'actor_id' => (string) $conversation->user_id, 'locale' => 'it', 'timezone' => 'UTC',
            'status' => 'running', 'input_json' => ['question' => 'TRACK-55']]);
    }

    private function context(AgentRun $run): AgentExecutionContext
    {
        return AgentExecutionContext::fromArray($run->getAttributes());
    }

    private function tool(AgentRun $run): array
    {
        $definition = app(AgentToolRegistry::class)->forContext($this->context($run), $run->user)['search_knowledge_base'];
        $execution = $run->toolExecutions()->create(['logical_index' => 1, 'tool_name' => $definition->name,
            'tool_kind' => $definition->kind, 'status' => 'completed']);
        $body = ['records' => [['id' => 'TRACK-55', 'customerId' => 'CUSTOMER-X', 'status' => 'scheduled']]];
        return ['execution_id' => $execution->id, 'tool' => $definition->name, 'executor_reference' => $definition->executorReference,
            'result' => $body, 'evidence_hash' => hash('sha256', json_encode($body, JSON_UNESCAPED_UNICODE)), 'retrieved_at' => now()->toIso8601String()];
    }

    public function test_live_snapshot_is_not_reused_for_a_new_question_but_an_explicit_recap_can_use_it(): void
    {
        $previous = $this->runFixture();
        $tool = $this->tool($previous);
        $previous->forceFill(['result_json' => ['evidence' => ['api_tools' => [$tool]]]])->save();
        $conversation = $previous->conversation;
        $memory = app(ConversationReasoning::class);
        $conversation->forceFill(['reasoning_state' => ['evidence' => $memory->references($conversation, ['api_tools' => [$tool]])]])->save();
        $current = $this->runFixture($conversation);
        $access = app(AgentEvidenceAccess::class);
        $this->assertSame([], $memory->recapEvidence($current));
        $this->assertSame([], $access->current($current, $this->context($current), ['api_tools' => [$tool]])['api_tools']);
        $current->forceFill(['result_json' => ['question_understanding' => ['transition' => 'recap', 'focus' => ['identifiers' => ['TRACK-55']]]]])->save();
        $recalled = $memory->recapEvidence($current);
        $this->assertCount(1, $recalled);
        $this->assertCount(1, $access->current($current, $this->context($current), ['api_tools' => $recalled], true)['api_tools']);
        $other = $this->runFixture();
        $this->assertSame([], $access->current($other, $this->context($other), ['api_tools' => [$tool]], true)['api_tools']);
    }

    public function test_disabled_tool_or_corrupted_payload_is_excluded_before_the_model(): void
    {
        $run = $this->runFixture();
        $tool = $this->tool($run);
        $access = app(AgentEvidenceAccess::class);
        $this->assertCount(1, $access->current($run, $this->context($run), ['api_tools' => [$tool]])['api_tools']);
        $changed = $tool;
        $changed['result']['records'][0]['status'] = 'invented';
        $this->assertSame([], $access->current($run, $this->context($run), ['api_tools' => [$changed]])['api_tools']);
        $tool['tool'] = 'disabled_connector';
        $this->assertSame([], $access->current($run, $this->context($run), ['api_tools' => [$tool]])['api_tools']);
    }

    public function test_only_registered_child_runs_can_supply_live_evidence_to_their_parent(): void
    {
        $parent = $this->runFixture();
        $child = $this->runFixture($parent->conversation);
        $tool = $this->tool($child);
        $access = app(AgentEvidenceAccess::class);
        $this->assertSame([], $access->current($parent, $this->context($parent), ['api_tools' => [$tool]])['api_tools']);
        $parent->forceFill(['result_json' => ['research_runs' => [$child->id]]])->save();
        $this->assertSame([], $access->current($parent, $this->context($parent), ['api_tools' => [$tool]])['api_tools']);
        $child->forceFill(['input_json' => ['research_parent_id' => $parent->id]])->save();
        $this->assertCount(1, $access->current($parent, $this->context($parent), ['api_tools' => [$tool]])['api_tools']);
        $child->forceFill(['project_key' => 'other'])->save();
        $this->assertSame([], $access->current($parent, $this->context($parent), ['api_tools' => [$tool]])['api_tools']);
    }

    public function test_document_revalidation_rejects_change_revocation_and_cross_project(): void
    {
        $run = $this->runFixture();
        $document = KnowledgeDocument::create(['tenant_id' => 'default', 'project_key' => 'demo', 'source_type' => 'text',
            'title' => 'Shipment', 'source_path' => 'fixture/shipment', 'mime_type' => 'text/plain', 'status' => 'active',
            'document_hash' => str_repeat('a', 64), 'version_hash' => Str::random(32)]);
        $chunk = KnowledgeChunk::create(['tenant_id' => 'default', 'project_key' => 'demo', 'knowledge_document_id' => $document->id,
            'chunk_order' => 0, 'chunk_text' => 'TRACK-55 scheduled.', 'chunk_hash' => hash('sha256', 'TRACK-55 scheduled.')]);
        $evidence = ['documents' => [['document_id' => $document->id, 'evidence' => [['chunk_id' => $chunk->id,
            'content' => $chunk->chunk_text, 'evidence_hash' => $chunk->chunk_hash]]]]];
        $access = app(AgentEvidenceAccess::class);
        $this->assertCount(1, $access->current($run, $this->context($run), $evidence)['documents']);
        $chunk->update(['chunk_text' => 'TRACK-55 cancelled.']);
        $this->assertSame([], $access->current($run, $this->context($run), $evidence)['documents']);
        $chunk->update(['chunk_text' => 'TRACK-55 scheduled.']);
        $document->update(['status' => 'archived']);
        $this->assertSame([], $access->current($run, $this->context($run), $evidence)['documents']);
        $document->update(['status' => 'active', 'project_key' => 'private']);
        $this->assertSame([], $access->current($run, $this->context($run), $evidence)['documents']);
    }

    public function test_revoked_document_is_also_removed_from_a_catalog_tool_result(): void
    {
        $run = $this->runFixture();
        $document = KnowledgeDocument::create(['tenant_id' => 'default', 'project_key' => 'demo', 'source_type' => 'text',
            'title' => 'Manual', 'source_path' => 'fixture/manual', 'mime_type' => 'text/plain', 'status' => 'active',
            'document_hash' => str_repeat('a', 64), 'version_hash' => Str::random(32)]);
        $definition = app(AgentToolRegistry::class)->forContext($this->context($run), $run->user)['list_knowledge_documents'];
        $execution = $run->toolExecutions()->create(['logical_index' => 1, 'tool_name' => $definition->name, 'tool_kind' => 'catalog', 'status' => 'completed']);
        $result = ['documents' => [['id' => $document->id, 'title' => 'Manual']]];
        $tool = ['execution_id' => $execution->id, 'tool' => $definition->name, 'executor_reference' => $definition->executorReference,
            'result' => $result, 'evidence_hash' => hash('sha256', json_encode($result, JSON_UNESCAPED_UNICODE))];
        $access = app(AgentEvidenceAccess::class);
        $this->assertCount(1, $access->current($run, $this->context($run), ['api_tools' => [$tool]])['api_tools']);
        $document->update(['status' => 'archived']);
        $this->assertSame([], $access->current($run, $this->context($run), ['api_tools' => [$tool]])['api_tools']);
    }
}
