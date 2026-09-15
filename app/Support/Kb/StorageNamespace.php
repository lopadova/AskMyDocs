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
     * STRING, else the configured source prefix.
     *
     * The `is_string` guard is the point. `metadata` is persisted JSON and a
     * legacy or directly-ingested row can carry anything under `prefix`; a
     * bare `(string)` cast turns an array into the literal `"Array"` (with a
     * PHP warning) and a path composed from that silently names a namespace
     * nobody recorded. An explicit empty string is NOT malformed — it is a
     * row that recorded "no prefix" and must keep it, never the configured
     * default. One reading for every consumer, exactly like recordedDisk().
     *
     * @param  mixed  $metadata  the row's `metadata` (an array, or anything else — treated as no namespace)
     */
    public static function recordedPrefix(mixed $metadata): string
    {
        $prefix = is_array($metadata) ? ($metadata['prefix'] ?? null) : null;

        return is_string($prefix) ? $prefix : (string) config('kb.sources.path_prefix', '');
    }
}
