<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KbCollection;
use App\Models\KbCollectionMember;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class EvaluateCollectionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int,int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $knowledgeDocumentId,
        public readonly string $tenantId = 'default',
    ) {
        $this->onQueue(config('kb.ingest.queue', 'kb-ingest'));
    }

    public static function dispatchForCurrentTenant(int $knowledgeDocumentId): \Illuminate\Foundation\Bus\PendingDispatch
    {
        $tenantId = app(TenantContext::class)->current();

        return self::dispatch($knowledgeDocumentId, $tenantId);
    }

    public function handle(TenantContext $tenantContext): void
    {
        $previousTenant = $tenantContext->current();
        try {
            $tenantContext->set($this->tenantId);

            $document = KnowledgeDocument::query()
                ->forTenant($this->tenantId)
                ->find($this->knowledgeDocumentId);

            if ($document === null) {
                return;
            }

            $collections = KbCollection::query()
                ->forTenant($this->tenantId)
                ->get();

            foreach ($collections as $collection) {
                if (! $this->matchesStaticCriteria($collection->criteria ?? [], $document)) {
                    continue;
                }

                $existing = KbCollectionMember::query()
                    ->forTenant($this->tenantId)
                    ->where('collection_id', $collection->id)
                    ->where('knowledge_document_id', $document->id)
                    ->first();

                // Manual exclusions (W5.4) must remain sticky and block
                // automatic evaluator re-inserts.
                if ($existing !== null && $existing->manually_excluded) {
                    continue;
                }

                KbCollectionMember::query()->updateOrCreate(
                    [
                        'tenant_id' => $this->tenantId,
                        'collection_id' => $collection->id,
                        'knowledge_document_id' => $document->id,
                    ],
                    [
                        'reason' => 'static_match',
                        'semantic_score' => null,
                        'manually_excluded' => false,
                    ],
                );
            }
        } finally {
            $tenantContext->set($previousTenant);
        }
    }

    /**
     * @param  array<string,mixed>  $criteria
     */
    private function matchesStaticCriteria(array $criteria, KnowledgeDocument $document): bool
    {
        $projects = $this->stringList($criteria['projects'] ?? null);
        if ($projects !== [] && ! in_array((string) $document->project_key, $projects, true)) {
            return false;
        }

        $canonicalTypes = $this->stringList($criteria['canonical_types'] ?? null);
        if ($canonicalTypes !== []) {
            $type = is_string($document->canonical_type) ? $document->canonical_type : '';
            if ($type === '' || ! in_array($type, $canonicalTypes, true)) {
                return false;
            }
        }

        $slugGlobs = $this->stringList($criteria['slug_globs'] ?? null);
        if ($slugGlobs !== []) {
            $slug = is_string($document->slug) ? $document->slug : '';
            if ($slug === '') {
                return false;
            }

            $matched = false;
            foreach ($slugGlobs as $glob) {
                if (Str::is($glob, $slug)) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                return false;
            }
        }

        $criteriaTags = $this->stringList($criteria['tags'] ?? null);
        if ($criteriaTags !== []) {
            $documentTags = $this->extractDocumentTags($document);
            if ($documentTags === [] || array_intersect($criteriaTags, $documentTags) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function extractDocumentTags(KnowledgeDocument $document): array
    {
        $frontmatter = is_array($document->frontmatter_json) ? $document->frontmatter_json : [];
        $metadata = is_array($document->metadata) ? $document->metadata : [];

        $tags = [];
        foreach ($this->stringList($frontmatter['tags'] ?? null) as $tag) {
            $tags[] = $tag;
        }
        foreach ($this->stringList($metadata['tags'] ?? null) as $tag) {
            $tags[] = $tag;
        }

        return array_values(array_unique($tags));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $trimmed = trim($item);
            if ($trimmed === '') {
                continue;
            }
            $out[] = $trimmed;
        }

        return array_values(array_unique($out));
    }
}

