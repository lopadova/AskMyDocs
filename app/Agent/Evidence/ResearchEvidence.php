<?php

declare(strict_types=1);

namespace App\Agent\Evidence;

/** Flow ownership is server metadata. It does not replace source authorization. */
final class ResearchEvidence
{
    /** Reassign authorized historical snapshots by entity, never by old turn's task indexes. */
    public static function recapTools(array $tools, array $subquestions): array
    {
        $scoped = [];
        foreach ($tools as $tool) {
            $identities = array_merge([], ...array_map(fn ($entry) => \App\Services\Chat\Reasoning\EvidenceProjector::identities($entry['record']),
                \App\Services\Chat\Reasoning\EvidenceProjector::records($tool)));
            $flows = array_keys(array_filter($subquestions, fn ($sub) => array_intersect($sub['identifiers'] ?? [], $identities) !== []));
            if ($flows !== []) {
                $scoped[] = [...$tool, 'research_flow_ids' => $flows];
            }
        }
        return $scoped;
    }

    public static function tagged(array $evidence, int $flow): array
    {
        foreach ($evidence['documents'] ?? [] as $i => $document) {
            foreach ($document['evidence'] ?? [] as $j => $chunk) {
                $evidence['documents'][$i]['evidence'][$j]['research_flow_ids'] = [$flow];
            }
        }
        foreach ($evidence['api_tools'] ?? [] as $i => $tool) {
            $evidence['api_tools'][$i]['research_flow_ids'] = [$flow];
        }
        return $evidence;
    }

    public static function forFlow(array $evidence, int $flow): array
    {
        $filtered = ['documents' => [], 'api_tools' => [], 'warnings' => []];
        foreach ($evidence['documents'] ?? [] as $document) {
            $chunks = array_values(array_filter($document['evidence'] ?? [], fn ($chunk) => in_array($flow, $chunk['research_flow_ids'] ?? [], true)));
            if ($chunks !== []) {
                $filtered['documents'][] = [...$document, 'evidence' => $chunks, 'chunks_used' => count($chunks)];
            }
        }
        $filtered['api_tools'] = array_values(array_filter($evidence['api_tools'] ?? [], fn ($tool) => in_array($flow, $tool['research_flow_ids'] ?? [], true)));
        return $filtered;
    }
}
