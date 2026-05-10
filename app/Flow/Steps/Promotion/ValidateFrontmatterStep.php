<?php

declare(strict_types=1);

namespace App\Flow\Steps\Promotion;

use App\Flow\Steps\StepTenantBinder;
use App\Services\Kb\Canonical\CanonicalParser;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 1 of {@see \App\Flow\Definitions\PromotionFlow}.
 *
 * Parses the candidate markdown via {@see CanonicalParser} and validates
 * the resulting frontmatter against the schema. On failure throws a
 * {@see RuntimeException} so the engine marks the run failed and no
 * approval token is issued (the operator never sees a "would you like to
 * promote this invalid doc?" prompt).
 *
 * Read-only: dry-run runs the parse + validate so operators get real
 * feedback without anything being staged.
 */
final class ValidateFrontmatterStep implements FlowStepHandler
{
    public function __construct(
        private readonly CanonicalParser $parser,
    ) {}

    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        $markdown = (string) ($context->input['markdown'] ?? '');
        if (trim($markdown) === '') {
            // R14 — fail loud rather than dispatch an empty draft.
            throw new RuntimeException(
                'ValidateFrontmatterStep: input["markdown"] must be a non-empty string.'
            );
        }

        $parsed = $this->parser->parse($markdown);
        if ($parsed === null) {
            throw new RuntimeException(
                'ValidateFrontmatterStep: no YAML frontmatter block detected at the top of the document.'
            );
        }

        $validation = $this->parser->validate($parsed);
        if (! $validation->valid) {
            $summary = $this->summariseErrors($validation->errors);
            throw new RuntimeException(
                'ValidateFrontmatterStep: invalid canonical frontmatter — '.$summary
            );
        }

        return FlowStepResult::success(
            output: [
                // Serialise the parsed DTO so it survives FlowContext::stepOutputs
                // (array<string, array<string, mixed>>). Re-hydrated by
                // WriteCanonicalMarkdownStep when needed.
                'parsed' => [
                    'frontmatter' => $parsed->frontmatter,
                    'body' => $parsed->body,
                    'type' => $parsed->type?->value,
                    'status' => $parsed->status?->value,
                    'slug' => $parsed->slug,
                    'docId' => $parsed->docId,
                    'retrievalPriority' => $parsed->retrievalPriority,
                    'relatedSlugs' => $parsed->relatedSlugs,
                    'supersedesSlugs' => $parsed->supersedesSlugs,
                    'supersededBySlugs' => $parsed->supersededBySlugs,
                    'tags' => $parsed->tags,
                    'owners' => $parsed->owners,
                    'summary' => $parsed->summary,
                    'parseErrors' => $parsed->parseErrors,
                ],
                'markdown' => $markdown,
            ],
            businessImpact: [
                'slug' => $parsed->slug,
                'doc_id' => $parsed->docId,
                'canonical_type' => $parsed->type?->value,
            ],
        );
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function summariseErrors(array $errors): string
    {
        $parts = [];
        foreach ($errors as $field => $msgs) {
            foreach ($msgs as $msg) {
                $parts[] = "[{$field}] {$msg}";
            }
        }
        return $parts === [] ? 'unknown validation error' : implode('; ', $parts);
    }
}
