<?php

declare(strict_types=1);

namespace App\Services\Kb\Versioning;

use App\Support\KbPath;
use Illuminate\Contracts\Filesystem\Filesystem;
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
 */
final class ConversionArtifactStore
{
    public const ROOT = '.artifacts';

    public const TMP_SUFFIX = '.tmp';

    private const SAFE_SEGMENT = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/';

    /** Reserved for encoded segments: never admitted verbatim. */
    private const ENCODED_PREFIX = 'h-';

    public function enabled(): bool
    {
        return (bool) config('kb.conversion_artifacts.enabled', false);
    }

    /**
     * Disk-relative artifact root for a prefix (`{prefix}/.artifacts`).
     */
    public function rootFor(string $prefix): string
    {
        $prefix = trim($prefix, '/');

        return $prefix === '' ? self::ROOT : $prefix.'/'.self::ROOT;
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
     * @throws RuntimeException when the composed path escapes the artifact root
     */
    public function pathFor(string $tenantId, string $projectKey, string $sourcePath, string $versionHash, string $prefix = ''): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $versionHash) !== 1) {
            throw new RuntimeException('ConversionArtifactStore: version hash must be 64 lowercase hex chars.');
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
        $tmp = $finalPath.'.'.Str::uuid().self::TMP_SUFFIX;
        if (! Storage::disk($disk)->put($tmp, $markdown)) {
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
    public function publish(string $disk, string $tmpPath, string $finalPath): void
    {
        $storage = Storage::disk($disk);
        $this->assertContainedOnDisk($disk, $finalPath);
        if ($this->finalMatchesTemp($storage, $tmpPath, $finalPath)) {
            $this->discardTemp($disk, $tmpPath);

            return;
        }
        if ($this->moveOver($storage, $tmpPath, $finalPath)) {
            // The move may have followed a symlinked parent: verify what landed.
            $this->assertContainedOnDisk($disk, $finalPath);

            return;
        }
        // A concurrent writer may have published between the two calls.
        if ($this->finalMatchesTemp($storage, $tmpPath, $finalPath)) {
            $this->discardTemp($disk, $tmpPath);

            return;
        }
        throw new RuntimeException(sprintf('ConversionArtifactStore: could not publish artifact [%s] %s.', $disk, $finalPath));
    }

    /** True only when a file at the final path carries exactly this attempt's bytes. */
    private function finalMatchesTemp(Filesystem $storage, string $tmpPath, string $finalPath): bool
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
    private function moveOver(Filesystem $storage, string $tmpPath, string $finalPath): bool
    {
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
        if ($storage->exists($finalPath) && ! $storage->delete($finalPath)) {
            return false;
        }

        return (bool) $storage->move($tmpPath, $finalPath);
    }

    /** Delete this attempt's temp file only — never another writer's, never the final path. */
    public function discardTemp(string $disk, string $tmpPath): void
    {
        if (! str_ends_with($tmpPath, self::TMP_SUFFIX)) {
            return;
        }
        try {
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
        return Storage::disk($disk)->exists($path);
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
        $root = $this->artifactRootOf($path);
        if ($root === null) {
            throw new RuntimeException("ConversionArtifactStore: [{$disk}] {$path} is not under an artifact root.");
        }
        if ((string) config("filesystems.disks.{$disk}.driver") !== 'local') {
            return;
        }
        $storage = Storage::disk($disk);
        $realRoot = realpath($storage->path($root));
        if ($realRoot === false) {
            return; // the root does not exist yet: nothing can resolve outside it
        }
        $absolute = $storage->path($path);
        $real = realpath($absolute) ?: realpath(dirname($absolute));
        if ($real === false) {
            return; // neither the file nor its parent exists yet
        }
        if ($real !== $realRoot && ! str_starts_with($real, $realRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("ConversionArtifactStore: [{$disk}] {$path} resolves outside the artifact root (symlink?); refused.");
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

        return implode('/', array_slice($segments, 0, $at + 1));
    }

    public function read(string $disk, string $path): ?string
    {
        try {
            $storage = Storage::disk($disk);
            if (! $storage->exists($path)) {
                return null;
            }
            $this->assertContainedOnDisk($disk, $path);
            $bytes = $storage->get($path);

            return is_string($bytes) ? $bytes : null;
        } catch (\Throwable $e) {
            Log::warning('ConversionArtifactStore: could not read artifact', ['disk' => $disk, 'path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Remove a published artifact. False when nothing was there or the disk
     * refused (logged) — never an exception: retention and erasure must
     * finish their database work whatever the disk says.
     */
    public function delete(string $disk, string $path): bool
    {
        try {
            $storage = Storage::disk($disk);
            if (! $storage->exists($path)) {
                return false;
            }
            $this->assertContainedOnDisk($disk, $path);
            if (! $storage->delete($path)) {
                Log::warning('ConversionArtifactStore: could not delete artifact', ['disk' => $disk, 'path' => $path]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('ConversionArtifactStore: could not delete artifact', ['disk' => $disk, 'path' => $path, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Sweep temp files older than `$maxAgeSeconds` under the artifact root
     * — leftovers of a writer that died between temp and move.
     *
     * @return int files removed
     */
    public function sweepTemps(string $disk, string $prefix, int $maxAgeSeconds, bool $dryRun = false): int
    {
        $storage = Storage::disk($disk);
        $root = $this->rootFor($prefix);
        if (! $storage->directoryExists($root)) {
            return 0;
        }
        $cutoff = time() - max(0, $maxAgeSeconds);
        $removed = 0;
        foreach ($storage->allFiles($root) as $file) {
            if (! str_ends_with($file, self::TMP_SUFFIX)) {
                continue;
            }
            try {
                if ($storage->lastModified($file) > $cutoff) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            if ($dryRun || $storage->delete($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Every published artifact under the root, disk-relative, temps excluded.
     *
     * @return list<string>
     */
    public function listArtifacts(string $disk, string $prefix): array
    {
        $storage = Storage::disk($disk);
        $root = $this->rootFor($prefix);
        if (! $storage->directoryExists($root)) {
            return [];
        }

        return array_values(array_filter(
            $storage->allFiles($root),
            static fn (string $file): bool => str_ends_with($file, '.md'),
        ));
    }
}
