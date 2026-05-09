<?php

declare(strict_types=1);

namespace App\Flow\Steps;

use App\Services\Kb\Canonical\CanonicalParser;
use App\Services\Kb\Pipeline\PipelineRegistry;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Support\KbPath;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 1 of {@see \App\Flow\Definitions\IngestDocumentFlow}.
 *
 * Reads the document bytes from the configured Laravel disk, runs the
 * Converter registered for the MIME type, and (separately) parses any
 * canonical frontmatter so downstream steps inherit a typed
 * `CanonicalParsedDocument` (or `null` for non-canonical docs / invalid
 * frontmatter — see R10 graceful-degrade).
 *
 * Dry-run: does the read + convert + parse so the operator gets real
 * "would this fail at convert time?" signal, but skips embedding +
 * persistence (those steps' own dry-run handling kicks in / they short-
 * circuit).
 */
final class ParseMarkdownStep implements FlowStepHandler
{
    public function __construct(
        private readonly PipelineRegistry $registry,
        private readonly CanonicalParser $canonicalParser,
    ) {}

    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        $input = $context->input;
        $projectKey = (string) ($input['project_key'] ?? '');
        $relativePath = (string) ($input['source_path'] ?? '');
        $disk = (string) ($input['disk'] ?? '');
        $mimeType = (string) ($input['mime_type'] ?? 'text/markdown');
        $rawMetadata = $input['metadata'] ?? [];
        $metadata = is_array($rawMetadata) ? $rawMetadata : [];

        $normalizedPath = KbPath::normalize($relativePath);
        $fullPath = $this->resolveStoragePath($normalizedPath);
        $storage = Storage::disk($disk);

        if (! $storage->exists($fullPath)) {
            // R4 / R14 — fail loud, do NOT return success-with-empty.
            throw new RuntimeException(
                "ParseMarkdownStep: file not found on disk [{$disk}]: {$fullPath}"
            );
        }

        $bytes = $storage->get($fullPath);
        if ($bytes === null) {
            throw new RuntimeException(
                "ParseMarkdownStep: failed to read file [{$disk}]: {$fullPath}"
            );
        }

        $combinedMetadata = array_merge($metadata, [
            'disk' => $disk,
            'prefix' => (string) config('kb.sources.path_prefix', ''),
        ]);

        $source = new SourceDocument(
            sourcePath: $normalizedPath,
            mimeType: $mimeType,
            bytes: (string) $bytes,
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: $combinedMetadata,
        );

        $converter = $this->registry->resolveConverter($mimeType);
        $converted = $converter->convert($source);

        $canonical = $this->tryParseCanonical($projectKey, $normalizedPath, $converted->markdown);

        $output = [
            // Serialise the DTOs so they survive context propagation
            // (FlowContext::stepOutputs is array<string, array<string, mixed>>).
            // We re-hydrate when downstream steps need the full object back.
            'project_key' => $projectKey,
            'source_path' => $normalizedPath,
            'mime_type' => $mimeType,
            'markdown' => $converted->markdown,
            'media_items' => $converted->mediaItems,
            'extraction_meta' => $converted->extractionMeta,
            'metadata' => $combinedMetadata,
            'canonical' => $canonical === null ? null : [
                'frontmatter' => $canonical->frontmatter,
                'body' => $canonical->body,
                'type' => $canonical->type?->value,
                'status' => $canonical->status?->value,
                'slug' => $canonical->slug,
                'docId' => $canonical->docId,
                'retrievalPriority' => $canonical->retrievalPriority,
                'relatedSlugs' => $canonical->relatedSlugs,
                'supersedesSlugs' => $canonical->supersedesSlugs,
                'supersededBySlugs' => $canonical->supersededBySlugs,
                'tags' => $canonical->tags,
                'owners' => $canonical->owners,
                'summary' => $canonical->summary,
                'parseErrors' => $canonical->parseErrors,
            ],
        ];

        $impact = [
            'mime_type' => $mimeType,
            'markdown_bytes' => strlen($converted->markdown),
            'is_canonical_candidate' => $canonical !== null,
        ];

        if ($context->dryRun) {
            return new FlowStepResult(
                success: true,
                output: $output,
                businessImpact: $impact,
            );
        }

        return FlowStepResult::success($output, $impact);
    }

    private function resolveStoragePath(string $normalizedRelativePath): string
    {
        $prefix = (string) config('kb.sources.path_prefix', '');
        return ltrim(trim($prefix, '/').'/'.ltrim($normalizedRelativePath, '/'), '/');
    }

    private function tryParseCanonical(string $projectKey, string $sourcePath, string $markdown): ?\App\Services\Kb\Canonical\CanonicalParsedDocument
    {
        if (! (bool) config('kb.canonical.enabled', true)) {
            return null;
        }
        $parsed = $this->canonicalParser->parse($markdown);
        if ($parsed === null) {
            return null;
        }
        $validation = $this->canonicalParser->validate($parsed);
        if ($validation->valid) {
            return $parsed;
        }

        Log::warning('Canonical frontmatter present but invalid; ingesting as non-canonical.', [
            'project_key' => $projectKey,
            'source_path' => $sourcePath,
            'errors' => $validation->errors,
        ]);
        return null;
    }
}
