<?php

declare(strict_types=1);

namespace App\Flow\Steps\Folder;

use App\Flow\Steps\StepTenantBinder;
use App\Jobs\IngestDocumentJob;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Support\Kb\SourceType;
use App\Support\KbPath;
use Illuminate\Support\Facades\Storage;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 2 of {@see \App\Flow\Definitions\IngestFolderFlow}.
 *
 * Iterates the file list produced by {@see ListFolderFilesStep} and
 * dispatches one ingest per file. Honours `input['sync']`:
 *
 *   - true  → calls {@see DocumentIngestor::ingest()} inline (mirrors
 *             KbIngestFolderCommand --sync semantics).
 *   - false → calls {@see IngestDocumentJob::dispatch()} so each row
 *             becomes its own kb.ingest sub-flow on the queue. The
 *             dispatch carries the captured tenant_id so the worker
 *             re-binds TenantContext (R30 hardening from PR #115).
 *
 * R1 — every relative path is normalised through {@see KbPath::normalize()}
 * before being handed downstream so the ingest + delete entry points
 * stay byte-identical.
 *
 * Per-file failures are accumulated into the output (NOT thrown) so
 * one bad file doesn't abort the whole fan-out — mirrors the original
 * KbIngestFolderCommand behaviour where a single unsupported extension
 * surfaces as an `! failed:` line and the rest of the batch keeps
 * dispatching. The CLI wrapper translates `failures > 0` into a
 * non-zero exit code so the failure is loud at the operator's terminal.
 *
 * Dry-run skipped — dispatch IS the side effect.
 */
final class DispatchIngestFanOutStep implements FlowStepHandler
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

        $listOutput = $context->stepOutputs['list-files'] ?? null;
        if (! is_array($listOutput)) {
            throw new RuntimeException(
                'DispatchIngestFanOutStep: missing prior step output [list-files].'
            );
        }

        $files = $listOutput['matched_files'] ?? [];
        if (! is_array($files)) {
            $files = [];
        }

        $tenantId = (string) $context->input['tenant_id'];
        $projectKey = (string) ($context->input['project_key'] ?? '');
        $disk = (string) $listOutput['disk'];
        $prefix = (string) ($context->input['prefix'] ?? '');
        $sync = (bool) ($context->input['sync'] ?? false);

        if ($projectKey === '') {
            throw new RuntimeException(
                'DispatchIngestFanOutStep: input["project_key"] must be a non-empty string.'
            );
        }

        $dispatched = 0;
        $failures = [];
        $storage = Storage::disk($disk);

        foreach ($files as $fullPath) {
            if (! is_string($fullPath) || $fullPath === '') {
                $failures[] = ['path' => (string) $fullPath, 'reason' => 'empty_path'];
                continue;
            }

            $relative = $this->stripPrefix($fullPath, $prefix);
            // R1 — normalise through KbPath so the ingest + delete entry
            // points see byte-identical paths. KbPath throws on traversal /
            // empty input; treat that as a per-file failure rather than
            // aborting the fan-out.
            try {
                $relative = KbPath::normalize($relative);
            } catch (\InvalidArgumentException $e) {
                $failures[] = ['path' => $fullPath, 'reason' => 'invalid_path: '.$e->getMessage()];
                continue;
            }

            $extension = (string) pathinfo($relative, PATHINFO_EXTENSION);
            $sourceType = SourceType::fromExtension($extension);
            if ($sourceType === SourceType::UNKNOWN) {
                $failures[] = ['path' => $relative, 'reason' => 'unsupported_extension: '.$extension];
                continue;
            }

            try {
                if ($sync) {
                    $this->ingestSync($storage, $disk, $prefix, $projectKey, $fullPath, $relative, $sourceType);
                } else {
                    IngestDocumentJob::dispatch(
                        projectKey: $projectKey,
                        relativePath: $relative,
                        disk: $disk,
                        title: null,
                        metadata: [],
                        mimeType: $sourceType->toMime(),
                        tenantId: $tenantId,
                    );
                }
                $dispatched++;
            } catch (\Throwable $e) {
                $failures[] = [
                    'path' => $relative,
                    'reason' => $e::class.': '.$e->getMessage(),
                ];
            }
        }

        return FlowStepResult::success(
            output: [
                'tenant_id' => $tenantId,
                'project_key' => $projectKey,
                'disk' => $disk,
                'sync' => $sync,
                'dispatched_count' => $dispatched,
                'failure_count' => count($failures),
                'failures' => $failures,
            ],
            businessImpact: [
                'dispatched_count' => $dispatched,
                'failure_count' => count($failures),
            ],
        );
    }

    private function ingestSync(
        \Illuminate\Contracts\Filesystem\Filesystem $storage,
        string $disk,
        string $prefix,
        string $projectKey,
        string $fullPath,
        string $relative,
        SourceType $sourceType,
    ): void {
        if (! $storage->exists($fullPath)) {
            throw new RuntimeException("File vanished before ingestion: {$fullPath}");
        }
        $bytes = (string) $storage->get($fullPath);
        $title = pathinfo($relative, PATHINFO_FILENAME);
        $this->ingestor->ingest(
            projectKey: $projectKey,
            source: new SourceDocument(
                sourcePath: $relative,
                mimeType: $sourceType->toMime(),
                bytes: $bytes,
                externalUrl: null,
                externalId: null,
                connectorType: 'local',
                metadata: ['disk' => $disk, 'prefix' => $prefix],
            ),
            title: $title,
        );
    }

    private function stripPrefix(string $path, string $prefix): string
    {
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            return ltrim($path, '/');
        }
        $path = ltrim($path, '/');
        if (str_starts_with($path, $prefix.'/')) {
            return substr($path, strlen($prefix) + 1);
        }
        return $path;
    }
}
