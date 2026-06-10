<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\KnowledgeDocument;

/**
 * Locate the on-disk markdown file backing a KnowledgeDocument row.
 *
 * Extracted verbatim from KbDocumentController (read-path semantics:
 * raw / download / print / export-pdf) so the bulk ZIP exporter and any
 * future read-side consumer resolve the SAME disk + path the ingest
 * side wrote (R1 / R8). Resolution order:
 *
 *   disk:  metadata['disk'] stamped at ingest time, else
 *          KbDiskResolver::forProject(project_key)
 *   path:  metadata['prefix'] stamped at ingest time, else
 *          config('kb.sources.path_prefix') — joined with the
 *          KbPath::normalize()d source_path.
 *
 * NOTE: DocumentDeleter keeps its own config-based default on the
 * delete path by design — do not fold it into this locator without
 * checking the deleter's fallback contract first.
 */
final class KbDocumentFileLocator
{
    public static function diskFor(KnowledgeDocument $document): string
    {
        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $stamped = $metadata['disk'] ?? null;
        if (is_string($stamped) && $stamped !== '') {
            return $stamped;
        }

        return KbDiskResolver::forProject($document->project_key);
    }

    /**
     * Join the KB path prefix (KB_PATH_PREFIX, R8) with the document's
     * normalised source_path. Matches the prefix resolution done by
     * DocumentDeleter::forceDelete() so the same file that was written
     * during ingest is the one we read now.
     *
     * @param  string|null  $normalizedPath  pre-normalised source_path; when
     *                                       null it is derived from the model
     */
    public static function fullPathFor(KnowledgeDocument $document, ?string $normalizedPath = null): string
    {
        $normalizedPath ??= KbPath::normalize((string) $document->source_path);

        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $prefix = array_key_exists('prefix', $metadata)
            ? (string) $metadata['prefix']
            : (string) config('kb.sources.path_prefix', '');
        $prefix = trim($prefix, '/');

        return $prefix === '' ? $normalizedPath : $prefix.'/'.$normalizedPath;
    }
}
