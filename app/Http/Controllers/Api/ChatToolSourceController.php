<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\AgentToolExecution;
use App\Models\Conversation;
use App\Support\SensitivePayloadRedactor;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Read recorded answer provenance; opening a reference never executes a tool. */
final class ChatToolSourceController extends Controller
{
    public function __invoke(Request $request, Conversation $conversation, int $message, int $execution, SensitivePayloadRedactor $redactor): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $tenant = app(TenantContext::class)->current();
        abort_unless($conversation->tenant_id === $tenant && (int) $conversation->user_id === (int) $user->id, 404);
        $answer = $conversation->messages()->whereKey($message)->where('role', 'assistant')->firstOrFail();
        $sources = data_get($answer->metadata, 'tool_sources', []);
        $source = collect(is_array($sources) ? $sources : [])->first(fn ($source) => is_array($source)
            && (int) ($source['execution_id'] ?? 0) === $execution);
        $usedInAnswer = $source !== null;
        if ($source === null && $answer->agent_run_id !== null) {
            $references = app(\App\Agent\AgentToolReferences::class)->forConversation($conversation, [$answer->agent_run_id]);
            $source = collect($references[$answer->agent_run_id] ?? [])->firstWhere('execution_id', $execution);
        }
        abort_unless($source !== null, 404);

        $record = AgentToolExecution::query()->whereKey($execution)->whereIn('tool_kind', ['api', 'mcp'])
            ->whereHas('run', fn ($query) => $query->forTenant($tenant)
                ->where('conversation_id', $conversation->id)->where('user_id', $user->id)
                ->where('channel', 'chat')->where('actor_type', 'user')->where('actor_id', (string) $user->id))
            ->with('run')->firstOrFail();
        abort_unless($user->canReadAllProjects()
            || in_array($record->run->project_key, $user->allowedProjects(), true), 404);

        return response()->json($redactor->redact([
            'execution_id' => $record->id,
            'tool' => $record->tool_name,
            'display_name' => $source['display_name'] ?? $record->tool_name,
            'kind' => $record->tool_kind,
            'status' => $record->status,
            'used_in_answer' => $usedInAnswer,
            'arguments' => $record->arguments_json ?? [],
            'request' => data_get($record->result_meta_json, 'request'),
            'response' => data_get($record->result_meta_json, 'result'),
            'complete' => data_get($record->result_meta_json, 'complete'),
            'stop_reason' => data_get($record->result_meta_json, 'stop_reason'),
            'physical_requests' => $record->physical_request_count,
            'latency_ms' => $record->latency_ms,
            'retrieved_at' => $record->completed_at?->toIso8601String(),
        ]))->header('Cache-Control', 'private, no-store');
    }
}
