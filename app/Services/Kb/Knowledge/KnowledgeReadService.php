<?php

declare(strict_types=1);

namespace App\Services\Kb\Knowledge;

use App\Models\KnowledgeDocument;
use App\Models\KnowledgeEntity;
use App\Models\KnowledgeExtraction;
use App\Models\KnowledgeObservation;
use App\Models\KnowledgeSection;
use App\Models\User;
use App\Services\Chat\Reasoning\EvidenceProjector;
use App\Services\Kb\Retrieval\RetrievalFilters;

final class KnowledgeReadService
{
    public function __construct(private readonly KnowledgeSource $sources, private readonly KnowledgeSpecificationService $specifications) {}

    /** No labels, counts or relationships are returned before an authorized original is verified. */
    public function search(string $tenant, string $project, string $query, ?User $actor, ?string $namespace = null, int $limit = 20, ?RetrievalFilters $filters = null): array
    {
        if ($project === '' || $project === '*' || $actor === null) return ['entities' => [], 'coverage' => ['partial' => true, 'reason' => 'actor_and_project_required']];
        $entities = $exactEntities = [];
        $checked = 0;
        $scanLimit = 1000;
        $q = KnowledgeEntity::query()->forTenant($tenant)->where('project_key', $project)->orderBy('id');
        if ($namespace !== null) $q->where('namespace', $namespace);
        if ($query !== '') {
            preg_match_all('/[\pL\pN][\pL\pN._\/-]{0,254}/u', $query, $tokens);
            $identifiers = array_values(array_unique([$query, ...array_slice($tokens[0], 0, 30)]));
            $terms = array_values(array_filter(array_slice($tokens[0], 0, 12), fn ($t) => mb_strlen($t) >= 4));
            $q->where(function ($match) use ($identifiers, $terms, $tenant, $project) {
                $match->whereIn('identifier', $identifiers);
                foreach ($terms as $term) {
                    $pattern = \App\Support\LikeEscaper::contains(mb_strtolower($term));
                    $match->orWhereRaw('LOWER(type) LIKE ? '.\App\Support\LikeEscaper::ESCAPE_SQL, [$pattern]);
                    $match->orWhereIn('id', KnowledgeObservation::query()->forTenant($tenant)->where('project_key', $project)
                        ->whereRaw('LOWER(quote) LIKE ? '.\App\Support\LikeEscaper::ESCAPE_SQL, [$pattern])->select('entity_id'));
                }
            });
            $q->reorder()->orderByRaw('CASE WHEN identifier IN ('.implode(',', array_fill(0, count($identifiers), '?')).') THEN 0 ELSE 1 END', $identifiers)->orderBy('id');
        }
        foreach ($q->cursor() as $entity) {
            if (++$checked > $scanLimit) break;
            // Exact identifiers retain case and leading zeroes; a general query may mention the identifier.
            $identifierMatch = $query === $entity->identifier || ($query !== '' && EvidenceProjector::contains($query, $entity->identifier));
            if ($exactEntities !== [] && ! $identifierMatch) break;
            $identityMatch = $query === '' || $query === $entity->identifier || EvidenceProjector::contains($query, $entity->identifier) || str_contains(mb_strtolower($entity->type), mb_strtolower($query));
            $context = $this->context($tenant, $project, $entity->id, $actor, null, $identityMatch ? 1 : 10, $filters);
            if ($context === null) continue;
            if (! $identityMatch) {
                preg_match_all('/[\pL\pN_-]{4,}/u', mb_strtolower($query), $terms);
                $text = mb_strtolower(implode(' ', array_column($context['timeline'], 'quote')));
                if (! array_filter($terms[0], fn ($term) => str_contains($text, $term))) continue;
            }
            if ($identifierMatch) $exactEntities[] = $context['entity']; else $entities[] = $context['entity'];
            if (max(count($entities), count($exactEntities)) >= max(1, min(100, $limit))) break;
        }
        $entities = $exactEntities !== [] ? $exactEntities : $entities;
        return ['entities' => $entities, 'coverage' => ['partial' => $checked > $scanLimit || count($entities) >= $limit, 'scope' => 'authorized_contributions']];
    }

    public function context(string $tenant, string $project, int $entityId, ?User $actor, ?int $cursor = null, int $limit = 30, ?RetrievalFilters $filters = null): ?array
    {
        if ($actor === null || $project === '' || $project === '*') return null;
        $entity = KnowledgeEntity::query()->forTenant($tenant)->where('project_key', $project)->find($entityId);
        if (! $entity) return null;
        $events = [];
        $limit = max(1, min(100, $limit));
        $scanned = 0;
        $last = $cursor ?? 0;
        $hasMore = false;
        $query = KnowledgeObservation::query()->forTenant($tenant)->where('project_key', $project)->where(fn ($q) => $q->where('entity_id', $entity->id)->orWhere('target_entity_id', $entity->id))->whereIn('extraction_id', $this->publishedRunIds($tenant, $project))->where('id', '>', $last)->orderBy('id');
        // Bound work, not authorized counts: a hidden observation never changes a displayed total.
        $documents = $runs = $material = $latest = [];
        foreach ($query->cursor() as $observation) {
            if (++$scanned > 1000) { $hasMore = true; break; }
            $last = $observation->id;
            $event = $this->verified($observation, $actor, $filters, $documents, $runs, $material, $latest);
            if ($event === null) continue;
            $events[] = $event;
            if (count($events) >= $limit) { $hasMore = true; break; }
        }
        if ($events === []) return null;
        // Summary is rebuilt from this authorized page only. No persisted multi-source summary leaks.
        $summary = array_values(array_unique(array_map(fn ($event) => $event['support'].': '.($event['subject']['identifier'] ?? '').' · '.$event['value'], $events)));
        $relations = [];
        foreach ($events as $event) {
            if ($event['target'] !== null) $relations[] = ['type' => $event['relation'], 'direction' => $event['subject']['id'] === $entity->id ? 'outgoing' : 'incoming', 'entity' => $event['subject']['id'] === $entity->id ? $event['target'] : $event['subject'], 'evidence_id' => $event['id']];
        }
        return ['entity' => $this->identity($entity), 'summary' => $summary, 'timeline' => $events, 'relations' => $relations,
            'next_cursor' => $hasMore ? $last : null, 'coverage' => ['partial' => $hasMore || collect($events)->contains(fn ($event) => $event['coverage']['partial']),
                'scope' => 'authorized_contributions', 'ordering' => 'observation_id', 'stale_revision' => collect($events)->contains('stale_revision', true)]];
    }

    public function observation(string $tenant, string $project, int $id, ?User $actor, ?RetrievalFilters $filters = null): ?array
    {
        if ($actor === null) return null;
        $observation = KnowledgeObservation::query()->forTenant($tenant)->where('project_key', $project)->find($id);
        if (! $observation) return null;
        $documents = $runs = $material = $latest = [];
        return $this->verified($observation, $actor, $filters, $documents, $runs, $material, $latest);
    }

    private function verified(KnowledgeObservation $observation, User $actor, ?RetrievalFilters $filters, array &$documents, array &$runs, array &$material, array &$latest): ?array
    {
        $run = $runs[$observation->extraction_id] ??= KnowledgeExtraction::query()->forTenant($observation->tenant_id)->where('project_key', $observation->project_key)->find($observation->extraction_id);
        if (! $run || ! in_array($run->status, ['complete', 'partial'], true)) return null;
        $document = $documents[$run->knowledge_document_id] ??= KnowledgeDocument::query()->forTenant($observation->tenant_id)->where('project_key', $observation->project_key)->where('status', 'active')->find($run->knowledge_document_id);
        if (! $document || ! $actor->hasDocumentAccess($document, 'view')) return null;
        $newest = $latest[$document->id] ??= KnowledgeExtraction::query()->forTenant($observation->tenant_id)->where('knowledge_document_id', $document->id)->whereIn('status', ['complete', 'partial'])->max('id');
        if ((int) $newest !== $run->id) return null;
        $section = KnowledgeSection::query()->where('extraction_id', $run->id)->find($observation->section_id);
        if (! $section || ! hash_equals($section->text_hash, hash('sha256', $section->text))
            || mb_substr($section->text, $observation->quote_start, mb_strlen($observation->quote)) !== $observation->quote) return null;
        if ($filters !== null && (! $section->chunk_id || ! app(\App\Services\Kb\KbSearchService::class)->allowsMemoryCandidate($document->id, $section->chunk_id, $filters))) return null;
        try {
            $version = $material[$document->id] ??= $this->sources->version($document, $this->sources->parts($document));
            if (! hash_equals($run->source_version, $version)) return null;
        } catch (\Throwable) { return null; }
        $target = $observation->target_entity_id ? KnowledgeEntity::query()->forTenant($document->tenant_id)->where('project_key', $document->project_key)->find($observation->target_entity_id) : null;
        $subject = KnowledgeEntity::query()->forTenant($document->tenant_id)->where('project_key', $document->project_key)->find($observation->entity_id);
        if (! $subject) return null;
        $current = $this->specifications->effective($document->tenant_id, $document->project_key);
        return ['id' => $observation->id, 'subject' => $this->identity($subject), 'property' => $observation->property, 'value' => $observation->value, 'support' => $observation->support,
            'quote' => $observation->quote, 'event_at' => $observation->event_at?->toIso8601String(), 'rule' => $observation->rule,
            'relation' => $observation->relation, 'target' => $target ? $this->identity($target) : null,
            'source' => ['document_id' => $document->id, 'title' => $document->title, 'source_path' => $document->source_path, 'source_type' => $document->source_type,
                'chunk_id' => $section->chunk_id, 'section' => $section->ordinal, 'heading' => $section->heading, 'version' => $run->source_version, 'quote_start' => $observation->quote_start],
            'revision_id' => $run->contract['revision_id'], 'stale_revision' => ($current['hash'] ?? null) !== $run->specification_hash,
            'coverage' => ['scope' => 'indexed_available_text', 'original_completeness' => 'not_assumed', 'total_sections' => $run->total_sections, 'processed_sections' => $run->processed_sections, 'abstained_sections' => $run->abstained_sections,
                'partial' => $run->status !== 'complete'], 'evidence_hash' => hash('sha256', $observation->quote), 'independent_support_key' => hash('sha256', $observation->quote)];
    }

    private function publishedRunIds(string $tenant, string $project): \Illuminate\Database\Eloquent\Builder
    {
        return KnowledgeExtraction::query()->forTenant($tenant)->where('project_key', $project)->whereIn('status', ['complete', 'partial'])
            ->whereIn('knowledge_document_id', KnowledgeDocument::query()->forTenant($tenant)->where('project_key', $project)->where('status', 'active')->select('id'))
            ->whereNotExists(function ($newer) use ($tenant, $project) {
                $newer->selectRaw('1')->from('knowledge_extractions as newer')->where('newer.tenant_id', $tenant)->where('newer.project_key', $project)
                    ->whereIn('newer.status', ['complete', 'partial'])->whereColumn('newer.knowledge_document_id', 'knowledge_extractions.knowledge_document_id')
                    ->whereColumn('newer.id', '>', 'knowledge_extractions.id');
            })->select('id');
    }

    private function identity(KnowledgeEntity $entity): array
    {
        return ['id' => $entity->id, 'type' => $entity->type, 'namespace' => $entity->namespace, 'identifier' => $entity->identifier, 'project_key' => $entity->project_key];
    }

    /** Original passages suitable for the existing search/citation contracts. */
    public function candidates(string $tenant, ?string $project, string $query, ?User $actor, ?RetrievalFilters $filters = null, int $limit = 6): \Illuminate\Support\Collection
    {
        if (! $project || ! $actor) return collect();
        $result = $this->search($tenant, $project, $query, $actor, limit: 3, filters: $filters);
        $out = [];
        foreach ($result['entities'] as $entity) {
            $context = $this->context($tenant, $project, $entity['id'], $actor, limit: $limit, filters: $filters);
            foreach ($context['timeline'] ?? [] as $event) {
                $source = $event['source'];
                if (! $source['chunk_id']) continue;
                $out[] = ['chunk_id' => $source['chunk_id'], 'chunk_text' => $event['quote'], 'chunk_hash' => $event['evidence_hash'],
                    'heading_path' => $source['heading'], 'project_key' => $project, 'vector_score' => 1.0, 'rerank_score' => 1.0,
                    'knowledge_observation_id' => $event['id'], 'metadata' => ['knowledge' => ['entity_id' => $entity['id'], 'coverage' => $event['coverage'], 'support' => $event['support'], 'stale_revision' => $event['stale_revision']]],
                    'document' => ['id' => $source['document_id'], 'title' => $source['title'], 'source_path' => $source['source_path'], 'source_type' => $source['source_type'], 'project_key' => $project]];
                if (count($out) >= $limit) break 2;
            }
        }
        return collect($out);
    }
}
