<?php

declare(strict_types=1);

namespace Tests\Fixtures\Kb;

use App\Services\Kb\DocumentDeleter;
use Closure;

/**
 * A deleter that lets a test act BETWEEN the prune's reference snapshot and
 * the removal gate — the window an identical ingest can commit a row that
 * re-takes a content-addressed path in. The hook runs before the gate's own
 * (locked) reference re-check, on the real run and on the dry-run batch
 * check alike.
 */
final class RaceInsertingDeleter extends DocumentDeleter
{
    /** @var (Closure(string, string): void)|null receives (disk, path) */
    public static ?Closure $beforeGate = null;

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
