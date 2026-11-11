<?php

declare(strict_types=1);

namespace App\Services\Kb\Knowledge;

use App\Models\KnowledgeDocument;
use App\Models\KnowledgeExtraction;
use App\Models\User;
use App\Support\LikeEscaper;
use Illuminate\Support\Facades\Validator;

final class KnowledgeReprocessing
{
    public function select(string $tenant, string $project, array $selection, User $actor): array
    {
        abort_if($project === '*' || $project === '', 422, 'Select one project.');
        $data = Validator::make($selection, [
            'document_ids' => 'sometimes|array|max:500', 'document_ids.*' => 'integer|min:1',
            'source_types' => 'sometimes|array|max:30', 'source_types.*' => 'string|max:100',
            'path_prefix' => 'sometimes|string|max:500', 'after_id' => 'sometimes|integer|min:0',
            'limit' => 'sometimes|integer|min:1|max:500',
        ])->validate();
        $q = KnowledgeDocument::query()->forTenant($tenant)->where('project_key', $project)->where('status', 'active')->where('id', '>', $data['after_id'] ?? 0)->orderBy('id');
        if (! empty($data['document_ids'])) $q->whereIn('id', $data['document_ids']);
        if (! empty($data['source_types'])) $q->whereIn('source_type', $data['source_types']);
        if (! empty($data['path_prefix'])) $q->whereRaw('source_path LIKE ? '.LikeEscaper::ESCAPE_SQL, [LikeEscaper::escape($data['path_prefix']).'%']);
        $documents = $q->limit(($data['limit'] ?? 100) + 1)->get()->filter(fn ($d) => $actor->hasDocumentAccess($d, 'view'));
        $hasMore = $documents->count() > ($data['limit'] ?? 100);
        $documents = $documents->take($data['limit'] ?? 100);
        foreach ($documents as $document) app(KnowledgeEnrichment::class)->schedule($document);
        return ['scheduled_document_ids' => $documents->pluck('id')->all(), 'next_cursor' => $hasMore ? $documents->last()?->id : null];
    }

    public function runs(string $tenant, string $project, ?User $actor = null): array
    {
        $actor ??= auth()->user();
        if (! $actor) return [];
        // Statistics follow the same original-source permission checks as entity views.
        return KnowledgeExtraction::query()->forTenant($tenant)->where('project_key', $project)->orderByDesc('id')->limit(100)
            ->get(['id', 'knowledge_document_id', 'status', 'total_sections', 'processed_sections', 'abstained_sections', 'specification_hash', 'error', 'created_at', 'updated_at'])->map(function ($run) use ($tenant, $project, $actor) {
                $doc = KnowledgeDocument::query()->forTenant($tenant)->where('project_key', $project)->where('status', 'active')->find($run->knowledge_document_id);
                if (! $doc || ! $actor->hasDocumentAccess($doc, 'view')) return null;
                $cost = $latency = 0.0; $accepted = $unconfirmed = $unknown = 0;
                foreach (\App\Models\KnowledgeSection::query()->where('extraction_id', $run->id)->cursor() as $section) {
                    foreach ($section->decisions ?? [] as $decision) {
                        $accepted += (int) (($decision['status'] ?? '') === 'accepted');
                        $unconfirmed += (int) in_array($decision['support'] ?? '', ['proposed', 'contradictory'], true);
                        $value = data_get($decision, 'decision.usage.cost');
                        if (is_numeric($value)) $cost += (float) $value; else $unknown++;
                        $latency += (float) data_get($decision, 'decision.latency_ms', 0);
                    }
                }
                return [...$run->toArray(), 'metrics' => ['accepted' => $accepted, 'unconfirmed' => $unconfirmed, 'known_decision_cost_usd' => $cost, 'unknown_cost_decisions' => $unknown, 'decision_latency_ms' => $latency]];
            })->filter()->values()->all();
    }
}
