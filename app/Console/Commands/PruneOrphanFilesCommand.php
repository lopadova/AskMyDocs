<?php

namespace App\Console\Commands;

use App\Services\Kb\DocumentDeleter;
use App\Services\Kb\Ocr\OcrFigureStore;
use App\Models\KnowledgeDocument;
use App\Support\KbDiskResolver;
use App\Support\Kb\SourceType;
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
        $danglingOcr = $this->detectDanglingOcrTrees($storage, $allFiles, $prefix, $disk);
        // …and, inside trees a row still references, the runs no row names
        // any more (a version pruned while its run was in flight, a forced
        // re-run whose old run nothing points at): the deleter purges a tree
        // only with its last row, so these have no other reaper.
        $staleRuns = $this->detectStaleOcrRuns($allFiles, $prefix, $danglingOcr);

        if ($markdownFiles === [] && $danglingOcr === [] && $staleRuns === []) {
            $this->info("No source files found on disk [{$disk}].");

            return self::SUCCESS;
        }

        $relativePaths = $this->toRelativePaths($markdownFiles, $prefix);
        $orphans = $this->detectOrphans($relativePaths);
        $scanned = count($relativePaths);
        $orphanCount = count($orphans);

        if ($orphanCount === 0 && $danglingOcr === [] && $staleRuns === []) {
            $this->info("Scanned {$scanned} source file(s) on disk [{$disk}] — no orphans found.");

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->renderDryRun($storage, $orphans, $disk, $prefix);
            $this->renderDanglingOcrDryRun($danglingOcr, $disk);
            $this->renderStaleOcrRunsDryRun($staleRuns, $disk);
            $this->line(sprintf(
                'DRY-RUN: %d of %d orphan file(s), %d dangling OCR tree(s) and %d stale OCR run(s) found on disk [%s]. No changes made.',
                $orphanCount,
                $scanned,
                count($danglingOcr),
                count($staleRuns),
                $disk,
            ));

            return self::SUCCESS;
        }

        [$deleted, $failed, $orphanOcrKept] = $this->deleteOrphans($storage, $orphans, $prefix, $disk);
        [$purged, $inFlight, $ocrFailed] = $this->purgeDanglingOcrTrees($danglingOcr, $disk);
        [$runsPurged, $runsInFlight, $runsFailed] = $this->purgeStaleOcrRuns($staleRuns, $disk, $prefix);

        $this->info(sprintf(
            'Disk [%s]: scanned=%d orphans=%d deleted=%d failed=%d orphan_ocr_kept=%d dangling_ocr=%d purged=%d in_flight=%d ocr_failed=%d stale_runs=%d runs_purged=%d runs_in_flight=%d runs_failed=%d',
            $disk,
            $scanned,
            $orphanCount,
            $deleted,
            $failed,
            $orphanOcrKept,
            count($danglingOcr),
            $purged,
            $inFlight,
            $ocrFailed,
            count($staleRuns),
            $runsPurged,
            $runsInFlight,
            $runsFailed,
        ));

        return ($failed === 0 && $ocrFailed === 0 && $runsFailed === 0) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Runs (`{key}.ocr/{run}`) under trees that are NOT dangling — a row still
     * references the source — but that no row of any tenant, trashed
     * included, names in `metadata.converter.ocr.run` (the deleter's gate,
     * ADR 0030 §8). A tree beside an orphan file is not scanned: it goes with
     * the file.
     *
     * @param  array<int,string>  $allFiles
     * @param  array<int,string>  $danglingOcr
     * @return array<int,array{0:string,1:string}> [disk-relative source key, run]
     */
    private function detectStaleOcrRuns(array $allFiles, string $prefix, array $danglingOcr): array
    {
        $onDisk = array_flip(array_map(static fn (string $f): string => KbPath::normalize($f), $allFiles));
        $dangling = array_flip($danglingOcr);
        $suffix = OcrFigureStore::DIR_SUFFIX;
        $runs = [];
        foreach ($allFiles as $file) {
            $normalized = KbPath::normalize($file);
            $at = strpos($normalized, $suffix.'/');
            if ($at === false) {
                continue;
            }
            $key = substr($normalized, 0, $at);
            if ($key === '' || isset($dangling[$key]) || ! isset($onDisk[$key])) {
                continue;
            }
            $rest = substr($normalized, $at + strlen($suffix) + 1);
            $run = explode('/', $rest, 2)[0];
            if (preg_match('/^[a-f0-9]{64}$/', $run) !== 1) {
                continue;
            }
            $runs[$key.'|'.$run] = [$key, $run];
        }
        if ($runs === []) {
            return [];
        }

        $deleter = app(DocumentDeleter::class);
        $stale = [];
        foreach ($runs as [$key, $run]) {
            if ($deleter->documentReferencingOcrRun($this->stripPrefix($key, $prefix), $run) !== null) {
                continue;
            }
            $stale[] = [$key, $run];
        }
        usort($stale, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $stale;
    }

    /**
     * @param  array<int,array{0:string,1:string}>  $staleRuns
     * @return array{0:int,1:int,2:int} [purged, in_flight (kept), failed]
     */
    private function purgeStaleOcrRuns(array $staleRuns, string $disk, string $prefix): array
    {
        $purged = 0;
        $inFlight = 0;
        $failed = 0;
        $store = app(OcrFigureStore::class);
        foreach ($staleRuns as [$key, $run]) {
            try {
                if ($store->purgeRun($disk, $this->stripPrefix($key, $prefix), $prefix, $run)) {
                    $purged++;
                    continue;
                }
                $inFlight++;
                $this->line("  ~ kept (in flight): {$key}".OcrFigureStore::DIR_SUFFIX."/{$run}");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  ! could not purge stale OCR run {$key}".OcrFigureStore::DIR_SUFFIX."/{$run}: {$e->getMessage()}");
            }
        }

        return [$purged, $inFlight, $failed];
    }

    /**
     * @param  array<int,array{0:string,1:string}>  $staleRuns
     */
    private function renderStaleOcrRunsDryRun(array $staleRuns, string $disk): void
    {
        if ($staleRuns === []) {
            return;
        }
        $this->table(
            ['Stale OCR run ['.$disk.']'],
            array_map(static fn (array $r): array => [$r[0].OcrFigureStore::DIR_SUFFIX.'/'.$r[1]], $staleRuns),
        );
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
    private function detectDanglingOcrTrees($storage, array $allFiles, string $prefix, string $disk): array
    {
        $onDisk = array_flip(array_map(static fn (string $f): string => KbPath::normalize($f), $allFiles));
        $suffix = OcrFigureStore::DIR_SUFFIX;
        $sourceKeys = [];
        foreach ($allFiles as $file) {
            $key = self::ocrTreeSourceKey(KbPath::normalize($file), $suffix);
            if ($key === null || isset($onDisk[$key])) {
                continue; // not a generated tree, or source still on disk: handled with the file
            }
            $sourceKeys[$key] = true;
        }
        $keys = array_keys($sourceKeys);
        if ($keys === []) {
            return [];
        }

        // The reference gate is the deleter's: a row protects the tree only
        // when its RECORDED disk + prefix resolve to this very key on this
        // very disk — a row on another disk, or under another prefix, that
        // happens to share the logical `source_path` must not keep an
        // orphaned tree alive forever (nor, conversely, be ignored).
        $deleter = app(DocumentDeleter::class);
        $dangling = [];
        foreach ($keys as $key) {
            if ($deleter->documentReferencingStorageKey($disk, $key, $this->stripPrefix($key, $prefix)) !== null) {
                continue;
            }
            $dangling[] = $key;
        }
        sort($dangling);

        return $dangling;
    }

    /**
     * The source key a file under a generated OCR tree belongs to, or null
     * when the file is not part of one. A `.ocr` segment in a path names a
     * tree ONLY when what follows it has the store's run layout —
     * `{sha256 run key}/result.json` or `{run key}/images/{figure}` (see
     * OcrFigureStore::runDirFor() / store()): a legitimate source such as
     * `docs/archive.ocr/manual.png` is a directory that happens to end in
     * `.ocr`, and deriving `docs/archive` from it would let the purge remove
     * that source subtree. The LAST matching segment wins, so a source that
     * itself lives under such a directory (`docs/archive.ocr/manual.png.ocr/
     * {run}/result.json`) resolves to the source, not to the directory.
     */
    public static function ocrTreeSourceKey(string $normalized, string $suffix = OcrFigureStore::DIR_SUFFIX): ?string
    {
        $needle = $suffix.'/';
        $at = strrpos($normalized, $needle);
        while ($at !== false) {
            $key = substr($normalized, 0, $at);
            $rest = substr($normalized, $at + strlen($needle));
            if ($key !== '' && preg_match('#^[a-f0-9]{64}/(result\.json|images/[^/]+)$#', $rest) === 1) {
                return $key;
            }
            $at = $at === 0 ? false : strrpos($normalized, $needle, $at - strlen($normalized) - 1);
        }

        return null;
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
        // v8.36 / ADR 0029 — while OCR is on, an image is a source like a
        // Markdown file: an orphan scan (a failed first ingest) and the
        // `.ocr/` tree beside it would otherwise stay on the disk forever.
        // With OCR off images are not sources and are never touched (R43).
        $extensions = ['md', 'markdown'];
        if (filter_var(config('kb.ocr.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            $extensions = array_merge($extensions, SourceType::imageExtensions());
        }

        return array_values(array_filter($files, function (string $path) use ($extensions): bool {
            if (KbPath::isGeneratedAsset($path)) {
                return false; // never a source (ADR 0029 / 0030)
            }

            return in_array(strtolower((string) pathinfo($path, PATHINFO_EXTENSION)), $extensions, true);
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
     * @return array{0:int,1:int,2:int} [deleted, failed, ocr trees kept (in flight)]
     */
    private function deleteOrphans($storage, array $orphans, string $prefix, string $disk): array
    {
        $deleted = 0;
        $failed = 0;
        $ocrKept = 0;

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
            // and goes with it — the only sweep such a run ever gets. A
            // purge that fails is a failed sweep (R14): the source is gone
            // but generated OCR data stayed behind, so the path counts as
            // failed and the command exits non-zero, never a clean report.
            try {
                app(OcrFigureStore::class)->purgeBeside($disk, $target);
                // `purgeBeside()` is false both for "nothing there" and for a
                // run kept inside the in-flight grace: only a tree still on
                // the disk is reported (kept, never a failure — the next
                // sweep takes it once aged, as for a dangling tree).
                if ($storage->directoryExists($target.OcrFigureStore::DIR_SUFFIX)) {
                    $ocrKept++;
                    $this->line("  ~ kept (in flight): {$target}".OcrFigureStore::DIR_SUFFIX);
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  ! source deleted but its OCR assets could not be purged beside {$target}: {$e->getMessage()}");
                continue;
            }

            $deleted++;
        }

        return [$deleted, $failed, $ocrKept];
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
