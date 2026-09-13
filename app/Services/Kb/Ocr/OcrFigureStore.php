<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

use App\Support\KbPath;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Writes the figures an OCR run extracted next to the source document on
 * the kb disk (ADR 0029 §4):
 *
 *   {prefix}/{dir of source_path}/{basename}.ocr/{run}/images/fig-{page}-{n}.png
 *
 * `{run}` is content-addressed — the full 64-hex SHA-256 of the bytes that
 * were OCR'd, the driver and its fingerprint (a truncated digest would be a
 * 64-bit identifier two different inputs could share, and then one run
 * directory would serve the wrong text) — so two versions of the same source path never
 * overwrite each other's pixels (a re-run on identical bytes lands on the
 * same directory, which is exactly the idempotency the ingest has), and a
 * W2 artifact can point at the run that produced it. Tenant separation is
 * the source file's own: the assets live beside the file whose namespace
 * (disk + prefix + path) they inherit, so a deployment that isolates tenants
 * by disk/prefix isolates the figures with them.
 *
 * The Markdown references the files as `![Figure p.n](images/fig-p-n.png)`,
 * relative to the run directory — the shape the W4 export copies verbatim.
 * Every write checks its return value (R4).
 */
final class OcrFigureStore
{
    public const DIR_SUFFIX = '.ocr';

    /** Lease of the per-run reservation a purge takes: a directory removal, never a run. */
    public const PURGE_LOCK_SECONDS = 60;

    /**
     * Seconds a recorded run is treated as IN FLIGHT and never purged (ADR
     * 0029 §6). The run is recorded — and the run lock released — before the
     * row that will reference it commits (chunking, redaction and embedding
     * run in between), so a reference gate that counts committed rows only
     * has a window in which a concurrent hard delete or orphan sweep sees no
     * reference and would remove the figures a row is about to point at.
     * The run directory itself is the durable reservation: while it is
     * younger than this grace nothing purges it; a run that never gains a
     * row is removed by the orphan sweep once it has aged past it.
     */
    public const IN_FLIGHT_GRACE_SECONDS = 1800;

    /**
     * Disk-relative directory that holds a document's OCR assets.
     */
    public function assetsDirFor(string $sourcePath, string $prefix = ''): string
    {
        // The SAME key the ingest and the deleter resolve: prefix + source
        // through KbPath::normalize() (backslashes, repeated separators and
        // traversal segments handled once, not re-implemented here).
        $normalized = KbPath::normalize($sourcePath);
        $dir = trim($prefix, '/') === '' ? $normalized : KbPath::normalize(trim($prefix, '/').'/'.$normalized);

        return $dir.self::DIR_SUFFIX;
    }

    /**
     * Content-addressed run key over the bytes an OCR pass consumed AND the
     * engine that consumed them (driver name + fingerprint): the same bytes
     * through another driver or model land in another, immutable run.
     */
    public static function runKeyFor(string $bytes, string $driver = '', string $fingerprint = ''): string
    {
        return hash('sha256', $bytes."\0".$driver."\0".$fingerprint);
    }

    /**
     * Disk-relative directory of one OCR run's assets (`{assets dir}/{run}`).
     */
    public function runDirFor(string $sourcePath, string $prefix, string $runKey): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $runKey)) {
            throw new RuntimeException('OcrFigureStore: run key must be the 64 lowercase hex chars of a SHA-256.');
        }

        return $this->assetsDirFor($sourcePath, $prefix).'/'.$runKey;
    }

    /**
     * @param  list<OcrFigure>  $figures
     * @return list<array{path: string, page: int, index: int, bytes: int, relative: string}>
     */
    public function store(string $disk, string $sourcePath, string $prefix, string $runKey, array $figures): array
    {
        if ($figures === []) {
            return [];
        }

        $storage = Storage::disk($disk);
        $base = $this->runDirFor($sourcePath, $prefix, $runKey).'/images';
        $written = [];

        foreach ($figures as $figure) {
            $path = $base.'/'.$figure->fileName();
            if ($storage->put($path, $figure->bytes) === false) {
                throw new RuntimeException("OcrFigureStore: failed to write figure {$path} on disk [{$disk}].");
            }
            $written[] = [
                'path' => $path,
                'relative' => 'images/'.$figure->fileName(),
                'page' => $figure->page,
                'index' => $figure->index,
                'bytes' => strlen($figure->bytes),
            ];
        }

        return $written;
    }

    /**
     * The recorded result of one run — `{run dir}/result.json`. Written after
     * the figures, so a present result implies present figures; read by
     * OcrService::convert() to skip the driver (and the spend) when the same
     * bytes arrive again with the same driver.
     */
    public function resultPath(string $sourcePath, string $prefix, string $runKey): string
    {
        return $this->runDirFor($sourcePath, $prefix, $runKey).'/result.json';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function storeResult(string $disk, string $sourcePath, string $prefix, string $runKey, array $result): void
    {
        $path = $this->resultPath($sourcePath, $prefix, $runKey);
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || Storage::disk($disk)->put($path, $json) === false) {
            throw new RuntimeException("OcrFigureStore: failed to write OCR result {$path} on disk [{$disk}].");
        }
    }

    /**
     * A reuse performs no write, so an old run (past the in-flight grace)
     * reused by a new ingest would be purgeable before the new row commits.
     * Re-recording `result.json` with the same bytes refreshes the run's
     * newest file — the reservation the purge honours (ADR 0029 §6). A
     * refresh that fails is a failed reuse: without it the run may be
     * purged before the new row commits and the row would cite figures that
     * no longer exist, so the caller's ingest fails (and the job retries)
     * instead of committing a dangling reference.
     *
     * @throws RuntimeException
     */
    public function refreshReservation(string $disk, string $sourcePath, string $prefix, string $runKey): void
    {
        $path = $this->resultPath($sourcePath, $prefix, $runKey);
        try {
            $storage = Storage::disk($disk);
            $bytes = $storage->exists($path) ? $storage->get($path) : null;
            $ok = is_string($bytes) && $storage->put($path, $bytes) !== false;
        } catch (\Throwable $e) {
            throw new RuntimeException("OcrFigureStore: could not refresh the reservation of reused run {$path} on disk [{$disk}]: {$e->getMessage()}", 0, $e);
        }
        if (! $ok) {
            throw new RuntimeException("OcrFigureStore: could not refresh the reservation of reused run {$path} on disk [{$disk}].");
        }
    }

    /**
     * The recorded run, or null when none was recorded (absent, or a file
     * that is not the JSON the service wrote — logged, then redone). A file
     * that EXISTS but cannot be read is neither: it throws, so a transient
     * disk error never masquerades as a cache miss that runs — and bills —
     * the driver again for a run that is already on the disk.
     *
     * @return array<string, mixed>|null
     *
     * @throws \RuntimeException when the recorded run exists but cannot be read
     */
    public function loadResult(string $disk, string $sourcePath, string $prefix, string $runKey): ?array
    {
        $storage = Storage::disk($disk);
        $path = $this->resultPath($sourcePath, $prefix, $runKey);
        if (! $storage->exists($path)) {
            return null;
        }
        $raw = $storage->get($path);
        if (! is_string($raw)) {
            throw new \RuntimeException("Recorded OCR run exists but could not be read: {$path}.");
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            Log::warning('OcrFigureStore: recorded run is not valid JSON; the run will be redone', ['disk' => $disk, 'path' => $path]);

            return null;
        }

        return $decoded;
    }

    /**
     * Remove every OCR asset of a document (used by hard delete / prune).
     * Returns true when a directory existed and was removed.
     */
    public function purge(string $disk, string $sourcePath, string $prefix = ''): bool
    {
        return $this->purgeAt($disk, $this->assetsDirFor($sourcePath, $prefix));
    }

    /**
     * Remove the assets directory that sits beside an already-resolved
     * source key (`{fullPath}.ocr`) — the deleter's entry point, which has
     * the resolved key in hand and not the prefix.
     */
    public function purgeBeside(string $disk, string $fullPath): bool
    {
        return $this->purgeAt($disk, $fullPath.self::DIR_SUFFIX);
    }

    /**
     * Grace during which a recorded run counts as in flight (see the constant).
     */
    public static function inFlightGraceSeconds(): int
    {
        return max(0, (int) config('kb.ocr.purge_grace_seconds', self::IN_FLIGHT_GRACE_SECONDS));
    }

    /**
     * Remove ONE recorded run (`{assets dir}/{run}`) — the retention entry
     * point (ADR 0030 §8): the prune purges a pruned version's run when no
     * remaining row references it, while the `.ocr/` tree as a whole still
     * goes with the last referencing row of the source. Grace-aware like the
     * tree purge: a run still inside the in-flight window is kept and false
     * is returned.
     */
    public function purgeRun(string $disk, string $sourcePath, string $prefix, string $runKey): bool
    {
        $storage = Storage::disk($disk);
        $runDir = $this->runDirFor($sourcePath, $prefix, $runKey);
        if (! $storage->directoryExists($runDir)) {
            return false;
        }
        if ($this->isInFlight($storage, $runDir, now()->getTimestamp() - self::inFlightGraceSeconds())) {
            Log::info('OcrFigureStore: OCR run inside the in-flight grace was kept', ['disk' => $disk, 'run_dir' => $runDir]);

            return false;
        }

        return (bool) $storage->deleteDirectory($runDir);
    }

    /**
     * Remove the run directories under `$dir` that are older than the
     * in-flight grace, then the directory itself when nothing is left. A run
     * still inside the grace is kept (its row may be about to commit) and
     * reported; returns true only when the whole directory is gone.
     */
    private function purgeAt(string $disk, string $dir): bool
    {
        $storage = Storage::disk($disk);
        if (! $storage->directoryExists($dir)) {
            return false;
        }

        // The whole removal — enumeration, each run, the directory itself —
        // runs under the assets-directory lock a converter holds for its
        // write phase (OcrService::underAssetsLock()): the per-run locks
        // protect the runs that EXIST at enumeration time, this one protects
        // the directory against a run that starts writing into it after
        // that, which the final deleteDirectory() would otherwise take with
        // it. A lock that cannot be taken is a converter writing right now:
        // the tree is kept, like a run in flight.
        $assetsLock = Cache::lock(OcrService::assetsLockKey($disk, KbPath::normalize($dir)), OcrService::ASSETS_LOCK_SECONDS);
        if (! $assetsLock->get()) {
            Log::info('OcrFigureStore: OCR assets directory is being written to; the purge is deferred to the next sweep', ['disk' => $disk, 'dir' => $dir]);

            return false;
        }
        try {
            return $this->purgeUnderAssetsLock($storage, $disk, $dir);
        } finally {
            $assetsLock->release();
        }
    }

    private function purgeUnderAssetsLock(Filesystem $storage, string $disk, string $dir): bool
    {
        $threshold = now()->getTimestamp() - self::inFlightGraceSeconds();
        $kept = [];
        foreach ($storage->directories($dir) as $runDir) {
            // A run is removed only under ITS reservation — the same lock
            // `OcrService::convert()` holds from the recorded-run check
            // through `refreshReservation()`. A run a worker is reusing at
            // this very moment (its figures verified, its `result.json` about
            // to be re-stamped) is therefore never deleted between the two,
            // and the ingest can never commit `mediaItems` pointing at
            // figures a purge took; a reservation that cannot be taken is a
            // run in flight, kept like an aged one inside the grace.
            $reservation = Cache::lock(OcrService::runLockKey($disk, KbPath::normalize($runDir)), self::PURGE_LOCK_SECONDS);
            if (! $reservation->get()) {
                $kept[] = $runDir;
                continue;
            }
            try {
                if ($this->isInFlight($storage, $runDir, $threshold)) {
                    $kept[] = $runDir;
                    continue;
                }
                if (! $storage->deleteDirectory($runDir)) {
                    throw new RuntimeException("OcrFigureStore: failed to remove OCR run {$runDir} on disk [{$disk}].");
                }
            } finally {
                $reservation->release();
            }
        }

        if ($kept !== []) {
            Log::info('OcrFigureStore: OCR runs inside the in-flight grace were kept; the orphan sweep removes them once aged', [
                'disk' => $disk,
                'dir' => $dir,
                'kept' => $kept,
                'grace_seconds' => self::inFlightGraceSeconds(),
            ]);

            return false;
        }

        // Re-checked under the lock: a run recorded between the enumeration
        // and this point (a writer that took the lock before the purge and
        // finished after the enumeration began) is a run to keep, never one
        // to remove with its parent.
        if ($storage->directories($dir) !== []) {
            Log::info('OcrFigureStore: a run appeared under the OCR assets directory during the purge; the directory is kept', ['disk' => $disk, 'dir' => $dir]);

            return false;
        }
        if (! $storage->deleteDirectory($dir)) {
            throw new RuntimeException("OcrFigureStore: failed to remove OCR assets directory {$dir} on disk [{$disk}].");
        }

        return true;
    }

    /**
     * A run is in flight while its newest file — `result.json` once recorded,
     * otherwise whatever the reservation holder has written so far — is
     * younger than the threshold.
     */
    private function isInFlight(Filesystem $storage, string $runDir, int $threshold): bool
    {
        $result = $runDir.'/result.json';
        if ($storage->exists($result)) {
            return $storage->lastModified($result) > $threshold;
        }
        $newest = 0;
        foreach ($storage->allFiles($runDir) as $file) {
            $newest = max($newest, (int) $storage->lastModified($file));
        }

        return $newest > $threshold;
    }
}
