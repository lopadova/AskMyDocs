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
}
