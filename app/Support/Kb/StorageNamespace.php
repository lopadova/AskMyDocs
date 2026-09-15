<?php

declare(strict_types=1);

namespace App\Support\Kb;

/**
 * The ONE reading of a row's recorded storage namespace (`metadata.disk`),
 * shared by every consumer that decides where a row's objects live — the
 * ingestor's retention scans, the deleter's source/OCR/artifact gates, the
 * prune snapshot, the backfill, the Time Machine reads.
 *
 * A disk is RECORDED only when it is a non-empty string. Absent, null, empty
 * or malformed (a stray scalar, an array) all mean "legacy or ambiguous":
 * such a row is never resolved to a guessed disk, references its logical
 * path wherever a deleting consumer looks (fail closed), and reads through
 * the configured disk — never through '' or 'Array'. Every consumer judges
 * the namespace in PHP through this class (the SQL only narrows by path),
 * so a malformed value gets the same answer as a null one.
 */
final class StorageNamespace
{
    /**
     * @param  mixed  $metadata  the row's `metadata` (an array, or anything else — treated as no namespace)
     */
    public static function recordedDisk(mixed $metadata): ?string
    {
        if (! is_array($metadata)) {
            return null;
        }
        $disk = $metadata['disk'] ?? null;

        return is_string($disk) && $disk !== '' ? $disk : null;
    }

    /**
     * The disk a row's objects are read from: the recorded one, else the
     * configured source disk.
     *
     * @param  mixed  $metadata  the row's `metadata` (an array, or anything else — treated as no namespace)
     */
    public static function diskOf(mixed $metadata): string
    {
        return self::recordedDisk($metadata) ?? (string) config('kb.sources.disk', 'kb');
    }

    /**
     * The prefix a row's objects live under: the recorded one when it is a
     * STRING that can actually name a path, else the configured source
     * prefix.
     *
     * The `is_string` guard is half the point. `metadata` is persisted JSON
     * and a legacy or directly-ingested row can carry anything under
     * `prefix`; a bare `(string)` cast turns an array into the literal
     * `"Array"` (with a PHP warning) and a path composed from that silently
     * names a namespace nobody recorded.
     *
     * A recorded prefix is returned VERBATIM even when it cannot name a path
     * (`../outside`): substituting the configured prefix for it would make
     * the row claim an object at a location it never recorded, and a deleting
     * consumer would then remove bytes that may belong to another row. What
     * such a value means is a decision per consumer, not a silent rewrite
     * here — {@see prefixCanNamePath()} is the shared judgement, and the
     * deleter fails closed on it while the read/write paths degrade.
     *
     * An explicit empty string is NOT malformed — it is a row that recorded
     * "no prefix" and must keep it, never the configured default. One reading
     * for every consumer, exactly like recordedDisk().
     *
     * @param  mixed  $metadata  the row's `metadata` (an array, or anything else — treated as no namespace)
     */
    public static function recordedPrefix(mixed $metadata): string
    {
        $prefix = is_array($metadata) ? ($metadata['prefix'] ?? null) : null;

        return is_string($prefix) ? $prefix : (string) config('kb.sources.path_prefix', '');
    }

    /**
     * Whether a prefix can take part in a composed path: the same rule
     * `KbPath::normalize()` enforces, asked BEFORE composition so a consumer
     * can decide instead of catching. An empty prefix is usable (it is "no
     * prefix"); a `\`-separated one is normalised the way every consumer
     * normalises it before the segments are read.
     *
     * The consumers do NOT agree on what to do with an unusable one, and
     * should not: a deleting consumer treats the row as referencing its path
     * everywhere (fail closed — never remove bytes on a guess), while a read
     * or a write degrades (read no original, stage no artifact) so one row's
     * bad metadata cannot fail a job for a version whose bytes are fine. What
     * they share is this judgement.
     */
    public static function prefixCanNamePath(string $prefix): bool
    {
        foreach (explode('/', str_replace('\\', '/', $prefix)) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
