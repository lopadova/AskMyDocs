<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

use App\Services\Kb\Versioning\ConversionArtifactStore;
use App\Support\Kb\HeldLock;
use App\Support\Kb\LockLostException;
use App\Support\Kb\SourceInFlight;
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
     * PR #492 Copilot round-2 — the caller holds the assets-directory lock
     * across this call (`OcrService::underAssetsLock()`), but until now
     * never handed it IN: a large figure set can outlive the lock's TTL
     * (`ASSETS_LOCK_SECONDS`), and `purgeAt()` may then take the directory
     * mid-loop while this keeps writing into it. `$held` is asserted right
     * before EVERY write — the same "check immediately before the
     * irreversible step" shape every other critical section in this cycle
     * uses (ADR 0030 §3) — so a lapsed TTL stops the loop instead of
     * writing figures a purge has since started removing.
     *
     * @param  list<OcrFigure>  $figures
     * @return list<array{path: string, page: int, index: int, bytes: int, relative: string}>
     *
     * @throws \App\Support\Kb\LockLostException when the assets lock lapses mid-write
     */
    public function store(string $disk, string $sourcePath, string $prefix, string $runKey, array $figures, HeldLock $held): array
    {
        if ($figures === []) {
            return [];
        }

        $storage = Storage::disk($disk);
        $base = $this->runDirFor($sourcePath, $prefix, $runKey).'/images';
        $written = [];

        foreach ($figures as $figure) {
            $held->assertHeld('OCR figure write');
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
     * PR #492 Copilot round-2 — `$assetsHeld` (the SAME lock the caller ran
     * the read+existence check under) is asserted immediately before the
     * decisive `put()`: the read and the write straddle a storage
     * round-trip, and without the assertion a lapsed lock would let this
     * write proceed after a purge has since taken the directory.
     *
     * PR #492 Copilot round-5 — the assets lock alone was not enough: the
     * caller's OWN run-level reservation (the run-lock `purgeRun()` itself
     * acquires before it may delete this run's directory) has a bare
     * `OcrFigureStore::PURGE_LOCK_SECONDS`-second (60s) lease in
     * `touchRunBeforeCommit()`, and this method's read+write can straddle a
     * storage round-trip long enough to outlive it — a `purgeRun()` racing
     * in right then would delete the run while this refresh believes it is
     * still reserved. `$runHeld` closes it: asserted alongside `$assetsHeld`
     * immediately before the same decisive `put()`, so a lapsed RUN
     * reservation refuses the refresh exactly like a lapsed assets lock
     * already does.
     *
     * @throws RuntimeException
     * @throws \App\Support\Kb\LockLostException when the assets lock or the run reservation lapses before the write
     */
    public function refreshReservation(string $disk, string $sourcePath, string $prefix, string $runKey, HeldLock $assetsHeld, HeldLock $runHeld): void
    {
        $path = $this->resultPath($sourcePath, $prefix, $runKey);
        try {
            $storage = Storage::disk($disk);
            $bytes = $storage->exists($path) ? $storage->get($path) : null;
            $runHeld->assertHeld('OCR reservation refresh');
            $assetsHeld->assertHeld('OCR reservation refresh');
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
     * tree purge, and taken under the run's own reservation — the lock
     * `OcrService::convert()` holds from the recorded-run check through
     * `refreshReservation()` — so a run a worker is reusing at this very
     * moment is never deleted between its figure check and its commit: a
     * reservation that cannot be taken is a run in flight. Returns false
     * when there is nothing to remove, the run is kept (in flight), or the
     * reservation lapsed during the scan (the purge is deferred);
     * throws when the disk refuses the removal (R14 — never a silent
     * "kept").
     *
     * This reservation protects against a CONCURRENT CONVERTER reusing the
     * SAME run — it says nothing about a document row committing a NEW
     * reference to it. Both callers decide "unreferenced" from a database
     * snapshot taken BEFORE this call, and a restore or a fresh ingest can
     * commit a row naming this run in the gap between that snapshot and the
     * deletion below. `$referencedCheck`, when given, closes it: invoked
     * under the SAME held reservation, immediately before the delete — a
     * `true` result means a row now references the run and the purge is
     * skipped, exactly as if the caller's own pre-check had found it. The
     * caller's own snapshot check therefore stays a cheap early exit (skip
     * even trying the lock for an obviously-referenced run); this is the
     * authoritative one.
     *
     * `$referencedCheck` only sees COMMITTED rows, so it cannot see an
     * ingest that wrote this run's `result.json` but has not yet committed
     * its document row (PR #492 Copilot round-3): that ingest's
     * {@see SourceInFlight} reservation over the SOURCE is probed first and
     * held through the whole decision+delete, closing that gap too.
     *
     * @throws RuntimeException when the directory exists, is not in flight, and cannot be removed
     */
    public function purgeRun(string $disk, string $sourcePath, string $prefix, string $runKey, ?callable $referencedCheck = null): bool
    {
        $storage = Storage::disk($disk);
        $runDir = $this->runDirFor($sourcePath, $prefix, $runKey);
        if (! $storage->directoryExists($runDir)) {
            return false;
        }
        // PR #492 Copilot round-2 — this reservation is what makes the run
        // "in use, do not delete" legible to a concurrent converter; on a
        // store the interface presence check in `cacheStoreCanLock()`
        // rejects (NullStore, or no LockProvider at all), `$reservation->get()`
        // below would report success unconditionally, so a purge could
        // remove a run a converter is reusing at this very moment and still
        // report success. `purgeAt()` already takes this posture for the
        // directory-level lock (`purgeAtUnderGraceOnly()`'s caller); here
        // there is no documented no-lock fallback for the run-level
        // reservation, so the purge is refused outright — deferred to the
        // next sweep, exactly like a reservation this call could not take.
        if (! ConversionArtifactStore::cacheStoreCanLock()) {
            Log::warning('OcrFigureStore: OCR run purge refused — the cache store cannot exclude a concurrent converter reusing this run; configure a lock-capable cache store (Redis in production)', ['disk' => $disk, 'run_dir' => $runDir]);

            return false;
        }
        // PR #492 Copilot round-3 — `$referencedCheck` (below) only sees
        // COMMITTED `knowledge_documents` rows. An ingest that already
        // called `OcrService::convert()` — this exact run's `result.json`
        // is already on disk — but has not yet committed its document row
        // is invisible to that check: the row commits several steps later
        // (chunking, redaction, embedding), and once the in-flight grace
        // elapses this purge could delete the run out from under it. That
        // ingest DOES hold {@see SourceInFlight} for its whole
        // read+convert+commit window ({@see \App\Jobs\IngestDocumentJob}),
        // so probing the SOURCE's reservation — not the run's — catches
        // exactly this gap. Held through the whole decision+delete below
        // (not merely probed and released), so a NEW ingest cannot start
        // reading this source in between either: the same TOCTOU-safe
        // pattern `DocumentDeleter::removeSourceFileIfUnreferenced()` uses
        // for the source file itself.
        $fullSourcePath = $this->sourceFullPath($sourcePath, $prefix);
        $sourceReservation = null;
        if ($fullSourcePath !== null) {
            try {
                $sourceReservation = SourceInFlight::acquireForRemoval($disk, $fullSourcePath);
            } catch (\Throwable $e) {
                Log::warning('OcrFigureStore: cannot ask whether an ingest reserved this run\'s source, so the run is not purged', ['disk' => $disk, 'run_dir' => $runDir, 'source_path' => $fullSourcePath, 'error' => $e->getMessage()]);

                return false;
            }
            if ($sourceReservation === null) {
                Log::info('OcrFigureStore: OCR run kept — an ingest holds its source reservation right now', ['disk' => $disk, 'run_dir' => $runDir, 'source_path' => $fullSourcePath]);

                return false;
            }
        }
        try {
            $reservation = Cache::lock(OcrService::runLockKey($disk, KbPath::normalize($runDir)), self::PURGE_LOCK_SECONDS);
            if (! $reservation->get()) {
                Log::info('OcrFigureStore: OCR run is reserved by a converter; the purge is deferred to the next sweep', ['disk' => $disk, 'run_dir' => $runDir]);

                return false;
            }
            $held = new HeldLock($reservation, 'OCR run reservation');
            try {
                if ($this->isInFlight($storage, $runDir, now()->getTimestamp() - self::inFlightGraceSeconds())) {
                    Log::info('OcrFigureStore: OCR run inside the in-flight grace was kept', ['disk' => $disk, 'run_dir' => $runDir]);

                    return false;
                }
                // The in-flight scan reads the directory: assert the reservation
                // is still ours right before the removal, so a TTL that lapsed
                // across the scan defers the purge instead of deleting a run a
                // converter has since reserved (ADR 0030 §3).
                $held->assertHeld('OCR run purge');
                if ($referencedCheck !== null && $referencedCheck()) {
                    Log::info('OcrFigureStore: OCR run purge skipped — a document committed a reference to it after the caller\'s snapshot', ['disk' => $disk, 'run_dir' => $runDir]);

                    return false;
                }
                if (! $storage->deleteDirectory($runDir)) {
                    throw new RuntimeException("OcrFigureStore: failed to remove OCR run {$runDir} on disk [{$disk}].");
                }

                return true;
            } catch (LockLostException $e) {
                Log::warning('OcrFigureStore: OCR run purge deferred — the reservation lapsed during the in-flight scan; the next sweep decides', ['disk' => $disk, 'run_dir' => $runDir, 'error' => $e->getMessage()]);

                return false;
            } finally {
                $reservation->release();
            }
        } finally {
            HeldLock::releaseQuietly($sourceReservation);
        }
    }

    /**
     * The SAME full-path formula {@see \App\Jobs\IngestDocumentJob::reserveSource()}
     * and `DocumentDeleter::resolveFullPath()` use to key {@see SourceInFlight}:
     * must be byte-identical, or this probe would ask about a DIFFERENT
     * reservation than the one an ingest of this exact source actually holds.
     * Null only when the recorded prefix/source cannot name a path — the
     * same case `runDirFor()` above has already proven does NOT apply to
     * `$sourcePath` itself (it normalizes it via `assetsDirFor()`), so this
     * can only fail on a `$prefix` that recombines into a "." / ".." segment.
     */
    private function sourceFullPath(string $sourcePath, string $prefix): ?string
    {
        try {
            return KbPath::normalize($prefix === '' ? $sourcePath : $prefix.'/'.$sourcePath);
        } catch (\InvalidArgumentException) {
            return null;
        }
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

        // v8.36 / PR #479 Copilot review round 5 (R43) — this method used to
        // call Cache::lock() unconditionally, so a caller on a store without
        // LockProvider (PruneOrphanFilesCommand's own "no mutex at all"
        // fallback, added for exactly this case) got an uncaught throw here
        // instead of the grace-only purge its own comment promises. The age
        // grace is the ONLY guard on such a store — the same posture
        // DocumentDeleter::removeSourceFileUnderGraceOnly() already takes for
        // the source-file case — never a lock this deployment was not
        // configured for.
        if (! ConversionArtifactStore::cacheStoreCanLock()) {
            return $this->purgeAtUnderGraceOnly($storage, $disk, $dir);
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
            return $this->purgeUnderAssetsLock($storage, $disk, $dir, new HeldLock($assetsLock, 'OCR assets directory'));
        } finally {
            $assetsLock->release();
        }
    }

    private function purgeUnderAssetsLock(Filesystem $storage, string $disk, string $dir, HeldLock $held): bool
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
                // The scan is a storage round-trip: a reservation whose TTL
                // lapsed across it keeps the run (the next sweep decides),
                // never deletes one a converter has since reserved.
                (new HeldLock($reservation, 'OCR run reservation'))->assertHeld('OCR run purge');
                if (! $storage->deleteDirectory($runDir)) {
                    throw new RuntimeException("OcrFigureStore: failed to remove OCR run {$runDir} on disk [{$disk}].");
                }
            } catch (LockLostException $e) {
                Log::warning('OcrFigureStore: OCR run kept — its reservation lapsed during the in-flight scan; the next sweep decides', ['disk' => $disk, 'run_dir' => $runDir, 'error' => $e->getMessage()]);
                $kept[] = $runDir;
                continue;
            } finally {
                $reservation->release();
            }
        }

        if ($kept !== []) {
            Log::info('OcrFigureStore: OCR runs were kept — inside the in-flight grace, reserved by a converter, or their reservation lapsed during the scan; the orphan sweep removes them once aged', [
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
        // The enumeration, N per-run removals and this re-listing are all
        // storage round-trips: the tree's own removal asserts the directory
        // lock is still ours, so a TTL outlived by a large purge keeps the
        // directory instead of taking it from a writer that has since
        // started (ADR 0030 §3).
        try {
            $held->assertHeld('OCR assets directory purge');
        } catch (LockLostException $e) {
            Log::warning('OcrFigureStore: OCR assets directory kept — the lock lapsed during the purge; the next sweep decides', ['disk' => $disk, 'dir' => $dir, 'error' => $e->getMessage()]);

            return false;
        }
        if (! $storage->deleteDirectory($dir)) {
            throw new RuntimeException("OcrFigureStore: failed to remove OCR assets directory {$dir} on disk [{$disk}].");
        }

        return true;
    }

    /**
     * v8.36 / PR #479 Copilot review round 5 — the lock-free twin of
     * {@see purgeUnderAssetsLock()} for a cache store without `LockProvider`
     * (R43): the same age-grace decision (`isInFlight()`), with no
     * assets-directory lock and no per-run reservation at all — there is
     * nothing to take them on. This is a narrower guarantee than the locked
     * path: a converter could in principle start writing into a run between
     * this method's age check and its delete, on a store that cannot
     * exclude it either way. The grace window is the only defense such a
     * store ever had before reservations existed (`removeSourceFileUnderGraceOnly()`
     * takes the identical posture for the source file itself), so this
     * restores that behaviour rather than crashing every call — the crash
     * this method replaces reported "failed" for every aged, genuinely
     * removable tree, which was strictly worse than the grace-only guard.
     */
    private function purgeAtUnderGraceOnly(Filesystem $storage, string $disk, string $dir): bool
    {
        $threshold = now()->getTimestamp() - self::inFlightGraceSeconds();
        $kept = [];
        foreach ($storage->directories($dir) as $runDir) {
            if ($this->isInFlight($storage, $runDir, $threshold)) {
                $kept[] = $runDir;
                continue;
            }
            if (! $storage->deleteDirectory($runDir)) {
                throw new RuntimeException("OcrFigureStore: failed to remove OCR run {$runDir} on disk [{$disk}].");
            }
        }

        if ($kept !== []) {
            Log::info('OcrFigureStore: OCR runs were kept — inside the in-flight grace (no lock-capable cache store to reserve them); the orphan sweep removes them once aged', [
                'disk' => $disk,
                'dir' => $dir,
                'kept' => $kept,
                'grace_seconds' => self::inFlightGraceSeconds(),
            ]);

            return false;
        }

        // Re-checked: a run written between the enumeration and this point
        // is one to keep, never one to remove with its parent — the same
        // re-check the locked path performs, just without a lock to hold
        // across it.
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
