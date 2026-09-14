<?php

declare(strict_types=1);

namespace App\Support\Kb;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The lock every actor on a storage key `(disk, full path)` shares (ADR 0030
 * §3): a row commit of a non-Markdown source, the `markdown_only` drop of its
 * original (scan + delete), and the orphan-file sweep's deletion of a source
 * (re-check + delete). One key, so none of them can slip between another's
 * two halves. Needs an atomic lock store (Redis in production).
 */
final class SourceKeyLock
{
    public const DEFAULT_SECONDS = 60;

    /** whether the invalid-TTL fallback was already reported in this process (once, not once per lock: a sweep takes one per orphan) */
    private static bool $warnedInvalidSeconds = false;

    /** Test seam: forget the report (mirrors HeldLock::resetWarnings()). */
    public static function resetWarnings(): void
    {
        self::$warnedInvalidSeconds = false;
    }

    public static function make(string $disk, string $fullPath): Lock
    {
        return Cache::lock('kb:source:'.$disk.':'.sha1($fullPath), self::seconds());
    }

    /** Seconds a holder waits for the lock before giving up (`kb.conversion_artifacts.source_lock_wait_seconds`). */
    public static function waitSeconds(): int
    {
        return max(0, (int) config('kb.conversion_artifacts.source_lock_wait_seconds', 10));
    }

    /**
     * Seconds the lock lives when its holder dies
     * (`kb.conversion_artifacts.source_lock_seconds`). A value that is not a
     * positive number of seconds — `0`, a negative, a non-number — is not a
     * shorter lock: it is the documented default, and it says so once. A
     * 1-second clamp would let the serialization lapse mid-commit on a large
     * document and nobody would know (SEC-SETTING-SHAPE-001). A holder whose
     * work outlives the TTL does not run unguarded either: it asserts the
     * lock is still its own before its irreversible step (HeldLock).
     */
    public static function seconds(): int
    {
        $configured = config('kb.conversion_artifacts.source_lock_seconds', self::DEFAULT_SECONDS);
        if (is_numeric($configured) && (int) $configured >= 1) {
            return (int) $configured;
        }
        if (! self::$warnedInvalidSeconds) {
            self::$warnedInvalidSeconds = true;
            Log::warning('SourceKeyLock: kb.conversion_artifacts.source_lock_seconds is not a positive number of seconds; using the default', [
                'configured' => is_scalar($configured) ? $configured : gettype($configured),
                'default' => self::DEFAULT_SECONDS,
            ]);
        }

        return self::DEFAULT_SECONDS;
    }
}
