<?php

declare(strict_types=1);

namespace Tests\Fixtures\Kb;

use App\Services\Kb\DocumentDeleter;
use Closure;

/**
 * A deleter that lets a test act BETWEEN the prune's reference snapshot and
 * the removal gate — the window an identical ingest can commit a row that
 * re-takes a content-addressed path in. `$beforeGate` fires before the gate
 * takes its lock AND again inside it (through `artifactsReferenced()`, the
 * predicate `artifactReferenced()` wraps), on the real run and on the
 * dry-run batch check alike: hooks must be idempotent and must not take the
 * artifact path lock themselves.
 */
final class RaceInsertingDeleter extends DocumentDeleter
{
    /** @var (Closure(string, string): void)|null receives (disk, path) */
    public static ?Closure $beforeGate = null; // fires twice on a real run: removeArtifactIfUnreferenced() and, through artifactReferenced(), artifactsReferenced() — keep hooks idempotent

    /** @var (Closure(string, string): void)|null runs INSIDE the artifact gate, after the lock is taken and before the reference re-check (simulates a lock that lapses mid-section) */
    public static ?Closure $insideGate = null;

    /** @var (Closure(string, string, string): void)|null receives (disk, fullPath, sourcePath) before the orphan-SOURCE gate takes its lock */
    public static ?Closure $beforeSourceGate = null;

    public function artifactReferenced(string $disk, string $path): bool
    {
        if (self::$insideGate !== null) {
            (self::$insideGate)($disk, $path);
        }

        return parent::artifactReferenced($disk, $path);
    }

    public function removeSourceFileIfUnreferenced(string $disk, string $fullPath, string $sourcePath, ?callable $whileHeld = null): string
    {
        if (self::$beforeSourceGate !== null) {
            (self::$beforeSourceGate)($disk, $fullPath, $sourcePath);
        }

        return parent::removeSourceFileIfUnreferenced($disk, $fullPath, $sourcePath, $whileHeld);
    }

    public function removeArtifactIfUnreferenced(string $disk, string $path): string
    {
        if (self::$beforeGate !== null) {
            (self::$beforeGate)($disk, $path);
        }

        return parent::removeArtifactIfUnreferenced($disk, $path);
    }

    public function artifactsReferenced(string $disk, array $paths): array
    {
        if (self::$beforeGate !== null) {
            foreach ($paths as $path) {
                (self::$beforeGate)($disk, $path);
            }
        }

        return parent::artifactsReferenced($disk, $paths);
    }
}
