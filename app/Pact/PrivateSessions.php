<?php

declare(strict_types=1);

namespace App\Pact;

use App\Agent\AgentExecutionContext;
use App\Models\AgentRun;
use App\Models\Conversation;
use Illuminate\Support\Facades\DB;

/** Read only the grant owner's conversations in the explicitly granted project. */
final class PrivateSessions
{
    public function execute(string $operation, array $arguments, AgentExecutionContext $context, AgentRun $run): array
    {
        $context->assertMatches($run);
        $id = data_get($run->input_json, 'pact_task_id');
        $row = is_string($id) ? DB::table('pact_agent_tasks')->where('id', $id)->where('tenant_id', $context->tenantId)->where('user_id', $context->actorId)->first() : null;
        if (! $row || $context->projectKey === null) throw new \DomainException('pact_session_scope_required');
        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        if (($payload['principal_context']['project_key'] ?? null) !== $context->projectKey) throw new \DomainException('pact_session_scope_mismatch');
        app(PactAuthorization::class)->authorize('read', $context->tenantId, $context->actorId, 'askmydocs', ['principal_context' => $payload['principal_context']]);
        $sessions = Conversation::query()->forTenant($context->tenantId)->where('user_id', $context->actorId)->where('project_key', $context->projectKey)->whereKeyNot($run->conversation_id);
        if ($operation === 'list_private_sessions') {
            return ['sessions' => $sessions->orderByDesc('updated_at')->limit(30)->get(['id', 'title', 'updated_at'])->map(fn ($session) => ['id' => (string) $session->id, 'title' => $session->title, 'updated_at' => $session->updated_at?->toIso8601String()])->all()];
        }
        if ($operation !== 'read_private_session' || ! is_numeric($arguments['session_id'] ?? null)) throw new \DomainException('invalid_pact_session_request');
        $session = $sessions->whereKey((int) $arguments['session_id'])->first();
        if (! $session) return ['error' => 'session_not_found'];
        $messages = $session->messages()->forTenant($context->tenantId)->whereIn('role', ['user', 'assistant'])->orderByDesc('id')->limit(30)->get(['role', 'content'])->reverse()->values()->map(fn ($message) => ['role' => $message->role, 'content' => mb_substr((string) $message->content, 0, 4000)])->all();
        return ['session' => ['id' => (string) $session->id, 'title' => $session->title, 'messages' => $messages]];
    }
}
