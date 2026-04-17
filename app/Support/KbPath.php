<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Canonical normalisation for KB source paths.
 *
 * Shared between the ingest and delete entry points (HTTP controllers +
 * artisan commands) so the two flows never diverge on what constitutes a
 * valid path. A document ingested as `docs//foo.md` is normalised to
 * `docs/foo.md` at write time — any later delete call must apply the same
 * rules or it would emit spurious "not found" errors.
 */
class KbPath
{
    /**
     * @throws InvalidArgumentException when the path is empty after
     *         normalisation or contains "." / ".." segments.
     */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = trim($path, '/');

        if ($path === '') {
            throw new InvalidArgumentException('Source path must be a non-empty relative path.');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Source path must be a relative path without "." or ".." segments.');
            }
        }

        return $path;
    }
}
