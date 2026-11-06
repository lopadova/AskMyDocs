<?php

declare(strict_types=1);

namespace App\Flow\Steps\Folder;

use App\Flow\Steps\StepTenantBinder;
use App\Services\Kb\Code\CodeIngestionPolicy;
use App\Services\Kb\Code\CodeLanguageMap;
use App\Support\Kb\SourceType;
use App\Support\KbPath;
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
        [$matching, $skippedCode] = $this->applyCodePolicy($storage, $matching);

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
                // Additive (R27): code files the admission policy left out of
                // the walk (deny-listed / oversize), each with its reason.
                'skipped_code_count' => count($skippedCode),
                'skipped_code' => $skippedCode,
            ],
            businessImpact: [
                'matched_count' => count($matching),
            ],
        );
    }

    /**
     * v8.45 / ADR 0035 D7 — a recursive walk over a repository meets
     * `vendor/`, `node_modules/`, lockfiles and `.env` files. With code
     * ingestion ON the deny-list and size cap prune them HERE, so they are
     * neither counted as matched nor reported as failures. With it OFF this is
     * a no-op and the walk is byte-identical to v8.44 (a `.php` the operator
     * listed explicitly still fails at dispatch as "unsupported", as before).
     *
     * @param  array<int,string>  $files
     * @return array{0: array<int,string>, 1: list<array{path: string, reason: string}>}
     */
    private function applyCodePolicy(\Illuminate\Contracts\Filesystem\Filesystem $storage, array $files): array
    {
        $policy = app(CodeIngestionPolicy::class);
        if (! $policy->enabled()) {
            return [$files, []];
        }
        $kept = [];
        $skipped = [];
        foreach ($files as $path) {
            if (CodeLanguageMap::languageForPath($path) === null) {
                $kept[] = $path;

                continue;
            }
            $size = null;
            try {
                $size = $storage->size($path);
            } catch (\Throwable) {
                // Size unknown: the byte-level check at ingest still applies.
            }
            $verdict = $policy->assessPath($path, is_int($size) ? $size : null);
            if ($verdict->accepted) {
                $kept[] = $path;

                continue;
            }
            $skipped[] = ['path' => $path, 'reason' => $verdict->label()];
        }

        return [$kept, $skipped];
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
        // Default to every supported source-type extension when omitted
        // (images only when OCR is on — v8.36 / ADR 0029, R43).
        return SourceType::knownExtensions((bool) config('kb.ocr.enabled', false));
    }

    /**
     * @param  array<int,string>  $files
     * @param  list<string>  $extensions
     * @return array<int,string>
     */
    private function filterByExtensions(array $files, array $extensions): array
    {
        // The converter's own output (`{source}.ocr/…/images/*.png`) is
        // never a source: a recursive walk that picked it up would OCR its
        // own figures and nest `.ocr/` trees on every run (ADR 0029).
        $files = array_values(array_filter(
            $files,
            static fn (string $path): bool => ! KbPath::isGeneratedAsset($path),
        ));
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
