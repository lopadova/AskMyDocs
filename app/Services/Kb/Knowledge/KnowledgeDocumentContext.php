<?php

declare(strict_types=1);

namespace App\Services\Kb\Knowledge;

use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\RetrievalFilters;

final class KnowledgeDocumentContext
{
    /** Navigation metadata only. Rebuild it from the authorized document before returning it to a session. */
    public function coordinate(string $tenant, string $project, int $documentId, int $chunkId, ?User $actor, RetrievalFilters $filters,
        ?string $documentVersion = null, ?string $chunkHash = null): ?array
    {
        if ($chunkId < 1 || ! $actor || $project === '' || $project === '*') return null;
        $doc = KnowledgeDocument::query()->forTenant($tenant)->where('project_key', $project)->where('status', 'active')->find($documentId);
        if (! $doc || ! $actor->hasDocumentAccess($doc, 'view')
            || ! app(KbSearchService::class)->allowsMemoryCandidate($documentId, $chunkId, $filters)) return null;
        $version = $doc->version_hash ?? $doc->document_hash;
        if ($documentVersion !== null && $documentVersion !== $version) return null;
        $chunk = $doc->chunks()->forTenant($tenant)->whereKey($chunkId)->first(['id', 'chunk_hash', 'heading_path']);
        if ($chunk === null || ($chunkHash !== null && $chunkHash !== $chunk->chunk_hash)) return null;

        $heading = is_array($chunk->heading_path) ? implode(' / ', $chunk->heading_path) : $chunk->heading_path;
        return ['document_id' => $doc->id, 'title' => mb_substr((string) $doc->title, 0, 250),
            'chunk_id' => $chunk->id, 'heading' => mb_substr((string) $heading, 0, 250),
            'document_version' => $version, 'chunk_hash' => $chunk->chunk_hash];
    }

    public function read(string $tenant, string $project, int $documentId, ?User $actor, int $cursor = 0, int $limit = 1,
        ?RetrievalFilters $filters = null, ?string $sourceVersion = null): ?array
    {
        if (! $actor || $project === '' || $project === '*') return null;
        $doc = KnowledgeDocument::query()->forTenant($tenant)->where('project_key', $project)->where('status', 'active')->find($documentId);
        if (! $doc || ! $actor->hasDocumentAccess($doc, 'view')) return null;
        $source = app(KnowledgeSource::class);
        try {
            $parts = $source->parts($doc);
        } catch (\RuntimeException $exception) {
            // A missing or changed email body cannot be replaced by its generated triage card.
            if (! data_get($doc->metadata, 'email_triage.evaluation_id')) throw $exception;
            return null;
        }
        $version = $source->version($doc, $parts);
        if ($sourceVersion !== null && ! hash_equals($version, $sourceVersion)) return null;
        $sections = $map = [];
        $allowed = $filters === null ? null : array_fill_keys(app(KbSearchService::class)
            ->allowedMemoryChunkIds($doc->id, array_values(array_unique(array_filter(array_column($parts, 'chunk_id')))), $filters), true);
        foreach ($source->sections($parts) as $section) {
            if ($allowed !== null && ! isset($allowed[$section['chunk_id']])) continue;
            $map[] = ['ordinal' => $section['ordinal'], 'heading' => $section['heading'], 'chunk_id' => $section['chunk_id']];
            if ($section['ordinal'] >= $cursor && count($sections) < max(1, min(3, $limit))) $sections[] = $section;
        }
        $related = [];
        if ($source->navigationOnly($doc)) {
            $scope = $filters ?? new RetrievalFilters(projectKeys: [$project]);
            $anchor = $doc->chunks()->orderBy('chunk_order')->value('id');
            if (! $anchor || ! app(KbSearchService::class)->allowsMemoryCandidate($doc->id, $anchor, $scope)) return null;
            $related = $this->linkedOriginals($doc, $actor, $scope);
        } elseif ($map === []) {
            return null;
        }
        $last = $sections === [] ? null : end($sections)['ordinal'];
        $more = $last !== null && collect($map)->contains(fn ($s) => $s['ordinal'] > $last);
        $visibleMap = array_values(array_filter($map, fn ($entry) => $entry['ordinal'] >= $cursor));
        return ['document_id' => $doc->id, 'title' => $doc->title, 'source_path' => $doc->source_path, 'source_version' => $version,
            'sections' => $sections, 'section_map' => array_slice($visibleMap, 0, 200), 'next_cursor' => $more ? $last + 1 : null,
            ...$source->navigationOnly($doc) ? ['linked_originals' => $related] : [],
            'coverage' => ['scope' => 'indexed_available_text', 'original_completeness' => 'not_assumed', 'total_sections' => count($map),
                'read_sections' => array_column($sections, 'ordinal'), 'partial' => $source->navigationOnly($doc) || count($sections) < count($map),
                'map_truncated' => count($visibleMap) > 200, 'next_map_cursor' => $visibleMap[200]['ordinal'] ?? null]];
    }

    /** The wiki compiler records its original sources in related. Text on a generated page is never returned as original evidence. */
    public function linkedOriginals(KnowledgeDocument $page, User $actor, RetrievalFilters $filters): array
    {
        $slugs = array_slice(array_filter((array) data_get($page->frontmatter_json, 'related', []), 'is_string'), 0, 32);
        if ($slugs === []) return [];
        $originals = [];
        foreach (KnowledgeDocument::query()->forTenant($page->tenant_id)->where('project_key', $page->project_key)
            ->where('status', 'active')->whereIn('slug', $slugs)->orderBy('id')->limit(32)->get() as $doc) {
            if (app(KnowledgeSource::class)->navigationOnly($doc) || data_get($doc->metadata, 'generated_fixture')) continue;
            $chunkId = (int) $doc->chunks()->orderBy('chunk_order')->value('id');
            $coordinate = $this->coordinate($page->tenant_id, $page->project_key, $doc->id, $chunkId, $actor, $filters);
            if ($coordinate !== null) $originals[] = $coordinate;
        }
        return $originals;
    }
}
