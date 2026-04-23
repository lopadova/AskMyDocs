<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolve which filesystem disk to use for a given KB project.
 *
 * Introduced in PR1 of the enhancement roadmap so tests and future callers
 * share a single source of truth for disk selection. Wired into
 * DocumentIngestor / DocumentDeleter / ingestion commands in a later PR
 * (current code still hardcodes `Storage::disk('kb')` or reads
 * `config('kb.sources.disk')` directly; PR3+ migrate those call sites).
 *
 * Fallback chain:
 *   1. `kb.project_disks[$projectKey]` explicit mapping (if non-empty string)
 *   2. `kb.canonical_disk` (= env KB_FILESYSTEM_DISK, default "kb")
 */
final class KbDiskResolver
{
    public static function forProject(?string $projectKey): string
    {
        $default = self::defaultDisk();

        if ($projectKey === null || $projectKey === '') {
            return $default;
        }

        $map = (array) config('kb.project_disks', []);

        if (! array_key_exists($projectKey, $map)) {
            return $default;
        }

        $disk = $map[$projectKey];

        if (! is_string($disk) || $disk === '') {
            return $default;
        }

        return $disk;
    }

    public static function raw(): string
    {
        return (string) config('kb.raw_disk', 'kb-raw');
    }

    private static function defaultDisk(): string
    {
        return (string) config('kb.canonical_disk', 'kb');
    }
}
