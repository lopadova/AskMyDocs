<?php

declare(strict_types=1);

namespace App\Services\Kb\Knowledge;

use App\Models\KbNode;
use App\Models\KbEdge;
use App\Models\KnowledgeEntity;
use App\Models\KnowledgeExtraction;
use App\Models\KnowledgeObservation;

final class KnowledgeGraphProjection
{
    public function project(KnowledgeExtraction $run): void
    {
        $observations = KnowledgeObservation::query()->forTenant($run->tenant_id)->where('extraction_id', $run->id)->get();
        $ids = $observations->pluck('entity_id')->merge($observations->pluck('target_entity_id'))->filter()->unique();
        $uids = [];
        foreach (KnowledgeEntity::query()->forTenant($run->tenant_id)->where('project_key', $run->project_key)->whereIn('id', $ids)->get() as $entity) {
            // Legacy graph surfaces may not perform contribution-level ACL checks.
            // They see neither labels nor identifiers: actual views use KnowledgeReadService.
            $uids[$entity->id] = 'entity:'.$entity->identity_hash;
            KbNode::query()->withoutGlobalScope('document_category')->updateOrCreate(['tenant_id' => $entity->tenant_id, 'project_key' => $entity->project_key, 'node_uid' => 'entity:'.$entity->identity_hash], [
                'node_category' => 'entity', 'node_type' => 'knowledge_entity', 'label' => 'Knowledge entity',
                'source_doc_id' => null, 'payload_json' => ['entity_id' => $entity->id, 'generation_source' => 'auto'],
            ]);
        }
        foreach ($observations as $observation) {
            if (! isset($uids[$observation->entity_id], $uids[$observation->target_entity_id])) continue;
            KbEdge::query()->withoutGlobalScope('document_category')->updateOrCreate([
                'tenant_id' => $run->tenant_id, 'project_key' => $run->project_key, 'edge_uid' => 'entity:'.$observation->fingerprint,
            ], ['edge_category' => 'entity', 'from_node_uid' => $uids[$observation->entity_id], 'to_node_uid' => $uids[$observation->target_entity_id],
                'edge_type' => 'related_to', 'source_doc_id' => null, 'weight' => 1, 'provenance' => 'knowledge_entity',
                'payload_json' => ['observation_id' => $observation->id, 'relation' => $observation->relation]]);
        }
    }

    public function lint(string $tenant, string $project): array
    {
        $missing = [];
        foreach (KbNode::query()->withoutGlobalScope('document_category')->forTenant($tenant)->where('project_key', $project)->where('node_category', 'entity')->cursor() as $node) {
            if (! KnowledgeEntity::query()->forTenant($tenant)->where('project_key', $project)->whereKey(data_get($node->payload_json, 'entity_id'))->exists()) $missing[] = $node->node_uid;
        }
        return ['missing_entity_projections' => $missing, 'healthy' => $missing === []];
    }
}
