<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Agent\AgentExecutionContextFactory;
use App\Agent\AgentRunDispatcher;
use App\Mcp\Apps\McpAppTurnContext;
use App\Models\Conversation;
use App\Support\Canonical\CanonicalType;
use App\Support\Kb\SourceType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Starts the durable agent path for an authenticated conversation turn. */
final class AgentMessageController extends Controller
{
    public function store(
        Request $request,
        Conversation $conversation,
        AgentExecutionContextFactory $contexts,
        AgentRunDispatcher $runs,
        McpAppTurnContext $mcpAppContext,
    ): JsonResponse {
        $user = $request->user();
        abort_if($user === null, 401);
        if ($conversation->user_id !== $user->id) {
            abort(403);
        }

        $validated = $request->validate(array_merge(
            [
                'content' => ['required', 'string', 'max:10000'],
                'mcp_app_id' => ['sometimes', 'string', 'ulid'],
            ],
            $this->retrievalFilterRules(),
        ));
        $mcpAppId = is_string($validated['mcp_app_id'] ?? null)
            ? $validated['mcp_app_id']
            : null;
        $appContext = $mcpAppContext->resolve($mcpAppId, $user, $conversation);
        $message = $conversation->messages()->create([
            'role' => 'user',
            'content' => $validated['content'],
        ]);
        $context = $contexts->forUser($user, $conversation->project_key);
        $input = [
            'question' => $validated['content'],
            'filters' => is_array($validated['filters'] ?? null) ? $validated['filters'] : [],
            'user_message_id' => $message->id,
        ];
        if ($appContext !== null && $mcpAppId !== null) {
            $input['mcp_app_id'] = $mcpAppId;
        }
        $run = $runs->dispatch($context, $input, [
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
        ]);
        $message->forceFill(['metadata' => ['agent_run_id' => $run->run_id]])->save();

        return response()->json([
            'run_id' => $run->run_id,
            'status' => $run->status,
            'locale' => $run->locale,
            'events_url' => '/agent-runs/'.$run->run_id.'/events',
            'cancel_url' => '/agent-runs/'.$run->run_id.'/cancel',
            'continue_url' => '/agent-runs/'.$run->run_id.'/continue',
            'user_message' => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'metadata' => $message->metadata,
                'created_at' => $message->created_at,
            ],
        ], 202);
    }

    /** @return array<string,array<int,string>> */
    private function retrievalFilterRules(): array
    {
        $sourceTypes = collect(SourceType::cases())
            ->reject(fn (SourceType $type): bool => $type === SourceType::UNKNOWN)
            ->map(fn (SourceType $type): string => $type->value)
            ->all();
        $canonicalTypes = array_map(
            static fn (CanonicalType $type): string => $type->value,
            CanonicalType::cases(),
        );

        return [
            'filters' => ['nullable', 'array'],
            'filters.project_keys' => ['nullable', 'array'],
            'filters.project_keys.*' => ['string', 'max:120'],
            'filters.tag_slugs' => ['nullable', 'array'],
            'filters.tag_slugs.*' => ['string', 'max:120'],
            'filters.source_types' => ['nullable', 'array'],
            'filters.source_types.*' => ['string', 'in:'.implode(',', $sourceTypes)],
            'filters.canonical_types' => ['nullable', 'array'],
            'filters.canonical_types.*' => ['string', 'in:'.implode(',', $canonicalTypes)],
            'filters.connector_types' => ['nullable', 'array'],
            'filters.connector_types.*' => ['string', 'max:120'],
            'filters.doc_ids' => ['nullable', 'array'],
            'filters.doc_ids.*' => ['integer', 'min:1'],
            'filters.collection_id' => ['nullable', 'integer', 'min:1'],
            'filters.folder_globs' => ['nullable', 'array'],
            'filters.folder_globs.*' => ['string', 'max:255'],
            'filters.date_from' => ['nullable', 'date'],
            'filters.date_to' => ['nullable', 'date', 'after_or_equal:filters.date_from'],
            'filters.languages' => ['nullable', 'array'],
            'filters.languages.*' => ['string', 'size:2'],
        ];
    }
}
