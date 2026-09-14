<?php

namespace App\Console\Commands;

use App\Services\Kb\DocumentDeleter;
use App\Services\Kb\Ocr\OcrFigureStore;
use App\Models\KnowledgeDocument;
use App\Support\Kb\LazyDiskListing;
use App\Support\KbDiskResolver;
use App\Support\Kb\SourceType;
use App\Support\KbPath;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * Scan a KB disk for markdown files that have no matching row in
 * `knowledge_documents` (including soft-deleted rows) and optionally
 * delete them. Designed to run as a nightly `--dry-run` from the
 * scheduler so operators can inspect leftovers before purging.
 *
 * Memory-safe (R3): the disk is walked ONCE, lazily (Flysystem's listing is
 * a generator — `allFiles()` would materialise the whole tree), and sources
 * are judged in batches of 1000 against a single `whereIn('source_path',
 * ...)` query per batch; what the pass keeps is bounded by what it reports
 * (the orphans, one entry per OCR tree, one per OCR run), never by the
 * number of files on the disk. No whole-table `->get()` is ever issued even
 * on corpora with millions of rows.
 *
 * Soft-delete aware (R2) and scope-blind: the orphan decision is taken
 * over the whole table (`withoutGlobalScopes()` — trashed rows, every
 * tenant, whatever project scope the caller may read), so a document still
 * inside its retention window, or one the admin command runner's caller
 * cannot read, never has its file flagged as orphan. The same documented
 * R30 exception as the dangling-tree sweep and the deleter's reference
 * gate: a source object on a shared disk is not tenant namespaced.
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
        try {
            $prefix = $this->normalizePrefix((string) config('kb.sources.path_prefix', ''));
        } catch (\InvalidArgumentException $e) {
            // SEC-PATH-001 — a traversing prefix would make this command walk
            // and delete outside the KB subtree: refused, never a sweep.
            $this->error("KB_PATH_PREFIX cannot be used as a scan root: {$e->getMessage()}");

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');

        $storage = Storage::disk($disk);

        // ONE lazy pass over the disk, scoped to the configured prefix so we
        // never report or delete files outside the KB subtree (R8; on
        // bucket-backed disks also a large performance win): sources are
        // judged in batches as the walk goes (`orphans`), and the OCR trees
        // and runs the walk meets are indexed by their directory — bounded
        // by the trees, never by the files under them (R3).
        try {
            $scan = $this->scan($storage, $prefix, $disk);
        } catch (\Throwable $e) {
            // The local adapter refuses to walk through a symbolic link
            // (SymbolicLinkEncountered), a bucket may refuse a page, the
            // per-batch lookup may lose the database: a scan that stops is
            // a refused sweep, reported with the class that gave up and
            // non-zero — never a clean "no orphans" over a tree that was
            // never read (R14). Nothing was deleted: every decision comes
            // after the scan.
            $this->error("Could not complete the scan of disk [{$disk}] (".$e::class."): {$e->getMessage()}. Nothing was deleted.");

            return self::FAILURE;
        }
        $orphans = $scan['orphans'];
        $scanned = $scan['scanned'];
        // v8.36 / ADR 0029 §6 — `{source}.ocr/` trees whose source is gone
        // from the disk AND from every row (a hard delete that kept an
        // in-flight run, a failed first ingest whose source was never
        // written): nothing else ever sweeps them.
        $treeProbeFailed = 0;
        $danglingOcr = $this->detectDanglingOcrTrees($storage, $scan['trees'], $prefix, $disk, $treeProbeFailed);
        // …and, inside trees a row still references, the runs no row names
        // any more (a version pruned while its run was in flight, a forced
        // re-run whose old run nothing points at): the deleter purges a tree
        // only with its last row, so these have no other reaper.
        $staleRuns = $this->detectStaleOcrRuns($scan['runs'], $scan['trees'], $prefix, $danglingOcr, $disk);

        // A tree whose source the disk refused to probe is reported and
        // kept (fail closed); the run exits non-zero so the refusal is never
        // read as a clean sweep (R14).
        $probeSuffix = $treeProbeFailed > 0 ? " tree_probe_failed={$treeProbeFailed}" : '';
        $probeExit = $treeProbeFailed > 0 ? self::FAILURE : self::SUCCESS;

        if ($scanned === 0 && $danglingOcr === [] && $staleRuns === []) {
            $this->info("No source files found on disk [{$disk}].{$probeSuffix}");

            return $probeExit;
        }

        $orphanCount = count($orphans);

        if ($orphanCount === 0 && $danglingOcr === [] && $staleRuns === []) {
            $this->info("Scanned {$scanned} source file(s) on disk [{$disk}] — no orphans found.{$probeSuffix}");

            return $probeExit;
        }

        if ($dryRun) {
            $this->renderDryRun($storage, $orphans, $disk, $prefix);
            $this->renderDanglingOcrDryRun($danglingOcr, $disk);
            $this->renderStaleOcrRunsDryRun($staleRuns, $disk);
            $this->line(sprintf(
                'DRY-RUN: %d of %d orphan file(s), %d dangling OCR tree(s) and %d stale OCR run(s) found on disk [%s]. No changes made.%s',
                $orphanCount,
                $scanned,
                count($danglingOcr),
                count($staleRuns),
                $disk,
                $probeSuffix,
            ));

            return $probeExit;
        }

        [$deleted, $failed, $orphanOcrKept] = $this->deleteOrphans($storage, $orphans, $prefix, $disk);
        [$purged, $inFlight, $ocrFailed] = $this->purgeDanglingOcrTrees($danglingOcr, $disk);
        [$runsPurged, $runsInFlight, $runsFailed] = $this->purgeStaleOcrRuns($staleRuns, $disk, $prefix);

        $this->info(sprintf(
            'Disk [%s]: scanned=%d orphans=%d deleted=%d failed=%d orphan_ocr_kept=%d dangling_ocr=%d purged=%d in_flight=%d ocr_failed=%d stale_runs=%d runs_purged=%d runs_in_flight=%d runs_failed=%d%s',
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
            $probeSuffix,
        ));

        return ($failed === 0 && $ocrFailed === 0 && $runsFailed === 0 && $treeProbeFailed === 0) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The single lazy pass over the disk. Every file is classified once:
     * a file with the OCR run layout (`{key}.ocr/{run}/result.json`,
     * `{key}.ocr/{run}/images/{figure}`) indexes its tree and its run; any
     * other generated asset (a file under a `*.ocr/` directory or under
     * `.artifacts/`, ADR 0029 / 0030) is never a source; a source file is
     * judged against the table in batches of 1000 relative paths, and only
     * the orphans are kept. Nothing proportional to the number of files
     * survives the walk (R3).
     *
     * A run is indexed from the SAME strict layout that names its tree
     * (`ocrTreeSourceKey()`): a run directory holding only files of another
     * shape (`{run}/notes.txt`) names no tree and no run — the store writes
     * only `result.json` and `images/{figure}` (`OcrFigureStore`), and a
     * lenient match would let a source directory that merely ends in `.ocr`
     * be read as a tree (see ocrTreeSourceKey()). Such residue, if it ever
     * exists, is not this sweep's to find.
     *
     * The orphan list is deduplicated once more at the end and sorted: a
     * disk listing never yields a path twice, but two raw paths can
     * normalize to the same key across batches, and the operator's table
     * and the delete order must not depend on the adapter's walk order.
     * `scanned` counts the source files walked (deduplicated within a
     * batch), not distinct keys.
     *
     * @return array{scanned: int, orphans: array<int,string>, trees: array<string,bool>, runs: array<string,array{0:string,1:string}>}
     *
     * @throws \Throwable when the disk refuses the walk (reported by the caller, R14)
     */
    private function scan(FilesystemAdapter $storage, string $prefix, string $disk): array
    {
        $extensions = $this->sourceExtensions();
        $suffix = OcrFigureStore::DIR_SUFFIX;
        $scanned = 0;
        $orphans = [];
        $trees = [];
        $runs = [];
        $batch = [];
        foreach (LazyDiskListing::files($storage, $prefix) as $raw) {
            $normalized = KbPath::normalize($raw);
            $key = self::ocrTreeSourceKey($normalized, $suffix);
            if ($key !== null) {
                // `true` until the tree pass asks the disk whether the source
                // key is still there — once per tree, never per file.
                $trees[$key] = $trees[$key] ?? true;
                $run = explode('/', substr($normalized, strlen($key) + strlen($suffix) + 1), 2)[0];
                $runs[$key.'|'.$run] = [$key, $run];

                continue;
            }
            if (KbPath::isGeneratedAsset($normalized)) {
                continue; // never a source (ADR 0029 / 0030)
            }
            if (! in_array(strtolower((string) pathinfo($normalized, PATHINFO_EXTENSION)), $extensions, true)) {
                continue;
            }
            $relative = $this->stripPrefix($normalized, $prefix);
            if (isset($batch[$relative])) {
                continue;
            }
            $batch[$relative] = true;
            $scanned++;
            if (count($batch) < 1000) {
                continue;
            }
            array_push($orphans, ...$this->detectOrphans(array_keys($batch), $prefix, $disk));
            $batch = [];
        }
        if ($batch !== []) {
            array_push($orphans, ...$this->detectOrphans(array_keys($batch), $prefix, $disk));
        }
        $orphans = array_values(array_unique($orphans));
        sort($orphans);

        return ['scanned' => $scanned, 'orphans' => $orphans, 'trees' => $trees, 'runs' => $runs];
    }

    /**
     * The extensions a source file may carry. v8.36 / ADR 0029 — while OCR
     * is on, an image is a source like a Markdown file: an orphan scan (a
     * failed first ingest) and the `.ocr/` tree beside it would otherwise
     * stay on the disk forever. With OCR off images are not sources and are
     * never touched (R43).
     *
     * @return array<int,string>
     */
    private function sourceExtensions(): array
    {
        $extensions = ['md', 'markdown'];
        if (filter_var(config('kb.ocr.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            $extensions = array_merge($extensions, SourceType::imageExtensions());
        }

        return $extensions;
    }

    /**
     * Runs (`{key}.ocr/{run}`) under trees that are NOT dangling — a row still
     * references the source — but that no row of any tenant, trashed
     * included, names in `metadata.converter.ocr.run` (the deleter's gate,
     * ADR 0030 §8). A tree beside an orphan file is not scanned: it goes with
     * the file.
     *
     * @param  array<string,array{0:string,1:string}>  $runs  indexed by the scan, one entry per run directory
     * @param  array<string,bool>  $trees  source key → whether the source is on the disk (decided by the tree pass)
     * @param  array<int,string>  $danglingOcr
     * @return array<int,array{0:string,1:string}> [disk-relative source key, run]
     */
    private function detectStaleOcrRuns(array $runs, array $trees, string $prefix, array $danglingOcr, string $disk): array
    {
        $dangling = array_flip($danglingOcr);
        $candidateRuns = [];
        foreach ($runs as $id => [$key, $run]) {
            if (isset($dangling[$key]) || ($trees[$key] ?? false) !== true) {
                continue;
            }
            if (preg_match('/^[a-f0-9]{64}$/', $run) !== 1) {
                continue;
            }
            $candidateRuns[$id] = [$key, $run];
        }
        if ($candidateRuns === []) {
            return [];
        }

        // The gate takes the run directory's full identity — this disk, this
        // prefix, the source path — so a same-named row under another
        // namespace neither keeps a stale run alive nor is mistaken for the
        // one being swept (ADR 0030 §8).
        // One bounded query per 500 candidates (R3), never one per run: a
        // shared disk accumulates runs and the nightly sweep must not scale
        // its query count with them.
        $deleter = app(DocumentDeleter::class);
        // `$key` is already KbPath::normalize()d (the scan), so the stripped
        // path is the normalized key the batch gate answers with.
        $candidates = array_values(array_map(fn (array $pair): array => [$this->stripPrefix($pair[0], $prefix), $pair[1]], $candidateRuns));
        $referenced = $deleter->documentsReferencingOcrRuns($disk, $prefix, $candidates);
        $stale = [];
        foreach ($candidateRuns as [$key, $run]) {
            if (isset($referenced[$this->stripPrefix($key, $prefix).'|'.$run])) {
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
                // `purgeRun()` throws when the disk refuses the removal
                // (counted as failed below, exit non-zero); false is only a
                // run kept inside the in-flight grace or reserved by a
                // converter — never a storage failure reported as "kept".
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
     * The disk is asked whether the source is still there ONCE per tree
     * (`fileExists()` — one stat per tree, not per file: the price of not
     * holding a set of every path on the disk, R3), and the answer is
     * written back into `$trees` for the stale-run pass. A probe
     * the disk refuses (a lost mount, a bucket answering 5xx) is a tree
     * treated as if its source were present — never dangling, never a
     * candidate for the stale-run pass — counted in `$probeFailed` and
     * reported by the caller, never an unhandled crash after the walk was
     * paid for (R14).
     *
     * @param  array<string,bool>  $trees  source key → on disk (filled here)
     * @return array<int,string> disk-relative source keys (the tree is `{key}.ocr`)
     */
    private function detectDanglingOcrTrees(FilesystemAdapter $storage, array &$trees, string $prefix, string $disk, int &$probeFailed): array
    {
        if ($trees === []) {
            return [];
        }

        // The reference gate is the deleter's: a row protects the tree only
        // when its RECORDED disk + prefix resolve to this very key on this
        // very disk — a row on another disk, or under another prefix, that
        // happens to share the logical `source_path` must not keep an
        // orphaned tree alive forever (nor, conversely, be ignored). A row
        // that never recorded its disk (ingested before the namespace was
        // persisted) protects the tree on any disk: deletion fails closed.
        $deleter = app(DocumentDeleter::class);
        $dangling = [];
        foreach (array_keys($trees) as $key) {
            try {
                $trees[$key] = $storage->fileExists($key);
            } catch (\Throwable $e) {
                $probeFailed++;
                // Fail closed: an unanswered probe keeps the tree, and the
                // stale-run pass reads the source as absent (`false`) so no
                // run under it is judged either.
                $trees[$key] = false;
                $this->error("  ! could not probe the source of OCR tree {$key}".OcrFigureStore::DIR_SUFFIX." on disk [{$disk}] ({$e->getMessage()}); kept");

                continue;
            }
            if ($trees[$key]) {
                continue; // source still on disk: handled with the file
            }
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
            // Look for an earlier `.ocr/` segment strictly BEFORE this one:
            // searching the prefix guarantees the loop advances (or ends)
            // on every path, whatever the suffix after the segment looks like.
            $at = $at === 0 ? false : strrpos(substr($normalized, 0, $at), $needle);
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
     * Memory-safe orphan detection (R3). For each chunk of up to 1000 paths
     * we ask the DB which ones are known, then subtract them from the chunk
     * in PHP. This keeps the `IN (...)` list well under the driver-specific
     * limits and never loads the whole `knowledge_documents` table. The
     * scan calls it once per batch of relative paths it has walked, so the
     * caller never holds more than one batch either.
     *
     * @param  array<int,string>  $relativePaths  normalized, prefix-free
     * @return array<int,string>
     */
    private function detectOrphans(array $relativePaths, string $prefix, string $disk): array
    {
        $orphans = [];
        $deleter = app(DocumentDeleter::class);

        foreach (array_chunk($relativePaths, 1000) as $chunk) {
            // A file is known only when a row's RECORDED namespace resolves
            // to this very key on this very disk — the same test the
            // dangling-tree sweep applies (`documentReferencesStorageKey()`):
            // a row carrying the same logical path on another disk, or under
            // another prefix, references another object, and the file here
            // (with any `.ocr/` tree beside it) is an orphan of this namespace.
            // Every row, whatever the caller may read (the admin command
            // runner executes this under a user whose AccessScopeScope would
            // hide other projects' rows — and their files would then be
            // "orphans"), trashed included, every tenant (the documented R30
            // exception: the object is not tenant namespaced): a deletion
            // decision is taken over the whole table, as the dangling-tree
            // sweep takes it.
            $known = [];
            $rows = KnowledgeDocument::query()
                ->withoutGlobalScopes()
                ->whereIn('source_path', $chunk)
                ->select(['id', 'project_key', 'source_path', 'metadata'])
                ->cursor();
            foreach ($rows as $row) {
                $relative = (string) $row->source_path;
                if (isset($known[$relative])) {
                    continue;
                }
                // A row that never recorded its disk (ingested before the
                // namespace was persisted) protects the file on its path
                // wherever the sweep looks — deletion fails closed, the
                // pre-namespace behaviour, never "a stranger to its own
                // file"; the predicate carries that rule for every consumer.
                if ($deleter->documentReferencesStorageKey($row, $disk, $this->applyPrefix($relative, $prefix))) {
                    $known[$relative] = true;
                }
            }

            foreach ($chunk as $relative) {
                if (! isset($known[$relative])) {
                    $orphans[] = $relative;
                }
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
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            return '';
        }

        // R1 / SEC-PATH-001 — the same canonical rules as every KB path:
        // `.` and `..` segments are rejected, never walked.
        return KbPath::normalize($prefix);
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
