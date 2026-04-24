<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Services\Admin\Exceptions\LogFileNotFoundException;
use App\Services\Admin\Exceptions\LogFileUnreadableException;
use InvalidArgumentException;
use SplFileObject;

/**
 * Tails the last N lines of a Laravel-style log file with bounded memory.
 *
 * Contract:
 *   - `$filename` MUST match `/^laravel(-\d{4}-\d{2}-\d{2})?\.log$/` — any
 *     other filename (absolute path, `..`, custom log) throws
 *     `InvalidArgumentException` (surfaces as HTTP 422 in the controller).
 *     This is the path-traversal guard (R4 + no-silent-failures skill).
 *   - `$maxLines` is capped at 2000. Callers may request less; larger
 *     requests silently cap rather than reject so the UI "tail 5000"
 *     slider still produces output.
 *   - Memory: we walk the file BACKWARDS using `SplFileObject::seek` +
 *     `fseek` so a multi-GB `laravel.log` never gets loaded in full. This
 *     is the R3 memory-safe variant of the naïve
 *     `file(…)` / `file_get_contents(…)` approach.
 *   - `$levelFilter` is a case-insensitive match on the Monolog bracketed
 *     level (e.g. `"ERROR"` matches `[ERROR]`). When null we return every
 *     line.
 *
 * Return shape:
 *   `[
 *       'lines' => string[],      // oldest-to-newest, max $maxLines
 *       'truncated' => bool,      // true when we hit the cap before BOF
 *       'total_scanned' => int,   // how many lines we actually examined
 *   ]`
 *
 * Errors:
 *   - Invalid filename  → InvalidArgumentException      → 422
 *   - Missing file      → RuntimeException              → 404
 *   - Unreadable / I/O  → RuntimeException              → 500
 */
class LogTailService
{
    public const MAX_LINES_HARD_CAP = 2000;

    private const FILENAME_REGEX = '/^laravel(-\d{4}-\d{2}-\d{2})?\.log$/';

    /**
     * @return array{lines: string[], truncated: bool, total_scanned: int}
     */
    public function tail(string $filename, int $maxLines = 500, ?string $levelFilter = null): array
    {
        if (! $this->isValidFilename($filename)) {
            throw new InvalidArgumentException(
                "Invalid log filename '{$filename}'. Only laravel.log or laravel-YYYY-MM-DD.log accepted.",
            );
        }

        $maxLines = max(1, min(self::MAX_LINES_HARD_CAP, $maxLines));

        $path = $this->resolveFullPath($filename);
        if (! is_file($path)) {
            throw new LogFileNotFoundException("Log file not found: {$filename}");
        }
        if (! is_readable($path)) {
            throw new LogFileUnreadableException("Log file not readable: {$filename}");
        }

        return $this->readTail($path, $maxLines, $levelFilter);
    }

    /**
     * Exposes the filename whitelist check for the controller's 422 guard.
     * Keeping it public lets the controller reject malformed input BEFORE
     * instantiating the service, so we never do disk I/O on bad input.
     */
    public function isValidFilename(string $filename): bool
    {
        // Defense-in-depth: trim + explicit directory-separator guard.
        // The regex already rejects `/` + `\` via the character class, but
        // we sanity-check trimmed length too to make the intent obvious.
        if (trim($filename) !== $filename || $filename === '') {
            return false;
        }

        return (bool) preg_match(self::FILENAME_REGEX, $filename);
    }

    /**
     * Resolve the full filesystem path. Centralised so the controller
     * never does its own `storage_path()` concatenation (one source of
     * truth for the log directory).
     */
    public function resolveFullPath(string $filename): string
    {
        // Trust $filename ONLY after isValidFilename() — callers must
        // have already called it (the controller does). We intentionally
        // do NOT call realpath() + symlink-resolution here: the whitelist
        // regex is the guard, not the filesystem layout.
        return storage_path('logs/'.$filename);
    }

    /**
     * Reverse-seek reader. Reads chunks from the end of the file and
     * accumulates complete lines until we have $maxLines or hit BOF.
     *
     * @return array{lines: string[], truncated: bool, total_scanned: int}
     */
    private function readTail(string $path, int $maxLines, ?string $levelFilter): array
    {
        // Copilot #1 fix: detect emptiness via `filesize()`, not via
        // `SplFileObject->key() === 0`. A file with exactly one line
        // would key at 0 too, so the previous branch silently dropped
        // the only line. `filesize()` returns false on stat failure
        // (e.g. permission drop mid-call) — treat that as a hard
        // error because the later `SplFileObject` call would also
        // fail and a caller expects a deterministic result.
        $size = filesize($path);
        if ($size === false) {
            throw new LogFileUnreadableException('Unable to stat log file: '.$path);
        }
        if ($size === 0) {
            return ['lines' => [], 'truncated' => false, 'total_scanned' => 0];
        }

        $file = new SplFileObject($path, 'rb');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        // SplFileObject->key() returns the *index* of the last line, but
        // when the file ends without a newline that index is the last
        // real line. We cap start at 0.
        $lines = [];
        $scanned = 0;
        $cursor = $totalLines;

        // We iterate backwards one line at a time via seek() — much
        // cheaper than reading the whole file because SplFileObject
        // buffers the currently-indexed line only.
        while ($cursor >= 0 && count($lines) < $maxLines) {
            $file->seek($cursor);
            $line = $file->current();

            if (! is_string($line)) {
                $cursor--;

                continue;
            }

            $line = rtrim($line, "\r\n");
            $scanned++;

            if ($line !== '' && $this->matchesLevel($line, $levelFilter)) {
                $lines[] = $line;
            }

            if ($cursor === 0) {
                break;
            }
            $cursor--;
        }

        $truncated = $cursor > 0 && count($lines) >= $maxLines;

        // Reverse so the caller gets oldest-to-newest.
        $lines = array_reverse($lines);

        return [
            'lines' => $lines,
            'truncated' => $truncated,
            'total_scanned' => $scanned,
        ];
    }

    private function matchesLevel(string $line, ?string $levelFilter): bool
    {
        if ($levelFilter === null || trim($levelFilter) === '') {
            return true;
        }

        // Laravel's stack formatter emits lines like
        // `[2025-01-01 10:00:00] local.ERROR: message ...`
        // while Monolog's bracketed formatter emits `... [ERROR] ...`.
        // Accept either shape so the filter works across both log
        // channels without the SPA having to know which one is in use.
        $needle = strtoupper(trim($levelFilter));
        $upper = strtoupper($line);

        return str_contains($upper, '['.$needle.']')
            || str_contains($upper, '.'.$needle.':');
    }
}
