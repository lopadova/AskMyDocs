<?php

declare(strict_types=1);

namespace App\Flow\Steps;

use App\Ai\EmbeddingsResponse;
use App\Services\Kb\Canonical\CanonicalParsedDocument;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\Pipeline\ChunkDraft;
use App\Support\Canonical\CanonicalStatus;
use App\Support\Canonical\CanonicalType;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 4 of {@see \App\Flow\Definitions\IngestDocumentFlow}.
 *
 * Wraps the previously-computed parse + chunk + embed outputs and calls
 * the new public {@see DocumentIngestor::persistDrafts()} entry point.
 * Idempotent on `(project_key, source_path, version_hash)` — re-runs
 * with identical content return the existing KnowledgeDocument instead
 * of inserting a duplicate.
 *
 * Compensator: {@see \App\Flow\Compensators\RollbackChunksCompensator}
 * force-deletes the document (cascade removes chunks + canonical nodes
 * + edges) when a downstream step fails.
 *
 * NOT dry-run-safe — the DB is the only artefact this step exists to
 * produce, so under dry-run the engine returns dryRunSkipped().
 */
final class PersistChunksStep implements FlowStepHandler
{
    public function __construct(
        private readonly DocumentIngestor $ingestor,
    ) {}

    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        if ($context->dryRun) {
            return FlowStepResult::dryRunSkipped();
        }

        $parseOutput = $this->requireOutput($context, 'parse-markdown');
        $chunkOutput = $this->requireOutput($context, 'chunk-document');
        $embedOutput = $this->requireOutput($context, 'embed-chunks');

        $title = (string) ($context->input['title'] ?? '');
        if ($title === '') {
            $title = pathinfo((string) ($parseOutput['source_path'] ?? ''), PATHINFO_FILENAME);
        }

        $drafts = array_map(
            static fn (array $d): ChunkDraft => new ChunkDraft(
                text: (string) ($d['text'] ?? ''),
                order: (int) ($d['order'] ?? 0),
                headingPath: (string) ($d['heading_path'] ?? ''),
                metadata: is_array($d['metadata'] ?? null) ? $d['metadata'] : [],
            ),
            (array) ($chunkOutput['chunk_drafts'] ?? []),
        );

        $embeddingResponse = new EmbeddingsResponse(
            embeddings: (array) ($embedOutput['embeddings'] ?? []),
            provider: (string) ($embedOutput['provider'] ?? ''),
            model: (string) ($embedOutput['model'] ?? ''),
            totalTokens: $embedOutput['total_tokens'] ?? null,
        );

        $canonical = $this->rehydrateCanonical($parseOutput['canonical'] ?? null);

        $metadata = is_array($parseOutput['metadata'] ?? null) ? $parseOutput['metadata'] : [];
        // Mirror DocumentIngestor::ingest() — combine connector / external_url
        // / external_id / converter into the metadata bag so the persisted
        // row carries the same shape as the legacy non-Flow path.
        $combinedMetadata = array_merge($metadata, [
            'connector' => 'local',
            'external_url' => null,
            'external_id' => null,
            'converter' => is_array($parseOutput['extraction_meta'] ?? null) ? $parseOutput['extraction_meta'] : [],
        ]);

        $document = $this->ingestor->persistDrafts(
            projectKey: (string) $parseOutput['project_key'],
            sourcePath: (string) $parseOutput['source_path'],
            title: $title,
            mimeType: (string) $parseOutput['mime_type'],
            sourceType: (string) $chunkOutput['source_type'],
            markdown: (string) $parseOutput['markdown'],
            chunkDrafts: $drafts,
            metadata: $combinedMetadata,
            embeddingResponse: $embeddingResponse,
            canonical: $canonical,
        );

        $output = [
            'knowledge_document_id' => (int) $document->id,
            'project_key' => (string) $document->project_key,
            'source_path' => (string) $document->source_path,
            'is_canonical' => (bool) $document->is_canonical,
        ];

        $impact = [
            'document_id' => (int) $document->id,
            'chunks_persisted' => count($drafts),
            'is_canonical' => (bool) $document->is_canonical,
        ];

        return FlowStepResult::success($output, $impact);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOutput(FlowContext $context, string $stepName): array
    {
        $output = $context->stepOutputs[$stepName] ?? null;
        if (! is_array($output)) {
            throw new RuntimeException(
                "PersistChunksStep: missing prior step output [{$stepName}]."
            );
        }
        return $output;
    }

    /**
     * Re-hydrate {@see CanonicalParsedDocument} from the serialised form
     * the parse-markdown step stashed in stepOutputs.
     */
    private function rehydrateCanonical(mixed $serialized): ?CanonicalParsedDocument
    {
        if (! is_array($serialized)) {
            return null;
        }
        $type = isset($serialized['type']) && is_string($serialized['type'])
            ? CanonicalType::tryFrom($serialized['type'])
            : null;
        $status = isset($serialized['status']) && is_string($serialized['status'])
            ? CanonicalStatus::tryFrom($serialized['status'])
            : null;

        return new CanonicalParsedDocument(
            frontmatter: is_array($serialized['frontmatter'] ?? null) ? $serialized['frontmatter'] : [],
            body: (string) ($serialized['body'] ?? ''),
            type: $type,
            status: $status,
            slug: $serialized['slug'] ?? null,
            docId: $serialized['docId'] ?? null,
            retrievalPriority: (int) ($serialized['retrievalPriority'] ?? 50),
            relatedSlugs: $this->stringList($serialized['relatedSlugs'] ?? []),
            supersedesSlugs: $this->stringList($serialized['supersedesSlugs'] ?? []),
            supersededBySlugs: $this->stringList($serialized['supersededBySlugs'] ?? []),
            tags: $this->stringList($serialized['tags'] ?? []),
            owners: $this->stringList($serialized['owners'] ?? []),
            summary: $serialized['summary'] ?? null,
            parseErrors: $this->stringList($serialized['parseErrors'] ?? []),
        );
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        return array_values(array_filter(
            $value,
            static fn ($v): bool => is_string($v),
        ));
    }
}
