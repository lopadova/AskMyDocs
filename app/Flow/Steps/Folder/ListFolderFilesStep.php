<?php

declare(strict_types=1);

namespace App\Flow\Steps\Folder;

use App\Flow\Steps\StepTenantBinder;
use App\Support\Kb\SourceType;
use Illuminate\Support\Facades\Storage;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use RuntimeException;

/**
 * Step 1 of {@see \App\Flow\Definitions\IngestFolderFlow}.
 *
 * Walks the configured KB disk under `input['base_path']` and returns
 * the list of supported files matching `input['extensions']` (filtered
 * by extension, optionally bounded by `input['limit']`).
 *
 * No mutation: dry-run runs the listing so operators see exactly what
 * the next step would dispatch without queueing anything.
 *
 * R30 — even though Storage is tenant-agnostic, we still call
 * {@see StepTenantBinder} so the run record carries the caller's tenant
 * and downstream steps can use it for audit + dispatch metadata.
 */
final class ListFolderFilesStep implements FlowStepHandler
{
    public function execute(FlowContext $context): FlowStepResult
    {
        StepTenantBinder::bindFromContext($context);

        $disk = (string) ($context->input['disk'] ?? '');
        $basePath = (string) ($context->input['base_path'] ?? '');
        $recursive = (bool) ($context->input['recursive'] ?? false);
        $limit = max(0, (int) ($context->input['limit'] ?? 0));
        $extensions = $this->normalizeExtensions($context->input['extensions'] ?? null);

        if ($disk === '') {
            throw new RuntimeException(
                'ListFolderFilesStep: input["disk"] must be a non-empty string.'
            );
        }

        $storage = Storage::disk($disk);
        $allFiles = $recursive ? $storage->allFiles($basePath) : $storage->files($basePath);
        $matching = $this->filterByExtensions($allFiles, $extensions);

        if ($limit > 0) {
            $matching = array_slice($matching, 0, $limit);
        }

        return FlowStepResult::success(
            output: [
                'disk' => $disk,
                'base_path' => $basePath,
                'extensions' => $extensions,
                'matched_count' => count($matching),
                'matched_files' => array_values($matching),
            ],
            businessImpact: [
                'matched_count' => count($matching),
            ],
        );
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function normalizeExtensions(mixed $raw): array
    {
        if (is_array($raw)) {
            $clean = array_map(
                static fn ($v): string => strtolower(ltrim((string) $v, '.')),
                $raw,
            );
            $filtered = array_filter($clean, static fn (string $v): bool => $v !== '');
            return array_values(array_unique($filtered));
        }
        // Default to every supported source-type extension when omitted.
        return SourceType::knownExtensions();
    }

    /**
     * @param  array<int,string>  $files
     * @param  list<string>  $extensions
     * @return array<int,string>
     */
    private function filterByExtensions(array $files, array $extensions): array
    {
        if ($extensions === []) {
            return $files;
        }
        return array_values(array_filter(
            $files,
            static function (string $path) use ($extensions): bool {
                $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                return in_array($ext, $extensions, true);
            },
        ));
    }
}
