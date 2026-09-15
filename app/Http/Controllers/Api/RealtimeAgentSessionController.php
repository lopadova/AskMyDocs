<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use AgentsFullDuplex\RealtimeAgent\Enums\InteractionLevel;
use AgentsFullDuplex\RealtimeAgent\Facades\RealtimeAgent;
use AgentsFullDuplex\RealtimeAgent\Models\AgentSessionRecord;
use App\Http\Requests\AgentChatScopeRules;
use App\Models\Conversation;
use App\Models\RealtimeAgentSessionLink;
use App\Realtime\AskMyDocsChatTurnTool;
use App\Realtime\RealtimeAgentAvailability;
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
            'expires_at' => $expiresAt->toISOString(),
        ], 201);
    }

    private function instructions(): string
    {
        return <<<'INSTRUCTIONS'
You are the realtime voice interface for the current AskMyDocs conversation.
For every substantive user request, call askmydocs.chat_turn exactly once with the request as understood. Never answer from memory, general knowledge, the realtime transcript, or UI context.
When the tool returns response.answer, speak that canonical answer faithfully without adding facts or changing citations. When it returns handoff.required, say that confirmation is available in the chat and stop speaking. When it returns an error, give a brief apology and ask the user to continue in text chat.
Do not invent tools, modify filters, expose internal identifiers, or claim that an action completed before the tool confirms it.
INSTRUCTIONS;
    }
}
