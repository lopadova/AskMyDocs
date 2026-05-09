<?php

declare(strict_types=1);

namespace App\Flow\Steps\Promotion;

use App\Flow\Steps\StepTenantBinder;
use App\Models\KbCanonicalAudit;
use App\Services\Kb\Canonical\CanonicalParsedDocument;
use App\Services\Kb\Canonical\CanonicalWriter;
use App\Support\Canonical\CanonicalStatus;
use App\Support\Canonical\CanonicalType;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 3 of {@see \App\Flow\Definitions\PromotionFlow} (after the
 * approval gate). Calls {@see CanonicalWriter::write()} to put the
 * canonical markdown bytes on the KB disk.
 *
 * Compensator: {@see \App\Flow\Compensators\DeleteCanonicalMarkdownCompensator}
 * removes the file from disk if a downstream step fails.
 *
 * Also writes a `promoted` audit row to {@see KbCanonicalAudit} so the
 * editorial trail records the operator-approved promotion event with
 * its actor (from the approval payload, when available).
 *
 * Dry-run skipped — disk write is the only artefact.
 */
final class WriteCanonicalMarkdownStep implements FlowStepHandler
{
    public function __construct(
        private readonly CanonicalWriter $writer,
    ) {}

    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        if ($context->dryRun) {
            return FlowStepResult::dryRunSkipped();
        }

        $validateOutput = $context->stepOutputs['validate-frontmatter'] ?? null;
        if (! is_array($validateOutput)) {
            throw new RuntimeException(
                'WriteCanonicalMarkdownStep: missing prior step output [validate-frontmatter].'
            );
        }

        $markdown = (string) ($validateOutput['markdown'] ?? '');
        $parsed = $this->rehydrateCanonical($validateOutput['parsed'] ?? null);
        if ($parsed === null) {
            throw new RuntimeException(
                'WriteCanonicalMarkdownStep: validate-frontmatter produced no parsed payload.'
            );
        }

        // CanonicalWriter::write() throws RuntimeException on disk failure
        // (R4) — let the engine catch it and trigger compensation if any.
        $relativePath = $this->writer->write($parsed, $markdown);

        $projectKey = (string) ($context->input['project_key'] ?? '');
        $this->writePromotionAudit($projectKey, $parsed, $relativePath, $context);

        return FlowStepResult::success(
            output: [
                'project_key' => $projectKey,
                'relative_path' => $relativePath,
                'disk' => (string) config('kb.sources.disk', 'kb'),
                'slug' => $parsed->slug,
                'doc_id' => $parsed->docId,
            ],
            businessImpact: [
                'relative_path' => $relativePath,
                'slug' => $parsed->slug,
            ],
        );
    }

    private function writePromotionAudit(
        string $projectKey,
        CanonicalParsedDocument $parsed,
        string $relativePath,
        FlowContext $context,
    ): void {
        if (! (bool) config('kb.canonical.audit_enabled', true)) {
            return;
        }
        // The approval-gate step's output carries the actor info that the
        // engine populated when the approval was consumed. We surface it
        // in the audit row so the editorial trail records WHO approved.
        $approvalOutput = $context->stepOutputs['approval-gate'] ?? [];
        $actorPayload = is_array($approvalOutput) ? ($approvalOutput['actor'] ?? null) : null;
        $actor = is_array($actorPayload) && isset($actorPayload['name']) && is_string($actorPayload['name'])
            ? $actorPayload['name']
            : 'flow:kb.promote';

        KbCanonicalAudit::create([
            'project_key' => $projectKey,
            'doc_id' => $parsed->docId,
            'slug' => $parsed->slug,
            'event_type' => 'promoted',
            'actor' => $actor,
            'before_json' => null,
            'after_json' => [
                'canonical_type' => $parsed->type?->value,
                'canonical_status' => $parsed->status?->value,
                'retrieval_priority' => $parsed->retrievalPriority,
            ],
            'metadata_json' => [
                'relative_path' => $relativePath,
                'flow_run_id' => $context->flowRunId,
            ],
        ]);
    }

    /**
     * @param  mixed  $serialized
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
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, static fn ($v): bool => is_string($v)));
    }
}
