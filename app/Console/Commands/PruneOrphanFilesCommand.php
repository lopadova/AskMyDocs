<?php

namespace App\Console\Commands;

use App\Services\Kb\Ocr\OcrFigureStore;
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
        // v8.36 / ADR 0029 §6 — `{source}.ocr/` trees whose source is gone
        // from the disk AND from every row (a hard delete that kept an
        // in-flight run, a failed first ingest whose source was never
        // written): nothing else ever sweeps them.
        $danglingOcr = $this->detectDanglingOcrTrees($storage, $allFiles, $prefix);

        if ($markdownFiles === [] && $danglingOcr === []) {
            $this->info("No markdown files found on disk [{$disk}].");

            return self::SUCCESS;
        }

        $relativePaths = $this->toRelativePaths($markdownFiles, $prefix);
        $orphans = $this->detectOrphans($relativePaths);
        $scanned = count($relativePaths);
        $orphanCount = count($orphans);

        if ($orphanCount === 0 && $danglingOcr === []) {
            $this->info("Scanned {$scanned} markdown file(s) on disk [{$disk}] — no orphans found.");

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->renderDryRun($storage, $orphans, $disk, $prefix);
            $this->renderDanglingOcrDryRun($danglingOcr, $disk);
            $this->line(sprintf(
                'DRY-RUN: %d of %d orphan file(s) and %d dangling OCR tree(s) found on disk [%s]. No changes made.',
                $orphanCount,
                $scanned,
                count($danglingOcr),
                $disk,
            ));

            return self::SUCCESS;
        }

        [$deleted, $failed] = $this->deleteOrphans($storage, $orphans, $prefix, $disk);
        [$purged, $inFlight, $ocrFailed] = $this->purgeDanglingOcrTrees($danglingOcr, $disk);

        $this->info(sprintf(
            'Disk [%s]: scanned=%d orphans=%d deleted=%d failed=%d dangling_ocr=%d purged=%d in_flight=%d ocr_failed=%d',
            $disk,
            $scanned,
            $orphanCount,
            $deleted,
            $failed,
            count($danglingOcr),
            $purged,
            $inFlight,
            $ocrFailed,
        ));

        return ($failed === 0 && $ocrFailed === 0) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * `.ocr` trees on the disk whose source key exists neither on the disk
     * nor in ANY `knowledge_documents` row — live, archived or soft-deleted,
     * any tenant (`withoutGlobalScopes`: the tree sits beside a source object
     * that is not tenant namespaced, the same documented R30 exception as
     * the deleter's reference gate). A tree beside an orphan FILE is not
     * listed here: it goes with the file in {@see deleteOrphans()}.
     *
     * @param  array<int,string>  $allFiles
     * @return array<int,string> disk-relative source keys (the tree is `{key}.ocr`)
     */
    private function detectDanglingOcrTrees($storage, array $allFiles, string $prefix): array
    {
        $onDisk = array_flip(array_map(static fn (string $f): string => KbPath::normalize($f), $allFiles));
        $suffix = OcrFigureStore::DIR_SUFFIX;
        $sourceKeys = [];
        foreach ($allFiles as $file) {
            $normalized = KbPath::normalize($file);
            // The first `.ocr` segment names the tree; anything nested below
            // it belongs to a run.
            $at = strpos($normalized, $suffix.'/');
            if ($at === false) {
                continue;
            }
            $key = substr($normalized, 0, $at);
            if ($key === '' || isset($onDisk[$key])) {
                continue; // source still on disk: handled with the file
            }
            $sourceKeys[$key] = true;
        }
        $keys = array_keys($sourceKeys);
        if ($keys === []) {
            return [];
        }

        $dangling = [];
        foreach (array_chunk($keys, 1000) as $chunk) {
            $relative = array_map(fn (string $k): string => $this->stripPrefix($k, $prefix), $chunk);
            $known = array_flip(KnowledgeDocument::withoutGlobalScopes()
                ->whereIn('source_path', $relative)
                ->pluck('source_path')
                ->all());
            foreach ($chunk as $i => $key) {
                if (isset($known[$relative[$i]])) {
                    continue;
                }
                $dangling[] = $key;
            }
        }
        sort($dangling);

        return $dangling;
    }

    /**
     * @param  array<int,string>  $danglingOcr
     * @return array{0:int,1:int,2:int} [purged, in_flight (kept), failed]
     */
    private function purgeDanglingOcrTrees(array $danglingOcr, string $disk): array
    {
        $purged = 0;
        $inFlight = 0;
        $failed = 0;
        $store = app(OcrFigureStore::class);
        foreach ($danglingOcr as $sourceKey) {
            try {
                // Grace-aware: a run recorded inside the in-flight window is
                // kept (its row may be about to commit) and picked up by the
                // next sweep once aged.
                if ($store->purgeBeside($disk, $sourceKey)) {
                    $purged++;
                    continue;
                }
                $inFlight++;
                $this->line("  ~ kept (in flight): {$sourceKey}".OcrFigureStore::DIR_SUFFIX);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  ! could not purge dangling OCR tree {$sourceKey}".OcrFigureStore::DIR_SUFFIX.": {$e->getMessage()}");
            }
        }

        return [$purged, $inFlight, $failed];
    }

    /**
     * @param  array<int,string>  $danglingOcr
     */
    private function renderDanglingOcrDryRun(array $danglingOcr, string $disk): void
    {
        if ($danglingOcr === []) {
            return;
        }
        $this->table(
            ['Dangling OCR tree ['.$disk.']'],
            array_map(static fn (string $k): array => [$k.OcrFigureStore::DIR_SUFFIX], $danglingOcr),
        );
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
            if (KbPath::isGeneratedAsset($path)) {
                return false; // never a source (ADR 0029 / 0030)
            }
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
    private function deleteOrphans($storage, array $orphans, string $prefix, string $disk): array
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

            // An orphan source is typically a failed first ingest; the OCR
            // run it may have produced (`{source}.ocr/`) has no row either
            // and goes with it — the only sweep such a run ever gets.
            try {
                app(OcrFigureStore::class)->purgeBeside($disk, $target);
            } catch (\Throwable $e) {
                $this->warn("  ! could not purge OCR assets beside {$target}: {$e->getMessage()}");
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
