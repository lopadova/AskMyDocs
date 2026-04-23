<?php

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Support\KbDiskResolver;
use App\Support\KbPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Scan a KB disk for markdown files that have no matching row in
 * `knowledge_documents` (including soft-deleted rows) and optionally
 * delete them. Designed to run as a nightly `--dry-run` from the
 * scheduler so operators can inspect leftovers before purging.
 *
 * Memory-safe (R3): paths are chunked into batches of 1000 against a
 * single `whereIn('source_path', ...)` query per chunk; no whole-table
 * `->get()` is ever issued even on corpora with millions of rows.
 *
 * Soft-delete aware (R2): uses `withTrashed()` so a document still
 * inside its retention window never has its file flagged as orphan.
 */
class PruneOrphanFilesCommand extends Command
{
    protected $signature = 'kb:prune-orphan-files
                            {--disk= : Override the resolved KB disk for this run}
                            {--project= : Resolve disk via KbDiskResolver::forProject()}
                            {--dry-run : List orphans without deleting anything}';

    protected $description = 'Find markdown files on the KB disk that have no matching knowledge_documents row and optionally delete them.';

    public function handle(): int
    {
        $disk = $this->resolveDisk();
        $prefix = $this->normalizePrefix((string) config('kb.sources.path_prefix', ''));
        $dryRun = (bool) $this->option('dry-run');

        $storage = Storage::disk($disk);

        // Scope the listing to the configured prefix so we never report or
        // delete files outside the KB subtree (R8). On bucket-backed disks
        // this is also a large performance win (avoid a full bucket walk).
        $allFiles = $prefix === '' ? $storage->allFiles() : $storage->allFiles($prefix);
        $markdownFiles = $this->filterMarkdown($allFiles);

        if ($markdownFiles === []) {
            $this->info("No markdown files found on disk [{$disk}].");

            return self::SUCCESS;
        }

        $relativePaths = $this->toRelativePaths($markdownFiles, $prefix);
        $orphans = $this->detectOrphans($relativePaths);
        $scanned = count($relativePaths);
        $orphanCount = count($orphans);

        if ($orphanCount === 0) {
            $this->info("Scanned {$scanned} markdown file(s) on disk [{$disk}] — no orphans found.");

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->renderDryRun($storage, $orphans, $disk, $prefix);
            $this->line(sprintf(
                'DRY-RUN: %d of %d orphan file(s) found on disk [%s]. No changes made.',
                $orphanCount,
                $scanned,
                $disk,
            ));

            return self::SUCCESS;
        }

        [$deleted, $failed] = $this->deleteOrphans($storage, $orphans, $prefix);

        $this->info(sprintf(
            'Disk [%s]: scanned=%d orphans=%d deleted=%d failed=%d',
            $disk,
            $scanned,
            $orphanCount,
            $deleted,
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function resolveDisk(): string
    {
        $explicit = (string) ($this->option('disk') ?: '');

        if ($explicit !== '') {
            return $explicit;
        }

        $project = $this->option('project');

        return KbDiskResolver::forProject($project === null ? null : (string) $project);
    }

    /**
     * @param  array<int,string>  $files
     * @return array<int,string>
     */
    private function filterMarkdown(array $files): array
    {
        return array_values(array_filter($files, function (string $path): bool {
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

            return $ext === 'md' || $ext === 'markdown';
        }));
    }

    /**
     * Normalise every disk path and strip the KB_PATH_PREFIX so we can
     * compare against `knowledge_documents.source_path` directly
     * (DocumentIngestor stores paths without the prefix).
     *
     * @param  array<int,string>  $files
     * @return array<int,string>
     */
    private function toRelativePaths(array $files, string $prefix): array
    {
        $out = [];
        foreach ($files as $raw) {
            $normalized = KbPath::normalize($raw);
            $relative = $this->stripPrefix($normalized, $prefix);
            $out[] = $relative;
        }

        return array_values(array_unique($out));
    }

    /**
     * Memory-safe orphan detection (R3). For each chunk of up to 1000 paths
     * we ask the DB which ones are known, then subtract them from the chunk
     * in PHP. This keeps the `IN (...)` list well under the driver-specific
     * limits and never loads the whole `knowledge_documents` table.
     *
     * @param  array<int,string>  $relativePaths
     * @return array<int,string>
     */
    private function detectOrphans(array $relativePaths): array
    {
        $orphans = [];

        foreach (array_chunk($relativePaths, 1000) as $chunk) {
            $known = KnowledgeDocument::withTrashed()
                ->whereIn('source_path', $chunk)
                ->pluck('source_path')
                ->all();

            $diff = array_diff($chunk, $known);
            foreach ($diff as $orphan) {
                $orphans[] = $orphan;
            }
        }

        return $orphans;
    }

    /**
     * @param  array<int,string>  $orphans
     * @return array{0:int,1:int} [deleted, failed]
     */
    private function deleteOrphans($storage, array $orphans, string $prefix): array
    {
        $deleted = 0;
        $failed = 0;

        foreach ($orphans as $relative) {
            $target = $this->applyPrefix($relative, $prefix);

            $ok = $storage->delete($target);

            if ($ok !== true) {
                $failed++;
                $this->error("  ! failed to delete: {$target}");
                continue;
            }

            $deleted++;
        }

        return [$deleted, $failed];
    }

    /**
     * @param  array<int,string>  $orphans
     */
    private function renderDryRun($storage, array $orphans, string $disk, string $prefix): void
    {
        $rows = [];
        foreach ($orphans as $relative) {
            $target = $this->applyPrefix($relative, $prefix);
            $size = $storage->exists($target) ? $storage->size($target) : 0;
            $rows[] = [$target, $this->formatSize($size)];
        }

        $this->table(['Path on disk ['.$disk.']', 'Size'], $rows);
    }

    private function stripPrefix(string $path, string $prefix): string
    {
        if ($prefix === '') {
            return $path;
        }

        if (str_starts_with($path, $prefix.'/')) {
            return substr($path, strlen($prefix) + 1);
        }

        return $path;
    }

    /**
     * Normalise the KB_PATH_PREFIX with the same slash rules applied to the
     * paths we compare against (R8, R1): convert backslashes, collapse
     * duplicate slashes, trim leading/trailing slashes. Empty prefix is
     * preserved (meaning "scan the whole disk").
     *
     * Without this, a Windows-style prefix such as `kb\\proj` would never
     * match paths normalised via `KbPath::normalize()` and the prefix-strip
     * step would silently leak — producing false positives in the orphan
     * list and, in non-dry-run mode, unwanted deletions.
     */
    private function normalizePrefix(string $prefix): string
    {
        $prefix = str_replace('\\', '/', $prefix);
        $prefix = preg_replace('#/+#', '/', $prefix) ?? $prefix;

        return trim($prefix, '/');
    }

    private function applyPrefix(string $relative, string $prefix): string
    {
        if ($prefix === '') {
            return $relative;
        }

        return $prefix.'/'.$relative;
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%.1f MB', $bytes / 1024 / 1024);
    }
}
