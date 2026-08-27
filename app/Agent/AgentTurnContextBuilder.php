<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;
use App\Models\Conversation;
use App\Services\Widget\WidgetPiiMasker;
use App\Support\SensitivePayloadRedactor;

/** Builds a bounded, structured cross-turn context for the planner and synthesizer. */
final readonly class AgentTurnContextBuilder
{
    public function __construct(
        private WidgetPiiMasker $masker,
        private SensitivePayloadRedactor $redactor,
    ) {}

    /** @return array<string, mixed> */
    public function build(AgentRun $run, ?string $mcpAppContext = null): array
    {
        $conversation = $run->conversation;
        if (! $conversation instanceof Conversation) {
            return $this->redactor->redact($this->masker->maskArray(array_filter([
                'current_selection' => data_get($run->input_json, 'selection'),
                'mcp_app' => $mcpAppContext,
            ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '')) ?? []);
        }

        $currentMessageId = (int) data_get($run->input_json, 'user_message_id', 0);
        $messages = $conversation->messages()
            ->when($currentMessageId > 0, fn ($query) => $query->where('id', '<', $currentMessageId))
            ->latest('id')
            ->limit(10)
            ->get(['id', 'role', 'content', 'metadata'])
            ->reverse()
            ->map(fn ($message): array => array_filter([
                'role' => $message->role,
                'content' => mb_substr((string) $message->content, 0, 3000),
                'selection' => data_get($message->metadata, 'agent_selection'),
            ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== ''))
            ->values()
            ->all();

        $previousRuns = AgentRun::query()
            ->forTenant($run->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $run->user_id)
            ->where('id', '<', $run->id)
            ->whereIn('status', [AgentRun::STATUS_COMPLETED, AgentRun::STATUS_PARTIAL])
            ->latest('id')
            ->limit(3)
            ->get()
            ->reverse()
            ->map(fn (AgentRun $previous): array => [
                'question' => mb_substr((string) data_get($previous->input_json, 'question', ''), 0, 2000),
                'answer' => mb_substr((string) data_get($previous->result_json, 'response.answer', ''), 0, 3000),
                'tool_results' => array_map(
                    fn (array $tool): array => $this->compactTool($tool),
                    array_slice(
                        is_array(data_get($previous->result_json, 'evidence.api_tools'))
                            ? data_get($previous->result_json, 'evidence.api_tools')
                            : [],
                        -8,
                    ),
                ),
            ])
            ->values()
            ->all();

        return $this->redactor->redact($this->masker->maskArray(array_filter([
            'conversation_messages' => $messages,
            'previous_runs' => $previousRuns,
            'current_selection' => data_get($run->input_json, 'selection'),
            'mcp_app' => $mcpAppContext,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '')) ?? []);
    }

    /** @param array<string,mixed> $tool @return array<string,mixed> */
    private function compactTool(array $tool): array
    {
        return array_filter([
            'tool' => $tool['tool'] ?? null,
            'display_name' => $tool['display_name'] ?? null,
            'arguments' => $this->bounded($tool['arguments'] ?? []),
            'result' => $this->bounded($this->preferStructuredContent($tool['result'] ?? [])),
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    private function preferStructuredContent(mixed $result): mixed
    {
        if (! is_array($result)) {
            return $result;
        }
        $structured = data_get($result, 'artifact.structuredContent');
        if (is_array($structured)) {
            $artifact = is_array($result['artifact'] ?? null) ? $result['artifact'] : [];
            $result['artifact'] = array_filter([
                'structuredContent' => $structured,
                'provenance' => is_array($artifact['provenance'] ?? null) ? $artifact['provenance'] : null,
            ]);
        }

        return $result;
    }

    private function bounded(mixed $value, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return mb_substr($value, 0, 2000);
        }
        if (! is_array($value) || $depth >= 8) {
            return $value;
        }

        $bounded = [];
        foreach (array_slice($value, 0, 30, true) as $key => $nested) {
            $bounded[$key] = $this->bounded($nested, $depth + 1);
        }

        return $bounded;
    }
}
