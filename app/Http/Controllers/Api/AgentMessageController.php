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
use Illuminate\Validation\ValidationException;

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
                'selection' => ['sometimes', 'array'],
                'selection.message_id' => ['required_with:selection', 'integer', 'min:1'],
                'selection.row_key' => ['required_with:selection', 'string', 'max:128'],
            ],
            $this->retrievalFilterRules(),
        ));
        $mcpAppId = is_string($validated['mcp_app_id'] ?? null)
            ? $validated['mcp_app_id']
            : null;
        $appContext = $mcpAppContext->resolve($mcpAppId, $user, $conversation);
        $selection = $this->resolveSelection(
            $conversation,
            is_array($validated['selection'] ?? null) ? $validated['selection'] : null,
        );
        $message = $conversation->messages()->create([
            'role' => 'user',
            'content' => $validated['content'],
            'metadata' => $selection === null ? null : ['agent_selection' => $selection],
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
        if ($selection !== null) {
            $input['selection'] = $selection;
        }
        $run = $runs->dispatch($context, $input, [
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
        ]);
        $message->forceFill(['metadata' => array_merge(
            is_array($message->metadata) ? $message->metadata : [],
            ['agent_run_id' => $run->run_id],
        )])->save();

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

    /**
     * Resolve a client row key against a server-produced selection artifact.
     *
     * @param  array<string,mixed>|null  $requested
     * @return array<string,mixed>|null
     */
    private function resolveSelection(Conversation $conversation, ?array $requested): ?array
    {
        if ($requested === null) {
            return null;
        }

        $source = $conversation->messages()
            ->whereKey((int) ($requested['message_id'] ?? 0))
            ->where('role', 'assistant')
            ->first();
        $artifact = is_array(data_get($source?->metadata, 'agent_artifact'))
            ? data_get($source?->metadata, 'agent_artifact')
            : null;
        if (! is_array($artifact) || ($artifact['interaction_mode'] ?? null) !== 'selection') {
            throw ValidationException::withMessages([
                'selection' => 'The selected artifact is not available in this conversation.',
            ]);
        }

        $rowKey = (string) ($requested['row_key'] ?? '');
        $rows = is_array($artifact['rows'] ?? null) ? $artifact['rows'] : [];
        $row = collect($rows)->first(
            static fn (mixed $candidate): bool => is_array($candidate)
                && hash_equals((string) ($candidate['key'] ?? ''), $rowKey),
        );
        if (! is_array($row) || ! is_array($row['record'] ?? null)) {
            throw ValidationException::withMessages([
                'selection.row_key' => 'The selected row no longer exists.',
            ]);
        }

        return [
            'source_message_id' => $source->id,
            'source_execution_id' => $artifact['source_execution_id'] ?? null,
            'tool' => $artifact['tool'] ?? null,
            'row_key' => $rowKey,
            'label' => $row['label'] ?? $rowKey,
            'record' => $row['record'],
        ];
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
