<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KbCollection;
use App\Models\KbCollectionMember;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\Retrieval\CosineCalculator;
use App\Support\TenantContext;
use InvalidArgumentException;
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
        public readonly ?int $collectionId = null,
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
                ->forTenant($this->tenantId);
            if ($this->collectionId !== null) {
                $collections->where('id', $this->collectionId);
            }
            $collections = $collections->get();

            $documentEmbedding = null;
            $documentEmbeddingComputed = false;

            foreach ($collections as $collection) {
                $staticMatch = $this->matchesStaticCriteria($collection->criteria ?? [], $document);
                if (! $documentEmbeddingComputed && is_array($collection->semantic_prompt_embedding) && $collection->semantic_prompt_embedding !== []) {
                    $documentText = $this->semanticDocumentText($document);
                    $documentEmbedding = $this->semanticDocumentEmbedding($documentText);
                    $documentEmbeddingComputed = true;
                }
                $semanticScore = $this->semanticScore($collection, $documentEmbedding);
                $threshold = (float) $collection->threshold;
                $semanticMatch = $semanticScore !== null && $semanticScore >= $threshold;

                if (! $staticMatch && ! $semanticMatch) {
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
                        'reason' => $staticMatch ? 'static_match' : 'semantic_match',
                        'semantic_score' => $semanticScore,
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

    /**
     * @param  list<float>|null  $documentEmbedding
     */
    private function semanticScore(KbCollection $collection, ?array $documentEmbedding): ?float
    {
        $promptEmbedding = $collection->semantic_prompt_embedding;
        if (! is_array($promptEmbedding) || $promptEmbedding === []) {
            return null;
        }

        if (! is_array($documentEmbedding) || $documentEmbedding === []) {
            return null;
        }

        try {
            return app(CosineCalculator::class)->similarity($promptEmbedding, $documentEmbedding);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function semanticDocumentText(KnowledgeDocument $document): string
    {
        $title = is_string($document->title) ? trim($document->title) : '';
        $chunkText = (string) KnowledgeChunk::query()
            ->forTenant($this->tenantId)
            ->where('knowledge_document_id', $document->id)
            ->orderBy('chunk_order')
            ->value('chunk_text');

        $chunkText = trim($chunkText);

        return trim($title . "\n\n" . $chunkText);
    }

    /**
     * @return list<float>|null
     */
    private function semanticDocumentEmbedding(string $documentText): ?array
    {
        if ($documentText === '') {
            return null;
        }

        $documentEmbedding = app(EmbeddingCacheService::class)->generate([$documentText])->embeddings[0] ?? null;
        if (! is_array($documentEmbedding) || $documentEmbedding === []) {
            return null;
        }

        return $documentEmbedding;
    }
}

