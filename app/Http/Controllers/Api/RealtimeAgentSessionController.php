<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use AgentsFullDuplex\RealtimeAgent\Engine\AgentSessionManager;
use AgentsFullDuplex\RealtimeAgent\Enums\InteractionLevel;
use AgentsFullDuplex\RealtimeAgent\Facades\RealtimeAgent;
use AgentsFullDuplex\RealtimeAgent\Models\AgentSessionRecord;
use App\Agent\AgentConversationBusy;
use App\Http\Requests\AgentChatScopeRules;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\RealtimeAgentSessionLink;
use App\Realtime\AskMyDocsChatTurnTool;
use App\Realtime\RealtimeAgentAvailability;
use App\Realtime\RealtimeChatRunPresenter;
use App\Realtime\RealtimeSessionAccess;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/** Starts only the fixed, server-owned AskMyDocs live-chat definition. */
final class RealtimeAgentSessionController extends Controller
{
    public function store(
        Request $request,
        Conversation $conversation,
        RealtimeAgentAvailability $availability,
    ): JsonResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_if((string) $conversation->user_id !== (string) $user->getAuthIdentifier(), 403);

        $status = $availability->status();
        if (! $status['available']) {
            return response()->json([
                'error' => [
                    'code' => 'realtime_agent_unavailable',
                    'reason' => $status['reason'],
                    'message' => 'The realtime assistant is not available in this environment.',
                ],
            ], 503);
        }

        $validated = $request->validate(AgentChatScopeRules::rules());
        $expiresAt = now()->addMinutes(max(1, (int) config('realtime-agent.session_ttl_minutes', 30)));

        $started = DB::transaction(function () use (
            $availability,
            $conversation,
            $expiresAt,
            $user,
            $validated,
        ) {
            $started = RealtimeAgent::make('askmydocs.chat.live')
                ->provider($availability->provider())
                ->instructions($this->instructions())
                ->context([
                    'locale' => (string) $user->locale,
                    'project' => $conversation->project_key,
                    'scope' => [
                        'filters_active' => (array) ($validated['filters'] ?? []) !== [],
                        'live_sources_active' => (array) ($validated['live_sources'] ?? []) !== [],
                    ],
                ])
                ->tools([AskMyDocsChatTurnTool::class])
                ->surface('chat.conversation')
                ->interactionLevel(InteractionLevel::Guide)
                ->allowAgentGoals(false)
                ->startFor($user);

            RealtimeAgentSessionLink::query()->create([
                'session_id' => $started->id,
                'tenant_id' => $conversation->tenant_id,
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'filters' => (array) ($validated['filters'] ?? []),
                'live_sources' => isset($validated['live_sources'])
                    ? (array) $validated['live_sources']
                    : null,
                'expires_at' => $expiresAt,
            ]);
            AgentSessionRecord::query()->whereKey($started->id)->update([
                'expires_at' => $expiresAt,
            ]);

            return $started;
        }, 3);

        return response()->json([
            ...$started->connection()->jsonSerialize(),
            'conversation_id' => $conversation->id,
            'busy_message' => (new AgentConversationBusy((string) $user->locale))->getMessage(),
            'expires_at' => $expiresAt->toISOString(),
        ], 201);
    }

    /** Read-only receipt discovery while the voice tool HTTP request is still running. */
    public function run(
        Request $request,
        Conversation $conversation,
        string $session,
        AgentSessionManager $sessions,
        RealtimeSessionAccess $access,
        RealtimeChatRunPresenter $presenter,
    ): JsonResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_if((string) $conversation->user_id !== (string) $user->id, 403);
        $input = $request->validate(['call_id' => ['required', 'string', 'max:255']]);
        $tenant = app(TenantContext::class)->current();
        abort_unless(RealtimeAgentSessionLink::query()->forTenant($tenant)
            ->whereKey($session)
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->exists(), 404);
        $link = $access->linkFor($user, $sessions->resume($session));
        abort_if($link === null, 404);
        $run = AgentRun::query()->forTenant($tenant)
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->where('actor_type', 'user')
            ->where('actor_id', (string) $user->id)
            ->where('channel', 'chat')
            ->where('input_json->realtime_session_id', $session)
            ->where('input_json->realtime_call_id', $input['call_id'])
            ->whereNull('input_json->research_parent_id')->latest('id')->first();

        return response()->json(['run' => $run === null ? null : $presenter->present($run)])
            ->header('Cache-Control', 'no-store');
    }

    private function instructions(): string
    {
        return <<<'INSTRUCTIONS'
You are the realtime voice interface for the current AskMyDocs conversation.
For every substantive user request, call askmydocs.chat_turn exactly once with the request as understood. Never answer from memory, general knowledge, the realtime transcript, or UI context.
Handle one request at a time. While askmydocs.chat_turn is pending, do not start, queue, retry, or replace it with another request. If the user asks something else, briefly say in their language: "Un attimo, una cosa alla volta. Sto ancora completando la richiesta precedente." Then wait for the first result and speak its answer. A single request may itself contain multiple questions: send those together in the same tool call.
If the tool returns busy=true, speak response.answer as a short waiting notice, do not retry that request automatically, and keep waiting for the original request. Do not confuse this notice with the original answer.
When the tool returns response.answer, speak that canonical answer faithfully without adding facts or changing citations. When it returns handoff.required, say that confirmation is available in the chat and stop speaking. When it returns an error, give a brief apology and ask the user to continue in text chat.
Do not invent tools, modify filters, expose internal identifiers, or claim that an action completed before the tool confirms it.
INSTRUCTIONS;
    }
}
