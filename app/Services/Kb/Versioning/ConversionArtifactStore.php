<?php

declare(strict_types=1);

namespace App\Services\Kb\Versioning;

use App\Support\Kb\HeldLock;
use App\Support\Kb\LazyDiskListing;
use App\Support\Kb\SettingInt;
use App\Support\Kb\SourceKeyLock;
use App\Support\KbPath;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v8.36 / ADR 0030 — where a version's converted Markdown lives on the kb
 * disk, and the temp-then-move protocol that publishes it.
 *
 *   {prefix}/.artifacts/{tenant}/{project}/{source_path}.versions/{version_hash}.md
 *
 * `source_path` is prefix-free and the prefix is one global setting, so
 * tenant and project are part of the key explicitly — as SAFE SEGMENTS,
 * never verbatim: a segment is admitted only when it matches
 * `^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$` AND does not start with the reserved
 * `h-` prefix; otherwise it is replaced by `h-` + the full 64-hex SHA-256 of
 * the value. The mapping is injective: a verbatim segment can never spell an
 * encoded one, and two distinct values share a segment only on a SHA-256
 * collision (ADR 0030 §3). The composed path goes through
 * `KbPath::normalize()` (no `..`, no `//`) and must stay inside the artifact
 * root. The `.artifacts/` root is a generated-asset subtree
 * (`KbPath::isGeneratedAsset()`, ADR 0029): the folder walker and the orphan
 * sweeps never read it back as a source.
 *
 * Publishing is temp-then-move: the writer puts the bytes at
 * `{final}.{uuid}.tmp` BEFORE the row's transaction, commits the row with the
 * final path, then moves its temp into place after commit. A concurrent
 * identical ingest that already published the same bytes wins: the final is
 * re-hashed against the temp (the key is the content hash, so the bytes must
 * be the same — a corrupt final is replaced by the verified temp) and the
 * loser drops its own temp only. Every write checks its return (R4).
 *
 * A temp is LEASED while its writer is alive: `writeTemp()` takes a cache
 * lease (`kb:artifact-temp:{sha1(disk|tmp)}`, `tmp_lease_seconds`) before the
 * bytes land and `publish()` / `discardTemp()` release it, so the temp sweep
 * never removes a file a writer is about to move into place — the file's age
 * alone cannot tell a slow transaction from a dead writer, and a temp deleted
 * under a live writer's feet fails its publish after the row committed
 * (Copilot review 10). The lease is the PRIMARY guard: it outlives the age
 * threshold (a shorter `tmp_lease_seconds` is raised past `tmp_max_age_seconds`, reported once),
 * so a temp still leased is never age-eligible; a held lease is `in_flight`
 * to the sweep, and the age threshold is the second guard for a lease the
 * store lost (`cache:clear`) or a store that cannot lease at all.
 */
final class ConversionArtifactStore
{
    public const ROOT = '.artifacts';

    public const TMP_SUFFIX = '.tmp';

    public const DEFAULT_TMP_LEASE_SECONDS = 7200;

    /** @var array<string,bool> configuration warnings already emitted by this process (once, not once per write) */
    private static array $warned = [];

    private const SAFE_SEGMENT = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/';

    /** Reserved for encoded segments: never admitted verbatim. */
    private const ENCODED_PREFIX = 'h-';

    public function enabled(): bool
    {
        return (bool) config('kb.conversion_artifacts.enabled', false);
    }

    private const DEFAULT_TMP_MAX_AGE_SECONDS = 3600;

    /**
     * Seconds a writer's temp lease lives (`kb.conversion_artifacts.tmp_lease_seconds`)
     * — long enough for the source-key lock wait, the row's transaction and
     * the move; a value that is not a positive number of seconds is a
     * misconfiguration reported once per process and replaced by the
     * default, never a lease that expires at once (fail closed on the
     * sweep's side). A lease shorter than `tmp_max_age_seconds` is RAISED
     * past it and reported once. Past, not to: the lease is taken BEFORE the
     * bytes are written and the sweep may delete from `mtime + max_age`
     * onwards, so a lease of exactly the threshold would lapse at the very
     * instant the temp becomes sweepable — zero margin for the writer the
     * lease exists for, one slower than the threshold. The clamp therefore
     * reproduces the shipped relationship (double the threshold, at least a
     * minute more), and the configured value is reported, never silently
     * honoured.
     */
    public static function tempLeaseSeconds(): int
    {
        $configured = config('kb.conversion_artifacts.tmp_lease_seconds', self::DEFAULT_TMP_LEASE_SECONDS);
        $seconds = self::DEFAULT_TMP_LEASE_SECONDS;
        $whole = SettingInt::whole($configured, 1);
        if ($whole !== null) {
            $seconds = $whole;
        } else {
            self::warnOnce('lease_shape', 'ConversionArtifactStore: kb.conversion_artifacts.tmp_lease_seconds is not a positive number of seconds; using the default', [
                'configured' => $configured,
                'default' => self::DEFAULT_TMP_LEASE_SECONDS,
            ]);
        }
        $maxAge = self::tempMaxAgeSeconds();
        if ($seconds < $maxAge) {
            // Clamped, not merely reported: a lease that expires before the
            // sweep may delete makes its writer indistinguishable from a dead
            // one exactly in the window the lease exists for.
            $effective = max($maxAge * 2, $maxAge + 60);
            self::warnOnce('lease_vs_age', 'ConversionArtifactStore: kb.conversion_artifacts.tmp_lease_seconds is shorter than tmp_max_age_seconds; raising the effective lease past the age threshold so a slow writer is never swept under', [
                // `$configured`, not `$seconds`: an invalid value was already
                // replaced by the default above, and reporting that as
                // "configured" would contradict the first warning.
                'configured_tmp_lease_seconds' => is_scalar($configured) ? $configured : gettype($configured),
                'tmp_max_age_seconds' => $maxAge,
                'effective_tmp_lease_seconds' => $effective,
            ]);

            return $effective;
        }

        return $seconds;
    }

    /** @param  array<string,mixed>  $context */
    private static function warnOnce(string $key, string $message, array $context): void
    {
        if (self::$warned[$key] ?? false) {
            return;
        }
        self::$warned[$key] = true;
        Log::warning($message, $context);
    }

    /** Test seam: forget which configuration warnings this process already emitted. */
    public static function resetWarnings(): void
    {
        self::$warned = [];
    }

    /**
     * ADR 0030 §3/§8 — the lock every actor on an artifact PATH shares: a
     * publish holds it around the post-commit move (after re-checking that
     * its row still points there), and every delete or sweep of the path
     * holds it around its reference re-check + removal
     * (`DocumentDeleter::removeArtifactIfUnreferenced()`). The path is the
     * content hash, so a concurrent identical ingest recreates the SAME path
     * a hard delete or prune is about to remove; with the lock, the remover
     * either sees the recreated row (kept) or removes before the publish
     * moves the new bytes in — never a live row's artifact deleted under it.
     * Shares the wait / TTL knobs of the source-key lock
     * (`source_lock_wait_seconds` / `source_lock_seconds`). A cache store
     * that cannot lock makes the lock unavailable: the call is REFUSED
     * (throws) — a publish discards its temp and rethrows, a removal is
     * reported `failed` — never run unguarded. Only the temp lease degrades
     * (`cacheStoreCanLock()`); the age threshold is its second guard. The lock has a
     * TTL and no renewal, so the callback receives a {@see HeldLock} and
     * asserts, right before its irreversible step, that it still owns it —
     * a lapsed lock is a refusal (LockLostException), never a race.
     *
     * @param  callable(HeldLock): mixed  $fn
     */
    public function underPathLock(string $disk, string $path, callable $fn): mixed
    {
        if (! self::cacheStoreCanLock()) {
            // A publish or a removal without the lock is a race with every
            // other writer of the path: refused (fail closed, R14) — the
            // caller discards its temp or reports `failed` — never run
            // unguarded. Only the temp lease degrades to "unleased"; the
            // age threshold is its second guard.
            throw new RuntimeException('ConversionArtifactStore: the cache store cannot hold locks, so the artifact path lock is unavailable; publish and removal are refused (configure a lock-capable cache store — Redis in production).');
        }
        $lock = Cache::lock('kb:artifact:'.$disk.':'.sha1($path), self::pathLockSeconds());
        $lock->block(self::pathLockWaitSeconds());
        try {
            // The section receives its lock so it can assert, right before
            // its irreversible step, that the TTL has not lapsed under it
            // (HeldLock::assertHeld() → LockLostException, refused).
            return $fn(new HeldLock($lock, 'artifact path'));
        } finally {
            // A release the store refused (a blip) must never turn a publish
            // or removal that DID happen into a failure; the lock lapses
            // with its TTL.
            HeldLock::releaseQuietly($lock);
        }
    }

    /**
     * The artifact PATH lock is configured by the same two knobs as the
     * storage-KEY lock (ADR 0030 §3), so it reads them through the same
     * methods rather than re-deriving them: a second reading is a second
     * chance to disagree, and this one already had — it cast the wait with
     * `(int)`, so `0.5` became `0` and a contended publish failed
     * immediately instead of taking the validated fallback, while its
     * sibling refused the value (SEC-SETTING-SHAPE-001).
     */
    private static function pathLockWaitSeconds(): int
    {
        return SourceKeyLock::waitSeconds();
    }

    private static function pathLockSeconds(): int
    {
        return SourceKeyLock::seconds();
    }

    /**
     * Seconds a temp file must be older than before a sweep may consider it
     * (`kb.conversion_artifacts.tmp_max_age_seconds`). ONE reading, shared
     * with `kb:prune-archived-versions`: this threshold is the only guard
     * left when the cache store cannot lease, so a `(int)` cast that turned
     * `0.5` or `abc` into `0` would make every live writer's temp eligible
     * for deletion the instant it is written. `0` is a legitimate setting —
     * it means "age is no protection", which is why the floor is 0 and not 1
     * — so only a value that is not a whole number at all falls back.
     */
    public static function tempMaxAgeSeconds(): int
    {
        $configured = config('kb.conversion_artifacts.tmp_max_age_seconds', self::DEFAULT_TMP_MAX_AGE_SECONDS);
        $seconds = SettingInt::whole($configured, 0);
        if ($seconds !== null) {
            return $seconds;
        }
        self::warnOnce('max_age_shape', 'ConversionArtifactStore: kb.conversion_artifacts.tmp_max_age_seconds is not a whole number of seconds; using the default', [
            'configured' => is_scalar($configured) ? $configured : gettype($configured),
            'default' => self::DEFAULT_TMP_MAX_AGE_SECONDS,
        ]);

        return self::DEFAULT_TMP_MAX_AGE_SECONDS;
    }

    /** The cache lease key of a temp file (one per attempt: the temp name carries a UUID). */
    public static function tempLeaseKey(string $disk, string $tmpPath): string
    {
        return 'kb:artifact-temp:'.sha1($disk.'|'.$tmpPath);
    }

    /**
     * The lease owner is derived from the key, so the writer needs no
     * instance state to release it (the store is resolved anew at every
     * step) and a sweeper's probe — a random owner — can never release a
     * writer's lease.
     */
    private static function tempLeaseOwner(string $disk, string $tmpPath): string
    {
        return 'writer:'.sha1('owner|'.$disk.'|'.$tmpPath);
    }

    /**
     * True when the default cache store can hold locks. A store that cannot
     * (apc, session, storage, a custom store) leaves the artifact temps
     * unleased — the age threshold is the documented second guard — and the
     * gap is reported once per process, never an ingest outage. An explicit
     * capability check, not a broad catch: a failure INSIDE a real lock
     * provider must stay a failure, never a silent "unleased". A
     * process-local provider (the array store) leases only within its own
     * process — the caveat the source-key lock carries too: Redis in
     * production. The null store, which implements the contract but grants
     * every lock unconditionally (no mutual exclusion at all), is REJECTED
     * here like a store that cannot lock: interface presence is not
     * exclusion. The artifact PATH lock (`underPathLock()`), the storage KEY
     * lock and the orphan-source sweep all refuse on a store this method
     * turns down.
     */
    public static function cacheStoreCanLock(): bool
    {
        try {
            $store = Cache::getStore();
        } catch (\Throwable) {
            // A manager that cannot name its store (a partial test double
            // stubbing `lock()` only): the lock attempt itself decides.
            return true;
        }
        if (! is_object($store)) {
            return true; // a double that answers nothing: the lock attempt decides
        }
        if ($store instanceof \Illuminate\Cache\NullStore) {
            // It implements the interface and grants EVERY lock: interface
            // presence is not mutual exclusion, and two writers would both
            // "hold" the path. Treated exactly like a store that cannot lock.
            self::warnOnce('null_lock_store', 'ConversionArtifactStore: the cache store grants every lock without excluding anyone (null driver) — artifact temp files are not leased and artifact publish/removal are REFUSED; configure a real lock-capable cache store (Redis in production)', ['store' => get_debug_type($store)]);

            return false;
        }
        if ($store instanceof \Illuminate\Contracts\Cache\LockProvider) {
            return true;
        }
        self::warnOnce('no_lock_store', 'ConversionArtifactStore: the cache store cannot hold locks — artifact temp files are not leased (only tmp_max_age_seconds protects an in-flight temp from the sweep) and artifact publish/removal are REFUSED; configure a lock-capable cache store (Redis in production)', ['store' => get_debug_type($store)]);

        return false;
    }

    private static function takeTempLease(string $disk, string $tmpPath): void
    {
        if (! self::cacheStoreCanLock()) {
            return;
        }
        $lease = Cache::lock(self::tempLeaseKey($disk, $tmpPath), self::tempLeaseSeconds(), self::tempLeaseOwner($disk, $tmpPath));
        if (! $lease->get()) {
            // The temp name is unique per attempt: the only way not to get
            // the lease is a lock store that refuses — refused, not written
            // (an unleased temp would be the sweep's to delete mid-write).
            throw new RuntimeException(sprintf('ConversionArtifactStore: could not lease artifact temp file [%s] %s.', $disk, $tmpPath));
        }
    }

    private static function releaseTempLease(string $disk, string $tmpPath): void
    {
        try {
            Cache::restoreLock(self::tempLeaseKey($disk, $tmpPath), self::tempLeaseOwner($disk, $tmpPath))->release();
        } catch (\Throwable $e) {
            // An expired or already-released lease, or a store that never
            // leased: nothing to hold any more.
            Log::debug('ConversionArtifactStore: artifact temp lease not released', ['disk' => $disk, 'path' => $tmpPath, 'error' => $e->getMessage()]);
        }
    }

    /**
     * True while a writer holds the temp's lease. The probe takes the lease
     * for one second under its own owner and gives it straight back: a held
     * lease refuses the probe, a free one is free again at once — never
     * taken from a writer, never left behind. A store that cannot lock
     * holds no lease: false, and the age threshold decides.
     */
    public static function tempLeaseHeld(string $disk, string $tmpPath): bool
    {
        if (! self::cacheStoreCanLock()) {
            return false;
        }
        $probe = Cache::lock(self::tempLeaseKey($disk, $tmpPath), 1);
        if (! $probe->get()) {
            return true;
        }
        $probe->release();

        return false;
    }

    /**
     * Disk-relative artifact root for a prefix (`{prefix}/.artifacts`).
     */
    public function rootFor(string $prefix): string
    {
        $prefix = trim(str_replace('\\', '/', $prefix), '/');
        if ($prefix === '') {
            return self::ROOT;
        }
        // `.artifacts` is a RESERVED segment: the containment check finds the
        // root as the only such segment of a path, so a prefix carrying one
        // (`a/.artifacts`) would put the real root (`a/.artifacts/.artifacts`)
        // inside a boundary drawn one level up, and a symlink planted there
        // could pass containment across namespaces. Refused for every path
        // this class composes; a stored path is checked by artifactRootOf().
        if (in_array(self::ROOT, explode('/', $prefix), true)) {
            throw new RuntimeException('ConversionArtifactStore: the configured path prefix must not contain the reserved `'.self::ROOT.'` segment.');
        }
        // SEC-PATH-001 — the prefix is configuration, but a sweep enumerates
        // and DELETES under the root it composes: a traversal segment or a
        // stray `//` must never make maintenance operate outside
        // `.artifacts/`. The same canonical rules as every KB path (R1).
        try {
            return KbPath::normalize($prefix.'/'.self::ROOT);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException('ConversionArtifactStore: the configured path prefix cannot form an artifact root: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * A path segment safe for every disk adapter and every tenant/project
     * name: verbatim when it is plain and does not start with the reserved
     * `h-` prefix, otherwise `h-` + the full SHA-256 — injective, so two
     * rows can never share an artifact path through the encoding.
     */
    public static function safeSegment(string $value): string
    {
        if (preg_match(self::SAFE_SEGMENT, $value) === 1 && $value !== '.' && $value !== '..' && ! str_starts_with($value, self::ENCODED_PREFIX)) {
            return $value;
        }

        return self::ENCODED_PREFIX.hash('sha256', $value);
    }

    /**
     * Disk-relative final path of a version's artifact.
     *
     * @throws RuntimeException when the composed path escapes the artifact
     *     root, or `$sourcePath` itself lies inside a generated-asset
     *     subtree (`.artifacts/`, `{x}.ocr/`) — v8.36 / PR #479 Copilot
     *     review round 3. `KbIngestController` and `ListFolderFilesStep`
     *     already reject those with `KbPath::isGeneratedAsset()` before a
     *     row is ever created, but `kb:ingest` (the single-file CLI) reaches
     *     `DocumentIngestor::ingestMarkdown()` — and this method — with no
     *     such guard: it could ingest the store's own converted output
     *     (`.artifacts/.../*.md`) as if it were a fresh source, composing a
     *     path that nests another artifact tree one level deeper under
     *     itself. Checked here, the shared choke point every caller
     *     (fresh ingest, identical re-ingest, `kb:artifacts-backfill`)
     *     composes the final artifact path through, closes the gap for all
     *     of them regardless of which entry point let the source path in —
     *     not only the one CLI command this round's finding named.
     */
    public function pathFor(string $tenantId, string $projectKey, string $sourcePath, string $versionHash, string $prefix = ''): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $versionHash) !== 1) {
            throw new RuntimeException('ConversionArtifactStore: version hash must be 64 lowercase hex chars.');
        }
        if (KbPath::isGeneratedAsset($sourcePath)) {
            throw new RuntimeException('ConversionArtifactStore: source path is inside a generated-asset directory (.ocr/ or .artifacts/) and cannot be published as an artifact.');
        }
        $root = $this->rootFor($prefix);
        $path = KbPath::normalize(sprintf(
            '%s/%s/%s/%s.versions/%s.md',
            $root,
            self::safeSegment($tenantId),
            self::safeSegment($projectKey),
            KbPath::normalize($sourcePath),
            $versionHash,
        ));
        if (! str_starts_with($path, $root.'/')) {
            throw new RuntimeException('ConversionArtifactStore: artifact path escapes the artifact root.');
        }

        return $path;
    }

    /**
     * Write the bytes to a per-attempt temp file beside the final path.
     *
     * @return string the disk-relative temp path
     *
     * @throws RuntimeException when the disk refuses the write (R4)
     */
    public function writeTemp(string $disk, string $finalPath, string $markdown): string
    {
        // SEC-PATH-001 — the parent of the final path is resolved BEFORE
        // the temp is written: `Storage::put()` would create the temp
        // through a planted symlink, and a refusal at publish() would then
        // come after the bytes had already landed outside the root.
        $this->assertContainedOnDisk($disk, $finalPath);
        $tmp = $finalPath.'.'.Str::uuid().self::TMP_SUFFIX;
        // Leased BEFORE the bytes land: a temp the sweep can see is a temp
        // the sweep can already tell is in flight.
        self::takeTempLease($disk, $tmp);
        try {
            $written = Storage::disk($disk)->put($tmp, $markdown);
        } catch (\Throwable $e) {
            // A write that THROWS (an adapter raising UnableToWriteFile) must
            // not leave a leased, half-written temp the sweep cannot see for
            // tmp_lease_seconds: the lease is given back and any partial
            // bytes removed before the failure propagates.
            $this->discardTemp($disk, $tmp);
            throw $e;
        }
        if (! $written) {
            $this->discardTemp($disk, $tmp);
            throw new RuntimeException(sprintf('ConversionArtifactStore: could not write artifact temp file [%s] %s.', $disk, $tmp));
        }

        return $tmp;
    }

    /**
     * Move a committed row's temp file into place. A final path that already
     * exists is NOT taken on faith (ADR 0030 §3): the path is the content
     * hash, so what is there is re-hashed against this attempt's bytes —
     * identical → keep it and drop the temp; anything else (a truncated or
     * replaced file) → the verified temp is moved over it, so a corrupt
     * artifact is repaired by the next identical ingest instead of being
     * kept, later reported as `integrity: mismatch`, and standing in for the
     * original in a `markdown_only` drop.
     *
     * @throws RuntimeException when the move fails and nothing verified is at the final path (R4)
     */
    public function publish(string $disk, string $tmpPath, string $finalPath, HeldLock $held): void
    {
        try {
            // Both paths are checked before any storage operation (SEC-PATH-001):
            // the temp is read, deleted and moved FROM, and a caller-supplied
            // `.tmp` outside the artifact root would otherwise be probed and
            // moved like one of ours.
            $this->assertContainedOnDisk($disk, $tmpPath);
            $this->publishLeased($disk, $tmpPath, $finalPath, $held);
        } finally {
            // Moved, discarded or refused: the attempt is over either way,
            // and a temp a failed publish left behind is the sweep's to take
            // once aged (`discardArtifact()` removes it before that).
            self::releaseTempLease($disk, $tmpPath);
        }
    }

    /**
     * PR #492 Copilot round-5 — `publish()`'s lock used to be `?HeldLock
     * $held = null`: nothing in the type system stopped a caller from
     * reaching the move/delete probes with no path lock at all, silently
     * skipping every `assertHeld()` in the path (`$held?->assertHeld()` is
     * a no-op on `null`) and racing a concurrent publish or reference-gated
     * removal (ADR 0030 §3's whole point). `publish()`'s ONE real caller —
     * `DocumentIngestor::publishArtifactForRow()` — always already holds
     * the path lock (it does a row/tenant re-check INSIDE the same
     * `underPathLock()` closure `publish()` itself must run under), so
     * `publish()` cannot self-acquire the lock without re-entering the
     * SAME key an outer holder already owns (a second `Cache::lock()` call
     * gets a fresh, unrelated owner token — it would block against, not
     * recognise, the caller's own lock). Making `$held` mandatory closes
     * the loophole for a FUTURE caller without touching that one.
     *
     * This wrapper is the self-locking entry point for callers (mostly
     * test fixtures seeding a published artifact with no re-check of their
     * own) that do not already hold the path lock: it acquires one via
     * {@see underPathLock()} and delegates.
     */
    public function publishUnderOwnLock(string $disk, string $tmpPath, string $finalPath): void
    {
        $this->underPathLock($disk, $finalPath, function (HeldLock $held) use ($disk, $tmpPath, $finalPath): void {
            $this->publish($disk, $tmpPath, $finalPath, $held);
        });
    }

    private function publishLeased(string $disk, string $tmpPath, string $finalPath, HeldLock $held): void
    {
        $storage = Storage::disk($disk);
        $this->assertContainedOnDisk($disk, $finalPath);
        if ($this->finalMatchesTemp($storage, $tmpPath, $finalPath)) {
            // This branch REPORTS SUCCESS (the bytes are already there), and
            // the caller reads that as licence to drop the original: it is
            // asserted like the move, after the two byte-probes above.
            $held->assertHeld('artifact publish');
            $this->discardTemp($disk, $tmpPath);

            return;
        }
        // The probes above are storage round-trips: the lock is asserted HERE,
        // immediately before the move, and again inside moveOver() before its
        // replace branch deletes — never once, far upstream (ADR 0030 §3).
        if ($this->moveOver($storage, $tmpPath, $finalPath, $held)) {
            // The move may have followed a symlinked parent: verify what landed.
            $this->assertContainedOnDisk($disk, $finalPath);

            return;
        }
        // A concurrent writer may have published between the two calls —
        // and this branch REPORTS SUCCESS on the strength of what it found
        // there, which licenses the retention tail: asserted like the move.
        if ($this->finalMatchesTemp($storage, $tmpPath, $finalPath)) {
            $held->assertHeld('artifact publish');
            $this->discardTemp($disk, $tmpPath);

            return;
        }
        throw new RuntimeException(sprintf('ConversionArtifactStore: could not publish artifact [%s] %s.', $disk, $finalPath));
    }

    /** True only when a file at the final path carries exactly this attempt's bytes. */
    private function finalMatchesTemp(FilesystemAdapter $storage, string $tmpPath, string $finalPath): bool
    {
        if (! $storage->exists($finalPath)) {
            return false;
        }
        $final = $storage->get($finalPath);
        $temp = $storage->get($tmpPath);
        if (! is_string($final) || ! is_string($temp)) {
            return false;
        }
        if (hash('sha256', $final) === hash('sha256', $temp)) {
            return true;
        }
        Log::warning('ConversionArtifactStore: artifact on disk does not match its content hash; replacing it with the verified bytes', ['path' => $finalPath]);

        return false;
    }

    /**
     * Move the temp over the final path. A rename overwrites atomically on a
     * local disk; an adapter that refuses to overwrite gets the stale file
     * removed first (the window between the two is the smallest available).
     */
    private function moveOver(FilesystemAdapter $storage, string $tmpPath, string $finalPath, HeldLock $held): bool
    {
        $held->assertHeld('artifact publish');
        try {
            if ($storage->move($tmpPath, $finalPath)) {
                return true;
            }
        } catch (\Throwable) {
            // fall through: try a replace
        }
        if (! $storage->exists($tmpPath)) {
            return false;
        }
        if ($storage->exists($finalPath)) {
            // The replace branch DELETES what is there: assert again, the
            // probes since the first assertion were storage round-trips.
            $held->assertHeld('artifact replace');
            if (! $storage->delete($finalPath)) {
                return false;
            }
        }

        // The single point every publish move goes through, and the last
        // assertion before the irreversible step (ADR 0030 §3). BOTH paths
        // reach it having spent storage round-trips since their last check —
        // the replace branch its `exists` + `delete`, the fallback its caught
        // failure and its own `exists` probes — and a TTL can lapse inside
        // any of them. Asserting only in the replace branch left the
        // "final absent" fallback able to move bytes after its lock was gone,
        // racing whichever holder took the path meanwhile.
        $held->assertHeld('artifact publish move');

        return (bool) $storage->move($tmpPath, $finalPath);
    }

    /** Delete this attempt's temp file only — never another writer's, never the final path — and give its lease back. */
    public function discardTemp(string $disk, string $tmpPath): void
    {
        // The lease goes first, whatever the path looks like: releasing a
        // lease that was never taken is a no-op, holding one past the
        // discard is a temp the sweep would report in flight for nothing.
        self::releaseTempLease($disk, $tmpPath);
        if (! str_ends_with($tmpPath, self::TMP_SUFFIX)) {
            return;
        }
        try {
            // Containment before the probe and the delete (SEC-PATH-001), as
            // for every other path this store touches: a `.tmp` outside the
            // artifact root is refused, never probed on the configured disk.
            $this->assertContainedOnDisk($disk, $tmpPath);
            $storage = Storage::disk($disk);
            if ($storage->exists($tmpPath) && ! $storage->delete($tmpPath)) {
                Log::warning('ConversionArtifactStore: could not remove artifact temp file', ['disk' => $disk, 'path' => $tmpPath]);
            }
        } catch (\Throwable $e) {
            Log::warning('ConversionArtifactStore: could not remove artifact temp file', ['disk' => $disk, 'path' => $tmpPath, 'error' => $e->getMessage()]);
        }
    }

    public function exists(string $disk, string $path): bool
    {
        // Contained like every other path-taking read: a caller never learns
        // anything about a path outside the artifact root.
        $this->assertContainedOnDisk($disk, $path);

        return Storage::disk($disk)->exists($path);
    }

    /**
     * Whether a published artifact is readable AND its bytes hash to
     * `$expectedHash` — the only sense in which an artifact "stands in" for
     * an original (ADR 0030 §3): a pointer proves nothing, a corrupt file
     * less than nothing.
     */
    public function verifies(string $disk, string $path, string $expectedHash): bool
    {
        if ($expectedHash === '') {
            return false;
        }
        $bytes = $this->read($disk, $path);

        return is_string($bytes) && hash('sha256', $bytes) === $expectedHash;
    }

    /**
     * ADR 0030 §3 — on a LOCAL disk the lexical containment of the key is
     * not enough: a symlink planted under `.artifacts/` can make a contained
     * key resolve outside the root. So every read, delete and publish on a
     * local disk also resolves the real path (the file's when it exists,
     * its parent's otherwise) and refuses it unless it sits under the real
     * artifact root. Object stores have no symlinks: the lexical check is
     * the whole check there.
     *
     * @throws RuntimeException when the resolved path escapes the artifact root
     */
    public function assertContainedOnDisk(string $disk, string $path): void
    {
        // The lexical check is the WHOLE check on an object store (no
        // symlinks, no realpath), so the key must be canonical before the
        // root is derived: `.artifacts/../outside.md` is not under the root,
        // whatever its first segment says (R1 — KbPath refuses `.` / `..`,
        // collapses `//`, converts `\`; a stored pointer that is not already
        // canonical is refused, never quietly reinterpreted).
        try {
            $canonical = KbPath::normalize($path);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException("ConversionArtifactStore: [{$disk}] {$path} is not a canonical artifact path: {$e->getMessage()}", 0, $e);
        }
        if ($canonical !== $path) {
            throw new RuntimeException("ConversionArtifactStore: [{$disk}] {$path} is not a canonical artifact path.");
        }
        $root = $this->artifactRootOf($path);
        if ($root === null) {
            throw new RuntimeException("ConversionArtifactStore: [{$disk}] {$path} is not under an artifact root.");
        }
        if ((string) config("filesystems.disks.{$disk}.driver") !== 'local') {
            return;
        }
        $storage = Storage::disk($disk);
        // The nearest EXISTING ancestor of the path is what a write would
        // follow: a symlink planted at any existing ancestor (the root not
        // yet created, a nested `.versions` directory) redirects the write,
        // so a missing immediate parent is never treated as safe.
        $absolute = $storage->path($path);
        $real = self::nearestExistingRealPath($absolute);
        if ($real === null) {
            return; // nothing of the path exists yet, not even the disk root: nothing can be followed
        }
        // The DISK root is the trust anchor: whatever exists of the path must
        // resolve inside it — an artifact root that is itself a symlink to
        // the outside is refused like any other link — and, once the artifact
        // root exists, inside that root as well.
        $realDisk = realpath($storage->path(''));
        if ($realDisk === false) {
            return; // the disk itself does not exist yet
        }
        if (! self::isWithin($real, $realDisk)) {
            // Outside the disk is outside the artifact root a fortiori.
            throw new RuntimeException("ConversionArtifactStore: [{$disk}] {$path} resolves outside the artifact root (symlink?); refused.");
        }
        $realRoot = realpath($storage->path($root));
        if ($realRoot !== false && ! self::isWithin($real, $realRoot)) {
            throw new RuntimeException("ConversionArtifactStore: [{$disk}] {$path} resolves outside the artifact root (symlink?); refused.");
        }
    }

    private static function isWithin(string $real, string $boundary): bool
    {
        return $real === $boundary || str_starts_with($real, rtrim($boundary, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    /**
     * The real path of the deepest existing ancestor of `$absolute` (the
     * path itself when it exists), or null when nothing of it exists. Walks
     * up to the filesystem root: `dirname()` reaches a fixpoint there, so the
     * loop always terminates and never fails open on a deep path.
     */
    private static function nearestExistingRealPath(string $absolute): ?string
    {
        $candidate = $absolute;
        while (true) {
            $real = realpath($candidate);
            if ($real !== false) {
                return $real;
            }
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return null;
            }
            $candidate = $parent;
        }
    }

    /** The `{prefix}/.artifacts` root a disk-relative artifact path belongs to, or null. */
    private function artifactRootOf(string $path): ?string
    {
        $segments = explode('/', $path);
        $at = array_search(self::ROOT, $segments, true);
        if ($at === false || $at === count($segments) - 1) {
            return null;
        }
        // A path this class is HANDED (a stored pointer) is held to the same
        // rule as one it composes: exactly one `.artifacts` segment. A second
        // one (a pointer written while a prefix carried the reserved segment)
        // would draw the boundary one level above the real root — refused.
        if (in_array(self::ROOT, array_slice($segments, $at + 1), true)) {
            return null;
        }

        return implode('/', array_slice($segments, 0, $at + 1));
    }

    public function read(string $disk, string $path): ?string
    {
        try {
            // Containment FIRST: on a local disk `exists()` would already
            // follow a symlink planted under `.artifacts/` and probe a path
            // outside the root before the check ran — every read fails closed.
            $this->assertContainedOnDisk($disk, $path);
            $storage = Storage::disk($disk);
            if (! $storage->exists($path)) {
                return null;
            }
            $bytes = $storage->get($path);

            return is_string($bytes) ? $bytes : null;
        } catch (\Throwable $e) {
            Log::warning('ConversionArtifactStore: could not read artifact', ['disk' => $disk, 'path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public const REMOVED = 'removed';

    public const ABSENT = 'absent';

    public const FAILED = 'failed';

    /** A removal refused by the reference gate: a row (a newer identical version) still points at the path. */
    public const KEPT = 'kept';

    /**
     * Remove a published artifact. False when nothing was there or the disk
     * refused (logged) — never an exception. A convenience wrapper with no
     * production caller: every retention and erasure path uses {@see remove()}
     * so a refusal is reported apart from an already-missing file.
     */
    public function delete(string $disk, string $path): bool
    {
        return $this->remove($disk, $path) === self::REMOVED; // no path lock here: the wrapper has no production caller (see above)
    }

    /**
     * Remove a published artifact and say what happened: `removed`, `absent`
     * (nothing was there — a prune finding the file already gone is done),
     * or `failed` (the disk refused, or the path resolves outside the root;
     * logged). Retention commands count the three apart so a refused delete
     * is never reported as a completed cleanup (R14).
     */
    public function remove(string $disk, string $path, ?HeldLock $held = null): string
    {
        try {
            // Containment FIRST (see read()): a delete never probes a path the
            // check would refuse.
            $this->assertContainedOnDisk($disk, $path);
            $storage = Storage::disk($disk);
            if (! $storage->exists($path)) {
                // `absent` is read by the caller as "nothing remains for the
                // row": a probe made under a lapsed lock must not license
                // that either (→ `failed`).
                $held?->assertHeld('artifact removal');

                return self::ABSENT;
            }
            // The containment check and the probe are storage round-trips:
            // the lock is asserted HERE, immediately before the delete, so a
            // TTL that lapsed across them refuses instead of deleting what a
            // concurrent publisher has since put there (→ `failed`).
            $held?->assertHeld('artifact removal');
            if (! $storage->delete($path)) {
                Log::warning('ConversionArtifactStore: could not delete artifact', ['disk' => $disk, 'path' => $path]);

                return self::FAILED;
            }

            return self::REMOVED;
        } catch (\Throwable $e) {
            Log::warning('ConversionArtifactStore: could not delete artifact', ['disk' => $disk, 'path' => $path, 'error' => $e->getMessage()]);

            return self::FAILED;
        }
    }

    /**
     * The artifact ROOT is checked before it is probed or listed
     * (SEC-PATH-001): a `.artifacts` that is itself a symlink to a directory
     * outside the disk would otherwise be enumerated — recursively, outside
     * the disk — before any individual entry could be refused. A sentinel
     * path under the root resolves through the root's real path, so the
     * same containment check refuses the link; a root that does not exist
     * yet is simply absent.
     *
     * @throws RuntimeException when the root resolves outside the disk
     */
    private function assertRootContained(string $disk, string $root): void
    {
        // A sentinel that can never be an artifact (no `.md`, never listed).
        $this->assertContainedOnDisk($disk, $root.'/.root-probe');
    }

    /**
     * Sweep temp files under the artifact root that are older than
     * `$maxAgeSeconds` AND that no writer holds a lease on — leftovers of a
     * writer that died between temp and move. The age is checked first (a
     * stat, free) and the lease only for the temps old enough to be swept
     * (a cache round-trip): an aged temp still leased is a writer's, however
     * old (a long transaction is not a dead writer) — kept and counted as
     * `in_flight`, the one case an operator cares about. Every entry is
     * checked against the real artifact root
     * before it is read or removed (a symlinked parent under `.artifacts/`
     * would otherwise let the sweep reach outside it); a refused entry is
     * skipped and counted as failed, as is a delete the disk refuses, so
     * the retention command can report them instead of a clean run (R14).
     *
     * @return array{removed: int, failed: int, in_flight: int}
     */
    public function sweepTemps(string $disk, string $prefix, int $maxAgeSeconds, bool $dryRun = false): array
    {
        $storage = Storage::disk($disk);
        $root = $this->rootFor($prefix);
        $this->assertRootContained($disk, $root);
        if (! $storage->directoryExists($root)) {
            return ['removed' => 0, 'failed' => 0, 'in_flight' => 0];
        }
        $cutoff = time() - max(0, $maxAgeSeconds);
        $removed = 0;
        $failed = 0;
        $inFlight = 0;
        try {
            // Lazy walk (R3): the tree is streamed, never materialised — only
            // the stale temps are ever held.
            foreach (LazyDiskListing::files($storage, $root) as $file) {
                if (! str_ends_with($file, self::TMP_SUFFIX)) {
                    continue;
                }
                $this->sweepOneTemp($storage, $disk, $file, $cutoff, $dryRun, $removed, $failed, $inFlight);
            }
        } catch (\Throwable $e) {
            // The local adapter refuses to walk through a symbolic link
            // (SymbolicLinkEncountered): a planted link under the root is a
            // refused sweep, reported as such — never a clean zero.
            Log::warning('ConversionArtifactStore: temp sweep aborted', ['disk' => $disk, 'root' => $root, 'exception' => $e::class, 'error' => $e->getMessage()]);
            $failed++;
        }

        return ['removed' => $removed, 'failed' => $failed, 'in_flight' => $inFlight];
    }

    private function sweepOneTemp(FilesystemAdapter $storage, string $disk, string $file, int $cutoff, bool $dryRun, int &$removed, int &$failed, int &$inFlight): void
    {
        try {
            $this->assertContainedOnDisk($disk, $file);
            if ($storage->lastModified($file) > $cutoff) {
                return;
            }
            if (self::tempLeaseHeld($disk, $file)) {
                $inFlight++;

                return;
            }
        } catch (\Throwable $e) {
            Log::warning('ConversionArtifactStore: temp file skipped by the sweep', ['disk' => $disk, 'path' => $file, 'error' => $e->getMessage()]);
            $failed++;

            return;
        }
        if ($dryRun || $storage->delete($file)) {
            $removed++;

            return;
        }
        Log::warning('ConversionArtifactStore: could not remove stale artifact temp file', ['disk' => $disk, 'path' => $file]);
        $failed++;
    }

    /**
     * Every published artifact under the root, disk-relative, temps excluded,
     * yielded lazily (R3: the tree is never materialised — a consumer batches
     * as it reads). Throws when the root cannot be walked (a local adapter
     * refuses a symbolic link under it): the caller reports a failed sweep
     * (R14).
     *
     * @return \Generator<int, string>
     */
    public function listArtifacts(string $disk, string $prefix): \Generator
    {
        $storage = Storage::disk($disk);
        $root = $this->rootFor($prefix);
        $this->assertRootContained($disk, $root);
        if (! $storage->directoryExists($root)) {
            return;
        }
        foreach (LazyDiskListing::files($storage, $root) as $file) {
            if (str_ends_with($file, '.md')) {
                yield $file;
            }
        }
    }
}
