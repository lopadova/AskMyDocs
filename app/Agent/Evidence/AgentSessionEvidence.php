<?php

declare(strict_types=1);

namespace App\Agent\Evidence;

use App\Agent\AgentExecutionContext;
use App\Models\AgentRun;

/** Prior turn checkpoints are the durable collection for a conversation. */
final readonly class AgentSessionEvidence
{
    public function __construct(private AgentEvidenceAccess $access) {}

    public function load(AgentRun $run, AgentExecutionContext $context): array
    {
        $collection = ['documents' => [], 'api_tools' => []];
        $remaining = (int) config('agent.session_evidence.previous_bytes', 262144);
        $previousRuns = AgentRun::query()->forTenant($run->tenant_id)
            ->where('channel', 'chat')->where('conversation_id', $run->conversation_id)
            ->where('project_key', $run->project_key)->where('user_id', $run->user_id)
            ->where('id', '<', $run->id)->whereNull('input_json->research_parent_id')
            ->where(fn ($query) => $query->whereIn('status', [AgentRun::STATUS_COMPLETED, AgentRun::STATUS_PARTIAL])
                ->orWhere('result_json->session_research->state', 'ready')
                ->orWhereNotNull('result_json->session_research->published_evidence'))
            ->latest('id')->limit((int) config('agent.session_evidence.previous_turns', 12))->cursor();
        $seen = [];

        foreach ($previousRuns as $previous) {
            if ($remaining <= 0) {
                break;
            }
            $batch = ['documents' => [], 'api_tools' => []];
            $snapshot = data_get($previous->result_json, 'session_research.published_evidence') ?? $previous->result_json['evidence'] ?? [];
            foreach ($snapshot['documents'] ?? [] as $document) {
                foreach ($document['evidence'] ?? [] as $chunk) {
                    $key = 'document:'.($document['document_id'] ?? '').':'.($chunk['evidence_hash'] ?? '');
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $batch['documents'][] = array_diff_key([
                        ...$document,
                        'evidence' => [[...array_diff_key($chunk, ['research_flow_ids' => true]), 'session_document_context' => true]],
                    ], array_flip(['session_memory_observation_id', 'research_flow_ids']));
                }
            }
            foreach ($snapshot['api_tools'] ?? [] as $tool) {
                $key = 'tool:'.($tool['execution_id'] ?? '');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $batch['api_tools'][] = [...array_diff_key($tool, array_flip(['session_memory_observation_id', 'research_flow_ids', 'expires_at'])),
                    'session_context' => true, 'historical_context' => true];
            }
            if ($batch['documents'] === [] && $batch['api_tools'] === []) {
                continue;
            }
            $safe = $this->access->current($run, $context, $batch);
            foreach (array_keys($collection) as $kind) {
                foreach ($safe[$kind] as $item) {
                    $bytes = strlen(json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                    if ($bytes <= $remaining) {
                        $collection[$kind][] = $item;
                        $remaining -= $bytes;
                    }
                }
            }
        }

        return $collection;
    }
}
