<?php

declare(strict_types=1);

namespace App\Services\Kb\Knowledge;

use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Kb\Retrieval\RetrievalFilters;

final class KnowledgeDocumentContext
{
    public function read(string $tenant, string $project, int $documentId, ?User $actor, int $cursor = 0, int $limit = 1, ?RetrievalFilters $filters = null): ?array
    {
        if (! $actor || $project === '' || $project === '*') return null;
        $doc = KnowledgeDocument::query()->forTenant($tenant)->where('project_key', $project)->where('status', 'active')->find($documentId);
        if (! $doc || ! $actor->hasDocumentAccess($doc, 'view')) return null;
        $all = app(KnowledgeSource::class)->sections(app(KnowledgeSource::class)->parts($doc));
        $sections = [];
        $map = [];
        foreach ($all as $section) {
            if ($filters && (! $section['chunk_id'] || ! app(\App\Services\Kb\KbSearchService::class)->allowsMemoryCandidate($doc->id, $section['chunk_id'], $filters))) continue;
            $map[] = ['ordinal' => $section['ordinal'], 'heading' => $section['heading'], 'chunk_id' => $section['chunk_id']];
            if ($section['ordinal'] >= $cursor && count($sections) < max(1, min(3, $limit))) $sections[] = $section;
        }
        $last = $sections === [] ? null : end($sections)['ordinal'];
        $more = $last !== null && collect($map)->contains(fn ($s) => $s['ordinal'] > $last);
        return ['document_id' => $doc->id, 'title' => $doc->title, 'source_path' => $doc->source_path, 'sections' => $sections,
            'section_map' => array_slice($map, 0, 200), 'next_cursor' => $more ? $last + 1 : null,
            'coverage' => ['scope' => 'indexed_available_text', 'original_completeness' => 'not_assumed', 'total_sections' => count($map), 'read_sections' => array_column($sections, 'ordinal'), 'partial' => count($sections) < count($map), 'map_truncated' => count($map) > 200]];
    }
}
