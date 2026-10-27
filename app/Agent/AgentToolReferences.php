<?php

declare(strict_types=1);

namespace App\Agent;

use App\Models\AgentRun;
use App\Models\AgentToolExecution;
use App\Models\Conversation;

/** Lightweight references for the actual calls of each answer, including uncited calls. */
final class AgentToolReferences
{
    public function forConversation(Conversation $conversation, array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }
        $scope = fn () => AgentRun::query()->forTenant($conversation->tenant_id)
            ->where('conversation_id', $conversation->id)->where('user_id', $conversation->user_id)
            ->where('channel', 'chat')->where('actor_type', 'user')->where('actor_id', (string) $conversation->user_id);
        $parents = $scope()->whereIn('id', $runIds)->get(['id', 'result_json']);
        $owners = $parents->mapWithKeys(fn ($run) => [$run->id => $run->id])->all();
        $children = $parents->flatMap(fn ($run) => data_get($run->result_json, 'research_runs', []))->unique()->all();
        if ($children !== []) {
            foreach ($scope()->whereIn('id', $children)->get(['id', 'input_json']) as $child) {
                $parent = data_get($child->input_json, 'research_parent_id');
                if (isset($owners[$parent])) {
                    $owners[$child->id] = $parent;
                }
            }
        }
        $references = [];
        foreach (AgentToolExecution::query()->whereIn('agent_run_id', array_keys($owners))
            ->whereIn('tool_kind', ['api', 'mcp'])->whereIn('status', ['completed', 'failed'])
            ->orderBy('id')->get(['id', 'agent_run_id', 'tool_name', 'tool_kind', 'completed_at']) as $execution) {
            $references[$owners[$execution->agent_run_id]][] = [
                'execution_id' => $execution->id, 'tool' => $execution->tool_name,
                'kind' => $execution->tool_kind, 'retrieved_at' => $execution->completed_at?->toIso8601String(),
            ];
        }
        return $references;
    }
}
