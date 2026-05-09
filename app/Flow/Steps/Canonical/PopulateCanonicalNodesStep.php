<?php

declare(strict_types=1);

namespace App\Flow\Steps\Canonical;

use App\Flow\Steps\StepTenantBinder;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KbNode;
use App\Support\Canonical\CanonicalType;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 2 of {@see \App\Flow\Definitions\CanonicalIndexFlow}.
 *
 * Mutates {@see KbNode}: upserts the self-node for the canonical document
 * AND ensures every wikilinked / frontmatter-referenced target slug exists
 * as a (possibly dangling) node in the same project. Records the list of
 * created node ids so the compensator can roll back exactly the nodes
 * THIS step created without disturbing pre-existing graph state.
 *
 * Compensator: {@see \App\Flow\Compensators\RollbackCanonicalNodesCompensator}
 *
 * Dry-run skipped: this step's only artefact is the DB write.
 */
final class PopulateCanonicalNodesStep implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        if ($context->dryRun) {
            return FlowStepResult::dryRunSkipped();
        }

        $loadOutput = $context->stepOutputs['load-document'] ?? null;
        if (! is_array($loadOutput)) {
            throw new RuntimeException(
                'PopulateCanonicalNodesStep: missing prior step output [load-document].'
            );
        }
        if (! ($loadOutput['indexable'] ?? false)) {
            // Upstream short-circuit: nothing to do, propagate state.
            return FlowStepResult::success(
                output: ['skipped' => true, 'reason' => $loadOutput['reason'] ?? 'not_indexable'],
                businessImpact: ['nodes_created' => 0],
            );
        }

        $documentId = (int) $loadOutput['document_id'];
        // Re-load inside the step so we have the full Eloquent model with
        // typed casts and access to $document->frontmatter_json.
        $document = KnowledgeDocument::find($documentId);
        if ($document === null) {
            // Race: doc was deleted between load-document and now. Treat as
            // a hard failure so compensation can run and the operator sees
            // the disappearance.
            throw new RuntimeException(
                "PopulateCanonicalNodesStep: KnowledgeDocument [{$documentId}] vanished mid-flow."
            );
        }

        /** @var list<int> $createdNodeIds */
        $createdNodeIds = [];

        $selfNodeId = $this->upsertSelfNode($document, $createdNodeIds);
        $this->upsertTargetNodesFromFrontmatter($document, $createdNodeIds);
        $this->upsertTargetNodesFromChunks($document, $createdNodeIds);

        return FlowStepResult::success(
            output: [
                'document_id' => $documentId,
                'project_key' => (string) $document->project_key,
                'self_node_id' => $selfNodeId,
                'created_node_ids' => $createdNodeIds,
                'self_slug' => (string) $document->slug,
                'doc_id' => $document->doc_id,
            ],
            businessImpact: [
                'nodes_created' => count($createdNodeIds),
            ],
        );
    }

    /**
     * @param  list<int>  $createdNodeIds
     */
    private function upsertSelfNode(KnowledgeDocument $doc, array &$createdNodeIds): int
    {
        $type = CanonicalType::tryFrom((string) $doc->canonical_type);
        $nodeType = $type !== null ? $type->nodeType() : (string) $doc->canonical_type;

        // R30 — KbNode uses BelongsToTenant; the trait fills tenant_id on
        // create automatically. firstOrCreate (rather than updateOrCreate)
        // tracks whether the row was actually inserted by THIS step so the
        // compensator only deletes nodes it created.
        $existing = KbNode::where('project_key', $doc->project_key)
            ->where('node_uid', $doc->slug)
            ->first();

        if ($existing !== null) {
            // Refresh the metadata in place (canonical status / dangling=false
            // / source_doc_id) but don't mark it as "created by this run".
            $existing->update([
                'node_type' => $nodeType,
                'label' => (string) $doc->title,
                'source_doc_id' => $doc->doc_id,
                'payload_json' => [
                    'dangling' => false,
                    'canonical_status' => $doc->canonical_status,
                ],
            ]);
            return (int) $existing->id;
        }

        $node = KbNode::create([
            'project_key' => (string) $doc->project_key,
            'node_uid' => (string) $doc->slug,
            'node_type' => $nodeType,
            'label' => (string) $doc->title,
            'source_doc_id' => $doc->doc_id,
            'payload_json' => [
                'dangling' => false,
                'canonical_status' => $doc->canonical_status,
            ],
        ]);
        $createdNodeIds[] = (int) $node->id;
        return (int) $node->id;
    }

    /**
     * @param  list<int>  $createdNodeIds
     */
    private function upsertTargetNodesFromFrontmatter(KnowledgeDocument $doc, array &$createdNodeIds): void
    {
        $derived = $this->derivedSlugLists($doc);
        $targets = array_unique(array_merge(
            $derived['related_slugs'],
            $derived['supersedes_slugs'],
            $derived['superseded_by_slugs'],
        ));

        foreach ($targets as $targetSlug) {
            if ($targetSlug === '' || $targetSlug === $doc->slug) {
                continue;
            }
            $this->ensureTargetNode((string) $doc->project_key, $targetSlug, $createdNodeIds);
        }
    }

    /**
     * @param  list<int>  $createdNodeIds
     */
    private function upsertTargetNodesFromChunks(KnowledgeDocument $doc, array &$createdNodeIds): void
    {
        $seen = [];
        KnowledgeChunk::where('knowledge_document_id', $doc->id)
            ->chunkById(200, function ($chunks) use ($doc, &$seen, &$createdNodeIds) {
                foreach ($chunks as $chunk) {
                    foreach ($this->extractWikilinks($chunk->metadata) as $target) {
                        if ($target === '' || $target === $doc->slug || isset($seen[$target])) {
                            continue;
                        }
                        $seen[$target] = true;
                        $this->ensureTargetNode((string) $doc->project_key, $target, $createdNodeIds);
                    }
                }
            });
    }

    /**
     * @param  list<int>  $createdNodeIds
     */
    private function ensureTargetNode(string $projectKey, string $slug, array &$createdNodeIds): void
    {
        $existing = KbNode::where('project_key', $projectKey)
            ->where('node_uid', $slug)
            ->first();
        if ($existing !== null) {
            return;
        }

        $node = KbNode::create([
            'project_key' => $projectKey,
            'node_uid' => $slug,
            'node_type' => 'unknown',
            'label' => $slug,
            'source_doc_id' => null,
            'payload_json' => ['dangling' => true],
        ]);
        $createdNodeIds[] = (int) $node->id;
    }

    /**
     * @return array{related_slugs: list<string>, supersedes_slugs: list<string>, superseded_by_slugs: list<string>}
     */
    private function derivedSlugLists(KnowledgeDocument $doc): array
    {
        $empty = ['related_slugs' => [], 'supersedes_slugs' => [], 'superseded_by_slugs' => []];
        $fm = $doc->frontmatter_json;
        if (! is_array($fm)) {
            return $empty;
        }
        $derived = $fm['_derived'] ?? null;
        if (! is_array($derived)) {
            return $empty;
        }
        return [
            'related_slugs' => $this->asSlugList($derived['related_slugs'] ?? []),
            'supersedes_slugs' => $this->asSlugList($derived['supersedes_slugs'] ?? []),
            'superseded_by_slugs' => $this->asSlugList($derived['superseded_by_slugs'] ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    private function extractWikilinks(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }
        return $this->asSlugList($metadata['wikilinks'] ?? []);
    }

    /**
     * @return list<string>
     */
    private function asSlugList(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }
        return array_values(array_filter($input, static fn ($v) => is_string($v) && $v !== ''));
    }
}
